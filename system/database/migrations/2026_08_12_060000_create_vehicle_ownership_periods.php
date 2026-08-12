<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('vehicle_ownership_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['vehicle_id', 'started_at', 'ended_at']);
            $table->index(['customer_id', 'ended_at']);
        });
        DB::table('vehicles')->whereNull('deleted_at')->orderBy('id')->each(function ($vehicle) {
            DB::table('vehicle_ownership_periods')->insert([
                'organization_id'=>$vehicle->organization_id,
                'vehicle_id'=>$vehicle->id,
                'customer_id'=>$vehicle->customer_id,
                'started_at'=>$vehicle->created_at ?: now(),
                'created_at'=>now(), 'updated_at'=>now(),
            ]);
        });
    }

    public function down(): void { Schema::dropIfExists('vehicle_ownership_periods'); }
};
