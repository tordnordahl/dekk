<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('storage_locations', function (Blueprint $table) {
            $table->string('label')->nullable()->after('code');
            $table->string('location_type', 30)->default('rack')->after('shelf');
            $table->unsignedTinyInteger('map_x')->nullable()->after('location_type');
            $table->unsignedTinyInteger('map_y')->nullable()->after('map_x');
            $table->unsignedTinyInteger('map_width')->default(18)->after('map_y');
            $table->unsignedTinyInteger('map_height')->default(14)->after('map_width');
            $table->unsignedSmallInteger('pick_order')->default(0)->after('map_height');
        });
    }

    public function down(): void
    {
        Schema::table('storage_locations', fn (Blueprint $table) => $table->dropColumn(['label','location_type','map_x','map_y','map_width','map_height','pick_order']));
    }
};
