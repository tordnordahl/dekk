<x-layouts.app title="E-postoppsett · DekkPilot" heading="E-postoppsett">
<div class="admin-subnav"><a href="{{ route('admin') }}">← Administrasjon</a><a href="{{ route('admin.communications') }}">Meldinger og SMS-oppsett</a></div>

<section class="panel" id="avsender"><div class="panel-head"><div><p class="eyebrow">VIRKSOMHETENS E-POST</p><h2>Avsenderadresse</h2></div></div><p class="muted">Svar fra kundene sendes til denne e-postadressen.</p><form method="post" action="{{ route('admin.system.tenant-mail') }}" class="stack">@csrf @method('PUT')<label>E-post dere sender fra<input type="email" name="from_address" value="{{ old('from_address',$tenantMail['from_address']??auth()->user()->organization->email??'') }}" required placeholder="kundeservice@firma.no"><small>Avsendernavnet hentes automatisk fra virksomhetens navn.</small></label><button class="button">Lagre avsenderadresse</button></form></section>

<section class="panel"><div class="panel-head"><div><p class="eyebrow">LEVERINGSKONTROLL</p><h2>Send test-e-post</h2></div></div><p class="muted">Test-e-posten legges i samme kø som øvrige meldinger. Følg status under Administrasjon → Kommunikasjon. Utsending starter normalt innen ti minutter, men kan ta lenger tid ved kø.</p><form method="post" action="{{ route('admin.system.test-mail') }}" class="search">@csrf<input type="email" name="recipient" value="{{ old('recipient',auth()->user()->email) }}" required aria-label="Mottaker"><button class="button">Send test</button></form></section>

</x-layouts.app>
