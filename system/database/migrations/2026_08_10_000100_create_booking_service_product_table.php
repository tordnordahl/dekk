<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('booking_service_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_product_id')->constrained()->restrictOnDelete();
            $table->string('service_name');
            $table->unsignedInteger('price_cents')->default(0);
            $table->unsignedSmallInteger('duration_minutes');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['booking_id', 'service_product_id']);
        });
        DB::table('bookings')->whereNotNull('service_product_id')->orderBy('id')->chunkById(500, function ($bookings) {
            foreach ($bookings as $booking) DB::table('booking_service_product')->insert(['booking_id'=>$booking->id,'service_product_id'=>$booking->service_product_id,'service_name'=>$booking->service_name,'price_cents'=>$booking->agreed_price_cents??0,'duration_minutes'=>max(5,(int)round((strtotime($booking->ends_at)-strtotime($booking->starts_at))/60)),'position'=>0,'created_at'=>now(),'updated_at'=>now()]);
        });
    }
    public function down(): void { Schema::dropIfExists('booking_service_product'); }
};
