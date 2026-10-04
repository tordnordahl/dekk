@php($billingBlocked = !auth()->user()->is_super_admin && !$organization->hasSubscriptionAccess())
<x-dynamic-component :component="$billingBlocked ? 'layouts.billing-gate' : 'layouts.app'" title="Abonnement · DekkPilot" heading="Abonnement">
@php($canManage = auth()->user()->is_super_admin || in_array(auth()->user()->role,['owner','admin'],true))
<script defer src="{{ asset('billing.js') }}?v=20261004-1"></script>
@if($billingBlocked)
<p class="eyebrow">DEKKPILOT · ABONNEMENT</p>
<h1 id="billing-gate-title">{{ $organization->suspended_at ? 'Tilgangen er stengt' : 'Aktiver abonnementet' }}</h1>
<p>{{ $organization->name }}</p>
@if($organization->suspended_at)
<p>Tilgangen er stengt av DekkPilot. Kontakt systemeier for gjenåpning. Betaling åpner ikke tilgangen automatisk.</p>
@else
<p id="billing-gate-description">Kontoen er klar. Fullfør abonnementsbetalingen hos Stripe før du kan bruke DekkPilot.</p>
@endif
<div class="billing-price"><strong>249 kr</strong><span>per måned inkl. mva.</span></div>
<p>Alle funksjoner og brukere er inkludert. SMS faktureres separat etter bruk. Ingen bindingstid.</p>
@if($organization->stripe_free_month_granted_at)<div class="usage-note">{{ $organization->stripe_free_month_count }} gratismåned(er) {{ $organization->stripe_free_month_applied_at?'er lagt til hos Stripe':'venter på aktivering' }}. Betalingskort registreres hos Stripe.</div>@endif
@if(in_array($organization->subscription_status,['past_due','unpaid'],true))<div class="errors">En betaling mangler. Åpne Stripe for å betale eller oppdatere kortet, og hent deretter ny status.</div>@endif
@include('billing.actions')
<form method="post" action="{{ route('logout') }}">@csrf<button class="button ghost">Logg ut</button></form>
@else
<section class="grid form-grid"><article class="panel">
<p class="eyebrow">DEKKPILOT</p><h2>249 kr per måned inkl. mva.</h2>
<p>Alle funksjoner og brukere er inkludert. SMS faktureres separat etter bruk.</p>
<p>Status: <strong>{{ ['active'=>'Aktivt','trialing'=>'Prøveperiode','incomplete'=>'Venter på betaling','incomplete_expired'=>'Betaling utløpt','past_due'=>'Betaling mangler','unpaid'=>'Ikke betalt','canceled'=>'Avsluttet','paused'=>'Satt på pause'][$organization->subscription_status] ?? $organization->subscription_status }}</strong></p>
@if($organization->suspended_at)
<div class="usage-note"><strong>Tilgangen er stengt av DekkPilot</strong><span>Kontakt DekkPilot for gjenåpning. Betaling åpner ikke tilgangen automatisk. Du kan fortsatt administrere eller si opp abonnementet hos Stripe.</span></div>
@elseif($organization->hasSubscriptionAccess())
<p>Virksomheten har tilgang til DekkPilot.</p><a class="button" href="{{ route('dashboard') }}">Åpne DekkPilot</a>
@else
<div class="usage-note"><strong>Aktiver abonnementet for å åpne systemet</strong><span>Kontoen og dataene dine er bevart. Eier eller administrator må fullføre betalingen hos Stripe.</span></div>
@endif
@if($organization->subscription_ends_at)<p>{{ $organization->stripe_cancel_at_period_end ? 'Abonnementet avsluttes' : 'Gjeldende periode slutter' }} {{ $organization->subscription_ends_at->format('d.m.Y') }}.</p>@endif
@if($organization->stripe_free_month_granted_at)
<p>{{ $organization->stripe_free_month_applied_at ? $organization->stripe_free_month_count.' gratismåned(er) lagt til hos Stripe' : $organization->stripe_free_month_count.' gratismåned(er) venter på aktivering' }}. Gjelder abonnementet; SMS kommer i tillegg.</p>
@endif
<p>SMS denne måneden: <strong>{{ number_format($smsUsage,0,',',' ') }}</strong>.</p>
</article><aside class="panel"><h2>Betaling og abonnement</h2>
@include('billing.actions')
</aside></section>
<section class="panel"><div class="panel-head"><div><p class="eyebrow">FAKTURAGRUNNLAG</p><h2>Månedsoversikt</h2></div></div><div class="table-wrap"><table><thead><tr><th>Periode</th><th>Abonnement</th><th>SMS</th><th>Rabatt</th><th>Totalt</th><th>Status</th></tr></thead><tbody>@forelse($statements as $statement)<tr><td>{{ $statement->period_start->translatedFormat('F Y') }}</td><td>{{ number_format($statement->subscription_cents/100,2,',',' ') }} kr</td><td>{{ $statement->sms_quantity }} stk.<small>{{ number_format($statement->sms_total_cents/100,2,',',' ') }} kr</small></td><td>{{ number_format($statement->discount_cents/100,2,',',' ') }} kr</td><td><strong>{{ number_format($statement->total_cents/100,2,',',' ') }} kr</strong></td><td><span class="status {{ $statement->status==='invoiced'?'completed':'in_progress' }}">{{ ['draft'=>'Utkast','ready'=>'Klart','invoiced'=>'Fakturert','void'=>'Annullert'][$statement->status] }}</span></td></tr>@empty<tr><td colspan="6" class="empty">Første grunnlag opprettes automatisk etter månedsslutt.</td></tr>@endforelse</tbody></table></div></section>
@endif
</x-dynamic-component>
