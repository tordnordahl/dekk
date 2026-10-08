<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('organizations',function(Blueprint $table) {
            $table->json('profile')->nullable();
            $table->longText('brreg_private_data')->nullable();
        });
    }
    public function down(): void { Schema::table('organizations',fn(Blueprint $table)=>$table->dropColumn(['profile','brreg_private_data'])); }
};
