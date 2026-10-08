<x-layouts.app title="{{ $tireSet->code }} · DekkPilot" heading="{{ $tireSet->vehicle->registration_number }}">
<link rel="stylesheet" href="{{ route('system.asset.query',['filename'=>'tire-details.css','v'=>'20261008-1']) }}">
@php
$statuses=['received'=>'Mottatt','stored'=>'På lager','picked'=>'Plukket','workshop'=>'På verksted','delivered'=>'Utlevert'];
$description=collect([['summer'=>'Sommerhjul','winter'=>'Vinterhjul','all_season'=>'Helårsdekk'][$tireSet->season]??null,$tireSet->manufacturer,$tireSet->size])->filter(fn($value)=>filled($value))->implode(' · ');
@endphp
<div class="tire-details-page">
<div class="admin-subnav"><a href="{{ route('inventory') }}">← Hjulhotell</a><a href="{{ route('customers.show',$tireSet->vehicle->customer) }}">Kundekort</a><a href="{{ route('tire-sets.measurements',$tireSet) }}">Målinger</a><a href="#movement">Plasshistorikk</a></div>
<section class="tire-details-layout">
<article class="panel tire-summary">
<header class="tire-summary-header"><div><p class="eyebrow">HJULSETT</p><div class="tire-summary-title"><h2>{{ $tireSet->code }}</h2><button type="button" class="tire-edit-button" data-edit-open="edit-tire-set">Rediger</button></div><p class="tire-description">{{ $description }}</p></div><span class="status {{ $tireSet->status==='stored'?'completed':'in_progress' }}">{{ $statuses[$tireSet->status]??'Ukjent status' }}</span></header>
<dl class="tire-facts">
<div><dt>Kunde</dt><dd>{{ $tireSet->vehicle->customer->name }}</dd></div>
<div><dt>Bil</dt><dd>{{ trim($tireSet->vehicle->make.' '.$tireSet->vehicle->model)?:'Ikke registrert' }}</dd></div>
<div><dt>Plassering</dt><dd>{{ $tireSet->storage_label }}</dd></div>
<div><dt>Laveste mønsterdybde</dt><dd>{{ $tireSet->minimum_tread_depth!==null?$tireSet->minimum_tread_depth.' mm':'Ikke målt' }}</dd></div>
<div><dt>Vask og pakking</dt><dd>{{ $tireSet->washed?'Vasket':'Ikke vasket' }} · {{ $tireSet->bagged?'Pakket':'Ikke pakket' }}</dd></div>
<div><dt>Sist kontrolltelt</dt><dd>{{ $tireSet->last_counted_at?->format('d.m.Y H:i')??'Ikke kontrolltelt' }}</dd></div>
</dl>
@php($extra=collect([$tireSet->dot_year?'DOT '.$tireSet->dot_year:null,$tireSet->season==='winter'?(['studded'=>'Pigg','unstudded'=>'Piggfritt'][$tireSet->winter_type]??null):null])->filter()->implode(' · '))
@if($extra)<p class="tire-extra">{{ $extra }}</p>@endif
@if($tireSet->hotel_notes)<div class="tire-note"><small>Kommentar</small><p>{{ $tireSet->hotel_notes }}</p></div>@endif
<div class="tire-actions"><a class="button" href="{{ route('tire-sets.inspection',$tireSet) }}">Ny kontroll</a>@if($labelsEnabled)<a class="button ghost" href="{{ route('tire-sets.labels',['ids'=>$tireSet->id]) }}" target="_blank" rel="noopener">Skriv etikett</a>@endif<form method="post" action="{{ route('tire-sets.count',$tireSet) }}">@csrf<button class="button ghost">Kontrolltelt nå</button></form></div>
@if($tireSet->received_at && $tireSet->status!=='delivered')<a class="tire-season-link" href="{{ route('tire-sets.exchange',$tireSet) }}">Bytt hjulsett for sesongen →</a>@endif
</article>
<aside class="panel tire-placement"><p class="eyebrow">FLYTT / STATUS</p><h2>Plassering og status</h2><form method="post" action="{{ route('tire-sets.status',$tireSet) }}" class="stack">@csrf @method('PATCH')<label>Status<select name="status">@foreach($statuses as $value=>$label)<option value="{{ $value }}" @selected($tireSet->status===$value)>{{ $label }}</option>@endforeach</select></label><label>Rad / reol<select name="storage_location_id"><option value="">Ingen plass</option>@foreach($locations as $location)<option value="{{ $location->id }}" @selected($tireSet->storage_location_id===$location->id)>{{ $location->code }} · Lengde 1–{{ $location->sets_per_shelf }} · Høyde 1–{{ $location->shelf_count }}</option>@endforeach</select></label><x-storage-coordinates :tire-set="$tireSet"/><button class="button">Oppdater hjulsett</button></form>
@if($agreement)<hr><strong>Hotellavtale: {{ ['active'=>'Aktiv','paused'=>'Pauset','ended'=>'Avsluttet','draft'=>'Utkast'][$agreement->status]??$agreement->status }}</strong><p>{{ number_format($agreement->price_cents/100,2,',',' ') }} kr</p>@endif
</aside></section>
@include('inventory.edit-details')
<section class="grid" id="inspection"><article class="panel"><p class="eyebrow">MÅLEHISTORIKK</p><h2>Kontroller</h2>@forelse($tireSet->inspections as $inspection)<details><summary>{{ $inspection->inspected_at->format('d.m.Y H:i') }} · {{ $inspection->overall_status }}</summary><div class="depth-row">@foreach($inspection->measurements as $wheel)<span>{{ $wheel->position }} <b>{{ $wheel->tread_depth_mm??'—' }} mm</b></span>@endforeach</div></details>@empty<p class="empty">Ingen målinger.</p>@endforelse</article><article class="panel" id="movement"><p class="eyebrow">PLASSHISTORIKK</p><h2>Alle bevegelser</h2>@forelse($tireSet->movements as $movement)<div class="person"><div><strong>{{ $movement->moved_at->format('d.m.Y H:i') }}</strong><p>{{ $movement->fromLocation?->code??'Uten plass' }} → {{ $movement->toLocation?->code??'Uten plass' }}</p></div><span class="tag">{{ $movement->reason }}</span></div>@empty<p class="empty">Ingen flyttinger logget ennå.</p>@endforelse</article></section></div></x-layouts.app>
