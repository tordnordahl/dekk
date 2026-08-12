<x-layouts.app title="Regnskap · DekkPilot" heading="Regnskap og fakturakø">
<link rel="stylesheet" href="{{ asset('accounting-setup.css') }}?v=20260812-1">
<div class="admin-subnav"><a href="{{ route('admin') }}">← Admin</a><a href="#connection">Regnskap</a><a href="#queue">Fakturakø</a><a href="#payments">Betalingsløsninger</a></div>

<section class="panel accounting-connect accounting-primary" id="connection">
 <div class="panel-head"><div><p class="eyebrow">REGNSKAPSKOBLING</p><h2>{{ $connection ? 'Tilkoblet '.ucfirst(str_replace('accounting_','',$connection->provider)) : 'Velg regnskapssystem' }}</h2><p>Feltene tilpasses systemet du velger. Nøkler krypteres og vises aldri igjen etter lagring.</p></div></div>
 <div class="provider-switch" role="tablist" aria-label="Velg regnskapssystem">
  <button type="button" role="tab" data-accounting-tab="fiken" aria-selected="{{ str_contains($connection?->provider ?? 'accounting_fiken','fiken') ? 'true' : 'false' }}"><strong>Fiken</strong><span>Sikker OAuth-tilkobling</span></button>
  <button type="button" role="tab" data-accounting-tab="tripletex" aria-selected="{{ str_contains($connection?->provider ?? '','tripletex') ? 'true' : 'false' }}"><strong>Tripletex</strong><span>Consumer + Employee Token</span></button>
  <button type="button" role="tab" data-accounting-tab="poweroffice" aria-selected="{{ str_contains($connection?->provider ?? '','poweroffice') ? 'true' : 'false' }}"><strong>PowerOffice</strong><span>Én klientnøkkel</span></button>
  <button type="button" role="tab" data-accounting-tab="unimicro" aria-selected="{{ str_contains($connection?->provider ?? '','unimicro') ? 'true' : 'false' }}"><strong>Uni Micro</strong><span>Company Key + tilgangstoken</span></button>
 </div>

 <div data-accounting-panel="fiken">
  <div class="provider-intro"><div><span class="provider-mark">F</span></div><div><h3>Fiken</h3><p>Bedriften sendes til Fiken og godkjenner tilgang. Dette er riktig oppsett for en SaaS-integrasjon.</p></div><span class="setup-status {{ $fikenOauthConfigured ? 'ok' : 'missing' }}">{{ $fikenOauthConfigured ? 'OAuth klar' : 'Mangler plattformoppsett' }}</span></div>
  @if(auth()->user()->is_super_admin)
   <details class="platform-credentials"><summary><span>Plattformoppsett</span><small>Kun superadmin</small></summary>
    <form method="post" action="{{ route('admin.accounting.fiken.platform') }}" class="stack">@csrf @method('put')
     <p class="field-help">Legg inn opplysningene fra Fiken-appen din én gang. Kundene skal aldri se eller fylle ut disse.</p>
     <label>Client ID<input name="client_id" value="{{ $fikenPlatform['client_id'] ?? '' }}" autocomplete="off" required></label>
     <label>Client Secret<input type="password" name="client_secret" autocomplete="new-password" placeholder="{{ filled($fikenPlatform['client_secret'] ?? null) ? 'Lagret sikkert – la stå tomt for å beholde' : 'Lim inn Client Secret' }}"></label>
     <label>Redirect URL<input value="{{ route('admin.accounting.fiken.callback') }}" readonly></label>
     <button class="button">Lagre Fiken-oppsett sikkert</button>
    </form>
   </details>
  @endif
  @if($fikenOauthConfigured)
   <div class="oauth-action"><a class="button full" href="{{ route('admin.accounting.fiken.connect') }}">Logg inn hos Fiken og godkjenn</a><small>Fiken åpnes i samme vindu. Etter godkjenning sendes du automatisk tilbake hit.</small></div>
  @else
   <div class="notice">Superadmin må lagre Client ID og Client Secret før Fiken kan kobles til.</div>
  @endif
  @if(str_contains($connection?->provider ?? '','fiken'))
   <form method="post" action="{{ route('admin.accounting.save') }}" class="stack connection-options">@csrf @method('put')<input type="hidden" name="provider" value="fiken"><input type="hidden" name="company_identifier" value="{{ $configuration['company_slug'] ?? '' }}"><input type="hidden" name="api_key" value="">
    <h3>Fakturainnstillinger</h3><div class="fields"><label>Betalingsfrist<input type="number" name="payment_days" min="1" max="90" value="{{ $configuration['payment_days'] ?? 14 }}" required></label><label>Inntektskonto<input name="income_account" inputmode="numeric" pattern="[3-8][0-9]{3}" value="{{ $configuration['income_account'] ?? '3000' }}" required></label></div><label class="check"><input type="checkbox" name="auto_export" value="1" @checked($configuration['auto_export'] ?? false)> Send automatisk når jobben fullføres</label><button class="button ghost">Lagre fakturainnstillinger</button>
   </form>
  @endif
 </div>

 <div data-accounting-panel="tripletex" hidden>
  <div class="provider-intro"><div><span class="provider-mark tripletex">T</span></div><div><h3>Tripletex</h3><p>Lim inn den ene tokenen du fikk fra Tripletex. DekkPilot håndterer resten.</p></div><span class="setup-status {{ $tripletexProductionConfigured ? 'ok' : 'missing' }}">{{ $tripletexProductionConfigured ? 'Produksjon klar' : 'Mangler Consumer Token' }}</span></div>
  @if(auth()->user()->is_super_admin)
   <details class="platform-credentials"><summary><span>Plattformoppsett</span><small>Kun superadmin</small></summary>
    <form method="post" action="{{ route('admin.accounting.tripletex.platform') }}" class="stack">@csrf @method('put')
     <label>Consumer Token – produksjon<input type="password" name="consumer_token" autocomplete="new-password" placeholder="{{ filled($tripletexPlatform['consumer_token'] ?? null) ? 'Lagret sikkert – la stå tomt for å beholde' : 'Lim inn Consumer Token' }}"></label>
     <label>Consumer Token – test<input type="password" name="test_consumer_token" autocomplete="new-password" placeholder="{{ filled($tripletexPlatform['test_consumer_token'] ?? null) ? 'Lagret sikkert – la stå tomt for å beholde' : 'Valgfritt test-token' }}"></label>
     <button class="button">Lagre Tripletex-oppsett sikkert</button>
    </form>
   </details>
  @endif
  @if(!$tripletexProductionConfigured)<div class="notice">Superadmin må lagre Tripletex Consumer Token før produksjonstilkoblinger kan brukes.</div>@endif
  <form method="post" action="{{ route('admin.accounting.save') }}" class="stack connection-options">@csrf @method('put')<input type="hidden" name="provider" value="tripletex">
   <label>Miljø<select name="environment"><option value="production">Produksjon</option><option value="test" @selected(str_contains($configuration['base_url'] ?? '','api-test'))>Test</option></select></label>
   <label>Tripletex-token<input type="password" name="api_key" autocomplete="new-password" placeholder="{{ str_contains($connection?->provider ?? '','tripletex') ? 'Lagret sikkert – la stå tomt for å beholde' : 'Lim inn tokenen fra Tripletex' }}"><small>Det er det eneste verkstedet trenger å legge inn.</small></label>
   <input type="hidden" name="company_identifier" value="0">
   <div class="fields"><label>Betalingsfrist<input type="number" name="payment_days" min="1" max="90" value="{{ str_contains($connection?->provider ?? '','tripletex') ? ($configuration['payment_days'] ?? 14) : 14 }}" required></label><label>Inntektskonto<input name="income_account" inputmode="numeric" pattern="[3-8][0-9]{3}" value="{{ str_contains($connection?->provider ?? '','tripletex') ? ($configuration['income_account'] ?? '3000') : '3000' }}" required></label></div>
   <label class="check"><input type="checkbox" name="auto_export" value="1" @checked(str_contains($connection?->provider ?? '','tripletex') && ($configuration['auto_export'] ?? false))> Send automatisk når jobben fullføres</label><button class="button">Lagre Tripletex-tilkobling</button>
  </form>
 </div>
 <div data-accounting-panel="poweroffice" hidden>
  <div class="provider-intro"><div><span class="provider-mark">P</span></div><div><h3>PowerOffice Go</h3><p>Aktiver DekkPilot i PowerOffice og lim inn klientnøkkelen.</p></div><span class="setup-status {{ $powerofficeConfigured?'ok':'missing' }}">{{ $powerofficeConfigured?'Plattform klar':'Mangler oppsett' }}</span></div>
  @if(auth()->user()->is_super_admin)<details class="platform-credentials" @if(!$powerofficeConfigured) open @endif><summary>Plattformoppsett for superadmin</summary><form method="post" action="{{ route('admin.accounting.poweroffice.platform') }}" class="stack">@csrf @method('put')<label>App Key<input type="password" name="app_key" placeholder="{{ filled($powerofficePlatform['app_key']??null)?'Lagret sikkert – la stå tomt for å beholde':'Lim inn App Key' }}"></label><label>Subscription Key<input type="password" name="subscription_key" placeholder="{{ filled($powerofficePlatform['subscription_key']??null)?'Lagret sikkert – la stå tomt for å beholde':'Lim inn Subscription Key' }}"></label><button class="button">Lagre plattformoppsett</button></form></details>@endif
  <form method="post" action="{{ route('admin.accounting.save') }}" class="stack connection-options">@csrf @method('put')<input type="hidden" name="provider" value="poweroffice"><input type="hidden" name="company_identifier" value="0"><label>Miljø<select name="environment"><option value="production">Produksjon</option><option value="test">Demo/test</option></select></label><label>Klientnøkkel<input type="password" name="api_key" placeholder="{{ str_contains($connection?->provider??'','poweroffice')?'Lagret sikkert – la stå tomt for å beholde':'Lim inn klientnøkkelen' }}"></label><div class="fields"><label>Betalingsfrist<input type="number" name="payment_days" min="1" max="90" value="14" required></label><label>Inntektskonto<input name="income_account" value="3000" required></label></div><label class="check"><input type="checkbox" name="auto_export" value="1"> Send automatisk når jobben fullføres</label><button class="button">Lagre PowerOffice-tilkobling</button></form>
 </div>
 <div data-accounting-panel="unimicro" hidden>
  <div class="provider-intro"><div><span class="provider-mark">U</span></div><div><h3>Uni Micro</h3><p>Koble DekkPilot til et aktivert Uni Micro-selskap. Tilgang lagres kryptert og kan testes før første eksport.</p></div><span class="setup-status {{ str_contains($connection?->provider??'','unimicro')?'ok':'missing' }}">{{ str_contains($connection?->provider??'','unimicro')?'Tilkoblet':'Ikke koblet' }}</span></div>
  <form method="post" action="{{ route('admin.accounting.save') }}" class="stack connection-options">@csrf @method('put')<input type="hidden" name="provider" value="unimicro">
   <label>Miljø<select name="environment"><option value="production" @selected(($configuration['environment']??'production')==='production')>Produksjon</option><option value="test" @selected(($configuration['environment']??'')==='test')>Test</option></select></label><label>API Base URL<input type="url" name="api_base_url" value="{{ str_contains($connection?->provider??'','unimicro')?($configuration['api_base_url']??'https://test.unimicro.no'):'https://test.unimicro.no' }}" required><small>Bruk AppFramework/ApiBaseUrl fra Uni Micro-tokenet eller aktiveringsmeldingen.</small></label>
   <label>Company Key<input name="company_identifier" value="{{ str_contains($connection?->provider??'','unimicro')?($configuration['company_key']??''):'' }}" required autocomplete="off" placeholder="UUID fra Uni Micro"><small>Finnes på selskapet som har aktivert DekkPilot-integrasjonen.</small></label>
   <label>OAuth access token<input type="password" name="api_key" autocomplete="new-password" placeholder="{{ str_contains($connection?->provider??'','unimicro')?'Lagret sikkert – la stå tomt for å beholde':'Lim inn tilgangstoken' }}"></label>
   <div class="fields"><label>Betalingsfrist<input type="number" name="payment_days" min="1" max="90" value="{{ str_contains($connection?->provider??'','unimicro')?($configuration['payment_days']??14):14 }}" required></label><label>Inntektskonto<input name="income_account" value="{{ str_contains($connection?->provider??'','unimicro')?($configuration['income_account']??'3000'):'3000' }}" required></label></div>
   <details><summary>Avansert fakturaoppsett</summary><div class="fields"><label>Distribution Plan ID<input type="number" name="distribution_plan_id" min="1" value="{{ str_contains($connection?->provider??'','unimicro')?($configuration['distribution_plan_id']??15):15 }}"><small>15 betyr normalt ingen utsendelse.</small></label><label>Payment Info Type ID<input type="number" name="payment_info_type_id" min="1" value="{{ str_contains($connection?->provider??'','unimicro')?($configuration['payment_info_type_id']??5):5 }}"></label></div></details>
   <label class="check"><input type="checkbox" name="auto_export" value="1" @checked(str_contains($connection?->provider??'','unimicro')&&($configuration['auto_export']??false))> Send automatisk når faktura er valgt eller dagsavslutningen kjøres</label><button class="button">Lagre Uni Micro-tilkobling</button>
  </form>
 </div>
 @if($connection)<form method="post" action="{{ route('admin.accounting.test') }}" class="test-connection">@csrf<button class="button ghost">Test aktiv tilkobling</button></form>@endif
</section>
<section class="panel" id="payments"><div class="panel-head"><div><p class="eyebrow">BETALING I KASSEN</p><h2>Bankterminal og Vipps</h2><p>Velg hvilke betalingsmåter kunden kan bruke i utsjekkingsportalen. Kortnummer og PIN håndteres aldri av DekkPilot.</p></div></div>
 <div class="fields">
  <form method="post" action="{{ route('admin.accounting.terminal') }}" class="stack connection-options">@csrf @method('put')<h3>Fysisk bankterminal</h3><p class="field-help">Betalingen bekreftes automatisk av terminal-/kasseintegrasjonen. Kunden eller medarbeideren kan aldri godkjenne betalingen manuelt.</p><label>Navn som vises kunden<input name="name" value="{{ $terminalConfiguration['name']??'Bankterminal i kassen' }}" required></label><label class="check"><input type="checkbox" name="active" value="1" @checked($terminalConfigured)> Vis bankterminal som betalingsmåte</label><small>Forsøket varer i 60 sekunder. Automatisk status krever at terminalleverandøren eller kasseappen bruker DekkPilots sikre betalings-API.</small><button class="button">Lagre terminaloppsett</button></form>
  <form method="post" action="{{ route('admin.accounting.vipps') }}" class="stack connection-options">@csrf @method('put')<h3>Vipps ePayment</h3><p class="field-help">Opplysningene hentes fra Vipps MobilePay-portalen og lagres kryptert.</p><label>Client ID<input name="client_id" value="{{ $vippsConfiguration['client_id']??'' }}" autocomplete="off"></label><label>Client Secret<input type="password" name="client_secret" autocomplete="new-password" placeholder="{{ filled($vippsConfiguration['client_secret']??null)?'Lagret sikkert – la stå tomt for å beholde':'Client Secret' }}"></label><label>Subscription Key<input type="password" name="subscription_key" autocomplete="new-password" placeholder="{{ filled($vippsConfiguration['subscription_key']??null)?'Lagret sikkert – la stå tomt for å beholde':'Subscription Key' }}"></label><label>Merchant Serial Number (MSN)<input name="msn" inputmode="numeric" pattern="[0-9]{4,10}" value="{{ $vippsConfiguration['msn']??'' }}"></label><label class="check"><input type="checkbox" name="test" value="1" @checked($vippsConfiguration['test']??false)> Bruk Vipps testmiljø</label><label class="check"><input type="checkbox" name="active" value="1" @checked($vippsConfigured)> Aktiver Vipps i utsjekkingsportalen</label><button class="button">Lagre Vipps-oppsett sikkert</button></form>
 </div>
</section>
<section class="panel" id="zettle"><div class="panel-head"><div><p class="eyebrow">BETALINGSPILOT</p><h2>Zettle by PayPal</h2><p>Separat integrasjon under utprøving. Dette er ikke støtte for vanlig bankterminal.</p></div><span class="setup-status {{ $zettlePilotEnabled&&$zettleConnection?'ok':'missing' }}">{{ !$zettlePilotEnabled?'Pilot av':($zettleConnection?'Pilot tilkoblet':'Pilot på – ikke tilkoblet') }}</span></div>@if(auth()->user()->is_super_admin)<details class="platform-credentials" @if(!$zettleConfigured) open @endif><summary>Plattformoppsett for superadmin</summary><form method="post" action="{{ route('admin.accounting.zettle.platform') }}" class="stack">@csrf @method('put')<label>Client ID<input name="client_id" value="{{ $zettlePlatform['client_id']??'' }}" required></label><label>Client Secret<input type="password" name="client_secret" placeholder="{{ filled($zettlePlatform['client_secret']??null)?'Lagret sikkert – la stå tomt for å beholde':'Lim inn Client Secret' }}"></label><label>Redirect URL<input value="{{ route('admin.accounting.zettle.callback') }}" readonly></label><label class="check"><input type="checkbox" name="pilot_enabled" value="1" @checked($zettlePilotEnabled)> Aktiver Zettle-pilot og selvbetjent utsjekk</label><small>Aktiver først etter en vellykket ende-til-ende-test med ekte testbetaling, statusbekreftelse og automatisk faktura ved dagsavslutning.</small><button class="button">Lagre Zettle-oppsett</button></form></details>@endif @if($zettlePilotEnabled&&$zettleConfigured)<a class="button" href="{{ route('admin.accounting.zettle.connect') }}">{{ $zettleConnection?'Koble til på nytt':'Logg inn hos Zettle' }}</a>@elseif(!$zettlePilotEnabled)<div class="notice">Zettle og utsjekkingsportalen er sperret inntil superadmin aktiverer piloten.</div>@else<div class="notice">Superadmin må legge inn Zettle app-legitimasjon først.</div>@endif</section>
@if(auth()->user()->organization)<section class="panel"><div class="panel-head"><div><p class="eyebrow">SELVBETJENT UTSJEKK</p><h2>Portal for disk og nettbrett</h2><p>{{ $zettlePilotEnabled?'Kunden skriver registreringsnummer og følger den aktiverte pilotflyten. Ubetalte jobber faktureres automatisk ved dagsavslutning.':'Portalen er synlig for forhåndsvisning, men betaling og oppslag er sperret mens Zettle-piloten er av.' }}</p></div><a class="button {{ $zettlePilotEnabled?'':'ghost' }}" target="_blank" rel="noopener" href="{{ route('checkout.show',auth()->user()->organization) }}">{{ $zettlePilotEnabled?'Åpne utsjekkingsportal':'Forhåndsvis sperret portal' }}</a></div><label>Fast portaladresse<input readonly value="{{ route('checkout.show',auth()->user()->organization) }}"></label></section>@endif

<section class="panel" id="queue"><div class="panel-head"><div><p class="eyebrow">EKSPORTKØ</p><h2>Fakturagrunnlag</h2></div><form method="post" action="{{ route('admin.accounting.queue-all') }}">@csrf<button class="button">Send alle klare / prøv feil på nytt</button></form></div>
<div class="accounting-stats"><span>Klar <strong>{{ $counts['ready'] ?? 0 }}</strong></span><span>I kø <strong>{{ $counts['queued'] ?? 0 }}</strong></span><span>Sendt <strong>{{ $counts['exported'] ?? 0 }}</strong></span><span>Feil <strong>{{ $counts['failed'] ?? 0 }}</strong></span></div>
<div class="table-wrap">
 <table>
  <thead><tr><th>Grunnlag</th><th>Kunde</th><th>Beløp</th><th>Status / logg</th><th></th></tr></thead>
  <tbody>
   @if($exports->count() === 0)
    <tr><td colspan="5" class="empty">Ingen fakturagrunnlag ennå. De opprettes når en booking fullføres.</td></tr>
   @else
    @foreach($exports as $invoice)
     <tr>
      <td><strong>{{ $invoice->reference }}</strong><small>{{ $invoice->created_at->format('d.m.Y H:i') }}</small></td>
      <td>{{ $invoice->customer_snapshot['name'] ?? 'Ukjent' }}<small>{{ $invoice->customer_snapshot['email'] ?? '' }}</small></td>
      <td><strong>{{ number_format($invoice->total_cents/100,2,',',' ') }} kr</strong><small>inkl. {{ number_format($invoice->vat_cents/100,2,',',' ') }} mva</small></td>
      <td><span class="status {{ $invoice->status }}">{{ ['ready'=>'Klar','queued'=>'I kø','processing'=>'Sender','exported'=>'Sendt','failed'=>'Feilet','cancelled'=>'Avbrutt'][$invoice->status] }}</span><small>{{ $invoice->external_id ? ucfirst($invoice->provider).' ID: '.$invoice->external_id : ($invoice->last_error ?: 'Forsøk: '.$invoice->attempts) }}</small></td>
      <td>
       @if(in_array($invoice->status, ['ready', 'failed'], true))
        <form method="post" action="{{ route('admin.accounting.queue',$invoice) }}">
         @csrf
         <button class="button ghost">{{ $invoice->status === 'failed' ? 'Prøv igjen' : 'Send manuelt' }}</button>
        </form>
       @endif
      </td>
     </tr>
    @endforeach
   @endif
  </tbody>
 </table>
</div>
{{ $exports->links() }}
</section>
<script defer src="{{ asset('accounting-setup.js') }}?v=20260811-2"></script>
</x-layouts.app>
