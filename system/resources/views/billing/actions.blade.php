@if(!$canManage)
<p>Kontakt virksomhetens eier eller administrator for å aktivere eller oppdatere abonnementet.</p>
@elseif(!$stripeReady)
<p>Systemeier må fullføre Stripe-oppsettet før betaling kan startes.</p>
@else
@if(!$organization->suspended_at && (!$organization->stripe_subscription_id || in_array($organization->subscription_status,['canceled','incomplete_expired'],true)))
<form method="post" action="{{ route('billing.checkout') }}" class="stack" data-billing-action>@csrf
<label class="check"><input type="checkbox" name="accept_subscription" value="1" required> Jeg godtar abonnement på 249 kr per måned inkl. mva. med automatisk fornyelse. Eventuell gratis måned vises hos Stripe. Abonnementet kan sies opp til periodens slutt.</label>
<button class="button">Aktiver abonnement hos Stripe</button></form>
@endif
@if($organization->stripe_customer_id)
<form method="post" action="{{ route('billing.portal') }}" data-billing-action>@csrf<button class="button">Administrer hos Stripe</button></form>
<p>Oppdater betalingskort og fakturaopplysninger, betal utestående fakturaer eller si opp abonnementet.</p>
@endif
<form method="post" action="{{ route('billing.refresh') }}" data-billing-action>@csrf<button class="button ghost">Oppdater status fra Stripe</button></form>
@endif

<p data-billing-progress role="status" aria-live="polite" hidden></p>
