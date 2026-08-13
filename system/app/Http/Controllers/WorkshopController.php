<?php

namespace App\Http\Controllers;

use App\Models\TireInspection;
use App\Models\TireSet;
use App\Models\WorkOrder;
use App\Services\Accounting\AccountingExportService;
use App\Services\TireInspectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class WorkshopController extends Controller
{
    public function index(Request $request): View
    {
        $orders = WorkOrder::with(['customer','vehicle','assignedUser','tasks','reservations'])
            ->where('organization_id', $request->user()->organization_id)
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'quality_check' THEN 1 WHEN 'ready' THEN 2 ELSE 3 END")
            ->latest()->paginate(30)->withQueryString();
        return view('work-orders.index', compact('orders'));
    }

    public function show(Request $request, WorkOrder $workOrder): View
    {
        $this->owns($request, $workOrder);
        return view('work-orders.show', ['order' => $workOrder->load(['customer','vehicle.tireSets.inspections.measurements','booking.services','quote.items','tasks','reservations'])]);
    }

    public function status(Request $request, WorkOrder $workOrder, AccountingExportService $accounting): RedirectResponse
    {
        $this->owns($request, $workOrder);
        $data = $request->validate(['status' => ['required','in:ready,in_progress,quality_check,completed,cancelled']]);
        if ($data['status'] === 'completed' && $workOrder->tasks()->where('required', true)->where('completed', false)->exists()) return back()->withErrors(['work_order' => 'Alle obligatoriske kontrollpunkter må fullføres først.']);
        $updates = ['status' => $data['status']];
        if ($data['status'] === 'in_progress' && !$workOrder->started_at) $updates['started_at'] = now();
        if ($data['status'] === 'completed') $updates['completed_at'] = now();
        $workOrder->update($updates);
        if ($data['status'] === 'completed') DB::transaction(function () use ($workOrder, $accounting) { foreach ($workOrder->reservations()->where('status','reserved')->lockForUpdate()->get() as $reservation) { $product=\App\Models\TireProduct::whereKey($reservation->tire_product_id)->lockForUpdate()->first(); if($product)$product->update(['stock_quantity'=>max(0,$product->stock_quantity-$reservation->quantity)]); $reservation->update(['status'=>'consumed']); } if($workOrder->booking){$workOrder->booking->update(['status'=>'completed']);$accounting->createFromBooking($workOrder->booking);} });
        if ($data['status'] === 'cancelled') $workOrder->reservations()->where('status','reserved')->update(['status'=>'released']);
        $this->audit($request, 'work_order.'.$data['status'], $workOrder);
        return back()->with('success', 'Arbeidsordren er oppdatert.');
    }

    public function task(Request $request, WorkOrder $workOrder, int $task, TireInspectionService $inspections): RedirectResponse
    {
        $this->owns($request, $workOrder);
        $item = $workOrder->tasks()->findOrFail($task);
        $data = $request->validate([
            'completed' => ['required', 'boolean'],
            'result' => ['nullable', 'required_if:completed,1', 'in:ok,attention,deviation,not_applicable'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $done = (bool) $data['completed'];
        if ($done && ($data['result'] ?? null) === 'deviation' && blank($data['notes'] ?? null)) {
            return back()->withErrors(['notes' => 'Beskriv avviket før kontrollpunktet fullføres.']);
        }
        $resultLabels = ['ok'=>'Utført og godkjent','attention'=>'Utført – bør følges opp','deviation'=>'Avvik registrert','not_applicable'=>'Ikke relevant'];
        $notes = $done
            ? '['.($resultLabels[$data['result']] ?? 'Utført').']'.(filled($data['notes'] ?? null) ? "\n".trim($data['notes']) : '')
            : (filled($data['notes'] ?? null) ? trim($data['notes']) : $item->notes);
        $item->update(['completed'=>$done,'notes'=>$notes,'completed_by'=>$done?$request->user()->id:null,'completed_at'=>$done?now():null]);
        if($done&&preg_match('/monter valgte dekk/i',$item->name)&&$workOrder->quote_id){$workOrder->loadMissing('quote.items');$quote=$workOrder->quote;$selected=$quote?->items->firstWhere('id',$quote->selected_quote_item_id)??$quote?->items->first();$product=$selected?->tire_product_id?\App\Models\TireProduct::find($selected->tire_product_id):null;$set=$quote?->source_tire_set_id?TireSet::where('organization_id',$workOrder->organization_id)->find($quote->source_tire_set_id):TireSet::where('organization_id',$workOrder->organization_id)->where('vehicle_id',$workOrder->vehicle_id)->first();if($set){$set->update(['manufacturer'=>$product?->brand??$set->manufacturer,'model'=>$product?->model??$set->model,'size'=>$product?->size??$set->size,'dot_year'=>now()->year,'condition_notes'=>'Nye dekk montert via arbeidsordre '.$workOrder->reference.'.']);$inspections->recordNewTires($set,$request->user()->id);}}
        $this->audit($request, $done ? 'work_order.task_completed' : 'work_order.task_reopened', $workOrder);
        return back()->with('success', $done ? 'Kontrollpunkt og informasjon er lagret.' : 'Kontrollpunktet er åpnet igjen.');
    }

    public function inspection(Request $request, TireSet $tireSet): View
    {
        abort_unless($tireSet->organization_id === $request->user()->organization_id, 404);
        return view('inspections.form', ['set'=>$tireSet->load(['vehicle.customer','inspections.measurements'])]);
    }

    public function storeInspection(Request $request, TireSet $tireSet): RedirectResponse
    {
        abort_unless($tireSet->organization_id === $request->user()->organization_id, 404);
        $data=$request->validate(['notes'=>['nullable','string','max:3000'],'wheels'=>['required','array','size:4'],'wheels.*.position'=>['required','in:front_left,front_right,rear_left,rear_right'],'wheels.*.tread_depth_mm'=>['nullable','numeric','between:0,20'],'wheels.*.dot_year'=>['nullable','integer','between:1990,2100'],'wheels.*.tpms_status'=>['required','in:ok,warning,missing,not_checked'],'wheels.*.tire_damage'=>['nullable','boolean'],'wheels.*.rim_damage'=>['nullable','boolean'],'wheels.*.uneven_wear'=>['nullable','boolean'],'wheels.*.notes'=>['nullable','string','max:500']]);
        DB::transaction(function()use($data,$request,$tireSet){$wheels=collect($data['wheels']);$depths=$wheels->pluck('tread_depth_mm')->filter(fn($v)=>$v!==null);$dotYears=$wheels->pluck('dot_year')->filter();$damage=$wheels->contains(fn($w)=>!empty($w['tire_damage'])||!empty($w['rim_damage'])||($w['tpms_status']??'')==='missing');$minimum=$depths->isEmpty()?null:(float)$depths->min();$oldestDot=$dotYears->isEmpty()?null:(int)$dotYears->min();$ageRequiresReplacement=$oldestDot!==null&&$oldestDot<=now()->year-10;$status=$damage||$ageRequiresReplacement||($minimum!==null&&$minimum<3)?'replace':(($minimum!==null&&$minimum<4)||($oldestDot!==null&&$oldestDot<=now()->year-5)||$wheels->contains(fn($w)=>!empty($w['uneven_wear'])||($w['tpms_status']??'')==='warning')?'attention':'good');$inspection=TireInspection::create(['public_id'=>(string)Str::uuid(),'organization_id'=>$tireSet->organization_id,'tire_set_id'=>$tireSet->id,'inspected_by'=>$request->user()->id,'overall_status'=>$status,'notes'=>$data['notes']??null,'inspected_at'=>now()]);foreach($data['wheels']as$wheel)$inspection->measurements()->create([...$wheel,'tire_damage'=>!empty($wheel['tire_damage']),'rim_damage'=>!empty($wheel['rim_damage']),'uneven_wear'=>!empty($wheel['uneven_wear'])]);$tireSet->update(['minimum_tread_depth'=>$minimum,'dot_year'=>$oldestDot??$tireSet->dot_year,'condition_notes'=>$data['notes']??$tireSet->condition_notes]);});
        return redirect()->route('tire-sets.inspection',$tireSet)->with('success','Kontrollen er lagret for alle fire hjul.');
    }

    private function owns(Request $request, WorkOrder $order): void { abort_unless($order->organization_id === $request->user()->organization_id, 404); }
    private function audit(Request $request,string $action,WorkOrder $order):void{DB::table('audit_logs')->insert(['organization_id'=>$order->organization_id,'user_id'=>$request->user()->id,'action'=>$action,'subject_type'=>WorkOrder::class,'subject_id'=>$order->id,'ip_address'=>$request->ip(),'created_at'=>now()]);}
}
