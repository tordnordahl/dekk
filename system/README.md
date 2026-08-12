# DekkPilot

Moderne, API-klar driftsplattform for dekkhotell og verksted. Første leveranse inneholder sikker installasjon, organisasjoner og avdelinger, innlogging, dashboard, kunder, kjøretøy, hjulsett, lagerplasser, bookinger og REST API v1.

## Installer med MySQL eller MAMP

1. Start Apache og MySQL i MAMP.
2. Åpne `install.php` i nettleseren.
3. Oppgi databasevert, port, det forhåndsbestemte databasenavnet og databasebrukeren.
4. Velg **Bruk eksisterende database** når databasen allerede er opprettet av hostingleverandøren.
5. Opprett første administrator.
6. Logg inn fra lenken etter fullført installasjon.

Installatøren bruker MySQL-port 3306 som standard. For lokal MAMP brukes normalt port 8889 og eventuelt socket `/Applications/MAMP/tmp/mysql/mysql.sock`. Socket skal normalt stå tom på en ekstern MySQL-server.

Installatøren kontrollerer databasen før den gjør endringer. En eksisterende, tom database brukes direkte. Dersom databasen inneholder virksomheter, brukere eller ukjente tabeller, stoppes installasjonen uten å overskrive data. Alternativet «Opprett bare hvis den mangler» skal bare brukes når databasebrukeren har `CREATE DATABASE`-rettighet.

Alle Laravel-migrasjoner kjøres internt uten avhengighet til `exec()`, og den første virksomheten får standardtjenester, tidsinnstillinger, lagerplasser, fakturabetaling og juridiske aksepter. Etter vellykket installasjon opprettes `storage/app/installed.lock`. Slett eller blokker deretter `install.php` på produksjonsserveren.

Serveren må ha PHP 8.2+, PDO MySQL, OpenSSL, Mbstring og installerte Composer-pakker. `storage/` og `bootstrap/cache/` må være skrivbare for PHP-brukeren. Dokumentroten bør peke på `public/`, og produksjonsadressen bør bruke HTTPS.

### Viktig ved opplasting fra en lokal installasjon

Ikke last opp disse lokale filene til en ny server:

- `.env`
- `storage/app/installed.lock`
- `storage/logs/*.log`
- filer i `storage/framework/sessions/`, `views/` og `cache/`

Behold mappene og deres `.gitignore`-filer, men ikke lokalt innhold. Dersom `installed.lock` allerede er kopiert til en helt ny server, slettes kun denne ene filen før `install.php` åpnes. Installereren kontrollerer deretter at databasen faktisk er tom før den gjør endringer.

MAMP-oppsettet på denne maskinen bruker frontkontroller i URL-en, derfor er standard systemadresse `http://localhost:8888/dekk/index.php`. Med en virtual host der dokumentroten peker på `public/`, kan en ren URL brukes i stedet.

## Produksjonsoppsett

Serveren skal ha ett cron-kall hvert minutt:

```cron
* * * * * cd /sti/til/system && php artisan schedule:run >> /dev/null 2>&1
```

Planleggeren behandler meldings- og regnskapskø, bookingbekreftelser, dekkoppfølging, helsesjekk, månedsgrunnlag og daglig databasebackup. Backup lagres utenfor dokumentroten i `storage/app/backups`, komprimeres, får filrettighet `0600` og roteres etter 14 dager. Sett `DB_DUMP_BINARY` til full sti til `mysqldump` dersom binærfilen ikke ligger i serverens PATH. Test restore regelmessig på en separat database; produksjonsdatabasen skal aldri brukes til restore-test.

Nødvendige produksjonsverdier i `.env`:

- `APP_ENV=production`, `APP_DEBUG=false`, korrekt HTTPS-basert `APP_URL`.
- SMTP eller annen støttet Laravel mailer. `MAIL_MAILER=log` sender ikke e-post.
- `FIKEN_CLIENT_ID` og `FIKEN_CLIENT_SECRET` for Fiken OAuth.
- `TRIPLETEX_CONSUMER_TOKEN` for kommersiell Tripletex-integrasjon.
- `SMS_BILLING_UNIT_PRICE_CENTS` for pris per levert SMS.
- `DB_DUMP_BINARY` for automatisk MySQL-backup.

Etter opplasting kjøres systemoppdateringen som superadmin. Den installerer alle migreringer og tømmer cache. Kjør deretter:

```bash
php artisan system:health
php artisan backup:database
php artisan schedule:list
```

Kopier sikkerhetskopier til kryptert ekstern lagring med hostingleverandørens backupjobb. Lokal backup alene beskytter ikke mot diskfeil eller kompromittert server.

## Regnskapsintegrasjoner

Fiken-kunder kobler til via OAuth-knappen. Personlig API-token er kun beholdt for direkte intern integrasjon og skal ikke brukes for DekkPilot-kunder. Registrer callback-URL-en som vises av `route('admin.accounting.fiken.callback')` i Fiken-appen.

Tripletex SaaS-modus bruker DekkPilots consumer token fra servermiljøet og kundens employee token fra adminskjermen. Uten consumer token går integrasjonen i eksplisitt intern JWT-modus. Faktura opprettes uten utsendelse; Tripletex/Fiken beholder fakturanummer, bokføring og distribusjon.

## API

Opprett et tidsbegrenset token:

```http
POST /dekk/index.php/api/v1/tokens
Content-Type: application/json

{"email":"admin@example.no","password":"...","device_name":"Min app"}
```

Bruk tokenet som `Authorization: Bearer <token>`. Token har eksplisitte `read`- og `workshop.write`-rettigheter, utløper etter 30 dager og kan trekkes tilbake under Admin → Sikkerhet. Data isoleres per organisasjon, token lagres kun som SHA-256-hash, og API-et har rate limiting.

OpenAPI-kontrakten finnes på `/api/v1/openapi.yaml` og i `public/openapi.yaml`. Appen skal behandle HTTP 401 som behov for ny innlogging, 403 som manglende rettighet, 409 som samtidighetskonflikt og 422 som valideringsfeil. Én skanning representerer hele hjulsettet.

## Sikkerhet og personvern

Eiere og administratorer kan aktivere TOTP-tofaktor under Admin → Sikkerhet. Hemmeligheten og gjenopprettingskodene lagres kryptert; selve engangskodene lagres bare som passordhash. Samme side viser app-enheter og de siste 100 revisjonshendelsene. Kundedata kan eksporteres fra kundekortet, og markedsføringsutsendelser får signert avmeldingslenke. Demoorganisasjoner kan aldri sende eksterne meldinger.

Twilio-statuscallback har et tilfeldig token per melding. Leverte og avviste meldinger oppdateres i køen, og avviste/uleverte SMS fjernes fra fakturerbart forbruk.

## Test

```bash
/Applications/MAMP/bin/php/php8.4.17/bin/php artisan test
```

Produksjonspakken kan bruke `composer install --no-dev --optimize-autoloader`. Utvikling og CI bruker dev-avhengighetene for PHPUnit. Ingen nye Composer-pakker er nødvendige for funksjonene over.
