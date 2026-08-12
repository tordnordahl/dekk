<?php

namespace App\Http\Controllers;

use App\Models\IntegrationSetting;
use App\Models\InvoiceExport;
use App\Services\Accounting\AccountingExportService;
use App\Services\Accounting\AccountingPlatformSettings;
use App\Services\Accounting\FikenOAuthService;
use App\Services\Accounting\ZettleOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class AccountingController extends Controller
{
    public function index(Request $request, AccountingPlatformSettings $platform): View
    {
        $org = $request->user()->organization_id;
        $connection = IntegrationSetting::where('organization_id', $org)->whereIn('provider', ['accounting_fiken','accounting_tripletex','accounting_poweroffice'])->where('active', true)->first();
        $zettleConnection = IntegrationSetting::where('organization_id',$org)->where('provider','sales_zettle')->where('active',true)->first();
        $configuration = $connection ? json_decode(Crypt::decryptString($connection->encrypted_credentials), true) : [];
        $fikenPlatform=$platform->fiken();$tripletexPlatform=$platform->tripletex();$powerofficePlatform=$platform->poweroffice();$zettlePlatform=$platform->zettle();$superadmin=(bool)$request->user()->is_super_admin;
        return view('admin.accounting', [
            'connection' => $connection, 'configuration' => $configuration,
            'exports' => InvoiceExport::where('organization_id', $org)->latest()->paginate(30),
            'counts' => InvoiceExport::where('organization_id', $org)->selectRaw('status, count(*) total')->groupBy('status')->pluck('total','status'),
            'fikenOauthConfigured' => app(FikenOAuthService::class)->configured(),
            'fikenPlatform' => $superadmin ? $fikenPlatform : [],
            'tripletexPlatform' => $superadmin ? $tripletexPlatform : [],
            'tripletexProductionConfigured' => filled($tripletexPlatform['consumer_token'] ?? null),
            'tripletexTestConfigured' => filled($tripletexPlatform['test_consumer_token'] ?? null),
            'powerofficePlatform'=>$superadmin?$powerofficePlatform:[], 'powerofficeConfigured'=>filled($powerofficePlatform['app_key']??null)&&filled($powerofficePlatform['subscription_key']??null),
            'zettlePlatform'=>$superadmin?$zettlePlatform:[], 'zettleConnection'=>$zettleConnection, 'zettleConfigured'=>app(ZettleOAuthService::class)->configured(),
            'zettlePilotEnabled'=>(bool)($zettlePlatform['pilot_enabled']??false),
        ]);
    }

    public function connectFiken(Request $request, FikenOAuthService $oauth): RedirectResponse
    {
        if(!$oauth->configured())return back()->withErrors(['accounting'=>'Superadmin må konfigurere FIKEN_CLIENT_ID og FIKEN_CLIENT_SECRET på serveren først.']);
        $state=Str::random(64);$request->session()->put('fiken_oauth_state',$state);
        $query=http_build_query(['response_type'=>'code','client_id'=>$oauth->clientId(),'redirect_uri'=>route('admin.accounting.fiken.callback'),'state'=>$state]);
        return redirect()->away('https://fiken.no/oauth/authorize?'.$query);
    }

    public function saveFikenPlatform(Request $request, AccountingPlatformSettings $platform): RedirectResponse
    {
        $data=$request->validate(['client_id'=>['required','string','max:500'],'client_secret'=>['nullable','string','max:2000']]);
        $old=$platform->fiken();
        if(blank($data['client_secret'])&&blank($old['client_secret']??null))return back()->withErrors(['client_secret'=>'Client Secret må fylles ut første gang.']);
        try {
            $platform->save('accounting.fiken.oauth',['client_id'=>trim($data['client_id']),'client_secret'=>$data['client_secret']?:$old['client_secret']],$request->user()->id);
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['accounting'=>$exception->getMessage()]);
        }
        DB::table('audit_logs')->insert(['organization_id'=>$request->user()->organization_id,'user_id'=>$request->user()->id,'action'=>'accounting.fiken.platform.updated','ip_address'=>$request->ip(),'created_at'=>now()]);
        return back()->with('success','Fiken OAuth-oppsettet er lagret kryptert. Du kan nå koble til Fiken.');
    }

    public function saveTripletexPlatform(Request $request, AccountingPlatformSettings $platform): RedirectResponse
    {
        $data=$request->validate(['consumer_token'=>['nullable','string','max:2000'],'test_consumer_token'=>['nullable','string','max:2000']]);
        $old=$platform->tripletex();
        try {
            $platform->save('accounting.tripletex',['consumer_token'=>$data['consumer_token']?:($old['consumer_token']??null),'test_consumer_token'=>$data['test_consumer_token']?:($old['test_consumer_token']??null)],$request->user()->id);
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['accounting'=>$exception->getMessage()]);
        }
        DB::table('audit_logs')->insert(['organization_id'=>$request->user()->organization_id,'user_id'=>$request->user()->id,'action'=>'accounting.tripletex.platform.updated','ip_address'=>$request->ip(),'created_at'=>now()]);
        return back()->with('success','Tripletex-plattformnøklene er lagret kryptert.');
    }

    public function fikenCallback(Request $request, FikenOAuthService $oauth): RedirectResponse
    {
        $expected=(string)$request->session()->pull('fiken_oauth_state');
        abort_unless($expected!==''&&hash_equals($expected,(string)$request->query('state')),403,'Ugyldig OAuth-state.');
        if($request->filled('error'))return redirect()->route('admin.accounting')->withErrors(['accounting'=>'Fiken-tilkoblingen ble avbrutt: '.$request->query('error_description',$request->query('error'))]);
        $request->validate(['code'=>['required','string','max:2000']]);
        try{$tokens=$oauth->exchange((string)$request->string('code'),$expected);$companies=Http::withToken($tokens['access_token'])->acceptJson()->timeout(20)->get('https://api.fiken.no/api/v2/companies',['page'=>0,'pageSize'=>100]);if(!$companies->successful())throw new \RuntimeException('Kunne ikke hente foretak fra Fiken.');$company=collect($companies->json())->first();if(!$company||empty($company['slug']))throw new \RuntimeException('Fiken-kontoen har ingen tilgjengelige foretak.');$credentials=['auth_mode'=>'oauth','api_key'=>$tokens['access_token'],'refresh_token'=>$tokens['refresh_token']??null,'expires_at'=>now()->addSeconds((int)($tokens['expires_in']??3600))->toIso8601String(),'company_slug'=>$company['slug'],'payment_days'=>14,'income_account'=>'3000','auto_export'=>false];IntegrationSetting::where('organization_id',$request->user()->organization_id)->whereIn('provider',['accounting_fiken','accounting_tripletex'])->update(['active'=>false]);IntegrationSetting::updateOrCreate(['organization_id'=>$request->user()->organization_id,'provider'=>'accounting_fiken'],['encrypted_credentials'=>Crypt::encryptString(json_encode($credentials,JSON_THROW_ON_ERROR)),'active'=>true,'updated_by'=>$request->user()->id]);DB::table('audit_logs')->insert(['organization_id'=>$request->user()->organization_id,'user_id'=>$request->user()->id,'action'=>'accounting.fiken.oauth.connected','ip_address'=>$request->ip(),'metadata'=>json_encode(['company_slug'=>$company['slug']]),'created_at'=>now()]);return redirect()->route('admin.accounting')->with('success','Fiken er koblet sikkert til '.$company['name'].'.');}catch(Throwable$e){report($e);return redirect()->route('admin.accounting')->withErrors(['accounting'=>$e->getMessage()]);}
    }

    public function savePowerOfficePlatform(Request $request, AccountingPlatformSettings $platform): RedirectResponse
    {
        $data=$request->validate(['app_key'=>['nullable','string','max:2000'],'subscription_key'=>['nullable','string','max:2000']]);$old=$platform->poweroffice();
        if(blank($data['app_key'])&&blank($old['app_key']??null))return back()->withErrors(['accounting'=>'PowerOffice App Key må fylles ut første gang.']);
        if(blank($data['subscription_key'])&&blank($old['subscription_key']??null))return back()->withErrors(['accounting'=>'PowerOffice Subscription Key må fylles ut første gang.']);
        $platform->save('accounting.poweroffice',['app_key'=>$data['app_key']?:$old['app_key'],'subscription_key'=>$data['subscription_key']?:$old['subscription_key']],$request->user()->id);
        return back()->with('success','PowerOffice-plattformnøklene er lagret kryptert.');
    }

    public function saveZettlePlatform(Request $request, AccountingPlatformSettings $platform): RedirectResponse
    {
        $data=$request->validate(['client_id'=>['required','string','max:500'],'client_secret'=>['nullable','string','max:2000'],'pilot_enabled'=>['nullable','boolean']]);$old=$platform->zettle();
        if(blank($data['client_secret'])&&blank($old['client_secret']??null))return back()->withErrors(['accounting'=>'Zettle Client Secret må fylles ut første gang.']);
        $platform->save('sales.zettle.oauth',['client_id'=>trim($data['client_id']),'client_secret'=>$data['client_secret']?:$old['client_secret'],'pilot_enabled'=>$request->boolean('pilot_enabled')],$request->user()->id);
        return back()->with('success','Zettle-plattformoppsettet er lagret kryptert. Pilotstatus er '.($request->boolean('pilot_enabled')?'aktiv':'av').'.');
    }

    public function connectZettle(Request $request, ZettleOAuthService $oauth): RedirectResponse
    {
        if(!(bool)(app(AccountingPlatformSettings::class)->zettle()['pilot_enabled']??false))return back()->withErrors(['accounting'=>'Zettle-piloten er slått av av superadmin.']);
        if(!$oauth->configured())return back()->withErrors(['accounting'=>'Superadmin må konfigurere Zettle Client ID og Client Secret først.']);
        $state=Str::random(64);$request->session()->put('zettle_oauth_state',$state);
        return redirect()->away('https://oauth.zettle.com/authorize?'.http_build_query(['response_type'=>'code','scope'=>'READ:PURCHASE READ:FINANCE','client_id'=>$oauth->clientId(),'redirect_uri'=>route('admin.accounting.zettle.callback'),'state'=>$state]));
    }

    public function zettleCallback(Request $request, ZettleOAuthService $oauth): RedirectResponse
    {
        $expected=(string)$request->session()->pull('zettle_oauth_state');abort_unless($expected!==''&&hash_equals($expected,(string)$request->query('state')),403,'Ugyldig OAuth-state.');
        if($request->filled('error'))return redirect()->route('admin.accounting')->withErrors(['accounting'=>'Zettle-tilkoblingen ble avbrutt.']);$request->validate(['code'=>['required','string','max:2000']]);
        try{$t=$oauth->exchange((string)$request->string('code'));$c=['api_key'=>$t['access_token'],'refresh_token'=>$t['refresh_token']??null,'expires_at'=>now()->addSeconds((int)($t['expires_in']??7200))->toIso8601String(),'scopes'=>['READ:PURCHASE','READ:FINANCE']];IntegrationSetting::updateOrCreate(['organization_id'=>$request->user()->organization_id,'provider'=>'sales_zettle'],['encrypted_credentials'=>Crypt::encryptString(json_encode($c,JSON_THROW_ON_ERROR)),'active'=>true,'updated_by'=>$request->user()->id]);return redirect()->route('admin.accounting')->with('success','Zettle er koblet til. Regnskapskoblingen din er ikke endret.');}catch(Throwable $e){report($e);return redirect()->route('admin.accounting')->withErrors(['accounting'=>$e->getMessage()]);}
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate(['provider'=>['required','in:fiken,tripletex,poweroffice'],'api_key'=>['nullable','string','max:2000'],'company_identifier'=>['required','string','max:255'],'auto_export'=>['nullable','boolean'],'environment'=>['nullable','in:production,test'],'payment_days'=>['required','integer','between:1,90'],'income_account'=>['nullable','string','regex:/^[3-8][0-9]{3}$/']]);
        $org = $request->user()->organization_id;
        $provider = 'accounting_'.$data['provider'];
        $existing = IntegrationSetting::where('organization_id',$org)->where('provider',$provider)->first();
        $old = $existing ? json_decode(Crypt::decryptString($existing->encrypted_credentials), true) : [];
        if (blank($data['api_key']) && blank($old['api_key'] ?? null)) return back()->withErrors(['api_key'=>'API-nøkkel må fylles ut første gang.']);
        $credentials = ['api_key'=>$data['api_key'] ?: $old['api_key'], 'auto_export'=>$request->boolean('auto_export'),'payment_days'=>(int)$data['payment_days'],'income_account'=>$data['income_account'] ?: '3000'];
        if ($data['provider'] === 'fiken') {
            $credentials['company_slug'] = $data['company_identifier'];
            if (($old['auth_mode'] ?? null) === 'oauth' && blank($data['api_key'])) $credentials += array_intersect_key($old, array_flip(['auth_mode','refresh_token','expires_at']));
        }
        elseif($data['provider']==='tripletex'){ $environment=$data['environment']??'production';$platform=app(AccountingPlatformSettings::class)->tripletex();$consumer=$environment==='test'?($platform['test_consumer_token']??null):($platform['consumer_token']??null);if(blank($consumer))return back()->withErrors(['accounting'=>'Superadmin må konfigurere Tripletex Consumer Token for valgt miljø først.']);$credentials['company_id']='0';$credentials['base_url']=$environment==='test'?'https://api-test.tripletex.tech/v2':'https://tripletex.no/v2';$credentials['auth_mode']='commercial'; }
        else{$platform=app(AccountingPlatformSettings::class)->poweroffice();if(blank($platform['app_key']??null)||blank($platform['subscription_key']??null))return back()->withErrors(['accounting'=>'Superadmin må konfigurere PowerOffice-plattformnøklene først.']);$credentials['environment']=$data['environment']??'production';}
        DB::transaction(function() use($org,$provider,$credentials,$request){
            IntegrationSetting::where('organization_id',$org)->whereIn('provider',['accounting_fiken','accounting_tripletex','accounting_poweroffice'])->where('provider','!=',$provider)->update(['active'=>false]);
            IntegrationSetting::updateOrCreate(['organization_id'=>$org,'provider'=>$provider],['encrypted_credentials'=>Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR)),'active'=>true,'updated_by'=>$request->user()->id]);
            DB::table('audit_logs')->insert(['organization_id'=>$org,'user_id'=>$request->user()->id,'action'=>'accounting.connection.updated','metadata'=>json_encode(['provider'=>$provider]),'created_at'=>now()]);
        });
        return back()->with('success','Regnskapskoblingen er lagret. API-nøkkelen vises aldri igjen.');
    }

    public function queue(Request $request, InvoiceExport $invoice, AccountingExportService $service): RedirectResponse
    {
        abort_unless($invoice->organization_id === $request->user()->organization_id, 404);
        $connection = $service->activeConnection($invoice->organization_id);
        if (!$connection) return back()->withErrors(['accounting'=>'Koble til et regnskapssystem først.']);
        $service->queue($invoice, $service->providerName($connection));
        $sent=$service->processQueued($invoice);
        return back()->with($sent?'success':'warning',$sent?'Fakturautkastet er sendt til regnskapssystemet.':'Eksporten feilet. Se feilmeldingen på fakturaraden.');
    }

    public function test(Request $request, AccountingExportService $service): RedirectResponse
    {
        $connection = $service->activeConnection($request->user()->organization_id);
        if (!$connection) return back()->withErrors(['accounting' => 'Lagre regnskapskoblingen før den testes.']);
        try {
            $result = $service->exporter($service->providerName($connection))->test($service->credentials($connection));
            DB::table('audit_logs')->insert(['organization_id'=>$request->user()->organization_id,'user_id'=>$request->user()->id,'action'=>'accounting.connection.tested','ip_address'=>$request->ip(),'metadata'=>json_encode(['provider'=>$result['provider'],'success'=>true]),'created_at'=>now()]);
            return back()->with('success', 'Tilkoblingen fungerer'.(!empty($result['company']) ? ' for '.$result['company'] : '').'.');
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['accounting' => 'Tilkoblingstesten feilet: '.$exception->getMessage()]);
        }
    }

    public function queueAll(Request $request, AccountingExportService $service): RedirectResponse
    {
        $org=$request->user()->organization_id; $connection=$service->activeConnection($org);
        if (!$connection) return back()->withErrors(['accounting'=>'Koble til et regnskapssystem først.']);
        $items=InvoiceExport::where('organization_id',$org)->whereIn('status',['ready','failed'])->get();
        $sent=0;foreach($items as $item){$service->queue($item,$service->providerName($connection));if($service->processQueued($item))$sent++;}
        return back()->with($sent===$items->count()?'success':'warning',$sent.' av '.$items->count().' fakturautkast ble sendt. Eventuelle feil vises på radene under.');
    }
}
