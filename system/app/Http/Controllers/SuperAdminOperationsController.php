<?php
namespace App\Http\Controllers;
use App\Services\SystemOperationsService;use Illuminate\Http\RedirectResponse;use Illuminate\Http\Request;use Illuminate\Support\Facades\Artisan;use Illuminate\Support\Facades\Cache;use Illuminate\Support\Str;use Illuminate\View\View;
class SuperAdminOperationsController extends Controller{
 public function show(SystemOperationsService$ops):View{return view('superadmin.operations',['operations'=>$ops->status(),'cronUrl'=>session('cron_url'),'backupMirrorConfigured'=>filled(env('BACKUP_MIRROR_PATH'))]);}
 public function generate(Request$request,SystemOperationsService$ops):RedirectResponse{$plain=Str::random(64);$current=$ops->get('system.cron');$ops->put('system.cron',[...$current,'token_hash'=>hash('sha256',$plain),'created_at'=>now()->toIso8601String(),'last_run_at'=>$current['last_run_at']??null],$request->user()->id);return back()->with('success','Ny cron-adresse er opprettet. Kopier den nå; den vises bare én gang.')->with('cron_url',route('system.cron',$plain));}
 public function run(string$token,SystemOperationsService$ops){abort_unless(strlen($token)===64&&ctype_alnum($token),404);$settings=$ops->get('system.cron');abort_unless(!empty($settings['token_hash'])&&hash_equals($settings['token_hash'],hash('sha256',$token)),404);$result=app(\App\Services\ScheduledOperations::class)->run('http');return response()->json($result,$result['ok']?($result['status']==='completed'?200:202):500);}
}
