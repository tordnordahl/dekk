<!doctype html><html lang="nb"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>Avtale {{ $agreement->version }} · DekkPilot</title><link rel="stylesheet" href="{{ route('system.asset.query',['filename'=>'legal.css']) }}"><style>.agreement-text{white-space:pre-line;overflow-wrap:anywhere} @media print{.legal-topbar,.legal-footer{display:none}.legal-document{box-shadow:none}}</style></head><body class="legal-page">
<header class="legal-topbar"><div class="legal-topbar-inner"><a class="legal-brand" href="{{ url('/') }}">DekkPilot</a><span>Bruksvilkår og databehandleravtale</span></div></header>
<main class="legal-wrap"><section class="legal-hero"><p class="legal-kicker">{{ $agreement->version }} · {{ $agreement->published_at->timezone('Europe/Oslo')->format('d.m.Y H:i') }}</p><h1>Avtale for DekkPilot</h1><p>For virksomheter. Denne versjonen bevares uendret og kan lagres som PDF fra nettleserens utskriftsmeny.</p></section>
<article class="legal-document">
<h2>Partene og leverandørens godkjenning</h2>
<p><strong>{{ $agreement->content['supplier_name'] }}</strong>, org.nr. {{ $agreement->content['supplier_number'] }}<br>{{ $agreement->content['supplier_address'] }}<br><a href="mailto:{{ $agreement->content['supplier_email'] }}">{{ $agreement->content['supplier_email'] }}</a></p>
<p>Leverandøren har forhåndsgodkjent denne avtalen ved {{ $agreement->content['signer_name'] }}, {{ $agreement->content['signer_title'] }}, på publiseringstidspunktet ovenfor. Kunden er virksomheten angitt ved registrering eller godkjenning i portalen. Avtalen inngås når kundens representant med nødvendig fullmakt aktivt krysser av og sender inn godkjenningen. Navn, virksomhet, tidspunkt og versjon dokumenteres elektronisk. Dette er ikke en BankID-signering eller kontroll av signaturrett.</p>
<h2>Om denne versjonen</h2><p class="agreement-text">{{ $agreement->content['summary'] }}</p>
<h2>Bruksvilkår</h2><div class="agreement-text">{{ $agreement->content['terms'] }}</div>
<h2 id="databehandleravtale">Databehandleravtale</h2><div class="agreement-text">{{ $agreement->content['dpa'] }}</div>
<h2>Vedlegg A: Underleverandører og behandlingssteder</h2><div class="agreement-text">{{ $agreement->content['processors'] }}</div>
<h2>Vedlegg B: Avslutning, eksport og sletting</h2><div class="agreement-text">{{ $agreement->content['deletion'] }}</div>
<p class="agreement-text"><small>Dokumentets kontrollsum (SHA-256): {{ $agreement->sha256 }}</small></p>
</article><footer class="legal-footer"><span>DekkPilot · {{ $agreement->version }}</span><a href="{{ route('legal.privacy') }}">Personvernerklæring</a></footer></main></body></html>
