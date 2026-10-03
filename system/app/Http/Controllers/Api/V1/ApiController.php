<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\TireSet;
use App\Models\StorageLocation;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\TireInspection;
use App\Models\WorkOrder;
use App\Services\TestDataGuard;
use App\Services\Accounting\AccountingExportService;
use App\Services\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ApiController extends Controller
{
    public function token(Request $request, TestDataGuard $guard, TotpService $totp): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required'], 'device_name' => ['required', 'string', 'max:100'], 'otp_code'=>['nullable','string','max:20']]);
        $user = User::where('email', strtolower($data['email']))->where('active', true)->first();
        $passwordMatches = Hash::check($data['password'], $user?->password ?? Hash::make(Str::random(64)));
        if (!$user || !$passwordMatches || $guard->organization((int) $user->organization_id)) return response()->json(['message' => 'Ugyldige innloggingsopplysninger.'], 401);
        if ($user->two_factor_confirmed_at) {
            $code = strtoupper(preg_replace('/\s/', '', (string)($data['otp_code'] ?? '')));
            if ($code === '' || !$totp->verify((string)$user->two_factor_secret, $code)) return response()->json(['message'=>'Tofaktorkode kreves.','code'=>'two_factor_required'], 422);
        }
        $plain = Str::random(64);
        $abilities = in_array($user->role, ['owner','admin','manager','technician','warehouse'], true) ? ['read','workshop.write'] : ['read'];
        DB::table('personal_access_tokens')->insert(['user_id' => $user->id, 'name' => $data['device_name'], 'device_name' => $data['device_name'], 'token_hash' => hash('sha256', $plain), 'abilities' => json_encode($abilities), 'expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['token' => $plain, 'token_type' => 'Bearer', 'expires_in' => 2592000, 'abilities'=>$abilities]);
    }

    public function revokeToken(Request $request): JsonResponse
    {
        DB::table('personal_access_tokens')->where('id', $request->attributes->get('api_token')->id)->update(['revoked_at'=>now(),'updated_at'=>now()]);
        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse { return response()->json(['data' => $request->user()->only('name', 'email', 'role', 'organization_id', 'branch_id')]); }

    public function customers(Request $request): JsonResponse
    {
        $this->requireRole($request, ['owner','admin','manager','customer_service']);
        $items = Customer::with('vehicles')->where('organization_id', $request->user()->organization_id)->latest()->paginate(min(100, max(1, (int) $request->query('per_page', 25))));
        return response()->json($items);
    }

    public function vehicles(Request $request): JsonResponse
    {
        $this->requireRole($request, ['owner','admin','manager','customer_service']);
        return response()->json(Vehicle::with('customer')->where('organization_id', $request->user()->organization_id)->paginate(50));
    }

    public function tireSets(Request $request): JsonResponse
    {
        return response()->json(TireSet::with(['vehicle.customer', 'storageLocation'])->where('organization_id', $request->user()->organization_id)->paginate(50));
    }

    public function warehouseMap(Request $request): JsonResponse
    {
        $locations = StorageLocation::withCount(['tireSets' => fn ($query) => $query->whereNotIn('status', ['delivered'])])->where('organization_id', $request->user()->organization_id)->where('branch_id', $request->user()->branch_id)->where('active', true)->orderBy('pick_order')->orderBy('code')->get();
        return response()->json(['data' => $locations, 'meta' => ['coordinate_system' => 'percent_0_100', 'map_ready' => $locations->whereNotNull('map_x')->isNotEmpty()]]);
    }

    public function bookings(Request $request): JsonResponse
    {
        $query=Booking::with(['customer','vehicle'])->where('organization_id',$request->user()->organization_id);
        if(in_array($request->user()->role,['technician','warehouse'],true))$query->where('assigned_user_id',$request->user()->id)->whereDate('starts_at',today());
        else{$query->where('starts_at','>=',$request->date('from',today()))->where('starts_at','<',$request->date('to',today()->addDays(60))->addDay());}
        return response()->json($query->orderBy('starts_at')->paginate(min(100,max(1,(int)$request->query('per_page',50)))));
    }

    public function workday(Request $request):JsonResponse
    {
        $user=$request->user();$today=Booking::with(['customer','vehicle'])->where('organization_id',$user->organization_id)->where('assigned_user_id',$user->id)->whereDate('starts_at',today())->whereNotIn('status',['cancelled','no_show'])->orderBy('starts_at')->get();$tomorrow=Booking::with(['customer','vehicle.tireSets.storageLocation'])->where('organization_id',$user->organization_id)->where('branch_id',$user->branch_id)->whereDate('starts_at',today()->addDay())->whereNotIn('status',['cancelled','no_show'])->orderBy('starts_at')->get();return response()->json(['data'=>['date'=>today()->toDateString(),'assigned_bookings'=>$today,'tomorrow_preparation'=>$tomorrow]]);
    }

    public function scan(Request$request,string$code):JsonResponse
    {
        $set=TireSet::with(['vehicle.customer','storageLocation'])->where('organization_id',$request->user()->organization_id)->where('code',strtoupper(trim($code)))->firstOrFail();return response()->json(['data'=>$set,'meta'=>['scan_represents_entire_set'=>true]]);
    }

    public function moveTireSet(Request$request,TireSet$tireSet,\App\Services\WarehousePlacementService $placement):JsonResponse
    {
        $this->requireRole($request, ['owner','admin','manager','technician','warehouse']);
        abort_unless($tireSet->organization_id===$request->user()->organization_id,404);$data=$request->validate(['status'=>['required','in:picked,workshop,stored,delivered'],'storage_location_id'=>['nullable','integer'],...\App\Services\WarehousePlacementService::rules()]);$from=$tireSet->storage_location_id;$to=array_key_exists('storage_location_id',$data)?$data['storage_location_id']:$from;if($to)abort_unless(StorageLocation::where('organization_id',$tireSet->organization_id)->whereKey($to)->exists(),422);DB::transaction(function()use($tireSet,$data,$request,$from,$to,$placement){$location=$to?StorageLocation::where('organization_id',$tireSet->organization_id)->findOrFail($to):null;$coordinates=$data['status']==='delivered'?['storage_shelf_number'=>null,'storage_position_number'=>null]:$placement->coordinates($location,$tireSet,$data);$tireSet->update(['status'=>$data['status'],'storage_location_id'=>$to,...$coordinates,'delivered_at'=>$data['status']==='delivered'?now():null]);if($from!==$to)DB::table('storage_location_movements')->insert(['organization_id'=>$tireSet->organization_id,'tire_set_id'=>$tireSet->id,'from_location_id'=>$from,'to_location_id'=>$to,'moved_by'=>$request->user()->id,'reason'=>'api_scan','moved_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);DB::table('audit_logs')->insert(['organization_id'=>$tireSet->organization_id,'user_id'=>$request->user()->id,'action'=>'tire_set.workflow.'.$data['status'],'subject_type'=>TireSet::class,'subject_id'=>$tireSet->id,'metadata'=>json_encode(['source'=>'api','one_scan_entire_set'=>true]),'created_at'=>now()]);});return response()->json(['data'=>$tireSet->fresh(),'meta'=>['entire_set_updated'=>true]]);
    }

    public function inspectTireSet(Request $request, TireSet $tireSet): JsonResponse
    {
        $this->requireRole($request, ['owner','admin','manager','technician']);
        abort_unless($tireSet->organization_id === $request->user()->organization_id, 404);
        $data=$request->validate(['notes'=>['nullable','string','max:3000'],'wheels'=>['required','array','size:4'],'wheels.*.position'=>['required','distinct','in:front_left,front_right,rear_left,rear_right'],'wheels.*.tread_depth_mm'=>['nullable','numeric','between:0,20'],'wheels.*.dot_year'=>['nullable','integer','between:1990,2100'],'wheels.*.tpms_status'=>['required','in:ok,warning,missing,not_checked'],'wheels.*.tire_damage'=>['nullable','boolean'],'wheels.*.rim_damage'=>['nullable','boolean'],'wheels.*.uneven_wear'=>['nullable','boolean'],'wheels.*.notes'=>['nullable','string','max:500']]);
        $inspection=DB::transaction(function()use($data,$request,$tireSet){$depths=collect($data['wheels'])->pluck('tread_depth_mm')->filter(fn($v)=>$v!==null);$minimum=$depths->isEmpty()?null:(float)$depths->min();$needsHelp=collect($data['wheels'])->contains(fn($w)=>!empty($w['tire_damage'])||!empty($w['rim_damage'])||!empty($w['uneven_wear'])||in_array($w['tpms_status'],['warning','missing'],true));$status=($minimum!==null&&$minimum<3)||$needsHelp?'replace':(($minimum!==null&&$minimum<4)?'attention':'good');$inspection=TireInspection::create(['public_id'=>(string)Str::uuid(),'organization_id'=>$tireSet->organization_id,'tire_set_id'=>$tireSet->id,'inspected_by'=>$request->user()->id,'overall_status'=>$status,'notes'=>$data['notes']??null,'inspected_at'=>now()]);foreach($data['wheels']as$wheel)$inspection->measurements()->create([...$wheel,'tire_damage'=>!empty($wheel['tire_damage']),'rim_damage'=>!empty($wheel['rim_damage']),'uneven_wear'=>!empty($wheel['uneven_wear'])]);$tireSet->update(['minimum_tread_depth'=>$minimum,'condition_notes'=>$data['notes']??$tireSet->condition_notes]);return$inspection;});
        return response()->json(['data'=>$inspection->load('measurements')],201);
    }

    public function workOrders(Request $request): JsonResponse
    {
        $query=WorkOrder::with(['customer','vehicle','tasks','reservations'])->where('organization_id',$request->user()->organization_id);
        if(in_array($request->user()->role,['technician','warehouse'],true))$query->where('assigned_user_id',$request->user()->id);
        if($request->filled('status'))$query->where('status',$request->string('status'));
        return response()->json($query->latest()->paginate(min(100,max(1,(int)$request->query('per_page',30)))));
    }

    public function workOrder(Request $request, WorkOrder $workOrder): JsonResponse
    {
        abort_unless($workOrder->organization_id===$request->user()->organization_id,404);
        if(in_array($request->user()->role,['technician','warehouse'],true))abort_unless($workOrder->assigned_user_id===$request->user()->id,403);
        return response()->json(['data'=>$workOrder->load(['customer','vehicle.tireSets.inspections.measurements','booking','quote.items','tasks','reservations'])]);
    }

    public function updateWorkOrder(Request $request, WorkOrder $workOrder, AccountingExportService $accounting): JsonResponse
    {
        $this->requireRole($request, ['owner','admin','manager','technician']);
        abort_unless($workOrder->organization_id===$request->user()->organization_id,404);
        if ($request->user()->role === 'technician') abort_unless($workOrder->assigned_user_id === $request->user()->id, 403);
        $data=$request->validate(['status'=>['required','in:ready,in_progress,quality_check,completed']]);if($data['status']==='completed'&&$workOrder->tasks()->where('required',true)->where('completed',false)->exists())return response()->json(['message'=>'Obligatoriske kontrollpunkter gjenstår.'],422);$updates=['status'=>$data['status']];if($data['status']==='in_progress'&&!$workOrder->started_at)$updates['started_at']=now();if($data['status']==='completed')$updates['completed_at']=now();DB::transaction(function()use($workOrder,$updates,$data,$accounting){$workOrder->update($updates);if($data['status']==='completed'){foreach($workOrder->reservations()->where('status','reserved')->lockForUpdate()->get()as$reservation){$product=\App\Models\TireProduct::whereKey($reservation->tire_product_id)->lockForUpdate()->first();if($product)$product->update(['stock_quantity'=>max(0,$product->stock_quantity-$reservation->quantity)]);$reservation->update(['status'=>'consumed']);}if($workOrder->booking){$workOrder->booking->update(['status'=>'completed']);$accounting->createFromBooking($workOrder->booking);}}});DB::table('audit_logs')->insert(['organization_id'=>$workOrder->organization_id,'user_id'=>$request->user()->id,'action'=>'work_order.'.$data['status'],'subject_type'=>WorkOrder::class,'subject_id'=>$workOrder->id,'metadata'=>json_encode(['source'=>'api']),'created_at'=>now()]);return response()->json(['data'=>$workOrder->fresh('tasks')]);
    }

    public function updateWorkOrderTask(Request $request, WorkOrder $workOrder, int $task): JsonResponse
    {
        $this->requireRole($request, ['owner','admin','manager','technician']);
        abort_unless($workOrder->organization_id===$request->user()->organization_id,404);
        if ($request->user()->role === 'technician') abort_unless($workOrder->assigned_user_id === $request->user()->id, 403);
        $data=$request->validate(['completed'=>['required','boolean'],'notes'=>['nullable','string','max:1000']]);$item=$workOrder->tasks()->findOrFail($task);$item->update(['completed'=>$data['completed'],'notes'=>$data['notes']??$item->notes,'completed_by'=>$data['completed']?$request->user()->id:null,'completed_at'=>$data['completed']?now():null]);return response()->json(['data'=>$item]);
    }

    private function requireRole(Request $request, array $roles): void
    {
        abort_unless(in_array($request->user()->role, $roles, true), 403);
    }
}
