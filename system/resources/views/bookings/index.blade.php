<x-layouts.app title="Timebok · DekkPilot" heading="Timebok">
<link rel="stylesheet" href="{{ asset('booking-picker.css') }}?v=20260810-2">
<link rel="stylesheet" href="{{ asset('booking-availability.css') }}?v=20260810-1">
<link rel="stylesheet" href="{{ asset('booking-services.css') }}?v=20260810-1">
<link rel="stylesheet" href="{{ asset('booking-overview.css') }}?v=20260810-1">
<link rel="stylesheet" href="{{ asset('booking-identity.css') }}?v=20260812-1">
<link rel="stylesheet" href="{{ asset('booking-completion.css') }}?v=20260811-2">
@php($canCreate=in_array(auth()->user()->role,['owner','admin','manager','customer_service'],true))
<section class="booking-toolbar panel">
    <form method="get">
        <input type="hidden" name="view" value="{{ request('view','bookings') }}">
        @if(request('view')==='available')
        <label>Tjeneste<select name="service_id">@foreach($services as $service)<option value="{{ $service->id }}" @selected($availabilityService?->id===$service->id)>{{ $service->name }} · {{ $service->duration_minutes }} min</option>@endforeach</select></label>
        <label>Fra dato<input type="date" name="available_date" min="{{ today()->toDateString() }}" value="{{ request('available_date',today()->toDateString()) }}"></label>
        <button class="button">Vis ledige timer</button>
        @else
        <label>Fra<input type="date" name="from" value="{{ request('from',today()->toDateString()) }}"></label>
        <label>Til<input type="date" name="to" value="{{ request('to',today()->addDays(30)->toDateString()) }}"></label>
        <label>Status<select name="status"><option value="">Alle</option><option value="scheduled" @selected(request('status')==='scheduled')>Planlagt</option><option value="in_progress" @selected(request('status')==='in_progress')>Pågår</option><option value="completed" @selected(request('status')==='completed')>Fullført</option></select></label>
        <button class="button">Vis periode</button><a href="{{ route('bookings',['date'=>'today']) }}">I dag</a>
        @endif
    </form>
    <div class="booking-toolbar-actions">
        <div><span class="confirmation-key confirmed">● Godkjent</span><span class="confirmation-key waiting">● Venter</span><span class="confirmation-key overbooked">● Overbooket</span></div>
        @if($canCreate)<button type="button" class="booking-add-button" data-booking-open aria-label="Opprett ny booking" title="Opprett ny booking">+</button>@endif
    </div>
</section>
<nav class="booking-view-switch" aria-label="Velg timebokvisning"><a class="{{ request('view')!=='available'?'active':'' }}" href="{{ route('bookings') }}">Bookinger</a><a class="{{ request('view')==='available'?'active':'' }}" href="{{ route('bookings',['view'=>'available']) }}">Ledige timer</a></nav>
<section class="booking-workspace">
    <article class="panel">
        @if(request('view')==='available')
        <div class="panel-head"><div><p class="eyebrow">LEDIG KAPASITET</p><h2>{{ $availabilityService?->name ?? 'Ledige timer' }}</h2><p>Velg et tidspunkt for å opprette booking.</p></div><span class="status">{{ $availabilitySlots->count() }} tider</span></div>
        <div class="availability-overview">@php($lastAvailableDay=null)@forelse($availabilitySlots as $slot)@if($slot['day_key']!==$lastAvailableDay)<h3>{{ $slot['day'] }}</h3>@php($lastAvailableDay=$slot['day_key'])@endif<a href="{{ route('bookings',['new'=>1,'slot'=>$slot['starts_at'],'service_id'=>$availabilityService?->id]) }}"><strong>{{ $slot['time'] }}</strong><span>til {{ $slot['end'] }}</span><em>Book →</em></a>@empty<div class="empty-state"><strong>Ingen ledige timer funnet</strong><span>Velg en senere dato eller kontroller kapasiteten under Innstillinger.</span></div>@endforelse</div>
        @else
        <div class="panel-head"><div><p class="eyebrow">FREMOVER</p><h2>{{ $bookings->total() }} bookinger</h2></div></div>
        <div class="booking-days">@php($lastDay=null)
        @forelse($bookings as $booking)
            @php($day=$booking->starts_at->toDateString())
            @if($day!==$lastDay)<div class="day-divider"><strong>{{ $booking->starts_at->isToday()?'I dag':($booking->starts_at->isTomorrow()?'I morgen':$booking->starts_at->translatedFormat('l d. F')) }}</strong><span>{{ $booking->starts_at->format('d.m.Y') }}</span></div>@php($lastDay=$day)@endif
            <div class="booking-row {{ $booking->capacity_overbooked?'overbooked':'' }}">
                <time>{{ $booking->starts_at->format('H:i') }}<small>{{ $booking->ends_at->format('H:i') }}</small></time>
                <div class="booking-customer"><div class="booking-customer-line"><strong>{{ $booking->vehicle?->registration_number ?? 'Uten bil' }}</strong><span>{{ $booking->customer->name }}</span></div><span>{{ $booking->service_name }}</span></div>
                <div><strong>{{ $booking->assignedUser?->name ?? 'Ikke tildelt' }}</strong><span>{{ $booking->workBay?->code ?? 'Bukk tildeles senere' }}</span></div>
                @if($booking->capacity_overbooked)<span class="overbooked-badge">! Overbooket</span>@else<span class="confirmation-badge {{ $booking->confirmation_status==='confirmed'?'confirmed':'waiting' }}">{{ $booking->confirmation_status==='confirmed'?'Godkjent':'Venter' }}</span>@endif
                <span class="status {{ $booking->status }}">{{ ['scheduled'=>'Venter','arrived'=>'Ankommet','in_progress'=>'Pågår','completed'=>'Fullført','cancelled'=>'Avbrutt','no_show'=>'Ikke møtt'][$booking->status] }}</span>
                @if(!in_array($booking->status,['completed','cancelled','no_show'],true)&&in_array(auth()->user()->role,['owner','admin','manager'],true))
                    <button type="button" class="mini-action" data-complete-booking data-action="{{ route('bookings.complete',$booking) }}" data-registration="{{ $booking->vehicle?->registration_number }}" data-tire-sets='@json($booking->completion_tire_sets)'>✓ Fullfør</button>
                @elseif($booking->status==='completed'&&in_array(auth()->user()->role,['owner','admin','manager'],true))<form method="post" action="{{ route('bookings.reopen',$booking) }}">@csrf<button class="mini-action undo">↶ Angre fullført</button></form>@endif
            </div>
        @empty<div class="empty-state"><strong>Ingen bookinger i perioden</strong><span>Velg en større periode eller opprett en ny time.</span></div>@endforelse
        </div>
        {{ $bookings->links() }}
        @endif
    </article>
</section>
@if($canCreate)
<dialog class="booking-dialog" data-booking-dialog>
    <div class="booking-dialog-head"><div><p class="eyebrow">NY TIME</p><h2>Opprett booking</h2></div><button type="button" data-booking-close aria-label="Lukk">×</button></div>
    <form method="post" action="{{ route('bookings.store') }}" class="stack booking-dialog-body" data-booking-form data-customer-search-url="{{ route('bookings.customer-search') }}" data-availability-url="{{ route('bookings.availability') }}" data-bay-count="{{ $workBays->count() }}" data-capacity-bookings='@json($capacityBookings->map(fn($booking)=>["start"=>$booking->starts_at->toIso8601String(),"end"=>$booking->ends_at->toIso8601String()])->values())'>
        @csrf
        <div class="booking-picker">
            <label>Søk etter kunde eller registreringsnummer<input type="search" data-booking-search autocomplete="off" placeholder="Skriv navn eller reg.nr." aria-describedby="booking-search-help"></label>
            <p id="booking-search-help" class="booking-help">Skriv minst to tegn. Du får bare treff blant egne kunder.</p>
            <div class="booking-search-status" data-booking-search-status aria-live="polite"></div>
            <div class="booking-search-results" data-booking-results role="listbox"></div>
            <section class="booking-selection" data-booking-selection hidden>
                <div class="booking-selection-head"><div><small>Valgt kunde</small><strong data-selected-customer></strong><span data-selected-customer-number></span></div><button type="button" data-booking-change>Bytt</button></div>
                <fieldset><legend>Velg én eller flere biler</legend><div class="booking-vehicle-choices" data-booking-vehicle-choices></div></fieldset>
                <p class="field-error" data-booking-vehicle-error aria-live="polite"></p>
            </section>
            <input type="hidden" name="customer_id" data-booking-customer-id>
        </div>
        <section class="booking-step"><p class="eyebrow">TJENESTE OG TID</p><fieldset class="service-choice-fieldset"><legend>Velg én eller flere tjenester</legend><div class="service-tools"><input type="search" data-service-filter placeholder="Søk i tjenester" autocomplete="off"><span data-service-summary>Ingen valgt</span></div><div class="service-choice-grid">@forelse($services as $service)<label class="service-choice" data-service-name="{{ mb_strtolower($service->name.' '.$service->code) }}"><input type="checkbox" name="service_product_ids[]" value="{{ $service->id }}" data-duration="{{ $service->duration_minutes }}" data-price="{{ $service->fixed_price_cents }}"><span><strong>{{ $service->name }}</strong><small>{{ $service->duration_minutes }} min · {{ number_format($service->fixed_price_cents/100,0,',',' ') }} kr</small></span></label>@empty<div class="empty-state"><strong>Ingen tjenester er aktive</strong><span>Aktiver eller opprett tjenester under Admin.</span></div>@endforelse</div></fieldset><div class="availability-actions"><button type="button" class="button" data-first-available>Vis 5 første ledige tider</button><button type="button" class="button ghost" data-next-available>Vis 10 ledige tider</button><div class="availability-date-nav"><button type="button" data-date-previous aria-label="Forrige dag">←</button><label>Fra dato<input type="date" data-availability-date value="{{ today()->toDateString() }}" min="{{ today()->toDateString() }}"></label><button type="button" data-date-next aria-label="Neste dag">→</button></div></div><div class="availability-status" data-availability-status aria-live="polite">Velg tjenester for å finne ledige tider.</div><div class="availability-slots" data-availability-slots></div></section>
        <div class="fields booking-resource-fields">
            <label>Arbeidsbukk <small>Valgfritt</small><select name="work_bay_id"><option value="">Tildel senere</option>@foreach($workBays as $bay)<option value="{{ $bay->id }}">{{ $bay->code }} · {{ $bay->name }}</option>@endforeach</select></label>
            <label>Ansatt <small>Valgfritt</small><select name="assigned_user_id"><option value="">Tildel senere</option>@foreach($employees as $employee)<option value="{{ $employee->id }}">{{ $employee->name }}</option>@endforeach</select></label>
        </div>
        <p class="booking-multi-note" data-booking-multi-note hidden>Ved flere biler opprettes én jobb per bil. Bukk og ansatt tildeles per jobb etterpå.</p>
        <div class="fields"><label>Start<input type="datetime-local" name="starts_at" required></label><label>Samlet varighet<input type="number" name="duration" min="5" max="1440" value="{{ $suggestedDuration }}" readonly></label></div>
        <div class="capacity-check" data-capacity-check hidden><strong data-capacity-title></strong><span data-capacity-copy></span></div>
        <label>Notat<textarea name="notes" rows="3"></textarea></label>
        <button class="button full">Book og send bekreftelse</button>
        <p class="booking-help">Kunden får en sikker lenke for å bekrefte eller avkrefte. Overbooking varsles, men tillates.</p>
    </form>
</dialog>
<dialog class="completion-dialog" data-completion-dialog>
 <div class="booking-dialog-head"><div><p class="eyebrow">AVSLUTT JOBB</p><h2>Skal hjul inn på hotellet?</h2><p data-completion-registration></p></div><button type="button" data-completion-close aria-label="Lukk">×</button></div>
 <form method="post" class="completion-body stack" data-completion-form>@csrf
  <div class="completion-options">
   <label><input type="radio" name="return_to_hotel" value="1"><span><strong>Ja, hjulsett skal lagres</strong><small>Flyttes til Mottak. Vask, fire målinger og lagerplass opprettes som påkrevde oppgaver.</small></span></label>
   <label><input type="radio" name="return_to_hotel" value="0"><span><strong>Nei, ingen hjul skal lagres</strong><small>Jobben avsluttes uten ny hotellflyt.</small></span></label>
  </div>
  <label data-completion-tire-wrap hidden>Velg hjulsett<select name="tire_set_id" data-completion-tires><option value="">Velg riktig sett</option></select><small data-completion-tire-help></small></label>
  <p class="notice" data-completion-empty hidden>Det finnes ikke noe hjulsett på bilen ennå. Registrer hjulsettet i Hjulhotell før jobben fullføres med hotellretur.</p>
  <div class="completion-actions"><button type="button" class="button ghost" data-completion-close>Avbryt</button><button class="button" data-completion-submit disabled>Fullfør jobb</button></div>
 </form>
</dialog>
@if($bookingPrefill)<script type="application/json" data-booking-prefill>@json($bookingPrefill)</script>@endif
<script src="{{ asset('booking-picker.js') }}?v=20260811-2" defer></script>
<script src="{{ asset('booking-availability.js') }}?v=20260811-2" defer></script>
<script src="{{ asset('booking-prefill.js') }}?v=20260811-2" defer></script>
<script src="{{ asset('booking-completion.js') }}?v=20260811-3" defer></script>
@endif
</x-layouts.app>
