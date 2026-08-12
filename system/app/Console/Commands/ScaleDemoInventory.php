<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\StorageLocation;
use App\Models\TireProduct;
use App\Models\TireSet;
use App\Models\Vehicle;
use App\Models\HotelAgreement;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkBay;
use App\Models\WorkOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ScaleDemoInventory extends Command
{
    protected $signature = 'demo:scale-inventory {--sets=600} {--organization=}';
    protected $description = 'Fyll demovirksomheten med realistiske kunder, bilparker og hjulsett';

    public function handle(): int
    {
        $count = max(1, min(5000, (int) $this->option('sets')));
        $org = $this->option('organization')
            ? Organization::findOrFail((int)$this->option('organization'))
            : Organization::firstOrCreate(['organization_number'=>'DEMO-DEKKPILOT'], ['public_id'=>(string)Str::uuid(),'name'=>'[DEMO] Nordisk Dekkhotell AS','email'=>'demo@dekkpilot.no','subscription_status'=>'active','billing_model'=>'invoice']);
        $branch = Branch::firstOrCreate(['organization_id'=>$org->id,'code'=>'HOVED'], ['public_id'=>(string)Str::uuid(),'name'=>'Demoavdeling Oslo','active'=>true]);
        $rackCount=max(7,(int)ceil(($count+ceil($count/9))*5/7/100));
        $codes=['MOTTAK'];for($i=1;$i<=$rackCount;$i++)$codes[]=chr(65+intdiv($i-1,4)).'-'.str_pad((string)((($i-1)%4)+1),2,'0',STR_PAD_LEFT);
        foreach($codes as $index=>$code) StorageLocation::updateOrCreate(['branch_id'=>$branch->id,'code'=>$code],['public_id'=>(string)(StorageLocation::where('branch_id',$branch->id)->where('code',$code)->value('public_id')?:Str::uuid()),'organization_id'=>$org->id,'zone'=>$index===0?'Mottak':substr($code,0,1),'location_type'=>$index===0?'receiving':'rack','shelf_count'=>10,'sets_per_shelf'=>10,'map_x'=>($index%4)*23+3,'map_y'=>intdiv($index,4)*20+5,'map_width'=>18,'map_height'=>14,'pick_order'=>$index+1,'capacity'=>100,'active'=>true]);
        $locations = StorageLocation::where('organization_id', $org->id)->where('active', true)->where('code', '!=', 'MOTTAK')->get();
        $technicians=collect(range(1,5))->map(fn($i)=>User::updateOrCreate(['organization_id'=>$org->id,'email'=>'tekniker'.$i.'@demo.dekkpilot.no'],['branch_id'=>$branch->id,'name'=>['Anders Vik','Sara Moen','Marius Berg','Nora Dahl','Emil Strand'][$i-1],'password'=>Hash::make(Str::random(40)),'role'=>'technician','active'=>true]));
        $bays=collect(range(1,3))->map(fn($i)=>WorkBay::updateOrCreate(['branch_id'=>$branch->id,'code'=>'BUKK-'.$i],['public_id'=>(string)(WorkBay::where('branch_id',$branch->id)->where('code','BUKK-'.$i)->value('public_id')?:Str::uuid()),'organization_id'=>$org->id,'name'=>'Løftebukk '.$i,'type'=>'vehicle_lift','active'=>true]));

        $sizes = ['205/55 R16','225/45 R17','225/50 R18','235/55 R19','245/45 R20','215/60 R17'];
        $tireBrands = ['Nokian','Continental','Michelin','Goodyear','Bridgestone','Hankook'];
        $cars = [
            ['Volkswagen','ID.4'], ['Toyota','RAV4'], ['Volvo','XC60'], ['Tesla','Model Y'],
            ['Skoda','Enyaq'], ['BMW','iX3'], ['Audi','Q4 e-tron'], ['Mercedes-Benz','EQB'],
            ['Ford','Mustang Mach-E'], ['Hyundai','IONIQ 5'], ['Kia','EV6'], ['Nissan','Qashqai'],
        ];
        $first = ['Ingrid','Martin','Nora','Emil','Sofie','Henrik','Maja','Jonas','Amalie','Oskar','Thea','Sander','Ida','Magnus','Leah','Aksel','Ella','Kristian','Tuva','Oliver','Live','Mathias','Selma','Elias','Aurora'];
        $last = ['Solberg','Dahl','Hansen','Johansen','Berg','Moen','Larsen','Nilsen','Lie','Strand','Haugen','Knutsen','Eriksen','Sæther','Bakke','Lunde','Vik','Aas','Holm','Eide','Rønning','Tangen','Sundby','Hagen','Myhre'];
        $companies = ['Nordlys Elektro AS','Fjordbud Logistikk AS','Grønn Drift AS','Sentrum Eiendom AS','Vestfold Servicepartner AS','Polar Bygg AS'];

        DB::transaction(function () use ($count, $org, $branch, $locations, $sizes, $tireBrands, $cars, $first, $last, $companies) {
            foreach ($sizes as $s => $size) foreach (['summer','winter'] as $season) foreach ([1,2,3] as $tier) {
                $sku = 'DEMO-'.($season === 'winter' ? 'V' : 'S').'-'.str_replace([' ','/'],'',$size).'-'.$tier;
                TireProduct::updateOrCreate(['organization_id'=>$org->id,'sku'=>$sku], [
                    'public_id'=>(string)(TireProduct::where('organization_id',$org->id)->where('sku',$sku)->value('public_id') ?: Str::uuid()),
                    'brand'=>$tireBrands[($s+$tier)%count($tireBrands)], 'model'=>['Premium Pro','Performance Plus','Everyday Grip'][$tier-1],
                    'size'=>$size, 'season'=>$season, 'studded'=>false, 'price_cents'=>[229900,179900,139900][$tier-1],
                    'cost_cents'=>[155000,120000,90000][$tier-1], 'stock_quantity'=>80+$s*10, 'active'=>true,
                ]);
            }

            for ($i=1; $i<=$count; $i++) {
                $offset = ($i-1) % 12;
                $block = intdiv($i-1, 12);
                $business = $offset < 4;
                $customerKey = $business ? $block*12+1 : ($offset < 6 ? $block*12+5 : $i);
                $number = 'BULK'.str_pad((string)$customerKey,5,'0',STR_PAD_LEFT);
                $privateIndex = max(0, $customerKey - 1);
                $name = $business ? $companies[$block % count($companies)].' · Avdeling '.($block+1) : $first[$privateIndex % count($first)].' '.$last[intdiv($privateIndex, count($first)) % count($last)];
                $customer = Customer::withTrashed()->updateOrCreate(['organization_id'=>$org->id,'customer_number'=>$number], [
                    'public_id'=>(string)(Customer::withTrashed()->where('organization_id',$org->id)->where('customer_number',$number)->value('public_id') ?: Str::uuid()),
                    'branch_id'=>$branch->id, 'type'=>$business?'business':'private', 'name'=>$name,
                    'organization_number'=>$business?str_pad((string)(910000000+$customerKey),9,'0',STR_PAD_LEFT):null,
                    'email'=>'bulk'.str_pad((string)$customerKey,5,'0',STR_PAD_LEFT).'@example.no', 'phone'=>'9'.str_pad((string)$customerKey,7,'0',STR_PAD_LEFT),
                    'postal_code'=>'0182', 'city'=>'OSLO',
                    'notes'=>'[DEMO-BULK] Syntetiske skaleringsdata. '.($business?'Bedriftskunde med bilpark.':'Privatkunde.'), 'deleted_at'=>null,
                ]);

                [$make,$model] = $cars[$i % count($cars)];
                $registration = 'DP'.str_pad((string)(10000+$i),5,'0',STR_PAD_LEFT);
                $vehicle = Vehicle::withTrashed()->updateOrCreate(['organization_id'=>$org->id,'registration_number'=>$registration], [
                    'public_id'=>(string)(Vehicle::withTrashed()->where('organization_id',$org->id)->where('registration_number',$registration)->value('public_id') ?: Str::uuid()),
                    'customer_id'=>$customer->id, 'make'=>$make, 'model'=>$model, 'model_year'=>2017+($i%9),
                    'recommended_tire_size'=>$sizes[$i%count($sizes)], 'notes'=>'[DEMO-BULK] Syntetisk kjøretøy for belastningstest.', 'deleted_at'=>null,
                ]);
                \App\Models\VehicleOwnershipPeriod::firstOrCreate(['vehicle_id'=>$vehicle->id,'customer_id'=>$customer->id,'ended_at'=>null],['organization_id'=>$org->id,'started_at'=>$vehicle->created_at?:now()]);
                $this->upsertWheelSet($org->id, $vehicle, $locations, $tireBrands, $sizes, $i, $i%2?'winter':'summer', 'DEMO-BULK-'.str_pad((string)$i,5,'0',STR_PAD_LEFT));

                if ($i % 9 === 0) {
                    $otherSeason = $i%2 ? 'summer' : 'winter';
                    $this->upsertWheelSet($org->id, $vehicle, $locations, $tireBrands, $sizes, $i+3, $otherSeason, 'DEMO-EXTRA-'.str_pad((string)$i,5,'0',STR_PAD_LEFT));
                }
            }

            Customer::where('organization_id',$org->id)->where('notes','like','[DEMO-BULK]%')->whereDoesntHave('vehicles')->forceDelete();
            $this->buildCommercialDemo($org,$branch);
        });

        $vehicles = Vehicle::where('organization_id',$org->id)->where('notes','like','[DEMO-BULK]%')->count();
        $sets = TireSet::where('organization_id',$org->id)->where('condition_notes','like','[DEMO-BULK]%')->count();
        $this->info("Demoen har nå {$vehicles} realistiske biler og {$sets} hjulsett. Flere privat- og bedriftskunder har flere biler.");
        return self::SUCCESS;
    }

    private function buildCommercialDemo(Organization $org, Branch $branch): void
    {
        $sets=TireSet::with('vehicle.customer')->where('organization_id',$org->id)->where('condition_notes','like','[DEMO-BULK]%')->whereIn('status',['stored','picked','workshop'])->orderBy('id')->get();
        foreach($sets->take((int)floor($sets->count()*.8)) as $set) HotelAgreement::updateOrCreate(['organization_id'=>$org->id,'vehicle_id'=>$set->vehicle_id,'tire_set_id'=>$set->id],['public_id'=>(string)(HotelAgreement::where('organization_id',$org->id)->where('tire_set_id',$set->id)->value('public_id')?:Str::uuid()),'branch_id'=>$branch->id,'customer_id'=>$set->vehicle->customer_id,'status'=>'active','starts_on'=>today()->subMonths(($set->id%10)+1),'renews_on'=>today()->addMonths(($set->id%11)+1),'price_cents'=>129900,'auto_renew'=>true,'terms_version'=>'demo-2026','terms_accepted_at'=>now()->subMonths(2),'notes'=>'[DEMO-BULK] Aktiv og automatisk koblet hotellavtale.']);

        $products=TireProduct::where('organization_id',$org->id)->where('active',true)->get()->groupBy(fn($p)=>$p->size.'|'.$p->season);
        foreach($sets->where('minimum_tread_depth','<',3)->take(12)->values() as $i=>$set){$status=['draft','sent','viewed','accepted','declined','sent'][$i%6];$options=$products->get($set->size.'|'.$set->season,collect())->take(3);if($options->isEmpty())continue;$quote=Quote::updateOrCreate(['organization_id'=>$org->id,'reference'=>'DEMO-T-'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT)],['public_id'=>(string)(Quote::where('organization_id',$org->id)->where('reference','DEMO-T-'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT))->value('public_id')?:Str::uuid()),'branch_id'=>$branch->id,'customer_id'=>$set->vehicle->customer_id,'vehicle_id'=>$set->vehicle_id,'source_tire_set_id'=>$set->id,'status'=>$status,'access_token_hash'=>hash('sha256','demo-quote-'.$org->id.'-'.$i),'subtotal_cents'=>(int)round($options->min('price_cents')*4/1.25),'vat_cents'=>(int)round($options->min('price_cents')*4-($options->min('price_cents')*4/1.25)),'total_cents'=>$options->min('price_cents')*4,'message'=>'[DEMO] Tre anbefalte dekkvalg basert på målt mønsterdybde.','sent_at'=>$status==='draft'?null:now()->subDays(5),'viewed_at'=>in_array($status,['viewed','accepted','declined'])?now()->subDays(4):null,'responded_at'=>in_array($status,['accepted','declined'])?now()->subDays(3):null,'expires_at'=>now()->addDays(14)]);$quote->items()->delete();foreach($options as$product)$quote->items()->create(['tire_product_id'=>$product->id,'description'=>$product->brand.' '.$product->model.' '.$product->size,'quantity'=>4,'unit_price_cents'=>$product->price_cents,'line_total_cents'=>$product->price_cents*4]);}

        $technicians=User::where('organization_id',$org->id)->where('role','technician')->where('active',true)->get();
        $vehicles=Vehicle::with('customer')->where('organization_id',$org->id)->where('notes','like','[DEMO-BULK]%')->take(6)->get();
        foreach($vehicles as$i=>$vehicle){$status=['draft','ready','in_progress','quality_check','completed','completed'][$i];$order=WorkOrder::updateOrCreate(['organization_id'=>$org->id,'reference'=>'DEMO-AO-'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT)],['public_id'=>(string)(WorkOrder::where('organization_id',$org->id)->where('reference','DEMO-AO-'.str_pad((string)($i+1),3,'0',STR_PAD_LEFT))->value('public_id')?:Str::uuid()),'branch_id'=>$branch->id,'customer_id'=>$vehicle->customer_id,'vehicle_id'=>$vehicle->id,'assigned_user_id'=>$i===0?null:$technicians->get($i%max(1,$technicians->count()))?->id,'status'=>$status,'started_at'=>in_array($status,['in_progress','quality_check','completed'])?now()->subHours(3):null,'completed_at'=>$status==='completed'?now()->subHour():null,'notes'=>'[DEMO-BULK] '.(['Ny arbeidsordre','Tildelt tekniker','Arbeid pågår','Venter på deler / sluttkontroll','Fullført – klar for fakturering','Fullført og kvalitetssikret'][$i])]);$order->tasks()->delete();foreach(['Kontroller hjul','Utfør bestilt arbeid','Sluttkontroll']as$position=>$name)$order->tasks()->create(['name'=>$name,'required'=>true,'completed'=>$status==='completed'||($status==='quality_check'&&$position<2)||($status==='in_progress'&&$position===0),'completed_by'=>$status==='draft'?null:$order->assigned_user_id,'completed_at'=>$status==='completed'?now()->subHour():null,'position'=>$position]);}
    }

    private function upsertWheelSet(int $orgId, Vehicle $vehicle, $locations, array $brands, array $sizes, int $seed, string $season, string $code): void
    {
        $status=['stored','stored','stored','picked','workshop','received','delivered'][$seed%7];
        $location=$status==='received'?null:$locations[$seed%$locations->count()];
        TireSet::withTrashed()->updateOrCreate(['organization_id'=>$orgId,'code'=>$code], [
            'public_id'=>(string)(TireSet::where('organization_id',$orgId)->where('code',$code)->value('public_id') ?: Str::uuid()),
            'vehicle_id'=>$vehicle->id, 'storage_location_id'=>$location?->id, 'season'=>$season, 'kind'=>'complete_wheels',
            'manufacturer'=>$brands[$seed%count($brands)], 'model'=>['IceContact','Hakka','Primacy','EfficientGrip'][$seed%4],
            'size'=>$sizes[$seed%count($sizes)], 'quantity'=>4, 'minimum_tread_depth'=>[1.7,2.2,2.8,3.2,3.8,4.6,5.4,6.8][$seed%8],
            'dot_year'=>now()->year-($seed%11), 'status'=>$status, 'condition_notes'=>'[DEMO-BULK] Variert tilstand for filtrering og salgsforslag.',
            'received_at'=>now()->subDays($seed%365), 'delivered_at'=>$status==='delivered'?now()->subDays($seed%30):null,
            'deleted_at'=>null,
        ]);
    }
}
