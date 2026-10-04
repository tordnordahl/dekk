<x-layouts.app title="Stripe · Superadmin" heading="Stripe-abonnement">
<div class="admin-subnav"><a href="{{ route('superadmin') }}">← Virksomheter og gratismåneder</a></div>
<section class="panel">
<p class="eyebrow">BETALING FOR DEKKPILOT</p><h2>Koble til Stripe</h2>
<p>Abonnementet er 249 kr per måned inkludert mva. Kundene betaler i Stripe og kan oppdatere kort, se fakturaer og avslutte abonnementet fra sin adminside.</p>
<p>Nøkkel: <strong>{{ $secretConfigured ? 'Lagret' : 'Mangler' }}</strong> · Betalingsvarsler: <strong>{{ $webhookConfigured ? 'Konfigurert' : 'Opprettes ved tilkobling' }}</strong>@if($secretConfigured) · Modus: <strong>{{ $testMode ? 'Test' : 'Live' }}</strong>@endif</p>
<form method="post" action="{{ route('superadmin.stripe.save') }}" class="stack">@csrf @method('PUT')
<label>Hemmelig Stripe-nøkkel<input type="password" name="secret" autocomplete="new-password" placeholder="{{ $secretConfigured ? 'La stå tomt for å beholde lagret nøkkel' : 'sk_live_…' }}" @required(!$secretConfigured)><small>Fra Stripe → Developers → API keys. Lagres kryptert og vises aldri igjen. Produksjon krever live-nøkkel.</small></label>
<label>Pris-ID for månedsabonnementet<input name="price_id" value="{{ $priceId }}" placeholder="price_…" required><small>Opprett én fast pris på 249 NOK hver måned. Prisen skal inkludere mva., ikke legge den oppå.</small></label>
<details><summary>Eksisterende webhook (valgfritt)</summary><label>Webhook-hemmelighet<input type="password" name="webhook_secret" autocomplete="new-password" placeholder="whsec_…"><small>La stå tomt: eksisterende hemmelighet beholdes, eller DekkPilot oppretter webhook automatisk.</small></label></details>
<p>Webhook-adresse: <code>{{ route('webhooks.stripe') }}</code>. Kundeportalen opprettes automatisk med kortoppdatering, fakturahistorikk og oppsigelse ved periodens slutt.</p>
<label class="check"><input type="checkbox" name="activate_all" value="1" required> Aktiver betalingskrav for alle virksomheter. {{ $legacyCount }} eksisterende virksomheter må aktivere Stripe-abonnement. Superadmin og demo beholder tilgang.</label>
<button class="button">Kontroller, koble til og aktiver</button>
</form>
</section>
<section class="panel"><h2>Gi gratis måneder</h2><p>Åpne virksomheten i superadminoversikten og velg «Gi gratis måneder» og antall (1–12). De første abonnementsmånedene blir gratis hvis de ikke har startet. For et aktivt abonnement gjelder rabatten kommende månedsbetalinger. Du kan gi nye måneder når forrige rabatt er brukt. Kunden må registrere betalingskort også når første måned er gratis. SMS faktureres separat.</p></section>
</x-layouts.app>
