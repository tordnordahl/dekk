<x-layouts.app title="Abonnement · DekkPilot" heading="Abonnement">
@php($canManage = auth()->user()->is_super_admin || in_array(auth()->user()->role,['owner','admin'],true))
<section class="grid form-grid"><article class="panel">
<p class="eyebrow">DEKKPILOT</p><h2>249 kr per måned inkl. mva.</h2>
<p>Alle funksjoner og brukere er inkludert. SMS faktureres separat etter bruk.</p>
<p>Status: <strong>{{ ['active'=>'Aktivt','trialing'=>'Prøveperiode','incomplete'=>'Venter på betaling','incomplete_expired'=>'Betaling utløpt','past_due'=>'Betaling mangler','unpaid'=>'Ikke betalt','canceled'=>'Avsluttet','paused'=>'Satt på pause'][$organization->subscription_status] ?? $organization->subscription_status }}</strong></p>
@if($organization->hasSubscriptionAccess())
<p>Virksomheten har tilgang til DekkPilot.</p><a class="button" href="{{ route('dashboard') }}">Åpne DekkPilot</a>
@else
<div class="usage-note"><strong>Aktiver abonnementet for å åpne systemet</strong><span>Kontoen og dataene dine er bevart. Eier eller administrator må fullføre betalingen hos Stripe.</span></div>
@endif
@if($organization->subscription_ends_at)<p>{{ $organization->stripe_cancel_at_period_end ? 'Abonnementet avsluttes' : 'Gjeldende periode slutter' }} {{ $organization->subscription_ends_at->format('d.m.Y') }}.</p>@endif
@if($organization->stripe_free_month_granted_at)
<p>{{ $organization->stripe_free_month_applied_at ? 'Gratismåned lagt til hos Stripe' : 'Én gratis måned venter på aktivering' }}. Gjelder abonnementet; SMS kommer i tillegg.</p>
@endif
<p>SMS denne måneden: <strong>{{ number_format($smsUsage,0,',',' ') }}</strong>.</p>
</article><aside class="panel"><h2>Betaling og abonnement</h2>
@if(!$canManage)
<p>Kontakt virksomhetens eier eller administrator for å aktivere eller oppdatere abonnementet.</p>
@elseif(!$stripeReady)
<p>Systemeier må fullføre Stripe-oppsettet før betaling kan startes.</p>
@else
@if(!$organization->stripe_subscription_id || in_array($organization->subscription_status,['canceled','incomplete_expired'],true))
<form method="post" action="{{ route('billing.checkout') }}" class="stack">@csrf
<label class="check"><input type="checkbox" name="accept_subscription" value="1" required> Jeg godtar abonnement på 249 kr per måned inkl. mva. med automatisk fornyelse. Eventuell gratis måned vises hos Stripe. Abonnementet kan sies opp til periodens slutt.</label>
<button class="button">Aktiver abonnement hos Stripe</button></form>
@endif
@if($organization->stripe_customer_id)
<form method="post" action="{{ route('billing.portal') }}">@csrf<button class="button">Administrer hos Stripe</button></form>
<p>Oppdater betalingskort og fakturaopplysninger, betal utestående fakturaer eller si opp abonnementet.</p>
@endif
<form method="post" action="{{ route('billing.refresh') }}">@csrf<button class="button ghost">Oppdater status fra Stripe</button></form>
@endif
</aside></section>
<section class="panel"><div class="panel-head"><div><p class="eyebrow">FAKTURAGRUNNLAG</p><h2>Månedsoversikt</h2></div></div><div class="table-wrap"><table><thead><tr><th>Periode</th><th>Abonnement</th><th>SMS</th><th>Rabatt</th><th>Totalt</th><th>Status</th></tr></thead><tbody>@forelse($statements as $statement)<tr><td>{{ $statement->period_start->translatedFormat('F Y') }}</td><td>{{ number_format($statement->subscription_cents/100,2,',',' ') }} kr</td><td>{{ $statement->sms_quantity }} stk.<small>{{ number_format($statement->sms_total_cents/100,2,',',' ') }} kr</small></td><td>{{ number_format($statement->discount_cents/100,2,',',' ') }} kr</td><td><strong>{{ number_format($statement->total_cents/100,2,',',' ') }} kr</strong></td><td><span class="status {{ $statement->status==='invoiced'?'completed':'in_progress' }}">{{ ['draft'=>'Utkast','ready'=>'Klart','invoiced'=>'Fakturert','void'=>'Annullert'][$statement->status] }}</span></td></tr>@empty<tr><td colspan="6" class="empty">Første grunnlag opprettes automatisk etter månedsslutt.</td></tr>@endforelse</tbody></table></div></section>
</x-layouts.app>
