@php($legalCss = in_array(request()->getHost(), ['localhost', '127.0.0.1', '::1'], true) ? route('system.asset', ['filename' => 'legal.css']) : asset('legal.css'))
<!doctype html>
<html lang="nb">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="index,follow">
    <title>Personvern · DekkPilot</title>
    <link rel="stylesheet" href="{{ $legalCss }}?v=20260812-1">
</head>

<body class="legal-page">
    <header class="legal-topbar">
        <div class="legal-topbar-inner"><a class="legal-brand" href="{{ url('/') }}"><span
                    class="legal-brand-mark">D</span><span>DekkPilot</span></a>
            <nav class="legal-nav" aria-label="Juridiske dokumenter"><a href="{{ route('legal.privacy') }}"
                    aria-current="page">Personvern</a><a href="{{ route('legal.terms') }}">Vilkår</a><a
                    href="{{ route('legal.dpa') }}">Databehandleravtale</a></nav>
        </div>
    </header>
    <main class="legal-wrap">
        <section class="legal-hero">
            <p class="legal-kicker">Åpenhet om opplysningene dine</p>
            <h1>Personvernerklæring</h1>
            <p>Her forklarer vi hvilke opplysninger DekkPilot behandler, hvorfor de brukes og hvilke rettigheter du har.
            </p>
        </section>
        <article class="legal-document">
            <div class="legal-meta"><span>Versjon 12. august 2026</span><span>Gjelder DekkPilot-tjenesten</span></div>
            <h2>1. Hvem erklæringen gjelder for</h2>
            <p>Erklæringen gjelder besøkende på nettsiden, personer som oppretter eller bruker en virksomhetskonto, og
                personer som kontakter DekkPilot. For personopplysninger et dekkhotell registrerer om sine egne kunder
                og ansatte, er dekkhotellet behandlingsansvarlig og DekkPilot databehandler.</p>
            <h2>2. Opplysninger vi behandler</h2>
            <p>Vi kan behandle navn, kontaktinformasjon, virksomhetstilknytning, organisasjonsnummer, konto- og
                abonnementsdata, avtaleaksepter, supporthenvendelser samt innloggings-, sikkerhets- og brukslogger.</p>
            <p>Tjenesten kan i tillegg inneholde opplysninger kunden registrerer om blant annet sine kunder, kjøretøy,
                registreringsnummer, bookinger, hjulsett, tilbud, kommunikasjon og betalingsstatus.</p>
            <h2>3. Formål og behandlingsgrunnlag</h2>
            <ul>
                <li>Levere og administrere tjenesten og kundeforholdet – nødvendig for å oppfylle avtalen.</li>
                <li>Sikre, feilsøke og forbedre tjenesten – berettiget interesse i trygg og stabil drift.</li>
                <li>Fakturering, regnskap og oppfyllelse av rettslige plikter – avtale og lovkrav.</li>
                <li>Markedsføring og meldinger – samtykke eller annet gyldig grunnlag der dette kreves.</li>
            </ul>
            <h2>4. Deling og integrasjoner</h2>
            <p>Opplysninger deles bare når det er nødvendig med leverandører av hosting, e-post, SMS, betaling,
                regnskap, kundestøtte og aktiverte integrasjoner, eller når loven krever det. Personopplysninger selges
                ikke. Kunden bestemmer selv hvilke valgfrie integrasjoner som aktiveres i sitt miljø.</p>
            <h2>5. Lagring og sikkerhet</h2>
            <p>Opplysninger lagres så lenge kontoen er aktiv eller det er nødvendig for formålet, avtalen og gjeldende
                lovkrav. DekkPilot bruker blant annet rollebasert tilgang, virksomhetsisolasjon, kryptert kommunikasjon,
                logging, sikkerhetskopiering og vedlikeholdsrutiner for å redusere risiko.</p>
            <h2>6. Overføring utenfor EØS</h2>
            <p>Dersom en valgt leverandør behandler data utenfor EØS, skal overføringen bygge på et gyldig
                overføringsgrunnlag og nødvendige tilleggstiltak.</p>
            <h2>7. Dine rettigheter</h2>
            <p>Du kan, når vilkårene er oppfylt, be om innsyn, retting, sletting, begrensning eller dataportabilitet, og
                protestere mot behandling. Henvendelser om data registrert av et dekkhotell bør først rettes til det
                aktuelle dekkhotellet. Du kan også klage til Datatilsynet.</p>
            <h2>8. Informasjonskapsler og tekniske data</h2>
            <p>DekkPilot kan bruke nødvendige informasjonskapsler for innlogging, sikkerhet og sesjonshåndtering.
                Eventuell analyse eller markedsføring som krever samtykke skal ikke aktiveres før slikt samtykke er
                innhentet.</p>
            <h2>9. Endringer</h2>
            <p>Erklæringen kan oppdateres når tjenesten, leverandører eller regelverket endres. Vesentlige endringer
                varsles på egnet måte.</p>
        
            <div class="legal-contact"><strong>Kontakt</strong>
                <p>Personvernspørsmål og rettighetskrav: <a href="mailto:hei@dekkpilot.no">hei@dekkpilot.no</a></p>
            </div>
        </article>
        <footer class="legal-footer"><span>© 2026 DekkPilot</span><a href="{{ url('/') }}">Tilbake til forsiden →</a>
        </footer>
    </main>
</body>

</html>