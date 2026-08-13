<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\TireSet;
use App\Models\TireInspection;
use App\Models\StorageLocation;
use App\Services\WarehousePlacementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WorkdayController extends Controller
{
    public function index(Request $request): View
    {
        $user=$request->user();$org=$user->organization_id;$code=strtoupper(trim((string)$request->query('code')));$scanned=null;
        $area=in_array($request->query('area'),['schedule','preparation','cleanup'],true)?$request->query('area'):'schedule';
        $scheduleMode=in_array($request->query('schedule'),['mine','all','bays'],true)?$request->query('schedule'):'mine';
        if($code!=='')$scanned=TireSet::with(['vehicle.customer','storageLocation'])->where('organization_id',$org)->where('code',$code)->first();
        $allToday=Booking::with(['customer','vehicle','assignedUser','workBay'])->where('organization_id',$org)->where('branch_id',$user->branch_id)->whereDate('starts_at',today())->whereNotIn('status',['cancelled','no_show'])->orderBy('starts_at')->get();
        $myToday=$allToday->where('assigned_user_id',$user->id)->values();
        $today=$scheduleMode==='mine'?$myToday:$allToday;
        $prepMode=in_array($request->query('prep'),['tomorrow','week'],true)?$request->query('prep'):'tomorrow';
        $prepFrom=today()->addDay()->startOfDay();
        $prepUntil=$prepMode==='week'?today()->addDays(7)->endOfDay():today()->addDay()->endOfDay();
        $preparationBookings=Booking::with(['customer','vehicle.tireSets.storageLocation'])
            ->where('organization_id',$org)->where('branch_id',$user->branch_id)
            ->whereBetween('starts_at',[$prepFrom,$prepUntil])->whereNotIn('status',['cancelled','no_show'])
            ->orderBy('starts_at')->get();
        $intakeSets=TireSet::with(['vehicle.customer','storageLocation'])
            ->where('organization_id',$org)->whereNotNull('received_at')->where('status','received')
            ->where(fn($q)=>$q->where('wash_status','!=','washed')->orWhereNull('minimum_tread_depth')->orWhereNull('storage_location_id'))
            ->whereDoesntHave('vehicle.bookings',fn($q)=>$q->whereNotIn('status',['cancelled','no_show'])->whereBetween('starts_at',[now(),now()->addDays(5)]))
            ->oldest('received_at')->limit(20)->get();
        $locations=StorageLocation::withCount(['tireSets'=>fn($q)=>$q->whereNotNull('received_at')->whereIn('status',['received','stored','picked','workshop'])])
            ->where('organization_id',$org)->where('branch_id',$user->branch_id)->where('active',true)->orderBy('code')->get();
        return view('workday.index',['today'=>$today,'myToday'=>$myToday,'allToday'=>$allToday,'area'=>$area,'scheduleMode'=>$scheduleMode,'preparationBookings'=>$preparationBookings,'prepMode'=>$prepMode,'scanned'=>$scanned,'scanCode'=>$code,'intakeSets'=>$intakeSets,'locations'=>$locations]);
    }

    public function move(Request $request,TireSet $tireSet):RedirectResponse
    {
        abort_unless($tireSet->organization_id===$request->user()->organization_id,404);
        $data=$request->validate(['status'=>['required','in:picked,workshop,stored,delivered'],'return_to'=>['nullable','in:scan,preparation'],'prep'=>['nullable','in:tomorrow,week']]);
        $from=$tireSet->storage_location_id;$to=$from;
        if($data['status']==='picked'){
            $receiving=StorageLocation::where('organization_id',$tireSet->organization_id)->where('branch_id',$request->user()->branch_id)->where('active',true)->where(fn($q)=>$q->where('location_type','receiving')->orWhere('code','MOTTAK'))->orderByRaw("CASE WHEN code = 'MOTTAK' THEN 0 ELSE 1 END")->first();
            if(!$receiving)return back()->withErrors(['preparation'=>'Opprett en aktiv lagerplass av typen «Mottak» før hjul kan legges klart.']);
            $to=$receiving->id;
        }
        DB::transaction(function()use($tireSet,$data,$request,$from,$to){
            $tireSet->update(['status'=>$data['status'],'storage_location_id'=>$to,'storage_shelf_number'=>$data['status']==='picked'?null:$tireSet->storage_shelf_number,'delivered_at'=>$data['status']==='delivered'?now():null]);
            if($from!==$to)DB::table('storage_location_movements')->insert(['organization_id'=>$tireSet->organization_id,'tire_set_id'=>$tireSet->id,'from_location_id'=>$from,'to_location_id'=>$to,'moved_by'=>$request->user()->id,'reason'=>$data['status']==='picked'?'prepared_for_booking':'workday','moved_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            DB::table('audit_logs')->insert(['organization_id'=>$tireSet->organization_id,'user_id'=>$request->user()->id,'action'=>'tire_set.workflow.'.$data['status'],'subject_type'=>TireSet::class,'subject_id'=>$tireSet->id,'metadata'=>json_encode(['one_scan_entire_set'=>true,'storage_location_id'=>$to,'prepared_for_booking'=>$data['status']==='picked']),'created_at'=>now()]);
        });
        $message=$data['status']==='picked'?'Hjulsettet er plukket og lagt på mottak.':'Hele hjulsettet er flyttet videre. Du trenger ikke skanne hvert hjul.';
        $parameters=($data['return_to']??'scan')==='preparation'?['area'=>'preparation','prep'=>$data['prep']??'tomorrow']:['code'=>$tireSet->code];
        return redirect()->route('workday',$parameters)->with('success',$message);
    }

    public function intakeStep(Request $request,TireSet $tireSet,WarehousePlacementService $placement):RedirectResponse
    {
        abort_unless((int)$tireSet->organization_id===(int)$request->user()->organization_id,404);
        abort_unless($tireSet->received_at&&$tireSet->status==='received',422);
        $data=$request->validate(['action'=>['required','in:wash_needed,skip_wash,washed,measure,place'],'storage_location_id'=>['nullable','integer','required_if:action,place'],'depths'=>['nullable','array','size:4','required_if:action,measure'],'depths.*'=>['nullable','numeric','between:0,20']]);
        $updates=[];$message='Hjulsettet er oppdatert.';$from=$tireSet->storage_location_id;
        if($data['action']==='wash_needed'){$updates=['wash_status'=>'needed','washed'=>false];$message='Vask er lagt inn som neste steg.';}
        elseif($data['action']==='skip_wash'){$updates=['wash_status'=>'not_needed','washed'=>false];$message='Vask er ikke nødvendig. Neste steg er måling.';}
        elseif($data['action']==='washed'){$updates=['wash_status'=>'washed','washed'=>true];$message='Vask er fullført. Neste steg er måling.';}
        elseif($data['action']==='measure'){$updates=['minimum_tread_depth'=>(float)min($data['depths'])];$message='Målingen er lagret. Neste steg er å velge lagerplass.';}
        else{
            if($tireSet->minimum_tread_depth===null)return back()->withErrors(['intake'=>'Mål hjulene før de plasseres på lager.']);
            if(!in_array($tireSet->wash_status,['washed','not_needed'],true))return back()->withErrors(['intake'=>'Avklar vask før hjulene plasseres på lager.']);
            $location=StorageLocation::where('organization_id',$tireSet->organization_id)->where('branch_id',$request->user()->branch_id)->where('active',true)->findOrFail($data['storage_location_id']??0);
            $shelf=$placement->nextShelf($location);if($shelf===null)return back()->withErrors(['intake'=>$location->code.' er full. Velg en annen reol.']);
            $updates=['storage_location_id'=>$location->id,'storage_shelf_number'=>$shelf,'status'=>'stored'];$message='Hjulsettet er ferdig og plassert på '.$location->code.', hylle '.$shelf.'.';
        }
        DB::transaction(function()use($tireSet,$updates,$request,$from,$data){$tireSet->update($updates);if($data['action']==='measure'){$minimum=(float)min($data['depths']);$inspection=TireInspection::create(['public_id'=>(string)Str::uuid(),'organization_id'=>$tireSet->organization_id,'tire_set_id'=>$tireSet->id,'inspected_by'=>$request->user()->id,'overall_status'=>$minimum<3?'replace':($minimum<4?'attention':'good'),'inspected_at'=>now()]);foreach(['front_left','front_right','rear_left','rear_right']as$i=>$position)$inspection->measurements()->create(['position'=>$position,'tread_depth_mm'=>$data['depths'][$i],'tpms_status'=>'not_checked','tire_damage'=>false,'rim_damage'=>false,'uneven_wear'=>false]);}$to=$tireSet->fresh()->storage_location_id;if($from!==$to)DB::table('storage_location_movements')->insert(['organization_id'=>$tireSet->organization_id,'tire_set_id'=>$tireSet->id,'from_location_id'=>$from,'to_location_id'=>$to,'moved_by'=>$request->user()->id,'reason'=>'workday_intake','moved_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);DB::table('audit_logs')->insert(['organization_id'=>$tireSet->organization_id,'user_id'=>$request->user()->id,'action'=>'tire_set.intake.'.$data['action'],'subject_type'=>TireSet::class,'subject_id'=>$tireSet->id,'metadata'=>json_encode(['source'=>'workday']),'created_at'=>now()]);});
        return redirect()->route('workday',['area'=>'cleanup'])->with('success',$message);
    }
}
