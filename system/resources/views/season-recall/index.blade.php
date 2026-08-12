<x-layouts.app title="Sesonginnkalling · DekkPilot" heading="Sesonginnkalling">
<link rel="stylesheet" href="{{ route('system.asset', ['filename' => 'season-recall.css']) }}?v=20260812-2">
<section class="recall-hero panel">
    <div><p class="eyebrow">FYLL TIMEBOKEN SMART</p><h2>Kall inn hotellkundene til sesongskift</h2><p>Velg sesong, kontroller mottakerne og legg personlige invitasjoner i e-post- og SMS-køen. Kunden bestiller selv fra kundeportalen.</p></div>
    <div class="recall-total"><strong>{{ $customers->count() }}</strong><span>aktuelle kunder</span></div>
</section>
<form method="get" class="recall-season panel"><strong>Hvilken sesong skal klargjøres?</strong><div><a class="{{ $season==='summer'?'active':'' }}" href="{{ route('season-recall.index',['season'=>'summer']) }}">☀ Sommerhjul</a><a class="{{ $season==='winter'?'active':'' }}" href="{{ route('season-recall.index',['season'=>'winter']) }}">❄ Vinterhjul</a></div></form>
<div class="recall-layout">
    <section class="panel"><div class="panel-head"><div><p class="eyebrow">MOTTAKERKONTROLL</p><h2>{{ $customers->count() }} hotellkunder</h2><p>Én invitasjon per kunde, også når kunden har flere biler.</p></div></div>
        <div class="recall-list">@forelse($customers as $customer)<article><span>{{ mb_substr($customer->name,0,1) }}</span><div><strong>{{ $customer->name }}</strong><small>{{ $customer->eligible_vehicles_count }} {{ $customer->eligible_vehicles_count===1?'bil':'biler' }} · {{ $customer->email ?: 'mangler e-post' }} · {{ $customer->phone ?: 'mangler mobil' }}</small></div></article>@empty<div class="empty-state"><strong>Ingen aktuelle hotellkunder</strong><span>Kontroller at hotellavtalene er aktive og at hjulsettene har riktig sesong.</span></div>@endforelse</div>
    </section>
    <aside class="panel recall-send"><p class="eyebrow">KLAR TIL UTSENDING</p><h2>Start innkalling</h2><div class="recall-counts"><span><strong>{{ $emailCount }}</strong>E-post</span><span><strong>{{ $smsCount }}</strong>SMS</span></div>
        @if($canSend)<form method="post" action="{{ route('season-recall.send') }}" class="stack">@csrf<input type="hidden" name="season" value="{{ $season }}"><fieldset><legend>Kanaler</legend><label class="check"><input type="checkbox" name="channels[]" value="email" checked> Send e-post</label><label class="check {{ !$smsAvailable?'disabled':'' }}"><input type="checkbox" name="channels[]" value="sms" @disabled(!$smsAvailable)> Send SMS <small>{{ $smsAvailable?'SMS faktureres etter bruk.':'Twilio må konfigureres først.' }}</small></label></fieldset><div class="recall-preview"><strong>Kunden mottar</strong><p>En personlig lenke til kundeportalen med ledige timer og mulighet til å bestille sesongskift.</p></div><label class="check confirm"><input type="checkbox" name="confirm" value="1" required> Jeg har kontrollert sesong og mottakere.</label><button class="button full" @disabled($customers->isEmpty())>Legg innkallingene i kø →</button></form>@else<div class="notice">Du kan kontrollere mottakerlisten. Eier, administrator eller avdelingsleder må starte utsendingen.</div>@endif
    </aside>
</div>
</x-layouts.app>
