<x-layouts.app title="Superadmin · DekkPilot" heading="SaaS-kunder">
<div class="admin-subnav"><a href="#kunder">Kunder</a><a href="{{ route('superadmin.stripe') }}">Stripe og abonnement</a><a href="#systemverktoy">Systemverktøy</a><a href="{{ route('superadmin.backups') }}">Backuper</a></div>
<section class="stats"><article><div><small>VIRKSOMHETER</small><strong>{{ $stats['organizations'] }}</strong></div></article><article><div><small>AKTIVE ABONNEMENTER</small><strong>{{ $stats['active'] }}</strong></div></article><article><div><small>BETALING MÅ FØLGES OPP</small><strong>{{ $stats['attention'] }}</strong></div></article><article><div><small>STENGTE VIRKSOMHETER</small><strong>{{ $stats['closed'] }}</strong></div></article></section>
<section class="panel" id="kunder"><div class="panel-head"><div><h2>Kundeoversikt</h2><p>Åpne et kundekort for å endre opplysninger, styre tilgang eller gi gratismåneder.</p></div></div>
<form class="fields" method="get"><label>Søk<input name="q" value="{{ request('q') }}" placeholder="Bedrift, org.nr. eller e-post"></label><label>Abonnement<select name="status"><option value="">Alle</option>@foreach(['active'=>'Aktiv','trialing'=>'Prøveperiode','past_due'=>'Forfalt','incomplete'=>'Ikke startet','canceled'=>'Avsluttet','unpaid'=>'Ikke betalt','paused'=>'Pauset'] as $value=>$label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach</select></label><label>Tilgang<select name="access"><option value="">Alle</option><option value="open" @selected(request('access')==='open')>Ikke administrativt stengt</option><option value="closed" @selected(request('access')==='closed')>Stengt av superadmin</option></select></label><button class="button">Søk</button></form>
<div class="table-wrap"><table><thead><tr><th>Virksomhet / kontakt</th><th>Tilgang</th><th>Siste faktura</th><th>Abonnement</th><th></th></tr></thead><tbody>
@forelse($organizations as $organization)
@php($owner=$organization->users->firstWhere('role','owner')??$organization->users->first())
<tr><td><strong><a href="{{ route('superadmin.customer',$organization) }}">{{ $organization->name }}</a></strong><small>{{ $organization->organization_number ?: 'Org.nr. mangler' }}</small><small>{{ $organization->email ?: $owner?->email }}</small></td>
<td><span class="status {{ $organization->hasSubscriptionAccess()?'completed':'cancelled' }}">{{ $organization->suspended_at ? 'Stengt av superadmin' : ($organization->hasSubscriptionAccess()?'Åpen':'Mangler abonnement') }}</span></td>
<td>
@if($organization->hasFreeAccess())
<strong>Gratis tilgang</strong><small>Til {{ $organization->free_access_until->timezone('Europe/Oslo')->format('d.m.Y H:i') }}</small>
@else
@include('superadmin.payment-status')
@endif
</td>
<td>{{ ['active'=>'Aktivt','trialing'=>'Prøveperiode','past_due'=>'Forfalt','incomplete'=>'Ikke aktivert','canceled'=>'Avsluttet','unpaid'=>'Ikke betalt','paused'=>'Pauset','incomplete_expired'=>'Aktivering utløpt'][$organization->subscription_status]??$organization->subscription_status }}
@if($organization->stripe_cancel_at_period_end)<small>Opphører ved periodeslutt</small>@endif
@if($organization->stripe_free_month_granted_at)<small>{{ $organization->stripe_free_month_count }} gratismåned(er) tildelt {{ $organization->stripe_free_month_granted_at->format('d.m.Y') }}</small>@endif</td>
<td><a class="button ghost" href="{{ route('superadmin.customer',$organization) }}">Administrer →</a></td></tr>
@empty<tr><td colspan="5">Ingen kunder matcher søket.</td></tr>@endforelse
</tbody></table></div>{{ $organizations->links() }}</section>
<details class="panel" id="systemverktoy"><summary><strong>Systemverktøy og drift</strong> – oppdateringer, e-post, backup og diagnose</summary>@include('superadmin.tools')</details>
</x-layouts.app>
