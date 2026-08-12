<x-layouts.app title="Tilbud · DekkPilot" heading="Tilbud">
<div class="quotes-workspace">
    <section class="quote-overview panel">
        <div class="quote-overview-copy">
            <p class="eyebrow">SALG UTEN REGNEARK</p>
            <h2>Følg opp dekk som bør byttes</h2>
            <p>DekkPilot finner lav mønsterdybde, matcher riktig dimensjon mot lageret og lager gode alternativer. Du vurderer alltid forslaget før kunden kontaktes.</p>
        </div>
        <div class="quote-overview-flow" aria-label="Arbeidsflyt for tilbud">
            <span><b>1</b>Kontroller behov</span><i>→</i><span><b>2</b>Velg dekk</span><i>→</i><span><b>3</b>Send til kunden</span>
        </div>
    </section>

    <section class="stats quote-stats">
        <a href="#forslag"><span class="stat-icon orange">{{ $suggestions->count() }}</span><div><small>KREVER VURDERING</small><strong>{{ $suggestions->count() === 1 ? 'Ett forslag' : $suggestions->count().' forslag' }}</strong><em>Gå gjennom nå</em></div><b>→</b></a>
        <a href="#historikk"><span class="stat-icon blue">↗</span><div><small>UTESTÅENDE TILBUD</small><strong>{{ number_format($pipeline['sent']/100,0,',',' ') }} kr</strong><em>Sendt eller åpnet</em></div><b>→</b></a>
        <a href="#historikk"><span class="stat-icon green">✓</span><div><small>GODKJENT DENNE MÅNEDEN</small><strong>{{ number_format($pipeline['accepted']/100,0,',',' ') }} kr</strong><em>Bekreftet av kunder</em></div><b>→</b></a>
        <a href="#marked"><span class="stat-icon violet">◷</span><div><small>KOMMENDE BEHOV</small><strong>{{ $pipeline['future'] }} hjulsett</strong><em>Mellom 3 og 4 mm</em></div><b>→</b></a>
    </section>

    <div class="quote-content-grid">
        <section class="panel quote-inbox" id="forslag">
            <div class="panel-head quote-section-head">
                <div><p class="eyebrow">INNBOKS</p><h2>Forslag som bør vurderes</h2><p>Kun forslag med riktig sesong, dimensjon og minst fire dekk på lager vises.</p></div>
                @if($suggestions->count())<div class="quote-head-actions"><span class="quote-count">{{ $suggestions->count() }} nye</span><a class="button" href="{{ route('quotes.bulk.review') }}">Send til alle kunder</a></div>@endif
            </div>
            <div class="opportunity-list">
                @forelse($suggestions as $set)
                    @php($options=$matches->get($set->size.'|'.$set->season,collect())->take(3))
                    <a class="opportunity-row" href="{{ route('quotes.suggestion',$set) }}">
                        <span class="tread-alert">{{ $set->minimum_tread_depth }}<small>mm</small></span>
                        <div class="opportunity-customer"><strong>{{ $set->vehicle->registration_number }}</strong><span>{{ $set->vehicle->customer->name }}</span><small>{{ $set->size }} · {{ $set->season==='winter'?'Vinterdekk':'Sommerdekk' }} · {{ $set->storageLocation?->code ?? 'Mottak' }}</small></div>
                        <div class="match-count"><strong>{{ $options->count() }} treff på lager</strong><span>@if($options->isNotEmpty())Fra {{ number_format($options->min('price_cents')*4/100,0,',',' ') }} kr for 4 @endif</span></div>
                        <span class="opportunity-action">Vurder <b>→</b></span>
                    </a>
                @empty
                    <div class="quote-empty"><span>✓</span><div><strong>Alt er vurdert</strong><p>Nye forslag vises her når et hjulsett måles under 3 mm og du har dekk som passer på lager.</p></div></div>
                @endforelse
            </div>
            {{ $suggestions->links() }}
        </section>

        <aside class="quote-side">
            <section class="panel pipeline-card">
                <p class="eyebrow">MULIG PIPELINE</p><h2>{{ $pipeline['opportunities'] }} hjulsett trenger oppfølging</h2>
                <div class="pipeline-bars"><div><span>Sendt eller lest</span><strong>{{ number_format($pipeline['sent']/100,0,',',' ') }} kr</strong></div><div><span>Godkjent i måneden</span><strong>{{ number_format($pipeline['accepted']/100,0,',',' ') }} kr</strong></div></div>
                <p class="pipeline-note">Beløpet viser tilbud som fortsatt venter på kundens svar.</p>
            </section>
            <section class="panel future-card" id="marked"><span class="future-icon">◷</span><p class="eyebrow">FREMOVER</p><h2>{{ $pipeline['future'] }} hjulsett nærmer seg</h2><p>Disse er målt mellom 3 og 4 mm. De følges med på, men kunden kontaktes ikke for tidlig.</p></section>
        </aside>
    </div>

    <section class="panel quote-history" id="historikk">
        <div class="panel-head quote-section-head"><div><p class="eyebrow">HISTORIKK</p><h2>Sendte tilbud og kundesvar</h2><p>Se hva kunden har mottatt og hvor hvert tilbud står.</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Tilbud</th><th>Kunde og bil</th><th>Alternativer</th><th>Pris fra</th><th>Status</th></tr></thead><tbody>
        @forelse($quotes as $quote)
            <tr><td><strong>{{ $quote->reference }}</strong><small>{{ $quote->created_at->format('d.m.Y') }}</small></td><td><strong>{{ $quote->customer->name }}</strong><small>{{ $quote->vehicle?->registration_number }}</small></td><td>{{ $quote->items->count() }} {{ $quote->items->count() === 1 ? 'valg' : 'gode valg' }}<small>{{ Str::limit($quote->items->pluck('description')->join(' · '),90) }}</small></td><td><strong>{{ number_format(($quote->items->min('line_total_cents')??0)/100,0,',',' ') }} kr</strong></td><td><span class="status {{ $quote->status==='accepted'?'completed':($quote->status==='declined'?'cancelled':'in_progress') }}">{{ ['draft'=>'Kladd','sent'=>'Venter på svar','viewed'=>'Åpnet av kunden','accepted'=>'Godkjent','declined'=>'Avslått','expired'=>'Utløpt'][$quote->status] }}</span></td></tr>
        @empty <tr><td colspan="5"><div class="quote-history-empty"><strong>Ingen tilbud er sendt ennå</strong><span>Det første tilbudet dukker opp her så snart du sender et forslag.</span></div></td></tr>@endforelse
        </tbody></table></div>{{ $quotes->links() }}
    </section>
</div>
</x-layouts.app>
