<link rel="stylesheet" href="{{ route('system.asset.query',['filename'=>'edit-dialog.css','v'=>'20261008']) }}">
<script defer src="{{ route('system.asset.query',['filename'=>'edit-dialog.js','v'=>'20261008']) }}"></script>
<section class="panel"><div class="panel-head"><div><p class="eyebrow">PRODUKTKATALOG</p><h2>Dekk og priser</h2></div><button type="button" class="button ghost" data-edit-open="create-product">＋ Legg til dekk</button></div>
<form method="get" action="{{ route('admin.settings') }}" class="product-search" data-product-search>
<input type="hidden" name="tab" value="products"><input name="product_q" type="search" maxlength="100" value="{{ is_string(request('product_q'))?request('product_q'):'' }}" placeholder="Søk på varenummer, merke, modell eller dimensjon" aria-label="Søk etter dekkvarer"><button class="button ghost">Søk</button>
</form><p class="product-search-status" role="status" aria-live="polite" data-product-search-status></p>
<div class="product-results" data-product-results aria-busy="false">@include('admin.settings.product-results')</div>
</section>
<x-edit-dialog id="create-product" title="Legg til dekkvare">
<form method="post" action="{{ route('admin.products.store') }}" class="stack">@csrf<label>Varenummer<input name="sku" required></label><label>Merke<input name="brand" required></label><label>Modell<input name="model" required></label><label>Dimensjon<input name="size" placeholder="205/55 R16" required></label><label>Sesong<select name="season"><option value="summer">Sommer</option><option value="winter">Vinter</option><option value="all_season">Helår</option></select></label><label>Pris inkl. mva.<input type="number" name="price" step="0.01" min="0" required></label><label>Innkjøpspris<input type="number" name="cost" step="0.01" min="0"></label><label>På lager<input type="number" name="stock_quantity" min="0" value="0" required></label><label class="check"><input type="checkbox" name="studded" value="1"> Piggdekk</label><button class="button">Legg til dekk</button></form>
</x-edit-dialog>
