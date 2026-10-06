<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('storage_locations', fn(Blueprint $t) => $t->timestamp('archived_at')->nullable());
        Schema::table('tire_sets', function(Blueprint $t) { $t->string('winter_type',20)->nullable(); $t->text('hotel_notes')->nullable(); });
        Schema::table('vehicles', function(Blueprint $t) { $t->string('contact_name')->nullable(); $t->string('contact_phone',32)->nullable(); });
        Schema::table('organizations', fn(Blueprint $t) => $t->json('label_settings')->nullable());
    }
    public function down(): void {
        Schema::table('storage_locations', fn(Blueprint $t) => $t->dropColumn('archived_at'));
        Schema::table('tire_sets', fn(Blueprint $t) => $t->dropColumn(['winter_type','hotel_notes']));
        Schema::table('vehicles', fn(Blueprint $t) => $t->dropColumn(['contact_name','contact_phone']));
        Schema::table('organizations', fn(Blueprint $t) => $t->dropColumn('label_settings'));
    }
};
