<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\HotelAgreement;
use App\Models\InvoiceExport;
use App\Models\OutboundMessage;
use App\Models\Quote;
use App\Models\StorageLocation;
use App\Models\TireInspection;
use App\Models\TireSet;
use App\Models\ServiceSetting;
use App\Models\TireProduct;
use App\Services\BookingWorkflowService;
use App\Services\WarehousePlacementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ActionCenterController extends Controller
{
    public function index(Request $request): View
    {
        $org=(int)$request->user()->organization_id;$branch=(int)$request->user()->branch_id;$items=collect();
        $code=strtoupper(trim((string)$request->query('code')));
        $labelReminders=(bool)(ServiceSetting::where('branch_id',$branch)->value('label_reminders_enabled')??true);
        $tireQuery=TireSet::with(['vehicle.customer','storageLocation','inspections.measurements'])
            ->where('organization_id',$org)->whereNotNull('received_at')->whereNotIn('status',['delivered'])
            ->where(function($q)use($labelReminders){$q->whereNull('storage_location_id')->orWhereNull('minimum_tread_depth')->orWhereIn('wash_status',['not_assessed','needed']);if($labelReminders)$q->orWhereNull('label_printed_at');});
        $tireSets=$tireQuery->limit(75)->get();
        if($code!==''&&!$tireSets->contains('code',$code)){
            $scanned=TireSet::with(['vehicle.customer','storageLocation','inspections.measurements'])->where('organization_id',$org)->where('code',$code)->first();
            if($scanned)$tireSets->prepend($scanned);
        }
        foreach($tireSets as$set){
            $needs=collect();
            if(!$set->storage_location_id)$needs->push('mangler lagerplass');
            if($set->minimum_tread_depth===null)$needs->push('mangler mønsterdybde');
            if(($set->wash_status??'not_assessed')==='not_assessed')$needs->push('vask må vurderes');
            if($set->wash_status==='needed')$needs->push('trenger vask');
            if($labelReminders&&!$set->label_printed_at)$needs->push('mangler etikett');
            $items->push(['priority'=>1,'type'=>'Hjulsett','title'=>($set->vehicle?->registration_number??$set->code).' · '.($set->vehicle?->customer?->name??'Ukjent kunde'),'detail'=>$needs->join(' · '),'tire_set_id'=>$set->id]);
        }
        $canManage=in_array($request->user()->role,['owner','admin','manager'],true);
        if($canManage){
            InvoiceExport::where('organization_id',$org)->where('status','failed')->limit(50)->get()->each(fn($invoice)=>$items->push(['priority'=>1,'type'=>'Regnskap','title'=>$invoice->reference.' kunne ikke sendes','detail'=>$invoice->last_error,'kind'=>'invoice','model'=>$invoice]));
            OutboundMessage::where('organization_id',$org)->where('status','failed')->limit(50)->get()->each(fn($message)=>$items->push(['priority'=>1,'type'=>'Melding','title'=>'Utsending til '.$message->recipient.' feilet','detail'=>$message->last_error,'kind'=>'message','model'=>$message]));
        }
        Booking::with(['customer','vehicle'])->where('organization_id',$org)->where('branch_id',$branch)->where('confirmation_status','pending')->whereBetween('starts_at',[now(),now()->addDays(7)])->limit(50)->get()->each(fn($booking)=>$items->push(['priority'=>2,'type'=>'Booking','title'=>($booking->vehicle?->registration_number??$booking->customer->name).' venter på bekreftelse','detail'=>$booking->starts_at->format('d.m.Y H:i'),'kind'=>'booking','model'=>$booking]));
        $quoted=Quote::where('organization_id',$org)->whereNotNull('source_tire_set_id')->whereIn('status',['draft','sent','viewed','accepted'])->pluck('source_tire_set_id');
        TireSet::with('vehicle.customer')->where('organization_id',$org)->needsReplacement()->whereNotIn('id',$quoted)->limit(50)->get()->each(function($set)use($items){$availability=app(\App\Services\InventoryAvailabilityService::class);$options=TireProduct::where('organization_id',$set->organization_id)->where('active',true)->where('stock_quantity','>=',4)->where('size',$set->size)->where('season',$set->season)->orderByDesc('price_cents')->get()->filter(fn($product)=>$availability->available($product)>=4)->take(3)->values();if($options->isEmpty())return;$set->setRelation('portalOptions',$options);$reasons=collect();if(in_array('low_tread',$set->replacement_reasons,true))$reasons->push($set->minimum_tread_depth.' mm');if(in_array('age',$set->replacement_reasons,true))$reasons->push('DOT '.$set->dot_year.' · ca. '.$set->age_years.' år');$reasons->push($set->size);$items->push(['priority'=>2,'type'=>'Tilbud','title'=>($set->vehicle?->registration_number??$set->code).' trenger dekkvurdering','detail'=>$reasons->join(' · '),'kind'=>'offer','model'=>$set]);});
        if($canManage)HotelAgreement::with(['customer','vehicle'])->where('organization_id',$org)->where('status','active')->whereBetween('renews_on',[today(),today()->addDays(60)])->limit(50)->get()->each(fn($agreement)=>$items->push(['priority'=>3,'type'=>'Avtale','title'=>$agreement->vehicle->registration_number.' fornyes snart','detail'=>$agreement->customer->name.' · '.$agreement->renews_on->format('d.m.Y'),'kind'=>'agreement','model'=>$agreement]));
        TireSet::with(['vehicle.customer','storageLocation'])->where('organization_id',$org)->where('status','stored')->where(fn($q)=>$q->whereNull('last_counted_at')->orWhere('last_counted_at','<',today()->subYear()))->limit(50)->get()->each(fn($set)=>$items->push(['priority'=>3,'type'=>'Telling','title'=>($set->vehicle?->registration_number??$set->code).' bør kontrolltelles','detail'=>$set->storageLocation?->code??'Uten lagerplass','kind'=>'count','model'=>$set]));
        return view('actions.index',['items'=>$items->sortBy('priority')->values(),'counts'=>$items->countBy('type'),'tireSets'=>$tireSets->keyBy('id'),'locations'=>StorageLocation::withCount(['tireSets'=>fn($q)=>$q->whereIn('status',['received','stored','picked','workshop'])])->where('organization_id',$org)->where('branch_id',$branch)->where('active',true)->orderBy('code')->get(),'openTireSetId'=>$code!==''?$tireSets->firstWhere('code',$code)?->id:null]);
    }

    public function updateTireSet(Request $request,TireSet $tireSet,WarehousePlacementService $placement):RedirectResponse
    {
        $org=(int)$request->user()->organization_id;abort_unless((int)$tireSet->organization_id===$org,404);
        abort_unless(in_array($request->user()->role,['owner','admin','manager','technician','warehouse'],true),403);
        $data=$request->validate(['storage_location_id'=>['required','integer'],'wash_status'=>['required','in:needed,washed,not_needed'],'wheels'=>['nullable','array','size:4'],'wheels.*.position'=>['required_with:wheels','distinct','in:front_left,front_right,rear_left,rear_right'],'wheels.*.tread_depth_mm'=>['nullable','numeric','between:0,20']]);
        $depths=collect($data['wheels']??[])->pluck('tread_depth_mm')->filter(fn($v)=>$v!==null&&$v!=='');
        if($tireSet->minimum_tread_depth===null&&$depths->count()!==4)throw ValidationException::withMessages(['wheels'=>'Mål alle fire dekkene før avviket fullføres.']);
        if($depths->isNotEmpty()&&$depths->count()!==4)throw ValidationException::withMessages(['wheels'=>'Fyll inn alle fire mønsterdybdene, eller la alle stå tomme.']);
        $location=StorageLocation::where('organization_id',$org)->where('branch_id',$request->user()->branch_id)->whereKey($data['storage_location_id'])->firstOrFail();
        $shelf=$tireSet->storage_location_id===$location->id?($tireSet->storage_shelf_number?:$placement->nextShelf($location)):$placement->nextShelf($location);
        if($shelf===null)return back()->withErrors(['storage_location_id'=>$location->code.' er full. Velg en annen reol.'])->withInput();
        DB::transaction(function()use($request,$tireSet,$location,$shelf,$data,$depths,$org){$from=$tireSet->storage_location_id;$updates=['storage_location_id'=>$location->id,'storage_shelf_number'=>$shelf,'status'=>$tireSet->status==='received'?'stored':$tireSet->status,'wash_status'=>$data['wash_status'],'washed'=>$data['wash_status']==='washed'];if($depths->isNotEmpty())$updates['minimum_tread_depth']=(float)$depths->min();$tireSet->update($updates);if($from!==$location->id)DB::table('storage_location_movements')->insert(['organization_id'=>$org,'tire_set_id'=>$tireSet->id,'from_location_id'=>$from,'to_location_id'=>$location->id,'moved_by'=>$request->user()->id,'reason'=>'action_center','moved_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);if($depths->isNotEmpty()){$minimum=(float)$depths->min();$inspection=TireInspection::create(['public_id'=>(string)Str::uuid(),'organization_id'=>$org,'tire_set_id'=>$tireSet->id,'inspected_by'=>$request->user()->id,'overall_status'=>$minimum<3?'replace':($minimum<4?'attention':'good'),'inspected_at'=>now()]);foreach($data['wheels']as$wheel)$inspection->measurements()->create([...$wheel,'tpms_status'=>'not_checked','tire_damage'=>false,'rim_damage'=>false,'uneven_wear'=>false]);}DB::table('audit_logs')->insert(['organization_id'=>$org,'user_id'=>$request->user()->id,'action'=>'tire_set.action_completed','subject_type'=>TireSet::class,'subject_id'=>$tireSet->id,'ip_address'=>$request->ip(),'metadata'=>json_encode(['location_id'=>$location->id,'wash_status'=>$data['wash_status'],'measured'=>$depths->isNotEmpty()]),'created_at'=>now()]);});
        return redirect()->route('actions')->with('success','Hjulsettet er oppdatert og avviket er håndtert.')->with('label_url',route('tire-sets.labels',['ids'=>$tireSet->id]));
    }

    public function resolve(Request $request,string $kind,int $id,BookingWorkflowService $bookings):RedirectResponse
    {
        $org=(int)$request->user()->organization_id;
        $data=$request->validate(['decision'=>['required','string','in:confirm,cancel,retry,renew,count']]);
        if($kind==='booking'){
            $booking=Booking::where('organization_id',$org)->where('branch_id',$request->user()->branch_id)->findOrFail($id);
            abort_unless($booking->confirmation_status==='pending',409,'Bookingen er allerede håndtert.');
            if($data['decision']==='confirm'){$booking->update(['confirmation_status'=>'confirmed','confirmation_responded_at'=>now(),'status'=>'scheduled']);$bookings->createWorkOrder($booking);$message='Bookingen er bekreftet.';}
            else{abort_unless($data['decision']==='cancel',422);$booking->update(['confirmation_status'=>'declined','confirmation_responded_at'=>now(),'status'=>'cancelled']);$message='Bookingen er avbestilt.';}
        }elseif($kind==='message'){
            abort_unless(in_array($request->user()->role,['owner','admin','manager'],true),403);abort_unless($data['decision']==='retry',422);
            $item=OutboundMessage::where('organization_id',$org)->where('status','failed')->findOrFail($id);$item->update(['status'=>'queued','attempts'=>0,'scheduled_at'=>now(),'failed_at'=>null,'last_error'=>null]);$message='Meldingen er lagt tilbake i utsendingskøen.';
        }elseif($kind==='agreement'){
            abort_unless(in_array($request->user()->role,['owner','admin','manager'],true),403);abort_unless($data['decision']==='renew',422);
            $item=HotelAgreement::where('organization_id',$org)->where('status','active')->findOrFail($id);$item->update(['renews_on'=>($item->renews_on??today())->addMonthsNoOverflow(6),'billed_at'=>null]);$message='Hotellavtalen er fornyet med seks måneder.';
        }elseif($kind==='count'){
            abort_unless($data['decision']==='count',422);$item=TireSet::where('organization_id',$org)->findOrFail($id);$item->update(['last_counted_at'=>now(),'last_counted_by'=>$request->user()->id]);$message=$item->code.' er kontrolltelt.';
        }else abort(404);
        DB::table('audit_logs')->insert(['organization_id'=>$org,'user_id'=>$request->user()->id,'action'=>'action_center.'.$kind.'.'.$data['decision'],'subject_id'=>$id,'ip_address'=>$request->ip(),'created_at'=>now()]);
        return redirect()->route('actions')->with('success',$message);
    }
}
