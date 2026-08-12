<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('storage_locations', function (Blueprint $table) {
            $table->unsignedTinyInteger('shelf_count')->default(5)->after('location_type');
            $table->unsignedSmallInteger('sets_per_shelf')->default(4)->after('shelf_count');
        });
        Schema::table('tire_sets', fn (Blueprint $table) => $table->unsignedTinyInteger('storage_shelf_number')->nullable()->after('storage_location_id'));

        DB::table('storage_locations')->orderBy('id')->get()->each(function ($location) {
            $shelves = min(20, max(1, (int) ceil($location->capacity / 20)));
            DB::table('storage_locations')->where('id', $location->id)->update(['shelf_count' => $shelves, 'sets_per_shelf' => max(1, (int) ceil($location->capacity / $shelves))]);
            $index = 0;
            DB::table('tire_sets')->where('storage_location_id', $location->id)->whereNotIn('status', ['delivered'])->orderBy('id')->get(['id'])->each(function ($set) use (&$index, $shelves) {
                DB::table('tire_sets')->where('id', $set->id)->update(['storage_shelf_number' => ($index % $shelves) + 1]); $index++;
            });
        });
    }
    public function down(): void
    {
        Schema::table('tire_sets', fn (Blueprint $table) => $table->dropColumn('storage_shelf_number'));
        Schema::table('storage_locations', fn (Blueprint $table) => $table->dropColumn(['shelf_count','sets_per_shelf']));
    }
};
