<x-layouts.app title="Tilbud til {{ $tireSet->vehicle->registration_number }} · DekkPilot" heading="Lag tilbud">
<div class="suggestion-page">
    <a class="quote-back" href="{{ route('quotes') }}">← Tilbake til alle tilbud</a>
    <section class="suggestion-head panel">
        <div class="suggestion-vehicle"><p class="eyebrow">{{ $tireSet->vehicle->customer->name }}</p><h2>{{ $tireSet->vehicle->registration_number }}</h2><div class="vehicle-facts"><span>{{ $tireSet->size }}</span><span>{{ $tireSet->season==='winter'?'Vinterdekk':'Sommerdekk' }}</span><span>{{ $tireSet->storageLocation?->code ?? 'Mottak' }}</span></div></div>
        <div class="measurement"><span class="tread-alert large">{{ $tireSet->replacement_reasons===['age'] ? $tireSet->age_years : $tireSet->minimum_tread_depth }}<small>{{ $tireSet->replacement_reasons===['age'] ? 'år' : 'mm' }}</small></span><div><strong>Bør byttes</strong><small>{{ $tireSet->replacement_reasons===['age'] ? 'Alder · DOT '.$tireSet->dot_year : 'Mønsterdybde'.($tireSet->age_assessment==='replace'?' og alder · DOT '.$tireSet->dot_year:'') }}</small></div></div>
    </section>

    <form method="post" action="{{ route('quotes.suggestion.send',$tireSet) }}" class="suggestion-form" data-quote-preview-form data-preview-url="{{ route('quotes.suggestion.preview',$tireSet) }}">@csrf
        <section class="suggestion-section-head"><div><span class="step-number">1</span><div><p class="eyebrow">VELG ALTERNATIVER</p><h2>Hva vil du vise kunden?</h2><p>Alle valgene passer bilen og finnes på lager. Det anbefalte alternativet vises først.</p></div></div><span>Velg 1–3</span></section>
        <section class="option-grid">
        @foreach($options as $product)
            @php($label=['Det beste','Bra valg','Godt valg'][$loop->index])
            <label class="tire-option {{ $loop->first?'recommended':'' }}">
                <input type="checkbox" name="product_ids[]" value="{{ $product->id }}" checked>
                <span class="option-check" aria-hidden="true">✓</span>
                <div class="option-top"><span class="option-label">{{ $label }}</span>@if($loop->first)<span class="recommended-note">Anbefalt</span>@endif</div>
                <h2>{{ $product->brand }}</h2><h3>{{ $product->model }}</h3>
                <p class="option-meta">{{ $product->size }} · {{ $product->studded?'Piggdekk':($product->season==='winter'?'Vinterdekk':'Sommerdekk') }}</p>
                <div class="option-copy">@if($loop->first)Et solid premiumvalg for kunden som ønsker det beste alternativet vi har tilgjengelig.@elseif($loop->index===1)Et svært bra valg med god balanse mellom kvalitet og pris.@else Et godt og trygt valg som dekker behovet til en konkurransedyktig pris.@endif</div>
                <div class="option-bottom"><strong class="option-price">{{ number_format($product->price_cents*4/100,0,',',' ') }} kr <small>for 4 dekk</small></strong><span class="stock-ok">✓ {{ $product->stock_quantity }} på lager</span></div>
            </label>
        @endforeach
        </section>

        <section class="panel send-review">
            <div class="send-review-main"><div class="suggestion-section-head compact"><div><span class="step-number">2</span><div><p class="eyebrow">GJØR TILBUDET PERSONLIG</p><h2>Skriv en kort melding</h2></div></div></div><label>Personlig melding til kunden<textarea name="message" rows="4" maxlength="2000" placeholder="Vi har kontrollert dekkene dine og anbefaler at de byttes før neste sesong."></textarea><small>Valgfritt. Kunden ser meldingen øverst i tilbudet.</small></label></div>
            <aside class="send-summary"><p class="eyebrow">KLART TIL UTSENDING</p><h3>Kunden mottar</h3><ul><li>Mønsterdybde, dekkalder og tydelig begrunnelse</li><li>Valgte dekk med totalpris</li><li>Mulighet til å godkjenne eller avslå</li></ul><p class="security-note">Ingen bestilling gjøres før kunden selv godkjenner.</p><button type="button" class="button ghost full preview-quote-button" data-preview-quote>Forhåndsvis e-post</button><button class="button full">Send tilbud til kunden <span>→</span></button></aside>
        </section>
    </form>
    <dialog class="email-preview-dialog" data-quote-preview aria-labelledby="quote-preview-title"><div class="email-preview-head"><div><p class="eyebrow">SLIK SER KUNDEN E-POSTEN</p><h2 id="quote-preview-title">Forhåndsvis tilbud</h2></div><button type="button" class="email-preview-close" data-quote-preview-close aria-label="Lukk">×</button></div><div class="email-preview-state" data-quote-preview-state>Laster forhåndsvisning …</div><iframe title="Forhåndsvisning av tilbudse-post" data-quote-preview-frame></iframe><div class="email-preview-footer"><button type="button" class="button ghost" data-quote-preview-close>Tilbake og rediger</button></div></dialog>
</div>
</x-layouts.app>
