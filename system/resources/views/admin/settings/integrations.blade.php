@if(auth()->user()->is_super_admin || !session('demo_read_only'))
<section class="panel integration-card"><div class="panel-head"><div><p class="eyebrow">INTEGRASJONER</p><h2>Statens vegvesen</h2></div><span class="status {{ $vegvesenConfigured ? 'completed':'cancelled' }}">{{ $vegvesenConfigured ? 'Konfigurert':'Ikke konfigurert' }}</span></div><p class="muted">API-nøkkelen brukes til automatisk oppslag av bilmerke, modell, årsmodell og tekniske kjøretøydata fra registreringsnummer. Nøkkelen krypteres med applikasjonsnøkkelen og vises aldri igjen etter lagring.</p><div class="integration-actions"><form method="post" action="{{ route('admin.vegvesen.update') }}" class="stack">@csrf @method('PUT')<label>{{ $vegvesenConfigured ? 'Erstatt API-nøkkel':'API-nøkkel' }}<input type="password" name="api_key" required minlength="16" maxlength="500" autocomplete="new-password" placeholder="Lim inn nøkkelen fra Statens vegvesen"></label><button class="button">{{ $vegvesenConfigured ? 'Erstatt nøkkel':'Lagre nøkkel sikkert' }}</button></form>@if($vegvesenConfigured)<form method="post" action="{{ route('admin.vegvesen.delete') }}">@csrf @method('DELETE')<button class="button danger">Fjern lagret nøkkel</button></form>@endif</div></section>
@endif

<section class="panel"><p class="eyebrow">KOM I GANG</p><h2>Slik kobler du til Statens vegvesen</h2>
<p>DekkPilot bruker tjenesten for <strong>tekniske kjøretøyopplysninger uten eierinformasjon</strong>. Den hjelper deg å hente bilopplysninger når du skriver inn et registreringsnummer.</p>
<ol>
<li><strong>Åpne Vegvesenets side:</strong> Følg lenken nedenfor og velg «Bestill API-nøkkel».</li>
<li><strong>Logg inn</strong> med norsk elektronisk ID, for eksempel BankID. Velg virksomheten du skal representere.</li>
<li><strong>Bestill nøkkelen</strong> for API-et for tekniske kjøretøyopplysninger, og følg anvisningene hos Statens vegvesen.</li>
<li><strong>Lim inn nøkkelen i DekkPilot</strong> i feltet ovenfor og trykk «Lagre nøkkel sikkert». Kopier bare selve nøkkelen, uten «Apikey» foran. DekkPilot legger til dette automatisk.</li>
<li><strong>Prøv et biloppslag:</strong> Gå til Kunder, åpne en kunde og velg «Legg til kjøretøy». Skriv registreringsnummeret og trykk «Hent bilinfo».</li>
</ol>
<p><a class="button" href="https://www.vegvesen.no/fag/teknologi/apne-data/et-utvalg-apne-data/api-for-tekniske-kjoretoyopplysninger/" target="_blank" rel="noopener noreferrer">Bestill API-nøkkel hos Statens vegvesen ↗</a></p>
<p><strong>Finner du ikke virksomheten eller får du ikke bestilt?</strong> Du trenger Altinn-tilgang til enkelttjenesten «Kjøretøyoppslag» eller en tilgangspakke som inkluderer denne. Be den som administrerer virksomhetens Altinn-tilganger om hjelp.</p>
<p class="muted">Nøkkelen lagres kryptert og vises ikke igjen etter lagring. Oppslaget henter tekniske bildata; kundens navn og kontaktinformasjon registreres separat.</p>
</section>
