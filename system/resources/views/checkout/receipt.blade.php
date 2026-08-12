<!doctype html>
<html lang="nb">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kvittering {{ $receipt['number'] }}</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef3f0;color:#10231b;font:13px/1.4 Arial,sans-serif}.receipt{width:min(80mm,calc(100% - 24px));margin:28px auto;background:#fff;padding:8mm;box-shadow:0 10px 35px #173d2c22}.title{font-size:11px;font-weight:900;letter-spacing:.12em}.brand{font-size:19px;font-weight:900;margin-top:4px}.muted{color:#687970}.block{margin-top:15px;padding-top:12px;border-top:1px solid #dfe7e2}.label{display:block;color:#687970;font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}.lines{margin-top:14px}.line{padding:9px 0;border-bottom:1px solid #e1e8e4}.line-main,.row{display:flex;justify-content:space-between;gap:14px}.line-main strong:last-child,.row span:last-child{text-align:right}.line small{display:block;color:#687970;margin-top:2px}.totals{display:grid;gap:5px;margin-top:14px}.total{font-size:18px;font-weight:900;padding-top:7px;border-top:2px solid #10231b}.paid{margin:18px 0 0;padding:11px;background:#e2f5e9;color:#14633e;font-weight:900;text-align:center}.footer{margin-top:14px;font-size:10px;color:#687970;text-align:center}.warning{background:#fff0dc;color:#863f0d;padding:9px;margin-top:12px;font-weight:700}.print{display:block;width:min(80mm,calc(100% - 24px));margin:0 auto 28px;border:0;border-radius:10px;background:#16764c;padding:13px;color:#fff;font-weight:800;cursor:pointer}@media print{body{background:#fff}.receipt{width:80mm;margin:0;box-shadow:none}.print{display:none}@page{size:80mm auto;margin:0}}
</style>
<script src="{{ asset('receipt-print.js') }}" defer></script>
</head>
<body>
<article class="receipt">
  <div class="title">ELEKTRONISK SALGSKVITTERING</div>
  <div class="brand">{{ $receipt['seller_name'] }}</div>
  <div>{{ $receipt['number'] }}</div>
  @if($receipt['seller_org_number'])<div>Org.nr. {{ $receipt['seller_org_number'] }}{{ $receipt['seller_vat_registered'] ? ' MVA' : '' }}</div>@endif
  @if($receipt['seller_address'])<div class="muted">{{ $receipt['seller_address'] }}</div>@endif
  @if($receipt['seller_phone'] || $receipt['seller_email'])<div class="muted">{{ implode(' · ',array_filter([$receipt['seller_phone'],$receipt['seller_email']])) }}</div>@endif
  @unless($receipt['seller_org_number'])<div class="warning">Mangler selgers organisasjonsnummer. Oppdater virksomhetsopplysningene før kvitteringen utstedes.</div>@endunless

  <section class="block"><span class="label">Kjøper</span><strong>{{ $receipt['buyer_name'] }}</strong>
    @if($receipt['buyer_org_number'])<div>Org.nr. {{ $receipt['buyer_org_number'] }}</div>@endif
    @if($receipt['buyer_address'])<div class="muted">{{ $receipt['buyer_address'] }}</div>@endif
  </section>
  <section class="block">
    <div class="row"><span>Salgstidspunkt</span><span>{{ $receipt['sale_at']?->format('d.m.Y H:i') }}</span></div>
    <div class="row"><span>Levering</span><span>{{ $receipt['delivery_at']?->format('d.m.Y H:i') }}</span></div>
    <div class="row"><span>Leveringssted</span><span>{{ $receipt['delivery_place'] ?: 'Verkstedet' }}</span></div>
    <div class="row"><span>Ordre</span><span>{{ $receipt['booking_reference'] }}</span></div>
    @if($receipt['registration_number'])<div class="row"><span>Reg.nr.</span><span>{{ $receipt['registration_number'] }}</span></div>@endif
  </section>
  <section class="lines">
    @foreach($receipt['lines'] as $line)
      <div class="line"><div class="line-main"><strong>{{ $line['description'] }}</strong><strong>{{ number_format($line['gross_cents']/100,2,',',' ') }} kr</strong></div><small>{{ rtrim(rtrim(number_format($line['quantity'],2,',',''),'0'),',') }} stk · inkl. {{ number_format($line['vat_rate'],0,',',' ') }} % MVA</small></div>
    @endforeach
  </section>
  <section class="totals">
    @foreach($receipt['vat_groups'] as $vat)<div class="row"><span>Grunnlag {{ number_format($vat['rate'],0,',',' ') }} %</span><span>{{ number_format($vat['net_cents']/100,2,',',' ') }} kr</span></div><div class="row"><span>MVA {{ number_format($vat['rate'],0,',',' ') }} %</span><span>{{ number_format($vat['vat_cents']/100,2,',',' ') }} kr</span></div>@endforeach
    <div class="row"><span>Netto</span><span>{{ number_format($receipt['net_cents']/100,2,',',' ') }} kr</span></div>
    <div class="row total"><span>Betalt</span><span>{{ number_format($receipt['total_cents']/100,2,',',' ') }} {{ $receipt['currency'] }}</span></div>
  </section>
  <section class="block"><div class="row"><span>Betalingsmåte</span><span>{{ $receipt['payment_method'] }}</span></div><div class="row"><span>Transaksjon</span><span>{{ $receipt['transaction_reference'] }}</span></div><div class="row"><span>Forfall</span><span>Betalt ved kjøp</span></div></section>
  <div class="paid">✓ BETALT</div><div class="footer">Ta vare på kvitteringen som dokumentasjon på kjøpet.</div>
</article>
<button class="print" type="button" data-print-receipt>Skriv ut kvittering</button>
</body></html>
