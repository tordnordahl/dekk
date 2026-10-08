<x-layouts.app title="Administrasjon · DekkPilot" heading="Administrasjon">
<link rel="stylesheet" href="{{ route('system.asset.query',['filename'=>'admin-overview.css','v'=>'20261008']) }}">
@php
$groups=[
['Virksomhet og ansatte','Adresse, fakturaopplysninger, ansatte, åpningstider og tilgang.',[
['admin.organization',[],'Virksomhetsopplysninger','Kontaktinformasjon, adresser og oppslag i Brreg'],
['admin.settings',['tab'=>'team'],'Ansatte og roller','Brukere og rettigheter'],
['admin.settings',['tab'=>'capacity'],'Åpningstider og arbeidsbukker','Nettbooking, kapasitet og tidsbruk'],
['admin.security',[],'Sikkerhet og app-tilgang','Tofaktor, enheter og sikkerhetslogg'],
['billing',[],'DekkPilot-abonnement','Abonnement, betalingskort og fakturaer fra DekkPilot']]],
['Varer og tjenester','Dekkmerker, salgsdekk, lagerantall, tjenestepriser og import.',[
['admin.settings',['tab'=>'products'],'Dekkvarer, merker og priser','Legg til eller rediger dekkvarer og dekkmerker'],
['admin.settings',['tab'=>'services'],'Tjenester og fastpriser','Dekkskift, dekkhotell, reparasjoner og varighet'],
['admin.tires',[],'Dekkatalog og vareimport','Større kataloger og import av dekk fra regneark']]],
['Hjulhotell og etiketter','Reoler, plassering, etikettmaler og utskrift.',[
['admin.warehouse',[],'Lagerkart og reoler','Bygg og endre lagerplasser'],
['admin.labels',[],'Etikettmal og utskrift','Størrelse, QR-kode, tekst og prøveutskrift'],
['admin.settings',['tab'=>'labels'],'Etikettpåminnelser','Slå etikettfunksjonene av eller på']]],
['Kundebetaling og regnskap','Betalingsmåter for kundene, regnskapskobling og fakturakø.',[
['admin.payments',[],'Stripe og Zettle','Betalingsoppsett for verkstedets kunder'],
['admin.accounting',[],'Regnskap, Vipps og terminal','Regnskapskoblinger, terminal, kvitteringer og fakturakø']]],
['E-post og SMS','Avsenderadresse, SMS-oppsett og oversikt over utsendelser.',[
['admin.communications',[],'Meldinger og SMS-oppsett','Utsendelser, feil, kampanjer og Twilio'],
['admin.system',[],'E-postavsender og leveringstest','Velg avsender og kontroller e-postleveringen']]],
['Biloppslag, import og portaler','Statens vegvesen, kundeimport og visninger for kunder og ansatte.',[
['admin.settings',['tab'=>'integrations'],'Statens vegvesen','API-nøkkel og automatisk biloppslag'],
['admin.imports',[],'Importer kunder, biler og hjulsett','Flytt eksisterende data fra Excel eller CSV'],
['admin.portals',[],'Kundeportal og utsjekking','Åpne kundeportal, betalingsside og teknikervisning']]],
];
@endphp
<p class="muted">Velg et område. Hvert område viser hva du kan endre.</p>
<div class="admin-overview">
@foreach($groups as [$title,$description,$links])
<details class="panel admin-area"><summary><div><h2>{{ $title }}</h2><p>{{ $description }}</p></div><span aria-hidden="true">＋</span></summary>
<nav aria-label="{{ $title }}">@foreach($links as [$route,$params,$label,$hint])<a href="{{ route($route,$params) }}"><strong>{{ $label }} <span aria-hidden="true">→</span></strong><small>{{ $hint }}@if($route==='admin.settings' && ($params['tab']??'')==='team') · {{ $counts['employees'] }} ansatte @endif</small></a>@endforeach</nav></details>
@endforeach
</div>
</x-layouts.app>
