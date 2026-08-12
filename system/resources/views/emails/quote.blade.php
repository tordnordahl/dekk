<!doctype html>
<html lang="nb"><body style="margin:0;background:#eef2ef;font-family:Arial,sans-serif;color:#15201b">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:30px 12px">
<table role="presentation" width="620" cellpadding="0" cellspacing="0" style="width:100%;max-width:620px;background:#fff;border-radius:20px;overflow:hidden">
<tr><td style="padding:26px 32px;background:#173a2b;color:#fff"><table width="100%"><tr><td><strong style="font-size:22px">{{ $quote->organization?->name ?? 'DekkPilot' }}</strong><br><span style="font-size:11px;color:#a9cbb9">Personlig dekkanbefaling</span></td><td align="right"><span style="display:inline-block;padding:7px 10px;background:#ffffff18;border-radius:20px;font-size:11px">{{ $quote->reference }}</span></td></tr></table></td></tr>
<tr><td style="padding:32px">
<p style="margin:0 0 7px;color:#2a8b59;font-size:11px;font-weight:bold;text-transform:uppercase;letter-spacing:1px">Tilbud til {{ $quote->vehicle?->registration_number }}</p>
<h1 style="margin:0 0 15px;font-size:28px;line-height:1.15">Hei {{ explode(' ',$quote->customer->name)[0] }}, vi har funnet gode alternativer</h1>
<p style="margin:0 0 22px;color:#536159;line-height:1.6">Vi har kontrollert hjulene dine og matchet riktig dimensjon mot dekk vi har tilgjengelig.</p>
@if($quote->sourceTireSet)
<div style="padding:15px 17px;background:#fff3eb;border-left:4px solid #d56634;border-radius:9px;margin-bottom:24px">
    <strong>Hvorfor vi anbefaler nye dekk</strong><br>
    @if($quote->sourceTireSet->minimum_tread_depth !== null)
        <span style="font-size:12px;color:#6b5549">Laveste målte mønsterdybde er {{ $quote->sourceTireSet->minimum_tread_depth }} mm. Minstekravet for {{ $quote->sourceTireSet->season==='winter'?'vinterføre':'sommerføre' }} er {{ $quote->sourceTireSet->season==='winter'?'3':'1,6' }} mm for personbil og lette kjøretøy.</span><br>
    @endif
    @if($quote->sourceTireSet->age_assessment === 'replace')
        <span style="font-size:12px;color:#6b5549">Dekkene er produsert i {{ $quote->sourceTireSet->dot_year }} og er omtrent {{ $quote->sourceTireSet->age_years }} år gamle. Vi anbefaler utskifting på grunn av alder, også dersom mønsterdybden fortsatt ser god ut.</span><br>
    @elseif($quote->sourceTireSet->age_assessment === 'inspect')
        <span style="font-size:12px;color:#6b5549">Dekkene er produsert i {{ $quote->sourceTireSet->dot_year }}. Dekk fra fem års alder bør kontrolleres ekstra nøye av fagperson.</span><br>
    @endif
    <span style="font-size:12px;color:#6b5549">Dekkets tilstand, skader og kjøretøyprodusentens anbefalinger inngår alltid i den endelige faglige vurderingen.</span>
</div>
@endif
@if($quote->message)<div style="padding:15px 17px;background:#edf7f1;border-radius:9px;margin-bottom:22px;line-height:1.5">{{ $quote->message }}</div>@endif
<p style="margin:0 0 10px;font-size:11px;font-weight:bold;color:#78857e;text-transform:uppercase;letter-spacing:.8px">Dine alternativer</p>
@foreach($quote->items->sortBy('position') as $item)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:10px;border:1px solid {{ $loop->first?'#66b88b':'#e1e7e3' }};border-radius:12px;background:{{ $loop->first?'#f2faf5':'#fff' }}"><tr><td style="padding:15px"><span style="display:inline-block;margin-bottom:5px;padding:3px 7px;border-radius:12px;background:{{ $loop->first?'#d9f1e4':'#edf1ef' }};color:#176541;font-size:9px;font-weight:bold;text-transform:uppercase">{{ $item->recommendation_label ?: 'Godt valg' }}</span><br><strong style="font-size:15px">{{ $item->description }}</strong><br><span style="font-size:11px;color:#718078">{{ $item->quantity }} dekk · {{ number_format($item->unit_price_cents/100,2,',',' ') }} kr per stk.</span></td><td align="right" style="padding:15px;white-space:nowrap"><strong style="font-size:17px">{{ number_format($item->line_total_cents/100,2,',',' ') }} kr</strong><br><span style="font-size:10px;color:#718078">inkl. mva.</span></td></tr></table>
@endforeach
<div style="margin:27px 0;text-align:center"><a href="{{ $responseUrl }}" style="display:inline-block;background:#167047;color:#fff;padding:15px 23px;border-radius:10px;text-decoration:none;font-weight:bold">Se, velg og bekreft tilbudet →</a></div>
<p style="margin:0;padding:13px;background:#f3f5f4;border-radius:9px;color:#67746d;font-size:11px;text-align:center">Du velger dekket og godtar kjøpsvilkårene på den sikre tilbudssiden. Ingenting bestilles direkte fra denne e-posten.</p>
<p style="font-size:11px;color:#7a867f;margin:24px 0 0;text-align:center">Den personlige lenken er gyldig til {{ $quote->expires_at->format('d.m.Y') }} og skal ikke videresendes.</p>
</td></tr></table><p style="font-size:10px;color:#829087;margin:14px">Sendt av {{ $quote->organization?->name ?? 'DekkPilot' }} via DekkPilot</p>
</td></tr></table></body></html>
