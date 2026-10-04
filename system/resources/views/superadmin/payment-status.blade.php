@php($invoice=$organization->stripe_latest_invoice)
@if($invoice)
<strong>{{ ($invoice['status']??'')==='paid' ? (($invoice['amount_paid']??0)>0?'Betalt':'Oppgjort – 0 kr') : (['open'=>'Ikke betalt','draft'=>'Utkast','void'=>'Annullert','uncollectible'=>'Ikke innkrevd'][$invoice['status']??'']??'Ukjent') }}</strong>
<small>{{ number_format(($invoice['amount_paid']??0)/100,2,',',' ') }} {{ strtoupper($invoice['currency']??'nok') }} innbetalt</small>
@if(($invoice['amount_remaining']??0)>0)<small>{{ number_format($invoice['amount_remaining']/100,2,',',' ') }} gjenstår</small>@endif
@else<strong>Ikke bekreftet</strong><small>{{ $organization->billing_model==='stripe'?'Hent status fra Stripe på kundekortet':'Venter på Stripe-oppsett' }}</small>@endif
@if($organization->stripe_synced_at)<small>Sist kontrollert {{ $organization->stripe_synced_at->format('d.m.Y H:i') }}</small>@endif
