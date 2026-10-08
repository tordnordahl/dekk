<x-layouts.app title="Backuper · DekkPilot" heading="Backuper">
<div class="admin-subnav"><a href="{{ route('superadmin') }}">← Superadmin</a></div>
<section class="panel"><div class="panel-head"><h2>Databasebackuper</h2><span class="status">{{ $backups->count() }} filer</span></div>
<p><strong>Maks 14 dager</strong></p><p class="muted">Databasebackupen omfatter alle virksomheter. Daglig opprydding fjerner filer eldre enn 14 dager fra backupmappen og en eventuell konfigurert speilmappe. Kun superadmin har tilgang.</p>
<form method="post" action="{{ route('superadmin.backups.create') }}">@csrf<button class="button">Ta backup nå</button></form>
<div class="table-wrap"><table><thead><tr><th>Opprettet</th><th>Fil</th><th>Størrelse</th><th></th></tr></thead><tbody>
@forelse($backups as $backup)<tr><td>{{ date('d.m.Y H:i',$backup['created_at']) }}</td><td>{{ $backup['name'] }}</td><td>{{ number_format($backup['size']/1024/1024,2,',',' ') }} MB</td><td><a class="button ghost" href="{{ route('superadmin.backups.download',$backup['name']) }}">Last ned</a></td></tr>
@empty<tr><td colspan="4">Ingen backuper funnet.</td></tr>@endforelse
</tbody></table></div></section>
</x-layouts.app>
