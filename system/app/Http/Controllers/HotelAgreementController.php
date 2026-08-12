<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\HotelAgreement;
use App\Models\ServiceProduct;
use App\Models\TireSet;
use App\Models\Vehicle;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class HotelAgreementController extends Controller
{
    public function index(Request $request): View
    {
        $org=$request->user()->organization_id;
        $query=HotelAgreement::query()->with(['customer','vehicle','tireSet.storageLocation'])->where('organization_id',$org);
        if(in_array($request->query('status'),['draft','active','paused','ended'],true))$query->where('status',$request->query('status'));
        if($request->query('filter')==='renewing')$query->where('status','active')->whereBetween('renews_on',[today(),today()->addDays(60)]);
        return view('hotel-agreements.index',['agreements'=>$query->latest()->paginate(40)->withQueryString(),'counts'=>HotelAgreement::where('organization_id',$org)->selectRaw('status,count(*) total')->groupBy('status')->pluck('total','status')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $org=$request->user()->organization_id;
        $data=$request->validate(['customer_id'=>['required','integer'],'vehicle_id'=>['required','integer'],'tire_set_id'=>['nullable','integer'],'price'=>['required','numeric','between:0,1000000'],'starts_on'=>['required','date'],'renews_on'=>['nullable','date','after_or_equal:starts_on'],'auto_renew'=>['nullable','boolean'],'terms_accepted'=>['nullable','accepted'],'notes'=>['nullable','string','max:2000']]);
        $customer=Customer::where('organization_id',$org)->findOrFail($data['customer_id']);$vehicle=Vehicle::where('organization_id',$org)->where('customer_id',$customer->id)->findOrFail($data['vehicle_id']);
        if(!empty($data['tire_set_id']))TireSet::where('organization_id',$org)->where('vehicle_id',$vehicle->id)->findOrFail($data['tire_set_id']);
        HotelAgreement::create(['public_id'=>(string)Str::uuid(),'organization_id'=>$org,'branch_id'=>$request->user()->branch_id,'customer_id'=>$customer->id,'vehicle_id'=>$vehicle->id,'tire_set_id'=>$data['tire_set_id']??null,'status'=>'active','starts_on'=>$data['starts_on'],'renews_on'=>$data['renews_on']??now()->parse($data['starts_on'])->addMonthsNoOverflow(6),'price_cents'=>(int)round($data['price']*100),'auto_renew'=>$request->boolean('auto_renew'),'terms_version'=>$request->boolean('terms_accepted')?'2026-08-10':null,'terms_accepted_at'=>$request->boolean('terms_accepted')?now():null,'notes'=>$data['notes']??null]);
        return back()->with('success','Dekkhotellavtalen er aktivert.');
    }

    public function status(Request $request, HotelAgreement $agreement): RedirectResponse
    {
        abort_unless($agreement->organization_id===$request->user()->organization_id,404);$data=$request->validate(['status'=>['required',Rule::in(['active','paused','ended'])]]);$agreement->update(['status'=>$data['status'],'ends_on'=>$data['status']==='ended'?today():null]);return back()->with('success','Avtalestatusen er oppdatert.');
    }

    public function count(Request $request, TireSet $tireSet): RedirectResponse
    {
        abort_unless($tireSet->organization_id===$request->user()->organization_id,404);$tireSet->update(['last_counted_at'=>now(),'last_counted_by'=>$request->user()->id]);DB::table('audit_logs')->insert(['organization_id'=>$tireSet->organization_id,'user_id'=>$request->user()->id,'action'=>'tire_set.counted','subject_type'=>TireSet::class,'subject_id'=>$tireSet->id,'ip_address'=>$request->ip(),'metadata'=>json_encode(['location_id'=>$tireSet->storage_location_id]),'created_at'=>now()]);return back()->with('success',$tireSet->code.' er kontrolltelt.');
    }
}
