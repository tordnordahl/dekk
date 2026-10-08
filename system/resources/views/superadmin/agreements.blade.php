<x-layouts.app title="Avtaler · DekkPilot" heading="Avtaler og godkjenning">
<link rel="stylesheet" href="{{ route('system.asset.query',['filename'=>'superadmin-customer.css','v'=>'20261008']) }}">
<div class="admin-subnav"><a href="{{ route('superadmin') }}">← Alle kunder</a>@if($agreement)<a href="{{ route('legal.agreement',$agreement) }}" target="_blank" rel="noopener">Les gjeldende avtale · {{ $agreement->version }} ↗</a>@endif</div>
<section class="panel agreement-editor"><h2>{{ $agreement?'Publiser en ny versjon':'Klargjør den første avtalen' }}</h2>
<p>Publisering lagrer en uforanderlig kopi og krever ny godkjenning fra eier eller administrator i alle ordinære virksomheter. Ansatte og demomiljøet stoppes ikke. Tidligere godkjenninger beholdes på kundekortet.</p>
@if(!$agreement)<div class="usage-note"><strong>Utkast – ikke aktivert</strong><span>Fyll inn leverandør, underleverandører, behandlingssteder og slettefrister. Kontroller teksten mot faktisk drift og få juridisk kvalitetssikring før publisering. Eksisterende registreringsvilkår gjelder frem til da.</span></div>@endif
<form method="post" action="{{ route('superadmin.agreements.publish') }}" class="stack">@csrf
<input type="hidden" name="base_version" value="{{ $agreement?->id??0 }}">
@php($content=$agreement?->content??$draft)
<details open><summary><strong>Leverandør og forhåndsgodkjenning</strong></summary><div class="fields">
@foreach(['supplier_name'=>'Juridisk selskapsnavn','supplier_number'=>'Organisasjonsnummer (9 sifre)','supplier_address'=>'Adresse','supplier_email'=>'Kontakt-e-post'] as $key=>$label)
<label>{{ $label }}<input name="{{ $key }}" value="{{ old($key,$content[$key]??'') }}" required maxlength="{{ $key==='supplier_address'?500:255 }}" @if($key==='supplier_email') type="email" @endif></label>
@endforeach
</div></details>
<label>Hva er nytt? Kort forklaring til kunden<textarea name="summary" rows="3" maxlength="1500" required>{{ old('summary',$content['summary']) }}</textarea></label>
@foreach(['terms'=>'Bruksvilkår','dpa'=>'Databehandleravtale','processors'=>'Vedlegg A: Underleverandører og behandlingssteder','deletion'=>'Vedlegg B: Avslutning, eksport og sletting'] as $key=>$label)
<details @if($errors->has($key)) open @endif><summary><strong>{{ $label }}</strong></summary>
@if($key==='processors')<p>Oppgi juridisk navn, oppgave, typer data, land for lagring/tilgang og eventuelt grunnlag for overføring utenfor EØS. Skill underdatabehandlere fra selvstendige behandlingsansvarlige og integrasjoner kunden bestiller selv. Listen må samsvare med faktisk drift.</p>@endif
@if($key==='deletion')<p>Avklar frist og prosess for eksport og sletting av aktive data etter opphør, eventuelle lovpålagte unntak og backuper. Backuper oppbevares maksimalt 14 dager; dette er ikke en automatisk slettefrist for aktive kundedata.</p>@endif
<textarea aria-label="{{ $label }}" name="{{ $key }}" rows="{{ in_array($key,['terms','dpa'])?22:8 }}" required>{{ old($key,$content[$key]??'') }}</textarea></details>
@endforeach
<label class="check"><input type="checkbox" name="confirm" value="1" required><span>Jeg har fullmakt til å binde leverandøren, har kontrollert innholdet og forhåndsgodkjenner avtalen på leverandørens vegne. Jeg vil publisere denne versjonen og be kundene godta den.</span></label>
<button class="button">Forhåndsgodkjenn og publiser avtalen</button>
<p class="muted">Dette er dokumentert elektronisk aksept, ikke BankID-signering. Endringer kan kreve forhåndsvarsel eller annen oppfølging; publisering her erstatter ikke slike plikter.</p>
</form></section></x-layouts.app>
