<x-layouts.app title="Hotellavtaler · DekkPilot" heading="Hotellavtaler">
<link rel="stylesheet" href="{{ route('system.asset.query',['filename'=>'edit-dialog.css','v'=>'20261008']) }}">
<link rel="stylesheet" href="{{ route('system.asset.query',['filename'=>'hotel-agreements.css','v'=>'20261008']) }}">
<script defer src="{{ route('system.asset.query',['filename'=>'edit-dialog.js','v'=>'20261008']) }}"></script>
@php
$labels=['active'=>'Aktiv','paused'=>'Pauset','ended'=>'Avsluttet','draft'=>'Utkast'];
$renewing=request('filter')==='renewing';
$current=$renewing?'renewing':request('status','all');
@endphp
<div class="agreements-page">
<nav class="agreement-filters" aria-label="Filtrer avtaler">
@foreach(['all'=>'Alle','active'=>'Aktive','renewing'=>'Fornyes snart','paused'=>'Pauset','ended'=>'Avsluttet'] as $key=>$label)
<a href="{{ route('hotel-agreements.index',$key==='all'?[]:($key==='renewing'?['filter'=>'renewing']:['status'=>$key])) }}" @if($current===$key) aria-current="page" @endif>{{ $label }}@if($key!=='renewing')<span>{{ $key==='all'?$counts->sum():($counts[$key]??0) }}</span>@endif</a>
@endforeach
</nav>
<section class="panel agreement-register">
<header class="agreement-heading"><div><p class="eyebrow">AVTALEREGISTER</p><h2>{{ $agreements->total() }} {{ $agreements->total()===1?'avtale':'avtaler' }}{{ $renewing?' · neste 60 dager':'' }}</h2></div><a class="agreement-link" href="{{ route('inventory') }}">Åpne hjulhotell →</a></header>
<details class="agreement-help"><summary>Hvordan fungerer hotellavtalene?</summary><p>Avtalen opprettes automatisk når hjulene leveres inn, med gjeldende halvårspris og seks måneders avtaleperiode. Bruk «Endre» for å pause eller avslutte en avtale. Avsluttede avtaler fornyes ikke, og tidligere krav beholdes.</p></details>
<div class="table-wrap"><table class="agreement-table"><thead><tr><th>Kunde</th><th>Bil / hjul</th><th>Periode</th><th>Pris / halvår</th><th>Status</th><th><span class="agreement-sr-only">Handlinger</span></th></tr></thead><tbody>
@forelse($agreements as $agreement)
<tr>
<td data-label="Kunde"><a class="agreement-customer" href="{{ route('customers.show',$agreement->customer) }}">{{ $agreement->customer->name }}</a><small>{{ $agreement->customer->customer_number }}</small></td>
<td data-label="Bil / hjul"><strong>{{ $agreement->vehicle->registration_number }}</strong>@if($agreement->tireSet)<small><a href="{{ route('tire-sets.show',$agreement->tireSet) }}">{{ $agreement->tireSet->code }}</a>{{ $agreement->tireSet->storageLocation?' · '.$agreement->tireSet->storageLocation->code:'' }}</small>@else<small>Ikke tilknyttet hjulsett</small>@endif</td>
<td data-label="Periode">{{ $agreement->starts_on->format('d.m.Y') }}<small>@if($agreement->status==='ended')Avsluttet{{ $agreement->ends_on?' '.$agreement->ends_on->format('d.m.Y'):'' }}@elseif($agreement->status==='paused')Fornyelse pauset @else{{ $agreement->renews_on?'Fornyes '.$agreement->renews_on->format('d.m.Y'):'Ingen fornyelsesdato' }}@endif</small></td>
<td data-label="Pris / halvår" class="agreement-price">{{ number_format($agreement->price_cents/100,2,',',' ') }} kr</td>
<td data-label="Status"><span class="status {{ $agreement->status==='active'?'completed':($agreement->status==='ended'?'cancelled':'in_progress') }}">{{ $labels[$agreement->status]??$agreement->status }}</span></td>
<td class="agreement-action"><button type="button" class="agreement-edit" data-edit-open="agreement-{{ $agreement->id }}" aria-label="Endre avtale for {{ $agreement->vehicle->registration_number }}">Endre</button></td>
</tr>
@empty<tr><td colspan="6" class="empty">Ingen avtaler i dette utvalget.</td></tr>@endforelse
</tbody></table></div>
{{ $agreements->links() }}
</section>
@foreach($agreements as $agreement)
<x-edit-dialog :id="'agreement-'.$agreement->id" title="Endre avtalestatus" :bag="'agreement'.$agreement->id">
<p class="agreement-dialog-context"><strong>{{ $agreement->vehicle->registration_number }}</strong> · {{ $agreement->customer->name }}</p>
<form method="post" action="{{ route('hotel-agreements.status',$agreement) }}" class="stack">@csrf @method('PATCH')
<label>Status<select name="status">@foreach(['active'=>'Aktiv','paused'=>'Pauset','ended'=>'Avsluttet'] as $value=>$label)<option value="{{ $value }}" @selected($agreement->status===$value)>{{ $label }}</option>@endforeach</select></label>
<p class="muted">Avsluttede avtaler fornyes ikke. Tidligere krav og historikk beholdes.</p>
<div class="dialog-actions"><button type="button" class="button ghost" data-edit-close>Avbryt</button><button class="button">Lagre status</button></div>
</form>
</x-edit-dialog>
@endforeach
</div>
</x-layouts.app>
