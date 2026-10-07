# Jovia Digital — kryptert lese-API

Ingen eksisterende API-er eller kundetilganger er endret. Ingen skrivehandlinger.
API-et er avslått som standard. Kjør `php artisan migrate --force` for demo-
flagget før API-et aktiveres. Ingen eksisterende data slettes eller tilganger endres.

## Før aktivering

På Tords lokale maskin er mottakernøkkelen opprettet utenfor webroten:

- Offentlig nøkkel: `~/Library/Application Support/JoviaDigitalAdmin/recipient-public.txt`.
- Privat nøkkel: `~/Library/Application Support/JoviaDigitalAdmin/recipient.key`.

Bare den offentlige nøkkelen skal overføres til DekkPilot-serveren.
Den private nøkkelen må sikkerhetskopieres sikkert. Tap av nøkkelen gjør gamle
krypterte svar uleselige. Ikke legg nøklene eller API-token i Git eller nettleserkode.

## Oppsett på DekkPilot-serveren

Etter at koden er hentet, kjør fra `system/`:

```sh
php artisan jovia:portfolio-token
```

Kommandoen viser et tilfeldig 256-bits lesetoken og tilhørende SHA-256-hash.
Lagre tokenet sikkert for lokal admin-tilkobling. Sett bare hashen i serverens
private `.env`, sammen med den offentlige nøkkelen:

```dotenv
JOVIA_PORTFOLIO_ENABLED=true
JOVIA_PORTFOLIO_TOKEN_HASH=<hash fra kommandoen>
JOVIA_PORTFOLIO_PUBLIC_KEY=<innhold fra recipient-public.txt>
```

Deretter `php artisan config:cache` og `php artisan route:clear`.
Kontroller at PHP sodium er tilgjengelig. HTTPS er obligatorisk i produksjon.
Bak en proxy må Laravel stole kun på faktiske reverse proxy-adresser for at
HTTPS-detektering skal fungere. Ikke åpne vilkårlige forwarded headers.

## Adresse og protokoll

På nåværende installasjonsstruktur:
`https://www.dekkpilot.no/system/index.php/api/v1/portfolio/overview`

Send `Authorization: Bearer <lesetoken>` og `Accept: application/json`
fra admin-portalens backend. Ikke bruk URL-parametere til tokenet.
GET støtter `page` og `per_page` (standard 100, maks 200). Hent alle sider
for komplett kundeliste. Globale statistikker gjelder hele systemet, ikke
bare siden. Maks 30 kall/minutt per avsender. Ingen nettleser-CORS er nødvendig.

Svar inneholder `algorithm=sodium-sealed-box`, `recipient_key_id`, base64-
`ciphertext` og `request_id`. Dekryptering på lokal backend bruker
`sodium_crypto_box_seal_open` med mottakerens nøkkelpar. Dekryptert innhold:

- Kundeorganisasjonens offentlige ID, navn og abonnementsstatus.
- Registreringsdato brukt som onboardingdato; `onboarding_date_source=registration`.
- Prisreferanse 24900 øre/måned, NOK, inkludert mva. Ikke faktisk innbetaling.
- Per kunde: antall sluttkunder, kjøretøy, sett, dekkenheter, lagrede sett,
  avdelinger, ansatte og bookinger.
- Globalt: samme tellinger, fullførte bookinger og nye sluttkunder/virksomheter
  de siste 30 dagene.

Soft-deleted sluttkunder, kjøretøy og sett teller ikke. Dekkenheter er summen
av `quantity` for sett som ikke bare er felger; dette er ikke varelager for salg.
`stored_sets` betyr status `stored`. Bookinger er registrerte bookinger gjennom
tidene, inkludert kansellerte; fullførte bookinger telles separat. Registrerte
ansatte inkluderer inaktive ansatte, men utelater brukere uten organisasjon.
Demomiljøer merkes under superadmin → kundekort → «Demomiljø og
porteføljestatistikk». De utelates fra kundeliste, paginering og alle tellinger.
Den innebygde organisasjonen `DEMO-DEKKPILOT` utelates alltid, også ved senere
opprettelse, og eksisterende innebygd demo merkes ved migrering.
Tallene er ikke bevis på faktisk bruk eller lønnsomhet. Ingen sluttkundenavn, e-post, bilskilt,
betalingsidentifikatorer eller notater eksponeres.

Gratisperiode vises som test; stengte kunder vises sperret. `active` betyr
aktivt abonnement med tilgang, ikke bekreftet betaling. Uavklart betalingsstatus
blir `unknown`, ikke automatisk «avsluttet» eller «betalende».

## Sikkerhet og drift

Gratis-/testperioden følger `trial_started_at`, `trial_ends_at` og
`trial_months_granted`. Lokal gratis tilgang bruker `free_access_until` som
sluttdato. En brukt Stripe-gratisrabatt estimeres fra anvendelsestidspunktet
pluss tildelte måneder (`trial_date_source=discount_estimate`); dette er ikke
Stripe-fakturadato og kan avvike. Stripe `trialing` uten lokal kjent sluttdato
returnerer null, ikke en gjettet dato fra ordinær abonnementsperiode.
Oppsigelse ved periodeslutt følger `cancel_at_period_end` og
`subscription_ends_at`. Ingen eksisterende betalings- eller tilgangslogikk endres.

Dedikert lesetoken: eksisterende brukertokens og superadminøkter gir ikke tilgang.
Serveren lagrer kun tokenhash og offentlig krypteringsnøkkel. HTTPS autentiserer
serveren og beskytter forespørselen; sealed-box krypterer selve svaret, men er
ikke en digital avsendersignatur. Admin-klienten må verifisere TLS-sertifikatet.
Token er en bearer-legitimasjon: roter ved mistanke om lekkasje. Bytt hash og
kjør config:cache for umiddelbar tilbakekalling. Slå av ENABLED ved behov.
API-et lagrer kun teknisk leselogging, ikke navn, token eller innhold.

API-et er implementert og lokalt testet. Det er ikke publisert eller verifisert
mot produksjonsserveren. Lokal admin-side er ikke koblet til API-et ennå.
