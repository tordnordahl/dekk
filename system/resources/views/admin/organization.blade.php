<x-layouts.app title="Virksomheten · DekkPilot" heading="Virksomheten">
<div class="admin-subnav"><a href="{{ route('admin') }}">← Administrasjon</a><a href="{{ route('billing') }}">DekkPilot-abonnement</a></div>
@php($profile=$organization->profile??app(App\Services\BrregService::class)->profile($organization->brreg_data??[]))
<section class="panel"><p class="eyebrow">VIRKSOMHETSOPPLYSNINGER</p><h2>{{ $organization->name }}</h2><p class="muted">Org.nr. {{ $organization->organization_number?:'Ikke registrert' }} · Sist hentet fra Brreg: {{ $organization->brreg_verified_at?->format('d.m.Y H:i')??'Ikke hentet' }}</p>
<form method="post" action="{{ route('admin.organization.update') }}" class="stack">@csrf @method('PUT')
<div class="fields"><label>Virksomhetsnavn<input name="name" value="{{ old('name',$organization->name) }}" required maxlength="255"></label><label>Nettside<input name="profile[website]" value="{{ old('profile.website',$profile['website']??'') }}" maxlength="255"></label><label>E-post<input type="email" name="email" value="{{ old('email',$organization->email) }}" maxlength="255"></label><label>Telefon<input name="phone" value="{{ old('phone',$organization->phone) }}" maxlength="32"></label></div>
@foreach(['business'=>['Besøks- / forretningsadresse','address','postal_code','city','country'],'postal'=>['Postadresse','postal_address','postal_postal_code','postal_city','postal_country'],'billing'=>['Fakturaopplysninger','billing_address','billing_postal_code','billing_city','billing_country']] as $group=>[$heading,$address,$code,$city,$country])
<fieldset style="border:0;padding:0;margin:12px 0"><legend><h3>{{ $heading }}</h3></legend>
@if($group==='billing')<p class="muted">Brreg oppgir postadresse, ikke fakturaadresse. Opplysningene her lagres i DekkPilot. Endringer hos Stripe eller regnskapsleverandøren gjøres i deres betalingsoppsett.</p>@endif
<label>Adresse<input name="profile[{{ $address }}]" value="{{ old('profile.'.$address,$profile[$address]??'') }}" maxlength="500"></label>
<div class="fields"><label>Postnummer<input name="profile[{{ $code }}]" value="{{ old('profile.'.$code,$profile[$code]??'') }}" maxlength="20"></label><label>Poststed<input name="profile[{{ $city }}]" value="{{ old('profile.'.$city,$profile[$city]??'') }}" maxlength="100"></label><label>Landkode<input name="profile[{{ $country }}]" value="{{ old('profile.'.$country,$profile[$country]??'NO') }}" maxlength="2" placeholder="NO"></label></div>
@if($group==='billing')<div class="fields"><label>Faktura-e-post<input type="email" name="profile[billing_email]" value="{{ old('profile.billing_email',$profile['billing_email']??'') }}" maxlength="255"></label><label>Fakturareferanse<input name="profile[billing_reference]" value="{{ old('profile.billing_reference',$profile['billing_reference']??'') }}" maxlength="100"></label></div>@endif
</fieldset>
@endforeach
<div><button class="button">Lagre virksomhetsopplysninger</button></div></form></section>
<section class="panel"><h2>Opplysninger fra Brønnøysundregistrene</h2>
@if($organization->brreg_data)<p>{{ data_get($organization->brreg_data,'name') }} · {{ data_get($organization->brreg_data,'organization_type_name') }}</p><p>Forretningsadresse: {{ implode(', ',array_filter([data_get($organization->brreg_data,'address'),data_get($organization->brreg_data,'postal_code'),data_get($organization->brreg_data,'city')]))?:'Ikke oppgitt' }}<br>Postadresse: {{ implode(', ',array_filter([data_get($organization->brreg_data,'postal_address'),data_get($organization->brreg_data,'postal_postal_code'),data_get($organization->brreg_data,'postal_city')]))?:'Ikke oppgitt' }}</p>@endif
<p class="muted">Hent en ny kopi fra registeret. Velg hvilke felt i skjemaet som også skal oppdateres. Tomme registerfelt overskriver ikke dine opplysninger. Lagre eventuelle endringer i skjemaet først.</p>
<form method="post" action="{{ route('admin.organization.brreg') }}" class="stack">@csrf
@foreach(['name'=>'Virksomhetsnavn','business'=>'Forretningsadresse','postal'=>'Postadresse','phone'=>'Telefon'] as $value=>$label)<label class="check"><input type="checkbox" name="apply[]" value="{{ $value }}"> Oppdater {{ mb_strtolower($label) }} fra Brreg</label>@endforeach
<div><button class="button ghost">Hent fra Brreg</button></div></form>
</section>
</x-layouts.app>
