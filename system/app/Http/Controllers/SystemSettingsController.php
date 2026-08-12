<?php

namespace App\Http\Controllers;

use App\Mail\OutboundMail;
use App\Models\IntegrationSetting;
use App\Models\PlatformSetting;
use App\Services\MailConfigurationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class SystemSettingsController extends Controller
{
    public function index(Request $request, MailConfigurationService $mail): View
    {
        $directory = storage_path('app/backups');
        $backups = collect(is_dir($directory) ? glob($directory.'/dekkpilot-*.sql.gz') : [])->map(fn ($path) => [
            'name' => basename($path), 'size' => filesize($path), 'created_at' => filemtime($path),
        ])->sortByDesc('created_at')->values();
        return view('admin.system', [
            'serverMail' => $request->user()->is_super_admin ? $mail->serverSettings() : [],
            'tenantMail' => $mail->tenantSettings($request->user()->organization_id),
            'backups' => $request->user()->is_super_admin ? $backups : collect(),
            'mysqldump' => (string) env('DB_DUMP_BINARY', 'mysqldump'),
        ]);
    }

    public function saveServerMail(Request $request): RedirectResponse
    {
        abort_unless($request->user()->is_super_admin, 403);
        $data = $request->validate([
            'transport' => ['required','in:smtp,sendmail,log'], 'host' => ['nullable','string','max:255'],
            'port' => ['nullable','integer','min:1','max:65535'], 'security' => ['required','in:tls,ssl,none'],
            'username' => ['nullable','string','max:255'], 'password' => ['nullable','string','max:1000'],
            'from_address' => ['required','email','max:255'], 'from_name' => ['required','string','max:255'],
            'messages_per_minute' => ['required','integer','min:1','max:600'],
        ]);
        $existing = app(MailConfigurationService::class)->serverSettings();
        if (blank($data['password'] ?? null)) $data['password'] = $existing['password'] ?? null;
        PlatformSetting::updateOrCreate(['key'=>'mail.server'], ['encrypted_value'=>Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)),'updated_by'=>$request->user()->id]);
        $this->audit($request, 'platform.mail.updated', ['transport'=>$data['transport'],'host'=>$data['host'],'port'=>$data['port']]);
        return back()->with('success', 'Serverens e-postinnstillinger er lagret kryptert. Send en test før produksjonsbruk.');
    }

    public function serverMail(MailConfigurationService $mail): View
    {
        return view('superadmin.server-mail', ['serverMail' => $mail->serverSettings()]);
    }

    public function saveTenantMail(Request $request): RedirectResponse
    {
        $data = $request->validate(['from_address'=>['required','email','max:255']]);
        $data['from_name'] = $request->user()->organization?->name ?: 'DekkPilot';
        $data['reply_to'] = $data['from_address'];
        IntegrationSetting::updateOrCreate(['organization_id'=>$request->user()->organization_id,'provider'=>'email_sender'], ['encrypted_credentials'=>Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)),'active'=>true,'updated_by'=>$request->user()->id]);
        $this->audit($request, 'organization.mail_sender.updated', ['from_address'=>$data['from_address'],'reply_to'=>$data['reply_to']]);
        return back()->with('success', 'Virksomhetens avsender er lagret. Send en test for å kontrollere leveringen.');
    }

    public function testMail(Request $request, MailConfigurationService $configuration): RedirectResponse
    {
        $data = $request->validate(['recipient'=>['required','email','max:255']]);
        abort_if(session('demo_read_only'), 403, 'Demo kan ikke sende e-post.');
        try {
            $configuration->configure($request->user()->organization_id);
            Mail::to($data['recipient'])->send(new OutboundMail('Test fra DekkPilot', "Dette er en test av e-postoppsettet.\n\nSendt ".now()->format('d.m.Y H:i').'.'));
            $this->audit($request, 'mail.test_sent', ['recipient'=>$data['recipient']]);
            return back()->with('success', 'Test-e-posten er sendt. Kontroller innboks og søppelpost.');
        } catch (Throwable $e) {
            report($e);
            return back()->withErrors(['mail'=>'Testen feilet: '.Str::limit($e->getMessage(), 350)]);
        }
    }

    public function createBackup(Request $request): RedirectResponse
    {
        abort_unless($request->user()->is_super_admin, 403);
        $data = $request->validate(['retention'=>['required','integer','min:1','max:365']]);
        $exit = Artisan::call('backup:database', ['--retention'=>$data['retention']]);
        $output = trim(Artisan::output());
        $this->audit($request, 'backup.created', ['successful'=>$exit===0]);
        return $exit === 0 ? back()->with('success', 'Backup er opprettet. '.$output) : back()->withErrors(['backup'=>'Backup feilet. '.$output]);
    }

    public function downloadBackup(Request $request, string $filename): BinaryFileResponse
    {
        abort_unless($request->user()->is_super_admin, 403);
        abort_unless((bool) preg_match('/\Adekkpilot-\d{8}-\d{6}\.sql\.gz\z/', $filename), 404);
        $path = storage_path('app/backups/'.$filename);
        abort_unless(is_file($path) && realpath($path) && str_starts_with(realpath($path), realpath(storage_path('app/backups')).DIRECTORY_SEPARATOR), 404);
        $this->audit($request, 'backup.downloaded', ['filename'=>$filename]);
        return response()->download($path, $filename, ['Content-Type'=>'application/gzip','Cache-Control'=>'private, no-store']);
    }

    private function audit(Request $request, string $action, array $metadata): void
    {
        DB::table('audit_logs')->insert(['organization_id'=>$request->user()->organization_id,'user_id'=>$request->user()->id,'action'=>$action,'ip_address'=>$request->ip(),'metadata'=>json_encode($metadata),'created_at'=>now()]);
    }
}
