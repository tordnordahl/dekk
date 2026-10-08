<p class="fine" data-result-count>{{ $products->total() }} {{ $products->total()===1?'vare':'varer' }} funnet</p>
<div class="table-wrap"><table><thead><tr><th>Varenr.</th><th>Dekk</th><th>Sesong</th><th>Lager</th><th>Pris</th><th></th></tr></thead><tbody>
@forelse($products as $product)
<tr><td>{{ $product->sku }}</td><td><strong>{{ $product->brand }} {{ $product->model }}</strong><small>{{ $product->size }}{{ !$product->active?' · Tatt ut av nye tilbud':'' }}</small></td><td>{{ ['summer'=>'Sommer','winter'=>'Vinter','all_season'=>'Helår'][$product->season] }}{{ $product->studded?' · pigg':'' }}</td><td>{{ $product->stock_quantity }}</td><td>{{ number_format($product->price_cents/100,2,',',' ') }} kr</td><td><button type="button" class="edit-trigger" data-edit-open="edit-product-{{ $product->id }}" aria-label="Rediger {{ $product->sku }}">Rediger</button></td></tr>
@empty<tr><td colspan="6" class="empty">Ingen varer funnet. Prøv et annet søk.</td></tr>@endforelse
</tbody></table></div>
{{ $products->links() }}
@foreach($products as $product)
<x-edit-dialog :id="'edit-product-'.$product->id" :title="'Rediger '.$product->sku" :bag="'product'.$product->id">
@php($restore=$errors->getBag('product'.$product->id)->any())
<form method="post" action="{{ route('admin.products.update',$product) }}" class="stack">@csrf @method('PUT')
<div class="fields">
@foreach(['sku'=>['Varenummer',64],'brand'=>['Merke',100],'model'=>['Modell',100],'size'=>['Dimensjon',64]] as $field=>[$label,$max])
<label>{{ $label }}<input name="{{ $field }}" required maxlength="{{ $max }}" value="{{ $restore?old($field):$product->$field }}"></label>
@endforeach
<label>Sesong<select name="season">@foreach(['summer'=>'Sommer','winter'=>'Vinter','all_season'=>'Helår'] as $value=>$label)<option value="{{ $value }}" @selected(($restore?old('season'):$product->season)===$value)>{{ $label }}</option>@endforeach</select></label>
<label>Pris inkl. mva.<input name="price" type="number" min="0" max="100000" step="0.01" required value="{{ $restore?old('price'):number_format($product->price_cents/100,2,'.','') }}"></label>
<label>Innkjøpspris<input name="cost" type="number" min="0" max="100000" step="0.01" value="{{ $restore?old('cost'):($product->cost_cents!==null?number_format($product->cost_cents/100,2,'.',''):'') }}"></label>
<label>På lager<input name="stock_quantity" type="number" min="0" max="100000" required value="{{ $restore?old('stock_quantity'):$product->stock_quantity }}"></label>
</div>
<label class="check"><input type="checkbox" name="studded" value="1" @checked($restore?old('studded'):$product->studded)> Piggdekk (vinter)</label>
<input type="hidden" name="active" value="0"><label class="check"><input type="checkbox" name="active" value="1" @checked($restore?old('active'):$product->active)> Tilgjengelig for nye tilbud</label>
<p class="fine">Fjern avkrysningen for å ta varen ut av nye tilbud. Tidligere tilbud og ordre beholdes. Varen kan aktiveres igjen her.</p>
<div class="dialog-actions"><button type="button" class="button ghost" data-edit-close>Avbryt</button><button class="button">Lagre endringer</button></div>
</form>
</x-edit-dialog>
@endforeach
