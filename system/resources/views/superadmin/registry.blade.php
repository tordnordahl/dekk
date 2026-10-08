<section class="panel"><h2>Virksomhets- og registeropplysninger</h2>
@php($profile=$organization->profile??[])
<p>Forretningsadresse: {{ implode(', ',array_filter([$profile['address']??null,$profile['postal_code']??null,$profile['city']??null]))?:'Ikke registrert' }}<br>
Fakturaadresse: {{ implode(', ',array_filter([$profile['billing_address']??null,$profile['billing_postal_code']??null,$profile['billing_city']??null]))?:'Ikke registrert' }}<br>
Faktura-e-post: {{ $profile['billing_email']??'Ikke registrert' }}</p>
<p class="muted">Sist virksomhetsoppslag: {{ $organization->brreg_verified_at?->format('d.m.Y H:i')??'Ikke hentet' }}. Åpne virksomheten og velg Virksomhetsopplysninger for å hente en ny kopi.</p>
<p class="muted">Rolle- og fullmaktsdata vises bare her i superadmin. Registerets vilkår og kombinasjoner må leses samlet; mulige roller er ikke en bekreftelse på signeringsrett.</p>
@foreach(['roles'=>'Registrerte roller','signatur'=>'Signatur','prokura'=>'Prokura'] as $key=>$label)
@php($entry=data_get($organization->brreg_private_data,$key,[]))
<details><summary>{{ $label }} · {{ ($entry['available']??false)?'Hentet':'Ikke oppdatert' }}</summary>
@if(!empty($entry['fetched_at']))<p>Sist hentet: {{ Illuminate\Support\Carbon::parse($entry['fetched_at'])->format('d.m.Y H:i') }}</p>@endif
@if(!($entry['available']??false))<p class="muted">Ingen ny bekreftet kopi tilgjengelig. Eventuelle tidligere opplysninger nedenfor kan være utdaterte.</p>@endif
@if(!empty($entry['data']))@include('superadmin.registry-tree',['data'=>$entry['data']])@else<p>Ingen lagrede opplysninger.</p>@endif
</details>
@endforeach
</section>
