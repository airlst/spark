<?php

namespace Laravel\Spark\Http\Controllers\API;

use Exception;
use Illuminate\Support\Facades\Auth;
use Stripe\Stripe;
use Laravel\Spark\Spark;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Stripe\Coupon as StripeCoupon;
use Stripe\Customer as StripeCustomer;
use Laravel\Spark\Subscriptions\Coupon;

class SubscriptionController extends Controller
{
    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth', ['only' => [
            'getCouponForUser',
        ]]);
    }

    /**
     * Get all of the plans defined for the application.
     *
     * @return \Illuminate\Http\Response
     */
    public function getPlans()
    {
        return response()->json(Spark::plans());
    }

    /**
     * Get the coupon for a given code.
     *
     * Used for the registration page.
     *
     * @param  string  $code
     * @return \Illuminate\Http\Response
     */
    public function getCoupon($code)
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        if (count(Spark::plans()) === 0) {
            abort(404);
        }

        try {
            return response()->json(
                Coupon::fromStripeCoupon(StripeCoupon::retrieve($code))
            );
        } catch (Exception $e) {
            abort(404);
        }
    }

    /**
     * Get the current coupon for the authenticated user.
     *
     * Used to display current discount on settings -> subscription tab.
     *
     * @return \Illuminate\Http\Response
     */
    public function getCouponForUser()
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        if (count(Spark::plans()) === 0) {
            abort(404);
        }

        try {
            $customer = StripeCustomer::retrieve(Auth::user()->stripe_id);

            if ($customer->discount) {
                return response()->json(
                    Coupon::fromStripeCoupon(
                        StripeCoupon::retrieve($customer->discount->coupon->id)
                    )
                );
            } else {
                abort(404);
            }
        } catch (Exception $e) {
            abort(404);
        }
    }

    /**
     * Get the billing address associated to the authenticated user.
     *
     * Used to populate the update billing address form on settings -> subscription tab.
     *
     * @return \Illuminate\Http\Response
     */
    public function getBillingAddressForUser()
    {
        Stripe::setApiKey(config('services.stripe.secret'));

        try {
            $customer = StripeCustomer::retrieve(Auth::user()->stripe_id);
            $card = $customer->sources->retrieve( $customer->default_source );
            return response()->json([
                "company" => isset( $customer->metadata->company ) ? $customer->metadata->company : "",
                "name" => $card->name,
                "street"  => $card->address_line1,
                "zip"  => $card->address_zip,
                "city"  => $card->address_city,
                "country"  => $card->address_country,
            ]);
        } catch (Exception $e) {
            abort(404);
        }
    }
}
