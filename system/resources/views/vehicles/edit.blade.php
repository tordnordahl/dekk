<x-layouts.app title="Rediger bil · DekkPilot" heading="Rediger bil">
<section class="panel">
<div class="panel-head"><div><h2>{{ $vehicle->registration_number }}</h2><p>Korriger bilopplysningene. Hjulsett, avtaler og historikk følger fortsatt samme bil.</p></div></div>
<form method="post" action="{{ route('vehicles.update', $vehicle) }}" class="stack">
@csrf
@method('PUT')
<div class="fields">
<label>Registreringsnummer<input name="registration_number" value="{{ old('registration_number', $vehicle->registration_number) }}" required maxlength="20"></label>
<label>Merke<input name="make" value="{{ old('make', $vehicle->make) }}" maxlength="100"></label>
<label>Modell<input name="model" value="{{ old('model', $vehicle->model) }}" maxlength="100"></label>
<label>Årsmodell<input name="model_year" type="number" min="1900" max="2100" value="{{ old('model_year', $vehicle->model_year) }}"></label>
<label>Kilometerstand<input name="mileage" type="number" min="0" max="4294967295" value="{{ old('mileage', $vehicle->mileage) }}"></label>
<label>Understellsnummer (VIN)<input name="vin" value="{{ old('vin', $vehicle->vin) }}" maxlength="32"></label>
<label>Anbefalt dekkdimensjon<input name="recommended_tire_size" value="{{ old('recommended_tire_size', $vehicle->recommended_tire_size) }}" maxlength="100"></label>
</div>
<label>Internt notat<textarea name="notes" maxlength="4000">{{ old('notes', $vehicle->notes) }}</textarea></label>
<div class="quote-actions"><button class="button">Lagre bilopplysninger</button><a class="button ghost" href="{{ route('vehicles.history', $vehicle) }}">Avbryt</a></div>
</form>
</section>
</x-layouts.app>
