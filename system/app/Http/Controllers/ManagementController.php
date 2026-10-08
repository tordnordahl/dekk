<?php
namespace App\Http\Controllers;

use App\Models\ServiceSetting;
use App\Models\TireProduct;
use App\Models\User;
use App\Models\WorkBay;
use App\Models\IntegrationSetting;
use App\Models\ServiceProduct;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ManagementController extends Controller
{
    public function employee(Request $request): RedirectResponse
    {
        $org = $request->user()->organization_id;
        $data = $request->validate([
            'name'=>['required','string','max:255'], 'email'=>['required','email','max:255',Rule::unique('users','email')],
            'role'=>['required',Rule::in(['admin','manager','customer_service','technician','warehouse','accounting'])],
            'password'=>['required','string','min:12','regex:/[a-z]/','regex:/[A-Z]/','regex:/[0-9]/'],
        ]);
        User::create([...$data,'organization_id'=>$org,'branch_id'=>$request->user()->branch_id,'active'=>true]);
        return back()->with('success','Den ansatte er opprettet og kan logge inn.');
    }

    public function updateEmployee(Request $request, User $employee): RedirectResponse
    {
        $org = (int) $request->user()->organization_id;
        abort_unless((int) $employee->organization_id === $org && !$employee->is_super_admin, 404);

        $roles = ['admin','manager','customer_service','technician','warehouse','accounting'];
        if ($employee->role === 'owner') $roles[] = 'owner';
        $data = $request->validate([
            'name' => ['required','string','max:255'],
            'email' => ['required','email','max:255',Rule::unique('users','email')->ignore($employee->id)],
            'role' => ['required',Rule::in($roles)],
            'password' => ['nullable','string','min:12','regex:/[a-z]/','regex:/[A-Z]/','regex:/[0-9]/'],
        ]);
        if (($employee->id === $request->user()->id || $employee->role === 'owner') && $data['role'] !== $employee->role) {
            return back()->withErrors(['employee' => 'Rollen til denne kontoen kan ikke endres her.']);
        }
        if (blank($data['password'] ?? null)) unset($data['password']);

        DB::transaction(function () use ($employee, $data, $request, $org) {
            $before = $employee->only(['name','email','role']);
            $employee->update($data);
            DB::table('audit_logs')->insert([
                'organization_id'=>$org, 'user_id'=>$request->user()->id,
                'action'=>'employee.updated', 'subject_type'=>User::class, 'subject_id'=>$employee->id,
                'ip_address'=>$request->ip(),
                'metadata'=>json_encode(['before'=>$before,'after'=>$employee->only(['name','email','role']),'password_changed'=>array_key_exists('password',$data)]),
                'created_at'=>now(),
            ]);
        });
        return back()->with('success','Den ansatte «'.$employee->name.'» er oppdatert.');
    }

    public function toggleEmployee(Request $request, User $employee): RedirectResponse
    {
        abort_unless($employee->organization_id === $request->user()->organization_id, 404);
        abort_if($employee->is_super_admin, 404);
        abort_if($employee->id === $request->user()->id, 422, 'Du kan ikke deaktivere din egen konto.');
        $employee->update(['active'=>!$employee->active]);
        DB::table('audit_logs')->insert(['organization_id'=>$employee->organization_id,'user_id'=>$request->user()->id,'action'=>$employee->active?'employee.activated':'employee.deactivated','subject_type'=>User::class,'subject_id'=>$employee->id,'ip_address'=>$request->ip(),'created_at'=>now()]);
        return back()->with('success','Tilgangen til '.$employee->name.' er oppdatert.');
    }

    public function bay(Request $request): RedirectResponse
    {
        $data=$request->validate(['name'=>['required','string','max:100'],'code'=>['required','string','max:20'],'type'=>['required',Rule::in(['tire_lift','vehicle_lift','workstation'])]]);
        WorkBay::create([...$data,'public_id'=>(string)Str::uuid(),'organization_id'=>$request->user()->organization_id,'branch_id'=>$request->user()->branch_id]);
        return back()->with('success','Arbeidsbukken er lagt til.');
    }

    public function timing(Request $request): RedirectResponse
    {
        $data=$request->validate(['minutes_per_wheel'=>['required','integer','between:1,60'],'booking_buffer_minutes'=>['required','integer','between:0,120'],'quote_expiry_days'=>['required','integer','between:1,90'],'booking_horizon_days'=>['required','integer','between:7,365'],'minimum_booking_notice_hours'=>['required','integer','between:0,168'],'preparation_days'=>['required','integer','between:0,14'],'weekday_open'=>['required','date_format:H:i'],'weekday_close'=>['required','date_format:H:i','after:weekday_open'],'saturday_open'=>['nullable','date_format:H:i'],'saturday_close'=>['nullable','required_with:saturday_open','date_format:H:i','after:saturday_open']]);
        $weekly=[1=>[$data['weekday_open'],$data['weekday_close']],2=>[$data['weekday_open'],$data['weekday_close']],3=>[$data['weekday_open'],$data['weekday_close']],4=>[$data['weekday_open'],$data['weekday_close']],5=>[$data['weekday_open'],$data['weekday_close']]];
        if(!empty($data['saturday_open']))$weekly[6]=[$data['saturday_open'],$data['saturday_close']];
        unset($data['weekday_open'],$data['weekday_close'],$data['saturday_open'],$data['saturday_close']);
        ServiceSetting::updateOrCreate(['branch_id'=>$request->user()->branch_id],[...$data,'weekly_hours'=>$weekly,'organization_id'=>$request->user()->organization_id]);
        return back()->with('success','Tids- og tilbudsinnstillingene er lagret.');
    }

    public function labelReminders(Request $request): RedirectResponse
    {
        ServiceSetting::updateOrCreate(['branch_id'=>$request->user()->branch_id],['organization_id'=>$request->user()->organization_id,'label_reminders_enabled'=>$request->boolean('enabled')]);
        return back()->with('success',$request->boolean('enabled')?'Etikettutskrift er aktivert.':'Etikettutskrift og etikettpåminnelser er slått av.');
    }

    public function tireProduct(Request $request): RedirectResponse
    {
        $org=$request->user()->organization_id;
        $data=$request->validate([
            'sku'=>['required','string','max:64',Rule::unique('tire_products')->where('organization_id',$org)], 'brand'=>['required','string','max:100'],
            'model'=>['required','string','max:100'],'size'=>['required','string','max:64'],'season'=>['required',Rule::in(['summer','winter','all_season'])],
            'price'=>['required','numeric','between:0,100000'],'cost'=>['nullable','numeric','between:0,100000'],'stock_quantity'=>['required','integer','between:0,100000'],'studded'=>['nullable','boolean'],
        ]);
        TireProduct::create(['public_id'=>(string)Str::uuid(),'organization_id'=>$org,'sku'=>$data['sku'],'brand'=>$data['brand'],'model'=>$data['model'],'size'=>$data['size'],'season'=>$data['season'],'studded'=>$request->boolean('studded'),'price_cents'=>(int)round($data['price']*100),'cost_cents'=>isset($data['cost'])?(int)round($data['cost']*100):null,'stock_quantity'=>$data['stock_quantity'],'active'=>true]);
        return back()->with('success','Dekket er lagt til i produktkatalogen.');
    }

    public function updateTireProduct(Request $request, TireProduct $product): RedirectResponse
    {
        $org=$request->user()->organization_id;
        abort_unless($product->organization_id===$org,404);
        $bag='product'.$product->id;
        $data=$request->validateWithBag($bag,[
            'sku'=>['required','string','max:64',Rule::unique('tire_products')->where('organization_id',$org)->ignore($product->id)],
            'brand'=>['required','string','max:100'],'model'=>['required','string','max:100'],'size'=>['required','string','max:64'],
            'season'=>['required','in:summer,winter,all_season'],'studded'=>['nullable','boolean'],
            'price'=>['required','numeric','between:0,100000'],'cost'=>['nullable','numeric','between:0,100000'],
            'stock_quantity'=>['required','integer','between:0,100000'],'active'=>['required','boolean'],
        ]);
        DB::transaction(function() use($request,$product,$data,$bag,$org) {
            $locked=TireProduct::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $reserved=app(\App\Services\InventoryAvailabilityService::class)->reserved($locked->id);
            if($data['stock_quantity']<$reserved || (!$data['active'] && $reserved>0)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['stock_quantity'=>'Varen har '.$reserved.' reserverte dekk. Lagerantallet kan ikke være lavere, og varen kan ikke tas ut før reservasjonene er avsluttet.'])->errorBag($bag);
            }
            $before=$locked->only(['sku','brand','model','size','season','stock_quantity','active','price_cents','cost_cents','studded']);
            $locked->update(['sku'=>$data['sku'],'brand'=>$data['brand'],'model'=>$data['model'],'size'=>$data['size'],'season'=>$data['season'],
                'studded'=>$data['season']==='winter' && $request->boolean('studded'),'price_cents'=>(int)round($data['price']*100),
                'cost_cents'=>isset($data['cost'])?(int)round($data['cost']*100):null,'stock_quantity'=>$data['stock_quantity'],'active'=>(bool)$data['active']]);
            DB::table('audit_logs')->insert(['organization_id'=>$org,'user_id'=>$request->user()->id,'action'=>'tire_product.updated','subject_type'=>TireProduct::class,'subject_id'=>$locked->id,
                'metadata'=>json_encode(['before'=>$before,'after'=>$locked->only(array_keys($before))]),'created_at'=>now()]);
        });
        return back()->with('success','Dekkvaren er oppdatert. Tidligere tilbud og ordre er bevart.');
    }

    public function saveVegvesen(Request $request): RedirectResponse
    {
        $data = $request->validate(['api_key' => ['required','string','min:16','max:500']]);
        $org = $request->user()->organization_id;
        DB::transaction(function () use ($data, $org, $request) {
            IntegrationSetting::updateOrCreate(
                ['organization_id' => $org, 'provider' => 'vegvesen'],
                ['encrypted_credentials' => Crypt::encryptString(json_encode(['api_key' => trim($data['api_key'])], JSON_THROW_ON_ERROR)), 'active' => true, 'updated_by' => $request->user()->id]
            );
            DB::table('audit_logs')->insert(['organization_id'=>$org,'user_id'=>$request->user()->id,'action'=>'integration.vegvesen.updated','ip_address'=>$request->ip(),'metadata'=>json_encode(['credential_changed'=>true]),'created_at'=>now()]);
        });
        return back()->with('success', 'API-nøkkelen til Statens vegvesen er kryptert og lagret.');
    }

    public function deleteVegvesen(Request $request): RedirectResponse
    {
        $org = $request->user()->organization_id;
        IntegrationSetting::where('organization_id', $org)->where('provider', 'vegvesen')->delete();
        DB::table('audit_logs')->insert(['organization_id'=>$org,'user_id'=>$request->user()->id,'action'=>'integration.vegvesen.deleted','ip_address'=>$request->ip(),'metadata'=>json_encode(['credential_deleted'=>true]),'created_at'=>now()]);
        return back()->with('success', 'Den lagrede API-nøkkelen er fjernet.');
    }

    public function serviceProduct(Request $request): RedirectResponse
    {
        $org=$request->user()->organization_id;
        $data=$request->validate(['code'=>['required','string','max:50',Rule::unique('service_products')->where('organization_id',$org)],'name'=>['required','string','max:150'],'description'=>['nullable','string','max:1000'],'category'=>['required',Rule::in(['storage','tire_change','repair','workshop','other'])],'fixed_price'=>['required','numeric','between:0,1000000'],'vat_rate'=>['required','numeric','between:0,100'],'duration_minutes'=>['required','integer','between:5,1440'],'is_favorite'=>['nullable','boolean']]);
        ServiceProduct::create(['public_id'=>(string)Str::uuid(),'organization_id'=>$org,'code'=>strtoupper($data['code']),'name'=>$data['name'],'description'=>$data['description']??null,'category'=>$data['category'],'fixed_price_cents'=>(int)round($data['fixed_price']*100),'vat_rate'=>$data['vat_rate'],'duration_minutes'=>$data['duration_minutes'],'active'=>true,'is_favorite'=>$request->boolean('is_favorite')]);
        return back()->with('success','Tjenesten og fastprisen er lagt til.');
    }

    public function toggleService(Request $request, ServiceProduct $service): RedirectResponse
    {
        abort_unless($service->organization_id===$request->user()->organization_id,404);$service->update(['active'=>!$service->active]);return back()->with('success','Tjenesten er oppdatert.');
    }

    public function updateService(Request $request, ServiceProduct $service): RedirectResponse
    {
        $org = $request->user()->organization_id;
        abort_unless($service->organization_id === $org, 404);
        $data = $request->validate([
            'code' => ['required','string','max:50',Rule::unique('service_products')->where('organization_id',$org)->ignore($service->id)],
            'name' => ['required','string','max:150'],
            'description' => ['nullable','string','max:1000'],
            'category' => ['required',Rule::in(['storage','tire_change','repair','workshop','other'])],
            'fixed_price' => ['required','numeric','between:0,1000000'],
            'vat_rate' => ['required','numeric','between:0,100'],
            'duration_minutes' => ['required','integer','between:5,1440'],
            'is_favorite' => ['nullable','boolean'],
        ]);
        $service->update(['code'=>strtoupper($data['code']),'name'=>$data['name'],'description'=>$data['description']??null,'category'=>$data['category'],'fixed_price_cents'=>(int)round($data['fixed_price']*100),'vat_rate'=>$data['vat_rate'],'duration_minutes'=>$data['duration_minutes'],'is_favorite'=>$request->boolean('is_favorite')]);
        DB::table('audit_logs')->insert(['organization_id'=>$org,'user_id'=>$request->user()->id,'action'=>'service.updated','subject_type'=>ServiceProduct::class,'subject_id'=>$service->id,'ip_address'=>$request->ip(),'created_at'=>now()]);
        return back()->with('success','Tjenesten «'.$service->name.'» er oppdatert.');
    }
}
