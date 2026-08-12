<?php
namespace App\Http\Controllers;
use App\Services\SuperAdminDiagnostics;use Illuminate\Http\RedirectResponse;use Illuminate\Http\Request;use Illuminate\Support\Facades\DB;use Illuminate\View\View;
class SuperAdminDiagnosticsController extends Controller
{
 public function show(SuperAdminDiagnostics$diagnostics):View{abort_unless($diagnostics->enabled(),404);return view('superadmin.diagnostics',['log'=>$diagnostics->latest(),'logStatus'=>$diagnostics->status()]);}
 public function toggle(Request$request,SuperAdminDiagnostics$diagnostics):RedirectResponse{$enabled=$request->boolean('enabled');$diagnostics->set($enabled);DB::table('audit_logs')->insert(['organization_id'=>$request->user()->organization_id,'user_id'=>$request->user()->id,'action'=>$enabled?'superadmin.diagnostics.enabled':'superadmin.diagnostics.disabled','ip_address'=>$request->ip(),'created_at'=>now()]);return back()->with('success',$enabled?'Sikker diagnosemodus er aktiv. Husk å slå den av når feilen er funnet.':'Diagnosemodus er slått av.');}
 public function clear(Request$request,SuperAdminDiagnostics$diagnostics):RedirectResponse{$request->validate(['confirmation'=>['accepted']],['confirmation.accepted'=>'Bekreft at du vil tømme feilloggen.']);$result=$diagnostics->clear();DB::table('audit_logs')->insert(['organization_id'=>$request->user()->organization_id,'user_id'=>$request->user()->id,'action'=>'superadmin.diagnostics.logs_cleared','ip_address'=>$request->ip(),'metadata'=>json_encode($result),'created_at'=>now()]);return back()->with('success',$result['files'].' loggfil(er) ble tømt. '.number_format($result['bytes']/1024,1,',',' ').' KB ble fjernet.');}
}
