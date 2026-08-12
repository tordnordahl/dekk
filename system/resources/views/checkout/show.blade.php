<!doctype html>
<html lang="nb">
<head>
 <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
 <meta name="theme-color" content="#0d2b20"><title>Betal og hent · {{ $organization->name }}</title>
 <link rel="stylesheet" href="{{ asset('checkout.css') }}?v=20260812-3">
</head>
<body>
<main class="checkout-shell">
 <section class="checkout-brand-panel" aria-label="Informasjon om utsjekking">
  <header class="checkout-brand"><span class="checkout-mark">D</span><span><strong>{{ $organization->name }}</strong><small>Drevet av DekkPilot</small></span></header>
  <div class="checkout-brand-copy"><p class="checkout-kicker">SELBETJENT UTSJEKKING</p><h1>Ferdig på<br><em>et øyeblikk.</em></h1><p>Finn den ferdige jobben, kontroller beløpet og betal trygt i kundemottaket.</p></div>
  <ol class="checkout-steps"><li class="active"><b>1</b><span><strong>Finn bilen</strong><small>Skriv registreringsnummer</small></span></li><li><b>2</b><span><strong>Kontroller jobben</strong><small>Se tjeneste og beløp</small></span></li><li><b>3</b><span><strong>Betal og hent</strong><small>Bekreft på terminalen</small></span></li></ol>
  <footer><span class="shield-icon">✓</span><span><strong>Personvern først</strong><small>Ingen navn eller kontaktopplysninger vises.</small></span></footer>
 </section>
 <section class="checkout-action-panel">
  <div class="checkout-action-card">
   <div class="checkout-mobile-brand"><span class="checkout-mark">D</span><span><strong>{{ $organization->name }}</strong><small>Selvbetjent utsjekking</small></span></div>
   <p class="checkout-kicker">TRINN 1 AV 3</p><h2>Hvilken bil skal hentes?</h2><p class="checkout-lead">Skriv inn registreringsnummeret. Vi viser bare en ferdig, ubetalt jobb som tilhører denne bilen.</p>
   @if($errors->any())<div class="checkout-message error" role="alert"><span>!</span><div><strong>Vi fant ikke jobben</strong><p>{{ $errors->first() }}</p></div></div>@endif
   <form method="post" action="{{ route('checkout.lookup',$organization) }}" class="checkout-search">@csrf
    <label for="registration_number">Registreringsnummer</label>
    <div class="license-input"><span aria-hidden="true">N<br><i>🇳🇴</i></span><input id="registration_number" name="registration_number" value="{{ old('registration_number') }}" maxlength="20" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="AB 12345" required autofocus></div>
    <button><span>Finn ferdig jobb</span><b aria-hidden="true">→</b></button>
   </form>
   <div class="checkout-help"><span>i</span><p>Finner du ikke jobben? Kontroller registreringsnummeret eller spør en medarbeider i kundemottaket.</p></div>
  </div>
 </section>
</main>
<script src="{{ asset('checkout.js') }}?v=20260812-1" defer></script>
</body></html>
