@if(isset($item['tire_set_id']))
<button type="button" class="action-item priority-{{ $item['priority'] }}" data-action-tire-open="{{ $item['tire_set_id'] }}"><span class="status cancelled">{{ $item['type'] }}</span><span><strong>{{ $item['title'] }}</strong><small>{{ $item['detail'] }}</small></span><b>Ordne nå →</b></button>
@else
<button type="button" class="action-item priority-{{ $item['priority'] }}" data-action-dialog-open="action-{{ $item['kind'] }}-{{ $item['model']->id }}"><span class="status {{ $item['priority']===1?'cancelled':($item['priority']===2?'in_progress':'scheduled') }}">{{ $item['type'] }}</span><span><strong>{{ $item['title'] }}</strong><small>{{ Str::limit($item['detail']??'',180) }}</small></span><b>Ordne nå →</b></button>
@endif
