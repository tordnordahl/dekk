<x-layouts.app title="Sikkerhet · DekkPilot" heading="Sikkerhet og app-tilgang">
<div class="admin-subnav"><a href="{{ route('admin') }}">← Admin</a><a href="#two-factor">Tofaktor</a><a href="#devices">App-enheter</a><a href="#log">Sikkerhetslogg</a></div>
<section class="grid form-grid" id="two-factor">
 <article class="panel"><p class="eyebrow">TOFAKTOR</p><h2>{{ auth()->user()->two_factor_confirmed_at?'Aktivert':'Beskytt kontoen' }}</h2>
 @if(auth()->user()->two_factor_confirmed_at)
  <p>Innlogging krever passord og en tidsbasert kode. Dette beskytter kundedata selv om passordet blir kjent.</p>
  <form method="post" action="{{ route('admin.security.2fa.disable') }}" class="stack">@csrf @method('DELETE')<label>Bekreft passord<input type="password" name="password" autocomplete="current-password" required></label><button class="button ghost">Deaktiver tofaktor</button></form>
 @else
  <p>Legg nøkkelen inn i Microsoft Authenticator, Google Authenticator, 1Password eller en annen TOTP-app.</p>
  @if($demoReadOnly)<div class="notice warning"><strong>Demo er skrivebeskyttet</strong><p>Tofaktor kan utforskes her, men kan ikke aktiveres på den delte testkontoen.</p></div>@endif
  <div class="usage-note"><strong>Oppsettsnøkkel</strong><code style="word-break:break-all">{{ $setupSecret }}</code></div>
  <a class="button ghost full" href="{{ $otpUri }}">Åpne autentiseringsapp</a>
  <form method="post" action="{{ route('admin.security.2fa.enable') }}" class="stack">@csrf<label>Sekssifret kode<input name="code" inputmode="numeric" autocomplete="one-time-code" required pattern="[0-9]{6}"></label><button class="button full" @disabled($demoReadOnly)>Kontroller og aktiver</button></form>
 @endif</article>
 <aside class="panel"><p class="eyebrow">ENGANGSKODER</p><h2>Gjenoppretting</h2>@if(session('recovery_codes'))<p>Lagre disse nå. De vises ikke igjen.</p><div class="usage-note">@foreach(session('recovery_codes') as $code)<code>{{ $code }}</code>@endforeach</div>@else<p>Ved aktivering får du åtte engangskoder. Hver kode kan bare brukes én gang.</p>@endif</aside>
</section>
<section class="panel" id="devices"><div class="panel-head"><div><p class="eyebrow">APP-TILGANG</p><h2>Dine enheter</h2></div></div><div class="table-wrap"><table><thead><tr><th>Enhet</th><th>Tilganger</th><th>Sist brukt</th><th>Utløper</th><th></th></tr></thead><tbody>@forelse($tokens as $token)<tr><td><strong>{{ $token->device_name?:$token->name }}</strong></td><td>{{ implode(', ',json_decode($token->abilities?:'[]',true)?:[]) }}</td><td>{{ $token->last_used_at?now()->parse($token->last_used_at)->format('d.m.Y H:i'):'Aldri' }}</td><td>{{ $token->revoked_at?'Tilbakekalt':($token->expires_at?now()->parse($token->expires_at)->format('d.m.Y'):'Ingen') }}</td><td>@if(!$token->revoked_at)<form method="post" action="{{ route('admin.security.token.revoke',$token->id) }}">@csrf @method('DELETE')<button class="button ghost">Trekk tilbake</button></form>@endif</td></tr>@empty<tr><td colspan="5" class="empty">Ingen app-enheter har logget inn.</td></tr>@endforelse</tbody></table></div></section>
<section class="panel" id="log"><div class="panel-head"><div><p class="eyebrow">REVISJONSLOGG</p><h2>Siste 100 hendelser</h2></div></div><div class="table-wrap"><table><thead><tr><th>Tidspunkt</th><th>Hendelse</th><th>Bruker</th><th>IP</th></tr></thead><tbody>@forelse($logs as $log)<tr><td>{{ now()->parse($log->created_at)->format('d.m.Y H:i') }}</td><td><strong>{{ $log->action }}</strong></td><td>{{ $log->user_id?:'System/kunde' }}</td><td>{{ $log->ip_address?:'—' }}</td></tr>@empty<tr><td colspan="4" class="empty">Ingen hendelser ennå.</td></tr>@endforelse</tbody></table></div></section>
</x-layouts.app>
