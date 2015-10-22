<div class="panel panel-default">
	<div class="panel-heading">
		Overview
		<div class="clearfix"></div>
	</div>

	<div class="panel-body">
		<form class="form-horizontal" role="form">
			<div class="form-group">
				<label for="number" class="col-sm-4 control-label">Subtotal</label>
				<div class="col-sm-6">
					<div class="form-control-static">
						$ <span class="vat-subtotal">@{{ selectedPlanPrice }}</span>
					</div>
				</div>
			</div>

			<div class="form-group">
				<label for="number" class="col-sm-4 control-label">VAT</label>
				<div class="col-sm-6">
					<div class="form-control-static">
						$ <span class="vat-taxes"></span>
					</div>
				</div>
			</div>

			<div class="form-group">
				<label for="number" class="col-sm-4 control-label">Total</label>
				<div class="col-sm-6">
					<div class="form-control-static">
						$ <span class="vat-total">@{{ selectedPlanPrice }}</span>
					</div>
				</div>
			</div>

			<div class="form-group">
				<div class="col-sm-6 col-sm-offset-4">
					<div class="checkbox">
						<label>
							<input type="checkbox" v-model="registerForm.terms">
							I Accept The <a href="/terms" target="_blank">Terms Of Service</a>
						</label>
					</div>
				</div>
			</div>

			<div class="form-group">
				<div class="col-sm-6 col-sm-offset-4">
					<button type="submit" class="btn btn-primary" v-on="click: register" v-attr="disabled: registerForm.registering">
						<span v-if="registerForm.registering">
							<i class="fa fa-btn fa-spinner fa-spin"></i> Registering
						</span>

						<span v-if=" ! registerForm.registering">
							<i class="fa fa-btn fa-check-circle"></i>

							<span v-if=" ! selectedPlan.trialDays">
								Register
							</span>

							<span v-if="selectedPlan.trialDays">
								Begin @{{ selectedPlan.trialDays }} Day Trial
							</span>
						</span>
					</button>
				</div>
			</div>
		</form>
	</div>
</div>
