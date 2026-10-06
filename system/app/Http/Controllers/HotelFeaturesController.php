<?php
namespace App\Http\Controllers;
use App\Models\{TireSet,Vehicle,StorageLocation,Organization};
use App\Services\{LabelSettings,SeasonExchangeService,WarehousePlacementService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HotelFeaturesController extends Controller
{
    private function owns(Request $request, $model): void { abort_unless((int)$model->organization_id===(int)$request->user()->organization_id,404); }
    private function audit(Request $request,string $action,$model): void {
        DB::table('audit_logs')->insert(['organization_id'=>$request->user()->organization_id,'user_id'=>$request->user()->id,'action'=>$action,'subject_type'=>get_class($model),'subject_id'=>$model->id,'created_at'=>now()]);
    }
    public function archiveRack(Request $request, StorageLocation $location) {
        $this->owns($request,$location);abort_unless((int)$location->branch_id===(int)$request->user()->branch_id,404);
        $request->validate(['confirmation'=>['required',Rule::in([$location->code])]]);
        DB::transaction(function() use($request,$location) {
            $location=StorageLocation::whereKey($location->id)->lockForUpdate()->firstOrFail();
            if($location->tireSets()->where('status','!=','delivered')->exists()) throw \Illuminate\Validation\ValidationException::withMessages(['location'=>'Flytt alle hjulsett ut av reolen før den fjernes.']);
            $location->update(['active'=>false,'archived_at'=>now()]);$this->audit($request,'warehouse.rack.archived',$location);
        });
        return back()->with('success','Reolen er fjernet fra oppsett og kart. Historikken er bevart.');
    }
    public function updateSet(Request $request,TireSet $tireSet) {
        $this->owns($request,$tireSet);
        $data=$request->validate(['hotel_notes'=>['nullable','string','max:4000'],'winter_type'=>['nullable','in:studded,unstudded']]);
        if($tireSet->season!=='winter') $data['winter_type']=null;
        $tireSet->update($data);$this->audit($request,'tire_set.details.updated',$tireSet);
        return back()->with('success','Hjulsettets opplysninger er lagret.');
    }
    public function vehicleContact(Request $request,Vehicle $vehicle) {
        $this->owns($request,$vehicle);
        $vehicle->update($request->validate(['contact_name'=>['nullable','string','max:255'],'contact_phone'=>['nullable','string','max:32']]));
        $this->audit($request,'vehicle.contact.updated',$vehicle);
        return back()->with('success','Bilens kontaktperson er lagret. Fakturamottaker er uendret.');
    }
    public function measurements(Request $request,TireSet $tireSet) {
        $this->owns($request,$tireSet);
        return view('inventory.measurements',['tireSet'=>$tireSet->load(['vehicle.customer','inspections.measurements'])]);
    }
    public function exchangeForm(Request $request,TireSet $tireSet) {
        $this->owns($request,$tireSet);
        return view('inventory.exchange',['out'=>$tireSet->load('vehicle'),
            'incoming'=>TireSet::where('organization_id',$tireSet->organization_id)->where('vehicle_id',$tireSet->vehicle_id)->whereKeyNot($tireSet->id)->where(fn($q)=>$q->where('status','delivered')->orWhereNull('received_at'))->get(),
            'locations'=>StorageLocation::where('organization_id',$tireSet->organization_id)->where('branch_id',$request->user()->branch_id)->where('active',true)->orderBy('code')->get()]);
    }
    public function exchange(Request $request,TireSet $tireSet,SeasonExchangeService $service) {
        $this->owns($request,$tireSet);
        $data=$request->validate(['confirm'=>['accepted'],'incoming_mode'=>['required','in:existing,new'],'incoming_id'=>['exclude_unless:incoming_mode,existing','required','integer'],
            'season'=>['exclude_unless:incoming_mode,new','required','in:summer,winter,all_season'],'manufacturer'=>['exclude_unless:incoming_mode,new','nullable','string','max:100'],'size'=>['exclude_unless:incoming_mode,new','nullable','string','max:50'],
            'winter_type'=>['exclude_unless:incoming_mode,new','nullable','in:studded,unstudded'],'hotel_notes'=>['exclude_unless:incoming_mode,new','nullable','string','max:4000'],
            'wheels'=>['exclude_unless:incoming_mode,new','required','array','size:4'],'wheels.*.position'=>['exclude_unless:incoming_mode,new','required','distinct','in:front_left,front_right,rear_left,rear_right'],'wheels.*.tread_depth_mm'=>['exclude_unless:incoming_mode,new','required','numeric','between:0,20'],
            'storage_location_id'=>['required','integer'],...WarehousePlacementService::rules()]);
        $in=$service->exchange($request->user(),$tireSet,$data);
        return redirect()->route('tire-sets.show',$in)->with('success','Sesongbyttet er registrert. Tidligere målinger er bevart. Kontroller innkommende hjul før videre bruk.');
    }
    public function labelSettings(Request $request) {
        return view('admin.labels',['settings'=>LabelSettings::forOrganization($request->user()->organization_id)]);
    }
    public function saveLabels(Request $request) {
        $data=$request->validate(LabelSettings::rules());$data['show_qr']=(bool)$data['show_qr'];
        $org=Organization::findOrFail($request->user()->organization_id);$org->forceFill(['label_settings'=>$data])->save();
        $this->audit($request,'labels.settings.updated',$org);
        return back()->with('success','Etikettoppsettet er lagret for virksomheten.');
    }
    public function previewLabels(Request $request) {
        $data=$request->validate(LabelSettings::rules());
        $set=new TireSet(['id'=>0,'code'=>'HJ-EKSEMPEL','season'=>'winter','winter_type'=>'unstudded','quantity'=>4,'manufacturer'=>'Eksempel','size'=>'205/55 R16','received_at'=>now(),'storage_position_number'=>12,'storage_shelf_number'=>3]);
        $vehicle=new Vehicle(['registration_number'=>'AB12345']);$vehicle->setRelation('customer',new \App\Models\Customer(['name'=>'Eksempelkunde']));
        $set->setRelation('vehicle',$vehicle);$set->setRelation('storageLocation',new StorageLocation(['code'=>'Rad 1']));
        $qr=(new \Endroid\QrCode\Builder\Builder(writer:new \Endroid\QrCode\Writer\SvgWriter(),data:'HJ-EKSEMPEL',size:240,margin:8))->build()->getDataUri();
        return view('inventory.labels',['sets'=>collect([$set]),'qrCodes'=>collect([0=>$qr]),'labelSettings'=>$data,'isPreview'=>true]);
    }
    public function export(Request $request) {
        $data=$request->validate(['season'=>['nullable','in:summer,winter,all_season'],'status'=>['nullable','in:received,stored,picked,workshop,delivered'],'q'=>['nullable','string','max:100']]);
        $query=TireSet::with(['vehicle.customer','storageLocation'])->where('organization_id',$request->user()->organization_id)->whereNotNull('received_at');
        if(!empty($data['season']))$query->where('season',$data['season']);
        if(!empty($data['status']))$query->where('status',$data['status']);else $query->where('status','!=','delivered');
        if(!empty($data['q']))$query->whereHas('vehicle',fn($q)=>$q->where('registration_number','like','%'.$data['q'].'%')->orWhereHas('customer',fn($c)=>$c->where('name','like','%'.$data['q'].'%')));
        $this->audit($request,'inventory.csv.exported',$request->user());
        return response()->streamDownload(function() use($query) {
            $f=fopen('php://output','w');fwrite($f,"\xEF\xBB\xBF");
            fputcsv($f,['Reg.nr.','Kunde','Kundetelefon','Kunde-e-post','Kontaktperson bil','Telefon bil','Hjulsett','Sesong','Pigg/piggfritt','Status','Rad/reol','Lengde','Høyde','Laveste mm','Kommentar'],';', '"','');
            foreach($query->lazyById(250) as $set) {
                $v=$set->vehicle;if(!$v || !$v->customer)continue;
                $cells=[$v->registration_number,$v->customer->name,$v->customer->phone,$v->customer->email,$v->contact_name,$v->contact_phone,$set->code,['summer'=>'Sommer','winter'=>'Vinter','all_season'=>'Helår'][$set->season],['studded'=>'Pigg','unstudded'=>'Piggfritt'][$set->winter_type]??'Ikke angitt',$set->status,$set->storageLocation?->code,$set->storage_position_number,$set->storage_shelf_number,$set->minimum_tread_depth,$set->hotel_notes];
                $cells=array_map(fn($v)=>preg_match('/^[\s]*[=+@\-]/u',(string)$v)?"'".$v:(string)$v,$cells);
                fputcsv($f,$cells,';', '"','');
            }
            fclose($f);
        },'dekkhotell-'.now()->format('Y-m-d').'.csv',['Content-Type'=>'text/csv; charset=UTF-8','Cache-Control'=>'no-store']);
    }
}
