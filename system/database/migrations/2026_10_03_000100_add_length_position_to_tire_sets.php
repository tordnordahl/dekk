<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Existing heights remain unchanged; actual lengths must be confirmed by staff.
        Schema::table('tire_sets', function (Blueprint $table) {
            $table->unsignedSmallInteger('storage_position_number')->nullable()->after('storage_shelf_number');
            $table->index(['storage_location_id', 'storage_shelf_number', 'storage_position_number'], 'tire_sets_coordinates_index');
        });
    }

    public function down(): void
    {
        Schema::table('tire_sets', function (Blueprint $table) {
            $table->dropIndex('tire_sets_coordinates_index');
            $table->dropColumn('storage_position_number');
        });
    }
};
