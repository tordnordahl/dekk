<link rel="stylesheet" href="{{ route('system.asset.query',['filename'=>'edit-dialog.css','v'=>'20261008']) }}">
<script defer src="{{ route('system.asset.query',['filename'=>'edit-dialog.js','v'=>'20261008']) }}"></script>
<x-edit-dialog id="edit-tire-set" title="Rediger hjulsett" bag="tireDetails" :reopen="$errors->has('confirmation')">
@php($restore=$errors->getBag('tireDetails')->any())
<form method="post" action="{{ route('tire-sets.details',$tireSet) }}" class="stack">@csrf @method('PUT')
<div class="fields">
<label>Dekkmerke<input name="manufacturer" maxlength="100" value="{{ $restore?old('manufacturer'):$tireSet->manufacturer }}"></label>
<label>Dimensjon<input name="size" maxlength="50" placeholder="205/55 R16" value="{{ $restore?old('size'):$tireSet->size }}"></label>
<label>Sesong<select name="season">@foreach(['summer'=>'Sommer','winter'=>'Vinter','all_season'=>'Helår'] as $value=>$label)<option value="{{ $value }}" @selected(($restore?old('season'):$tireSet->season)===$value)>{{ $label }}</option>@endforeach</select></label>
<label>Type<select name="kind">@foreach(['complete_wheels'=>'Komplette hjul','tires'=>'Dekk','rims'=>'Felger'] as $value=>$label)<option value="{{ $value }}" @selected(($restore?old('kind'):$tireSet->kind)===$value)>{{ $label }}</option>@endforeach</select></label>
<label>Vinterhjul<select name="winter_type">@foreach([''=>'Ikke angitt','studded'=>'Pigg','unstudded'=>'Piggfritt'] as $value=>$label)<option value="{{ $value }}" @selected(($restore?old('winter_type'):$tireSet->winter_type)===$value)>{{ $label }}</option>@endforeach</select><small>Brukes bare for vinterhjul.</small></label>
<label>Produksjonsår (DOT)<input name="dot_year" type="number" min="1990" max="2100" value="{{ $restore?old('dot_year'):$tireSet->dot_year }}"></label>
</div>
<label>Kommentar til hjulsettet<textarea name="hotel_notes" maxlength="4000" rows="3">{{ $restore?old('hotel_notes'):$tireSet->hotel_notes }}</textarea></label>
<p class="fine">Målinger og kontrollhistorikk beholdes. Nye målinger registreres under «Ny kontroll».</p>
<div class="dialog-actions"><button type="button" class="button ghost" data-edit-close>Avbryt</button><button class="button">Lagre endringer</button></div>
</form>
@if(auth()->user()->is_super_admin || in_array(auth()->user()->role, ['owner','admin','manager'], true))
@error('confirmation')<p class="notice danger">{{ $message }}</p>@enderror
<div id="delete-registration"><details @if($errors->has('confirmation')) open @endif><summary>Slett feilregistrert hjulsett</summary><p>Bruk dette for duplikater eller hjulsett som ikke finnes. Settet fjernes fra kundekort, lager og kundeportal. Historikk, betalinger og hotellavtaler beholdes.</p><form method="post" action="{{ route('tire-sets.destroy', $tireSet) }}" class="stack">@csrf @method('DELETE')<label>Skriv hjulkoden <strong>{{ $tireSet->code }}</strong> for å bekrefte<input name="confirmation" required autocomplete="off"></label><button class="button danger">Slett feilregistreringen</button></form></details></div>
@endif
</x-edit-dialog>
