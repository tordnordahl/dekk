@if($currentAgreement)
<p><strong>{{ $currentAcceptance?'Gjeldende avtale er godkjent':'Gjeldende avtale venter på godkjenning' }}</strong> · <a href="{{ route('legal.agreement',$currentAgreement) }}" target="_blank" rel="noopener">Les {{ $currentAgreement->version }} ↗</a></p>
<p class="muted">Én eier eller administrator med fullmakt godkjenner på vegne av virksomheten. Vanlige ansatte stoppes ikke. Superadmin kan ikke godkjenne på kundens vegne.</p>
@else
<p>Ingen versjonert avtale er publisert ennå. Kundene blir bedt om å godkjenne når den første avtalen publiseres.</p>
@endif
<p><a href="{{ route('superadmin.agreements') }}">Administrer avtaleteksten →</a></p>
@forelse($acceptances as $acceptance)
<details><summary><strong>DP-{{ $acceptance->agreement_id }}</strong> · {{ $acceptance->actor_name }} · {{ \Illuminate\Support\Carbon::parse($acceptance->accepted_at)->timezone('Europe/Oslo')->format('d.m.Y H:i') }}</summary>
<p>{{ $acceptance->actor_email }} · {{ $acceptance->actor_role }}<br>{{ $acceptance->organization_name }} · {{ $acceptance->organization_number }}</p>
<p><a href="{{ route('legal.agreement',$acceptance->agreement_id) }}" target="_blank" rel="noopener">Åpne godkjent avtale ↗</a></p>
<p class="muted">Godkjent {{ $acceptance->source==='registration'?'ved registrering':'i portalen' }}. IP: {{ $acceptance->ip_address?:'Ikke registrert' }}<br>Nettleser: {{ $acceptance->user_agent }}<br>Kontrollsum: <span style="overflow-wrap:anywhere">{{ $acceptance->sha256 }}</span></p>
</details>
@empty<p class="muted">Ingen godkjenning av den nye, versjonerte avtalen er registrert.</p>@endforelse
@if($legacyAcceptances->isNotEmpty())
<details><summary>Tidligere aksept ved registrering</summary><p class="muted">Eldre registreringer inneholder ikke en lagret kopi av avtaleteksten. De teller ikke som godkjenning av den nye avtalen.</p><ul>@foreach($legacyAcceptances as $legacy)<li>{{ ['eula'=>'Bruksvilkår','privacy'=>'Personvernerklæring','pricing'=>'Prisvilkår'][$legacy->document]??$legacy->document }} · {{ $legacy->version }} · {{ $legacy->accepted_at }}</li>@endforeach</ul></details>
@endif
