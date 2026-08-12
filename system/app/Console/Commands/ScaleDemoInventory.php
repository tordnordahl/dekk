<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\StorageLocation;
use App\Models\TireProduct;
use App\Models\TireSet;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
        foreach(['MOTTAK','A-01','A-02','B-01','B-02','C-01','C-02','D-01'] as $index=>$code) StorageLocation::firstOrCreate(['branch_id'=>$branch->id,'code'=>$code],['public_id'=>(string)Str::uuid(),'organization_id'=>$org->id,'zone'=>$index===0?'Mottak':substr($code,0,1),'location_type'=>$index===0?'receiving':'rack','shelf_count'=>6,'sets_per_shelf'=>20,'map_x'=>($index%4)*23+3,'map_y'=>intdiv($index,4)*20+5,'map_width'=>18,'map_height'=>14,'pick_order'=>$index+1,'capacity'=>120,'active'=>true]);
        $locations = StorageLocation::where('organization_id', $org->id)->where('active', true)->where('code', '!=', 'MOTTAK')->get();

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
        });

        $vehicles = Vehicle::where('organization_id',$org->id)->where('notes','like','[DEMO-BULK]%')->count();
        $sets = TireSet::where('organization_id',$org->id)->where('condition_notes','like','[DEMO-BULK]%')->count();
        $this->info("Demoen har nå {$vehicles} realistiske biler og {$sets} hjulsett. Flere privat- og bedriftskunder har flere biler.");
        return self::SUCCESS;
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
