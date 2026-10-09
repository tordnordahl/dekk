@php($legalCss = in_array(request()->getHost(), ['localhost', '127.0.0.1', '::1'], true) ? route('system.asset', ['filename' => 'legal.css']) : asset('legal.css'))
<!doctype html>
<html lang="nb">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="index,follow">
    <title>Bruksvilkår · DekkPilot</title>
    <link rel="stylesheet" href="{{ $legalCss }}?v=20260812-1">
</head>

<body class="legal-page">
    <header class="legal-topbar">
        <div class="legal-topbar-inner"><a class="legal-brand" href="{{ url('/') }}"><span
                    class="legal-brand-mark">D</span><span>DekkPilot</span></a>
            <nav class="legal-nav" aria-label="Juridiske dokumenter"><a
                    href="{{ route('legal.privacy') }}">Personvern</a><a href="{{ route('legal.terms') }}"
                    aria-current="page">Vilkår</a><a href="{{ route('legal.dpa') }}">Databehandleravtale</a></nav>
        </div>
    </header>
    <main class="legal-wrap">
        <section class="legal-hero">
            <p class="legal-kicker">Avtale for bruk av tjenesten</p>
            <h1>Bruksvilkår</h1>
            <p>Disse vilkårene regulerer virksomhetens tilgang til og bruk av DekkPilot.</p>
        </section>
        <article class="legal-document">
            <div class="legal-meta"><span>Versjon 12. august 2026</span><span>Norsk rett</span></div>
            <h2>1. Partene og aksept</h2>
            <p>Avtalen inngås mellom leverandøren av DekkPilot («leverandøren») og virksomheten som oppretter konto
                («kunden»). Personen som registrerer kontoen bekrefter å ha fullmakt til å binde virksomheten.</p>
            <h2>2. Tjenesten og bruksretten</h2>
            <p>Kunden får en begrenset, ikke-eksklusiv og ikke-overførbar rett til å bruke DekkPilot som nettbasert
                driftssystem i egen virksomhet. Kunden er ansvarlig for egne brukere, tilgangsroller, riktige
                opplysninger og forsvarlig bruk.</p>
            <h2>3. Pris og fakturering</h2>
            <p>Tjenesten koster 249 kroner per måned inkludert merverdiavgift, uten bindingstid. SMS og eventuelle
                eksterne leverandørkostnader faktureres separat etter bruk eller direkte av leverandøren. Abonnementet
                betales og fornyes automatisk via Stripe. Tilgang krever et aktivt abonnement. Betalingsinformasjon og
                oppsigelse administreres fra abonnementssiden. Oppsigelse gjelder fra slutten av gjeldende
                abonnementsperiode. En tildelt gratismåned gir 100 % rabatt på én månedsbetaling for abonnementet; SMS
                er ikke inkludert.</p>
            <h2>4. Kundedata og personopplysninger</h2>
            <p>Kunden beholder rettighetene til egne data og er behandlingsansvarlig for personopplysninger som legges
                inn om kundens kunder og ansatte. DekkPilot behandler disse på kundens vegne etter <a
                    href="{{ route('legal.dpa') }}">databehandleravtalen</a>. Kunden er ansvarlig for lovlig
                behandlingsgrunnlag og utsendelser.</p>
            <h2>5. Integrasjoner</h2>
            <p>Tjenesten kan kobles til blant annet Brønnøysundregistrene, Statens vegvesen, 1881, e-post, SMS, betaling
                og regnskapssystemer. Kunden er ansvarlig for egne avtaler, API-nøkler og kostnader der dette kreves.
                Tredjeparts tilgjengelighet, grensesnitt og vilkår kan endres utenfor leverandørens kontroll.</p>
            <h2>6. Tillatt bruk og sikkerhet</h2>
            <p>Kunden skal ikke omgå sikkerhet, forsøke å få tilgang til andre virksomheters data, spre skadelig kode
                eller bruke tjenesten ulovlig. Passord, integrasjonsnøkler og personlige portallenker skal beskyttes.
                Mistanke om misbruk skal varsles uten ugrunnet opphold.</p>
            <h2>7. Tilgjengelighet, vedlikehold og ansvar</h2>
            <p>Leverandøren skal arbeide for stabil og sikker drift, men garanterer ikke uavbrutt tilgjengelighet.
                Planlagt vedlikehold og nødvendige sikkerhetstiltak kan medføre midlertidig utilgjengelighet. Ansvar for
                indirekte tap, driftstap og tapt fortjeneste begrenses så langt loven tillater. Samlet direkte ansvar er
                begrenset til betalt abonnementsvederlag de siste tolv månedene, med unntak der ufravikelig lov
                bestemmer annet.</p>
            <h2>8. Kundens ansvar for drift</h2>
            <p>Kunden skal kontrollere kritiske opplysninger, ha nødvendige interne rutiner og ikke bruke tjenesten som
                eneste grunnlag der menneskelig kontroll eller lovpålagt dokumentasjon kreves. Demo- og testdata skal
                ikke brukes som produksjonsdata.</p>
            <h2>9. Varighet, oppsigelse og eksport</h2>
            <p>Avtalen løper månedlig og kan sies opp uten bindingstid. Ved avslutning kan kunden be om eksport av egne
                data innen rimelig tid. Data slettes eller anonymiseres etter gjeldende rutiner, databehandleravtalen og
                lovkrav.</p>
            <h2>10. Endringer og lovvalg</h2>
            <p>Vesentlige endringer i pris eller vilkår varsles før de trer i kraft. Norsk rett gjelder. Tvister skal
                først søkes løst i minnelighet og behandles ellers av norske domstoler.</p>
            <div class="legal-contact"><strong>Kontakt</strong>
                <p>Spørsmål om avtalen: <a href="mailto:hei@dekkpilot.no">hei@dekkpilot.no</a></p>
            </div>
        </article>
        <footer class="legal-footer"><span>© 2026 DekkPilot</span><a href="{{ url('/') }}">Tilbake til forsiden →</a>
        </footer>
    </main>
</body>

</html>