<x-layouts.app title="Driftsoppsett · DekkPilot" heading="Driftsoppsett">
<link rel="stylesheet" href="{{ route('system.asset.query',['filename'=>'service-admin.css','v'=>'20261006']) }}">
<link rel="stylesheet" href="{{ route('system.asset.query',['filename'=>'settings-navigation.css','v'=>'20261006']) }}">
@php
$sections=['team'=>'Ansatte og roller','capacity'=>'Åpningstider og kapasitet','services'=>'Tjenester og priser','products'=>'Dekk og varelager','labels'=>'Etiketter','integrations'=>'Statens vegvesen – biloppslag'];
$activeSection=request()->query('tab','team');
if(!is_string($activeSection)||!array_key_exists($activeSection,$sections)) $activeSection='team';
@endphp
<div class="admin-subnav"><a href="{{ route('admin') }}">← Administrasjon</a><a href="{{ route('billing') }}">Abonnement og Stripe</a></div>
<section class="panel settings-intro"><h2>Velg hva du vil administrere</h2><p>Åpne ett område om gangen. Etter lagring kommer du tilbake til samme område.</p>
<nav class="settings-navigation" aria-label="Områder i driftsoppsett">
@foreach($sections as $key=>$label)<a href="{{ route('admin.settings',['tab'=>$key]) }}" @if($activeSection===$key) aria-current="page" @endif>{{ $label }}@if($key==='team')<small>{{ $employees->count() }} ansatte</small>@elseif($key==='capacity')<small>{{ $workBays->count() }} arbeidsbukker</small>@elseif($key==='services')<small>{{ $services->count() }} tjenester</small>@endif</a>@endforeach
</nav></section>
<div class="settings-content" id="settings-content">@include('admin.settings.'.$activeSection)</div>
</x-layouts.app>
