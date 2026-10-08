<link rel="stylesheet" href="{{ route('system.asset.query',['filename'=>'edit-dialog.css','v'=>'20261008']) }}">
<script defer src="{{ route('system.asset.query',['filename'=>'edit-dialog.js','v'=>'20261008']) }}"></script>
<section class="panel"><div class="panel-head"><div><p class="eyebrow">PRODUKTKATALOG</p><h2>Dekk og priser</h2></div><div style="display:flex;flex-wrap:wrap;gap:8px"><button type="button" class="button ghost" data-edit-open="tire-brands">Dekkmerker</button><button type="button" class="button ghost" data-edit-open="create-product">＋ Legg til dekk</button></div></div>
<form method="get" action="{{ route('admin.settings') }}" class="product-search" data-product-search>
<input type="hidden" name="tab" value="products"><input name="product_q" type="search" maxlength="100" value="{{ is_string(request('product_q'))?request('product_q'):'' }}" placeholder="Søk på varenummer, merke, modell eller dimensjon" aria-label="Søk etter dekkvarer"><button class="button ghost">Søk</button>
</form><p class="product-search-status" role="status" aria-live="polite" data-product-search-status></p>
<div class="product-results" data-product-results aria-busy="false">@include('admin.settings.product-results')</div>
</section>
<x-edit-dialog id="create-product" title="Legg til dekkvare">
<form method="post" action="{{ route('admin.products.store') }}" class="stack">@csrf<label>Varenummer<input name="sku" required></label><label>Merke<select name="brand" required><option value="">Velg dekkmerke</option>@foreach($tireBrands as $brand)<option value="{{ $brand }}" @selected(old('brand')===$brand)>{{ $brand }}</option>@endforeach</select></label><label>Modell<input name="model" required></label><label>Dimensjon<input name="size" placeholder="205/55 R16" required></label><label>Sesong<select name="season"><option value="summer">Sommer</option><option value="winter">Vinter</option><option value="all_season">Helår</option></select></label><label>Pris inkl. mva.<input type="number" name="price" step="0.01" min="0" required></label><label>Innkjøpspris<input type="number" name="cost" step="0.01" min="0"></label><label>På lager<input type="number" name="stock_quantity" min="0" value="0" required></label><label class="check"><input type="checkbox" name="studded" value="1"> Piggdekk</label><button class="button">Legg til dekk</button></form>
</x-edit-dialog>

<x-edit-dialog id="tire-brands" title="Dekkmerker" bag="tireBrands" :reopen="session('brand_catalog_open',false)">
<p class="muted">Kjente dekkmerker er tilgjengelige for alle. Egne merker legges bare til for din virksomhet. Merker på eksisterende varer er også med i listen.</p>
<form method="post" action="{{ route('admin.brands.store') }}" class="stack">@csrf
<label>Nytt dekkmerke<input name="brand_name" maxlength="100" required value="{{ old('brand_name') }}" placeholder="Navn på dekkmerke"></label>
<button class="button">Legg til merke</button></form>
<p class="muted">Endringer gjelder bare din virksomhet. Ved navneendring oppdateres dekkvarene som bruker merket. Sletting fjerner merket fra nye valg, men beholder varer og historikk.</p>
<style>.brand-list{margin-top:18px}.brand-entry{border-top:1px solid #e5e9e6;padding:12px 0}.brand-entry summary{display:flex;justify-content:space-between;align-items:center;gap:12px;cursor:pointer;color:#245a43}.brand-entry summary span{font-size:12px;font-weight:500}.brand-entry summary strong{overflow-wrap:anywhere}.brand-entry[open]{padding-bottom:20px}</style>
<div class="brand-list">
@foreach($tireBrands as $brand)
<details class="brand-entry"><summary><strong>{{ $brand }}</strong><span>Rediger / slett</span></summary>
<form method="post" action="{{ route('admin.brands.change') }}" class="stack">@csrf @method('PATCH')
<input type="hidden" name="action" value="rename"><input type="hidden" name="original_name" value="{{ $brand }}">
<label>Navn<input name="brand_name" required maxlength="100" value="{{ $brand }}"></label>
<button class="button ghost">Lagre navn</button></form>
<form method="post" action="{{ route('admin.brands.change') }}" class="stack">@csrf @method('PATCH')
<input type="hidden" name="action" value="delete"><input type="hidden" name="original_name" value="{{ $brand }}">
<label class="check"><input type="checkbox" name="confirm" value="1" required> Fjern merket fra listen for nye varer</label>
<button class="button ghost">Slett merke</button></form>
</details>
@endforeach
</div>
</x-edit-dialog>
