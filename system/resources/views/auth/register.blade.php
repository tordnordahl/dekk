<!doctype html><html lang="nb"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Opprett konto · DekkPilot</title><link rel="stylesheet" href="{{ asset('app.css') }}"><link rel="stylesheet" href="{{ asset('admin.css') }}"><script defer src="{{ asset('registration.js') }}"></script><style>#company-result[hidden]{display:none!important}.field-error{color:#a32a22}</style></head>
<body class="auth-page"><section class="auth-card">
<div class="brand centered"><span class="brandmark">D</span><span>DekkPilot<small>Hele dekkhotellet · 249 kr/mnd</small></span></div>
<p class="eyebrow">KOM I GANG MED EN GANG</p><h1>Opprett virksomheten</h1>
<p class="muted">Vi kontrollerer virksomheten mot Brønnøysundregistrene. Etter registrering aktiverer du abonnementet hos Stripe for å åpne systemet.</p>
<div class="usage-note"><strong>SMS faktureres etter bruk</strong><span>Alle funksjoner er inkludert i månedsprisen. SMS-kostnader kommer i tillegg etter faktisk bruk.</span></div>
@if($errors->any())<div class="errors" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@if($errors->has('email') || $errors->has('organization_number'))<p><a href="{{ route('login') }}">Logg inn</a> · <a href="{{ route('password.request') }}">Glemt passord?</a></p>@endif</div>@endif
<form method="post" action="{{ route('register.store') }}" class="stack" data-registration-form data-lookup-url="{{ route('register.lookup') }}">@csrf
<label>Organisasjonsnummer<input name="organization_number" value="{{ old('organization_number') }}" inputmode="numeric" autocomplete="organization" maxlength="11" required autofocus><small>Skriv inn ni sifre. Virksomhetsnavnet hentes fra Brønnøysundregistrene.</small></label>
<div id="company-result" class="usage-note" hidden aria-live="polite"></div>
<label>Ditt navn<input name="name" value="{{ old('name') }}" autocomplete="name" required></label>
<label>E-post<input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required @if($errors->has('email')) aria-invalid="true" aria-describedby="email-error" @endif>@error('email')<small class="field-error" id="email-error">{{ $message }}</small>@enderror</label>
<label>Passord<input type="password" name="password" minlength="12" autocomplete="new-password" required><small>Minst 12 tegn med stor og liten bokstav og tall.</small></label>
<label>Gjenta passord<input type="password" name="password_confirmation" autocomplete="new-password" required></label>
@if($agreement)
<input type="hidden" name="agreement_id" value="{{ $agreement->id }}"><input type="hidden" name="agreement_hash" value="{{ $agreement->sha256 }}"><input type="hidden" name="eula" value="1">
<label class="check"><input type="checkbox" name="agreement" value="1" required><span>Jeg har fullmakt til å inngå avtalen for virksomheten og godtar <a href="{{ route('legal.agreement',$agreement) }}" target="_blank" rel="noopener">bruksvilkårene og databehandleravtalen ({{ $agreement->version }})</a>. Åpnes i en ny fane.</span></label>
@else
<label class="check"><input type="checkbox" name="eula" value="1" required {{ old('eula') ? 'checked' : '' }}> Jeg har lest og godtar <a href="{{ route('legal.terms') }}" target="_blank" rel="noopener">bruksvilkårene (EULA)</a>.</label>
@endif
<label class="check"><input type="checkbox" name="privacy" value="1" required {{ old('privacy') ? 'checked' : '' }}> Jeg har lest <a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener">personvernerklæringen</a> og bekrefter at virksomheten kan behandle data som beskrevet.</label>
<label class="check"><input type="checkbox" name="price_terms" value="1" required {{ old('price_terms') ? 'checked' : '' }}> Jeg godtar 249 kr per måned inkl. mva., uten binding. SMS faktureres separat etter bruk. Abonnementet betales automatisk via Stripe og kan sies opp til periodens slutt.</label>
<button class="button full">Opprett konto og fortsett til betaling</button></form>
<p class="fine"><a href="{{ route('login') }}">Har du allerede konto? Logg inn</a></p>
</section></body></html>
