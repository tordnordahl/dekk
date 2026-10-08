<x-layouts.app title="Kundekort · DekkPilot" :heading="$organization->name">
<link rel="stylesheet" href="{{ route('system.asset.query',['filename'=>'superadmin-customer.css','v'=>'20261008']) }}">
<div class="customer-toolbar"><a href="{{ route('superadmin') }}">← Alle kunder</a><form method="post" action="{{ route('superadmin.enter',$organization) }}">@csrf<button class="button ghost">Gå inn i virksomheten →</button></form></div>
<section class="customer-overview" aria-label="Kundestatus">
<div><small>TILGANG</small><strong>{{ $organization->suspended_at?'Stengt':($organization->hasSubscriptionAccess()?'Åpen':'Mangler abonnement') }}</strong></div>
<div><small>SISTE FAKTURA</small>
@include('superadmin.payment-status')
</div>
<div><small>AVTALE</small><strong>{{ $organization->organization_number==='DEMO-DEKKPILOT'?'Demo – unntatt':(!$currentAgreement?'Ikke publisert':($currentAcceptance?'Godkjent '.$currentAgreement->version:'Venter på godkjenning')) }}</strong>@if($currentAcceptance)<small>{{ $currentAcceptance->actor_name }} · {{ \Illuminate\Support\Carbon::parse($currentAcceptance->accepted_at)->timezone('Europe/Oslo')->format('d.m.Y H:i') }}</small>@endif</div>
</section>
<p class="customer-caption">Org.nr. {{ $organization->organization_number?:'Ikke registrert' }} · {{ $organization->email?:'E-post mangler' }} · {{ $organization->users_count }} brukere · {{ $organization->customers_count }} kunder · {{ $organization->vehicles_count }} biler · {{ $organization->tire_sets_count }} hjulsett</p>
<div class="customer-sections">
<details class="customer-section" id="opplysninger" @if($errors->any()) open @endif><summary><span><strong>Virksomhet og kontakt</strong><small>Kontaktopplysninger, adresse og oppslag fra Brønnøysundregistrene</small></span><span class="customer-chevron" aria-hidden="true">⌄</span></summary><div class="customer-section-body"><form class="stack" method="post" action="{{ route('superadmin.customer.update',$organization) }}">@csrf @method('PUT')
<label>Virksomhetsnavn<input name="name" value="{{ old('name',$organization->name) }}" required maxlength="255"></label><label>Organisasjonsnummer<input name="organization_number" value="{{ old('organization_number',$organization->organization_number) }}" maxlength="32"></label><label>Kontakt-/faktura-e-post<input type="email" name="email" value="{{ old('email',$organization->email) }}" maxlength="255"></label><label>Telefon<input name="phone" value="{{ old('phone',$organization->phone) }}" maxlength="32"></label><p class="muted">Oppdaterer virksomheten i DekkPilot. Brukernes innlogging og eksisterende fakturaopplysninger hos Stripe endres ikke.</p><button class="button">Lagre kundeopplysninger</button></form>
@include('superadmin.registry')
</div></details>
<details class="customer-section" id="abonnement" @if($errors->any()) open @endif><summary><span><strong>Betaling og gratismåneder</strong><small>Abonnementsstatus, Stripe og tildeling av gratis tilgang</small></span><span class="customer-chevron" aria-hidden="true">⌄</span></summary><div class="customer-section-body">@if($organization->free_access_until)<p><strong>Gratis tilgang uten Stripe:</strong> {{ $organization->free_access_until->timezone('Europe/Oslo')->format('d.m.Y H:i') }} ({{ $organization->hasFreeAccess()?'aktiv':'utløpt eller stengt' }}).</p>@endif
@include('superadmin.payment-status')
<p>Abonnementsstatus: <strong>{{ ['active'=>'Aktivt','trialing'=>'Prøveperiode','past_due'=>'Forfalt','incomplete'=>'Ikke aktivert','unpaid'=>'Ikke betalt','canceled'=>'Avsluttet','paused'=>'Pauset'][$organization->subscription_status]??$organization->subscription_status }}</strong></p>
@if($organization->subscription_ends_at)<p>Gjeldende periode til {{ $organization->subscription_ends_at->format('d.m.Y H:i') }}. {{ $organization->stripe_cancel_at_period_end?'Abonnementet opphører da.':'' }}</p>@endif
<p class="muted">Viser siste faktura, ikke en full oversikt over eventuell eldre gjeld. En oppgjort faktura på 0 kr betyr at ingen penger er innbetalt på den fakturaen.</p>
<form method="post" action="{{ route('superadmin.stripe.refresh',$organization) }}">@csrf<button class="button ghost">Hent ny status fra Stripe</button></form>
@if($organization->stripe_customer_id)<p><a target="_blank" rel="noopener" href="https://dashboard.stripe.com/customers/{{ rawurlencode($organization->stripe_customer_id) }}">Åpne kunde og fakturaer hos Stripe ↗</a></p>@endif
@if($organization->organization_number!=='DEMO-DEKKPILOT')
<details class="customer-subsection"><summary>Gi gratis måneder</summary>
@if($organization->stripe_free_month_granted_at)<p>Sist tildelt: {{ $organization->stripe_free_month_count }} måned(er), {{ $organization->stripe_free_month_granted_at->format('d.m.Y') }}. {{ $organization->free_access_grant_key===$organization->stripe_free_month_key?'Brukt som gratis tilgang uten Stripe.':($organization->stripe_free_month_applied_at?'Lagt til hos Stripe.':'Venter på aktivering eller fullføring.') }}</p>@endif
<form method="post" action="{{ route('superadmin.stripe.free-month',$organization) }}" class="stack">@csrf
@php($pending=$organization->hasUnusedFreeGrant())
<input type="hidden" name="grant_key" value="{{ $pending?$organization->stripe_free_month_key:(string)\Illuminate\Support\Str::uuid() }}">
<label>Antall gratismåneder<input type="number" name="months" min="1" max="12" value="{{ $pending?$organization->stripe_free_month_count:1 }}" @readonly($pending) required></label>
<p>Gjelder første eller kommende abonnementsbetalinger. Kunder uten Stripe-abonnement kan velge å starte gratisperioden uten kort. SMS er ikke inkludert. Du kan gi nye måneder når en eksisterende rabatt er brukt opp. En påbegynt tildeling fullføres med samme antall.</p><label class="check"><input type="checkbox" name="confirm" value="1" required> Jeg bekrefter gratisperioden for {{ $organization->name }}.</label><button class="button">{{ $pending?'Fullfør tildeling':'Gi gratis måneder' }}</button></form></details>@endif
</div></details>
<details class="customer-section" id="avtale" @if($errors->any()) open @endif><summary><span><strong>Avtale og godkjenning</strong><small>Hvem som har godkjent, tidspunkt og nøyaktig avtaleversjon</small></span><span class="customer-chevron" aria-hidden="true">⌄</span></summary><div class="customer-section-body">@include('superadmin.agreement-status')
</div></details>
<details class="customer-section" id="tilgang" @if($errors->any()) open @endif><summary><span><strong>Tilgang og brukere</strong><small>Steng eller gjenåpne tilgang, se brukere og administrative endringer</small></span><span class="customer-chevron" aria-hidden="true">⌄</span></summary><div class="customer-section-body"><p><strong>{{ $organization->suspended_at?'Stengt av superadmin':($organization->hasSubscriptionAccess()?'Virksomheten har tilgang':'Abonnement må aktiveres') }}</strong></p>
@if($organization->suspended_at)<p>Stengt {{ $organization->suspended_at->format('d.m.Y H:i') }}. {{ $organization->suspension_reason }}</p>@endif
<p>Stenging blokkerer ansatte i nettappen og API-et. Data beholdes. Kunden kan fortsatt administrere abonnementet. <strong>Stenging sier ikke opp abonnementet eller stopper Stripe-trekk.</strong> Oppsigelse administreres hos Stripe. Gjenåpning fjerner stengingen; vanlige betalingskrav gjelder fortsatt.</p>
<form class="stack" method="post" action="{{ route('superadmin.customer.access',$organization) }}">@csrf @method('PUT')<input type="hidden" name="closed" value="{{ $organization->suspended_at?0:1 }}">@unless($organization->suspended_at)<label>Intern begrunnelse<input name="reason" maxlength="500"></label>@endunless<label class="check"><input type="checkbox" name="confirm" value="1" required> Bekreft {{ $organization->suspended_at?'gjenåpning':'stenging' }} av {{ $organization->name }}.</label><button class="button {{ $organization->suspended_at?'':'danger' }}">{{ $organization->suspended_at?'Gjenåpne tilgang':'Steng tilgang' }}</button></form>
<hr><p>{{ $organization->customers_count }} kunder · {{ $organization->vehicles_count }} biler · {{ $organization->tire_sets_count }} hjulsett</p><ul>@foreach($organization->users as $user)<li>{{ $user->name }} · {{ $user->email }} · {{ $user->role }}{{ $user->active?'':' (deaktivert)' }}</li>@endforeach</ul><details><summary>Siste administrative endringer</summary><ul>@forelse($history as $event)<li>{{ $event->created_at }} · {{ ['superadmin.customer.updated'=>'Kundeopplysninger endret','superadmin.customer.closed'=>'Tilgang stengt','superadmin.customer.reopened'=>'Tilgang gjenåpnet','stripe.free_month_granted'=>'Gratismåneder tildelt','superadmin.tenant.entered'=>'Superadmin åpnet virksomheten'][$event->action]??$event->action }}</li>@empty<li>Ingen registrerte endringer.</li>@endforelse</ul></details></div></details>
<details class="customer-section" id="demo" @if($errors->any()) open @endif><summary><span><strong>Demomiljø og statistikk</strong><small>Utelat testdata fra porteføljestatistikken</small></span><span class="customer-chevron" aria-hidden="true">⌄</span></summary><div class="customer-section-body">
    <p>Merk test- og demokunder som ikke skal telle i Jovia Digital. Abonnement og innlogging endres ikke.</p>
    <form class="stack" method="post" action="{{ route('superadmin.customer.portfolio-demo', $organization) }}">
        @csrf
        @method('PUT')
        <input type="hidden" name="excluded" value="0">
        <label class="check">
            <input type="checkbox" name="excluded" value="1" @checked(old('excluded', $organization->exclude_from_portfolio))>
            Demomiljø — utelat kunden og alle tilhørende tellinger fra API-statistikken
        </label>
        <button class="button">Lagre statistikkvalg</button>
    </form>
</div></details>
</div>
</x-layouts.app>
