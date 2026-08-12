<x-layouts.app title="Kunder · DekkPilot" heading="Kunderegister">
@php($localAssetHost = in_array(request()->getHost(), ['localhost','127.0.0.1','::1'], true))
@php($phoneDirectoryEnabled = app(\App\Services\PhoneDirectory1881Service::class)->enabled())
<link rel="stylesheet" href="{{ $localAssetHost ? route('system.asset', ['filename'=>'customer-dialog.css']) : asset('customer-dialog.css') }}?v=20260812-1">
<link rel="stylesheet" href="{{ $localAssetHost ? route('system.asset', ['filename'=>'customer-profile.css']) : asset('customer-profile.css') }}?v=20260812-1">
<link rel="stylesheet" href="{{ $localAssetHost ? route('system.asset', ['filename'=>'customer-list.css']) : asset('customer-list.css') }}?v=20260812-1">
<link rel="stylesheet" href="{{ asset('customer-form-polish.css') }}?v=20260812-1">
@if($phoneDirectoryEnabled)<link rel="stylesheet" href="{{ asset('phone-directory.css') }}?v=20260812-1">@endif
<style>.customer-add-vehicle{display:inline-flex;align-items:center;min-height:32px;padding:0 10px;border:1px solid #cfe1d7;border-radius:9px;background:#f3f8f5;color:#1d6543;font-size:11px;font-weight:850;white-space:nowrap}.customer-add-vehicle:hover{border-color:#8dbba3;background:#e6f3eb}</style>
<section class="customer-workspace">
<article class="panel">
    <div class="panel-head customer-panel-head">
        <div><p class="eyebrow">ALLE KUNDER</p><h2>{{ $customers->total() }} registrert</h2></div>
        <div class="customer-head-actions">
            <form class="search"><input name="q" value="{{ request('q') }}" placeholder="Søk navn, telefon eller reg.nr"><button>Finn</button></form>
            <button type="button" class="customer-add-button" data-customer-open aria-label="Opprett ny kunde" title="Opprett ny kunde">+</button>
        </div>
    </div>
    <div class="table-wrap customer-table-wrap"><table class="customer-table"><thead><tr><th>Kunde</th><th>Kontakt</th><th>Kjøretøy</th><th><span class="screen-reader-only">Handlinger</span></th></tr></thead><tbody>
    @forelse($customers as $customer)
        <tr><td><a class="customer-row-person" href="{{ route('customers.show',$customer) }}"><span>{{ mb_strtoupper(mb_substr($customer->name,0,1)) }}</span><span><strong>{{ $customer->name }}</strong><small>{{ $customer->customer_number }} · {{ $customer->type === 'business' ? 'Bedrift' : 'Privat' }}</small></span></a></td><td><strong>{{ $customer->phone ?: 'Ingen telefon' }}</strong><small>{{ $customer->email?:'Ingen e-post' }}</small></td><td><div class="customer-vehicles">
            @forelse($customer->vehicles as $vehicle)<span class="tag"><b>{{ $vehicle->registration_number }}</b> {{ trim($vehicle->make.' '.$vehicle->model) }}</span>@empty<span class="customer-no-vehicle">Ingen kjøretøy</span>@endforelse
            </div></td><td><div class="customer-row-actions"><a class="customer-add-vehicle" href="{{ route('customers',['new_vehicle'=>$customer->public_id]) }}" aria-label="Legg til kjøretøy på {{ $customer->name }}">＋ Reg.nr.</a><a class="customer-open" href="{{ route('customers.show',$customer) }}">Åpne →</a>@if($customer->email)<details class="customer-row-tools"><summary aria-label="Flere valg">•••</summary><div><form method="post" action="{{ route('customers.portal.invite',$customer) }}">@csrf<button class="mini-action">Send kundeportal</button></form></div></details>@endif</div></td></tr>
    @empty<tr><td colspan="4" class="empty">Ingen kunder funnet.</td></tr>@endforelse
    </tbody></table></div>{{ $customers->links() }}
</article>
</section>

<dialog class="customer-dialog" data-customer-dialog data-auto-open="{{ request()->boolean('new') || ($errors->hasAny(['type','name','email','phone','organization_number','postal_code','notes']) && old('name') !== null) ? '1' : '0' }}">
    <div class="customer-dialog-head"><div><p class="eyebrow">STEG 1 AV 2</p><h2>Legg til kunde</h2></div><button type="button" data-customer-close aria-label="Lukk">×</button></div>
    <form method="post" action="{{ route('customers.store') }}" class="stack customer-dialog-body">@csrf
        <label>Kundetype<select name="type"><option value="private" @selected(old('type','private')==='private')>Privatkunde</option><option value="business" @selected(old('type')==='business')>Bedrift</option></select></label>
        <label>Navn<input name="name" value="{{ old('name') }}" required maxlength="255" autofocus>@error('name')<small class="field-error">{{ $message }}</small>@enderror</label>
        <div class="fields"><label>Telefon<input name="phone" value="{{ old('phone') }}" inputmode="tel" autocomplete="tel"></label><label>E-post<input type="email" name="email" value="{{ old('email') }}" autocomplete="email">@error('email')<small class="field-error">{{ $message }}</small>@enderror</label></div>
        @if($phoneDirectoryEnabled)<div class="directory-lookup"><button type="button" class="directory-lookup-button" data-directory-lookup data-directory-url="{{ route('phone-directory.lookup') }}"><span>1881</span><strong>Hent navn og adresse</strong></button><p data-directory-status>Skriv telefonnummer og hent offentlige kontaktopplysninger.</p></div>@endif
        <label>Adresse <small>(valgfritt)</small><input name="address" value="{{ old('address') }}" autocomplete="street-address"></label>
        <div class="fields" data-postal-lookup data-lookup-url="{{ route('postal-code.lookup','POSTAL_CODE') }}"><label>Postnummer<input name="postal_code" value="{{ old('postal_code') }}" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" autocomplete="postal-code" placeholder="0000" required data-postal-code>@error('postal_code')<small class="field-error">{{ $message }}</small>@enderror</label><label>Poststed<input name="city" value="{{ old('city') }}" readonly tabindex="-1" placeholder="Fylles ut automatisk" data-postal-city><small data-postal-status>Fylles ut fra postnummeret.</small></label></div>
        <label>Organisasjonsnummer<input name="organization_number" value="{{ old('organization_number') }}"></label>
        <label>Internt notat<textarea name="notes" rows="3">{{ old('notes') }}</textarea></label>
        <label class="check customer-hotel-choice"><input type="checkbox" name="uses_tire_hotel" value="1" @checked(old('uses_tire_hotel'))><span><strong>Dekkhotell</strong><small>Klargjør sommer- og vinterhjul på bilen. Hjulene registreres ikke som innlevert ennå.</small></span></label>
        <button class="button full">Neste: legg til kjøretøy →</button>
    </form>
</dialog>
@if($vehicleCustomer)
@php($vehicleFound = session('vehicle_lookup.customer_id') === $vehicleCustomer->id ? session('vehicle_lookup') : null)
<dialog class="customer-dialog" data-vehicle-dialog data-auto-open="1">
    <div class="customer-dialog-head"><div><p class="eyebrow">STEG 2 AV 2</p><h2>Legg til kjøretøy</h2><small>{{ $vehicleCustomer->name }} · {{ $vehicleCustomer->customer_number }}</small></div><a href="{{ route('customers') }}" class="dialog-close" aria-label="Lukk">×</a></div>
    <form method="post" action="{{ route('vehicles.store',$vehicleCustomer) }}" class="stack customer-dialog-body">@csrf<input type="hidden" name="onboarding" value="1">
        <div class="vehicle-lookup-row"><label>Registreringsnummer<input name="registration_number" value="{{ $vehicleFound['registration_number'] ?? old('registration_number') }}" required maxlength="20" autofocus placeholder="F.eks. AB12345"></label><button class="button ghost" type="submit" formmethod="get" formaction="{{ route('vehicles.lookup',$vehicleCustomer) }}">Hent bilinfo</button></div>
        @error('registration_number')<small class="field-error">{{ $message }}</small>@enderror
        @if(session('existing_vehicle'))<div class="notice"><strong>{{ session('existing_vehicle.registration_number') }} finnes allerede</strong><p>Bilen står på {{ session('existing_vehicle.customer') }}. Flytting bevarer bilens historikk og starter en ny eierperiode.</p><input type="hidden" name="existing_vehicle_id" value="{{ session('existing_vehicle.id') }}"><label class="check"><input type="checkbox" name="transfer_existing" value="1" required><span><strong>Flytt bilen til {{ $vehicleCustomer->name }}</strong><small>Tidligere kundehistorikk blir ikke synlig for den nye eieren.</small></span></label></div>@endif
        <p class="dialog-help">Skriv registreringsnummer og velg «Hent bilinfo». DekkPilot fyller inn opplysningene fra Statens vegvesen når integrasjonen er satt opp.</p>
        <div class="fields"><label>Merke<input name="make" value="{{ $vehicleFound['make'] ?? old('make') }}" placeholder="F.eks. Volvo"></label><label>Modell<input name="model" value="{{ $vehicleFound['model'] ?? old('model') }}" placeholder="F.eks. XC60"></label></div>
        <div class="fields"><label>Årsmodell<input name="model_year" value="{{ $vehicleFound['model_year'] ?? old('model_year') }}" type="number" min="1900" max="2100"></label><label>Kilometerstand<input name="mileage" value="{{ old('mileage') }}" type="number" min="0" placeholder="Valgfritt"></label></div>
        <input name="vin" value="{{ $vehicleFound['vin'] ?? old('vin') }}" type="hidden"><input name="recommended_tire_size" value="{{ $vehicleFound['recommended_tire_size'] ?? old('recommended_tire_size') }}" type="hidden">
        <label class="check"><input type="checkbox" name="uses_tire_hotel" value="1" @checked(request()->boolean('tire_hotel') || old('uses_tire_hotel'))><span><strong>Dekkhotell</strong><small>Oppretter sommer- og vinterhjul som «Ikke innlevert».</small></span></label>
        <div class="dialog-actions"><a class="button ghost" href="{{ route('customers') }}">Hopp over</a><button class="button">Lagre kjøretøy og fullfør</button></div>
    </form>
</dialog>
@endif
<script src="{{ $localAssetHost ? route('system.asset', ['filename'=>'customer-dialog.js']) : asset('customer-dialog.js') }}?v=20260812-2" defer></script>
@if($phoneDirectoryEnabled)<script src="{{ asset('phone-directory.js') }}?v=20260812-1" defer></script>@endif
</x-layouts.app>
