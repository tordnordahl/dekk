<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void {
        Schema::create('service_products', function (Blueprint $t) {
            $t->id(); $t->uuid('public_id')->unique(); $t->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code',50); $t->string('name'); $t->text('description')->nullable();
            $t->enum('category',['storage','tire_change','repair','workshop','other'])->default('other');
            $t->unsignedInteger('fixed_price_cents'); $t->decimal('vat_rate',5,2)->default(25); $t->unsignedSmallInteger('duration_minutes')->default(30);
            $t->boolean('active')->default(true); $t->timestamps(); $t->softDeletes();
            $t->unique(['organization_id','code']); $t->index(['organization_id','category','active']);
        });
        Schema::table('bookings', function (Blueprint $t) {
            $t->foreignId('service_product_id')->nullable()->after('assigned_user_id')->constrained()->nullOnDelete();
            $t->unsignedInteger('agreed_price_cents')->nullable()->after('service_name');
        });
        $defaults=[['HOTELL','Dekkhotell','storage',129900,20],['SKIFT','Sesongskift','tire_change',69900,40],['PLUGG','Plugging av dekk','repair',59900,30],['OMLEGG','Omlegging og balansering','workshop',129900,60],['BALANS','Balansering av hjul','workshop',79900,45],['VASK','Hjulvask','other',29900,20],['TPMS','TPMS-service','workshop',49900,30],['ETTER','Etterstramming','other',0,10]];
        foreach(DB::table('organizations')->pluck('id') as $org){foreach($defaults as [$code,$name,$category,$price,$duration]){DB::table('service_products')->insert(['public_id'=>(string)Str::uuid(),'organization_id'=>$org,'code'=>$code,'name'=>$name,'category'=>$category,'fixed_price_cents'=>$price,'vat_rate'=>25,'duration_minutes'=>$duration,'active'=>true,'created_at'=>now(),'updated_at'=>now()]);}}
    }
    public function down(): void {Schema::table('bookings',function(Blueprint $t){$t->dropConstrainedForeignId('service_product_id');$t->dropColumn('agreed_price_cents');});Schema::dropIfExists('service_products');}
};
