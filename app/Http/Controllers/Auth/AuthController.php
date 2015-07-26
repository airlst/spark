<?php

namespace Laravel\Spark\Http\Controllers\Auth;

use App\User;
use Validator;
use Exception;
use Carbon\Carbon;
use Laravel\Spark\Spark;
use Illuminate\Http\Request;
use Stripe\Coupon as StripeCoupon;
use Illuminate\Support\Facades\DB;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Laravel\Spark\Subscriptions\Plan;
use Laravel\Spark\Events\User\Registered;
use Laravel\Spark\Events\User\Subscribed;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\ThrottlesLogins;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\AuthenticatesAndRegistersUsers;
use Laravel\Spark\Contracts\Auth\Registrar as RegistrarContract;
use Laravel\Spark\Contracts\Auth\Subscriber as SubscriberContract;

class AuthController extends Controller
{
    use AuthenticatesAndRegistersUsers, ThrottlesLogins, ValidatesRequests;

    /**
     * The URI for the login route.
     *
     * @var string
     */
    protected $loginPath = '/login';

    /**
     * Create a new authentication controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->plans = Spark::plans();

        $this->middleware('guest', ['except' => 'getLogout']);
    }

    /**
     * Show the application login form.
     *
     * @return \Illuminate\Http\Response
     */
    public function getLogin()
    {
        return view('spark::auth.authenticate');
    }

    /**
     * Send the post-authentication response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $users
     * @return \Illuminate\Http\Response
     */
    protected function authenticated(Request $request, Authenticatable $user)
    {
        if (Spark::supportsTwoFactorAuth() && Spark::twoFactorProvider()->isEnabled($user)) {
            Auth::logout();

            $request->session()->put('spark:auth:id', $user->id);

            return redirect('login/token');
        }

        return redirect()->intended($this->redirectPath());
    }

    /**
     * Show the two-factor authentication token verification form.
     *
     * @return \Illuminate\Http\Response
     */
    public function getToken()
    {
        return session('spark:auth:id') ? view('spark::auth.token') : redirect('login');
    }

    /**
     * Verify the two-factor authentication token.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function postToken(Request $request)
    {
        $this->validate($request, ['token' => 'required']);

        if (! session('spark:auth:id')) {
            return redirect('login');
        }

        $model = config('auth.model');

        $user = (new $model)->findOrFail(
            $request->session()->pull('spark:auth:id')
        );

        if (Spark::twoFactorProvider()->tokenIsValid($user, $request->token)) {
            Auth::login($user);

            return redirect()->intended($this->redirectPath());
        } else {
            return back();
        }
    }

    /**
     * Show the application registration form.
     *
     * @param  \Illuminate\Http\Request
     * @return \Illuminate\Http\Response
     */
    public function getRegister(Request $request)
    {
        if (Spark::forcingPromotion() && ! $request->query('coupon')) {
            if (count($request->query()) > 0) {
                return redirect($request->fullUrl().'&coupon='.Spark::forcedPromotion());
            } else {
                return redirect($request->fullUrl().'?coupon='.Spark::forcedPromotion());
            }
        }

        if (count($this->plans->active()) > 0) {
            return view('spark::auth.registration.subscription');
        } else {
            return view('spark::auth.registration.simple');
        }
    }

    /**
     * Handle a registration request for the application.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function postRegister(Request $request)
    {
        $withSubscription = count($this->plans->active()) > 0 &&
                            $this->plans->find($request->plan) &&
                            $this->plans->find($request->plan)->price > 0;

        return $this->register($request, $withSubscription);
    }

    /**
     * Handle a registration request for the application.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  bool  $withSubscription
     * @return \Illuminate\Http\Response
     */
    protected function register(Request $request, $withSubscription = false)
    {
        $registrar = app(RegistrarContract::class);

        $this->validateRegistration(
            $request, $registrar->validator($request), $withSubscription
        );

        event(new Registered(
            $user = $this->createUser($request, $registrar, $withSubscription)
        ));

        if ($withSubscription) {
            event(new Subscribed($user));
        }

        Auth::login($user);
    }

    /**
     * Validate the new registration.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Validation\Validator  $validator
     * @param  bool  $withSubscription
     * @return void
     */
    protected function validateRegistration(Request $request, $validator, $withSubscription = false)
    {
        if ($withSubscription) {
            $validator->mergeRules('stripe_token', 'required');

            if ($request->coupon) {
                $validator->after(function ($validator) use ($request) {
                    $this->validateCoupon($validator, $request);
                });
            }
        }

        if ($validator->fails()) {
            $this->throwValidationException($request, $validator);
        }
    }

    /**
     * Validate that the provided coupon actually exists on Stripe.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     * @param  \Illuminate\Http\Request  $request
     * @return void
     */
    protected function validateCoupon($validator, Request $request)
    {
        try {
            $coupon = StripeCoupon::retrieve(
                $request->coupon, ['api_key' => config('services.stripe.secret')]
            );

            if ($coupon && $coupon->valid) {
                return;
            }
        } catch (Exception $e) {
            //
        }

        $validator->errors()->add('coupon', 'The provided coupon code is invalid.');
    }

    /**
     * Create a new user of the application.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Laravel\Spark\Contracts\Auth\Registrar  $registrar
     * @param  bool  $withSubscription
     * @return \Illuminate\Contracts\Auth\Authenticatable
     */
    protected function createUser(Request $request, $registrar, $withSubscription = false)
    {
        return DB::transaction(function () use ($request, $registrar, $withSubscription) {
            $user = $registrar->create($request);

            if ($withSubscription) {
                $this->createSubscriptionOnStripe($request, $user);
            }

            return $user;
        });
    }

    /**
     * Create the subscription on Stripe.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Contracts\Auth\Authenticatable  $user
     * @return void
     */
    protected function createSubscriptionOnStripe(Request $request, $user)
    {
        $plan = $this->plans->find($request->plan);

        $subscription = $user->subscription($plan->id);

        if ($plan->hasTrial()) {
            $subscription->trialFor(Carbon::now()->addDays($plan->trialDays));
        }

        if ($request->coupon) {
            $subscription->withCoupon($request->coupon);
        }

        $subscription->create($request->stripe_token, [
            'email' => $user->email,
        ]);
    }
}