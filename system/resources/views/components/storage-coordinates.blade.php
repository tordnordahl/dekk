@props(['tireSet' => null])
<div class="fields">
    <label>Lengde (plass bortover)<input name="storage_position_number" type="number" min="1" max="50" step="1" value="{{ $tireSet?->storage_position_number }}" placeholder="Automatisk"></label>
    <label>Høyde (nivå)<input name="storage_shelf_number" type="number" min="1" max="20" step="1" value="{{ $tireSet?->storage_position_number ? $tireSet->storage_shelf_number : '' }}" placeholder="Automatisk"></label>
</div>
<small>Velg både lengde og høyde for manuell plassering. La begge stå tomme for å beholde plassen eller velge første ledige plass.</small>
