<x-layouts.app title="Portaler · DekkPilot" heading="Portaler og visninger">
<link rel="stylesheet" href="{{ asset('admin-portals.css') }}?v=20260812-1">
<div class="admin-subnav"><a href="{{ route('admin') }}">← Administrasjon</a></div>

<section class="panel portal-admin-hero">
 <div><p class="eyebrow">PORTALOVERSIKT</p><h2>Se det brukerne ser</h2><p>Åpne riktig arbeidsflate uten å lete etter adresser. Kundens portal forhåndsvises trygt og skrivebeskyttet.</p></div>
</section>

<section class="portal-admin-grid">
 <article class="panel portal-admin-card customer-portal-card">
  <span class="portal-admin-icon">◎</span>
  <div><p class="eyebrow">KUNDEPORTAL</p><h2>Finn kunde med registreringsnummer</h2><p>Vis kundens biler, hjulstatus, timer, tilbud og historikk. Forhåndsvisningen kan ikke endre kundedata.</p></div>
  <form method="post" action="{{ route('admin.portals.customer') }}" class="portal-reg-search">@csrf
   <label>Registreringsnummer<input name="registration_number" value="{{ old('registration_number') }}" placeholder="F.eks. AB12345" maxlength="20" autocomplete="off" required></label>
   <button class="button">Vis kundeportal</button>
  </form>
  @error('registration_number')<p class="field-error">{{ $message }}</p>@enderror
 </article>

 <a class="panel portal-admin-card portal-admin-link" href="{{ route('checkout.show',$organization) }}" target="_blank" rel="noopener">
  <span class="portal-admin-icon">▣</span><div><p class="eyebrow">UTSJEKKING</p><h2>Betalingsportal</h2><p>Portalen som kan stå på skjerm eller nettbrett i kundemottaket. Kunden søker med registreringsnummer og betaler ferdig jobb.</p><strong>Åpne i ny fane →</strong></div>
 </a>

 <a class="panel portal-admin-card portal-admin-link" href="{{ route('ui-mode.choose') }}">
  <span class="portal-admin-icon">▥</span><div><p class="eyebrow">ANSATTVISNING</p><h2>Teknikermodus</h2><p>Mobilflaten med dagens jobber, skanning, hjulstatus og lagerkart. Du kan bytte tilbake når som helst.</p><strong>Velg arbeidsflate →</strong></div>
 </a>

 <a class="panel portal-admin-card portal-admin-link" href="{{ route('dashboard') }}">
  <span class="portal-admin-icon">⌂</span><div><p class="eyebrow">FULL PORTAL</p><h2>Administrativ arbeidsflate</h2><p>Den komplette løsningen for kunder, bookinger, dekkhotell, tilbud, lager og statistikk.</p><strong>Gå til oversikten →</strong></div>
 </a>
</section>
</x-layouts.app>
