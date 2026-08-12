<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('service_products', 'is_favorite')) {
            Schema::table('service_products', function (Blueprint $table): void {
                $table->boolean('is_favorite')->default(false)->after('active')->index();
            });
        }

        $defaults = [
            ['HOTELL','Dekkhotell','storage',129900,20,true],
            ['SKIFT','Sesongskift','tire_change',69900,40,true],
            ['PLUGG','Plugging av dekk','repair',59900,30,true],
            ['OMLEGG','Omlegging og balansering','workshop',129900,60,false],
            ['BALANS','Balansering av hjul','workshop',79900,45,false],
            ['VASK','Hjulvask','other',29900,20,false],
            ['TPMS','TPMS-service','workshop',49900,30,false],
            ['ETTER','Etterstramming','other',0,10,false],
        ];
        foreach (DB::table('organizations')->pluck('id') as $organizationId) {
            if (DB::table('service_products')->where('organization_id', $organizationId)->whereNull('deleted_at')->exists()) continue;
            foreach ($defaults as [$code,$name,$category,$price,$duration,$favorite]) {
                DB::table('service_products')->insert(['public_id'=>(string)Str::uuid(),'organization_id'=>$organizationId,'code'=>$code,'name'=>$name,'category'=>$category,'fixed_price_cents'=>$price,'vat_rate'=>25,'duration_minutes'=>$duration,'active'=>true,'is_favorite'=>$favorite,'created_at'=>now(),'updated_at'=>now()]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('service_products', 'is_favorite')) Schema::table('service_products', function (Blueprint $table): void {
            $table->dropIndex(['is_favorite']);
            $table->dropColumn('is_favorite');
        });
    }
};
