<x-layouts.app title="Driftsoppsett · DekkPilot" heading="Driftsoppsett">
<link rel="stylesheet" href="{{ route('system.asset.query',['filename'=>'service-admin.css','v'=>'20261006']) }}">
<link rel="stylesheet" href="{{ route('system.asset.query',['filename'=>'settings-navigation.css','v'=>'20261006']) }}">
@php
$sections=['team'=>'Ansatte og roller','capacity'=>'Åpningstider og arbeidsbukker','services'=>'Tjenester og priser','products'=>'Dekkvarer og merker','labels'=>'Etikettpåminnelser','integrations'=>'Statens vegvesen'];
$groups=['team'=>['team','capacity'],'capacity'=>['team','capacity'],'services'=>['services','products'],'products'=>['services','products'],'labels'=>['labels'],'integrations'=>['integrations']];
$descriptions=['team'=>'Brukere, roller og tilgang til systemet','capacity'=>'Åpningstider, nettbooking og kapasitet','services'=>'Tjenester, priser og varighet','products'=>'Salgsdekk, dekkmerker og lagerantall','labels'=>'Slå utskrift og påminnelser av eller på','integrations'=>'API-nøkkel og veiledning for biloppslag'];
$activeSection=request()->query('tab','team');
if(!is_string($activeSection)||!array_key_exists($activeSection,$sections)) $activeSection='team';
@endphp
<div class="admin-subnav"><a href="{{ route('admin') }}">← Administrasjon</a><a href="{{ route('billing') }}">Abonnement og Stripe</a></div>
<section class="panel settings-intro"><h2>{{ in_array($activeSection,['team','capacity'])?'Ansatte og arbeidsdag':(in_array($activeSection,['services','products'])?'Varer og tjenester':$sections[$activeSection]) }}</h2>
<nav class="settings-navigation" aria-label="Områder i driftsoppsett">
@foreach($groups[$activeSection] as $key)<a href="{{ route('admin.settings',['tab'=>$key]) }}" @if($activeSection===$key) aria-current="page" @endif>{{ $sections[$key] }}<small>{{ $descriptions[$key] }}</small></a>@endforeach
@if(in_array($activeSection,['team','capacity']))<a href="{{ route('admin.organization') }}">Virksomhetsopplysninger<small>Kontaktinformasjon, adresser og Brreg</small></a>@endif
@if(in_array($activeSection,['services','products']))<a href="{{ route('admin.tires') }}">Dekkatalog og vareimport<small>Importer større kataloger fra regneark</small></a>@endif
</nav></section>
<div class="settings-content" id="settings-content">@include('admin.settings.'.$activeSection)</div>
</x-layouts.app>
