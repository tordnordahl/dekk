<!doctype html><html lang="nb"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Aktiver abonnement · DekkPilot</title>
<link rel="stylesheet" href="{{ asset('app.css') }}"><link rel="stylesheet" href="{{ route('system.asset.query',['filename'=>'billing-gate.css']) }}?v=20261004-1"></head>
<body class="billing-gate-page"><div class="billing-backdrop" aria-hidden="true"><span>DekkPilot</span><div></div><div></div><div></div></div>
<main class="billing-gate" role="dialog" aria-modal="true" aria-labelledby="billing-gate-title" tabindex="-1">
@if(session('success'))<div class="flash" role="status">{{ session('success') }}</div>@endif
@if($errors->any())<div class="errors" role="alert"><strong>Betalingen kunne ikke fullføres</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@if(request()->attributes->has('subscription_notices'))
@php(request()->attributes->set('subscription_notices_rendered', true))
@foreach(request()->attributes->get('subscription_notices') as $notice)<div class="flash" role="status">Du har fått {{ $notice->months == 1 ? 'én gratis måned' : $notice->months.' gratis måneder' }} med DekkPilot! Rabatten vises hos Stripe. SMS er ikke inkludert.</div>@endforeach
@endif
{{ $slot }}
</main></body></html>
