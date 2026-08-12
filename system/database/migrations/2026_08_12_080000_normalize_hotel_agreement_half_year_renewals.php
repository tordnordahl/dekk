<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hotel_agreements')) return;
        DB::table('hotel_agreements')->whereIn('status',['draft','active','paused'])->orderBy('id')->chunkById(200,function($agreements):void{foreach($agreements as $agreement){$start=Carbon::parse($agreement->starts_on?:today());DB::table('hotel_agreements')->where('id',$agreement->id)->update(['renews_on'=>$start->addMonthsNoOverflow(6)->toDateString(),'updated_at'=>now()]);}});
    }

    public function down(): void
    {
        // Avtaledatoer skal ikke flyttes bakover ved rollback.
    }
};
