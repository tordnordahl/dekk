<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('tire_sets') && ! Schema::hasColumn('tire_sets', 'dot_month')) {
            Schema::table('tire_sets', fn (Blueprint $table) => $table->unsignedTinyInteger('dot_month')->nullable()->after('dot_year'));
        }
        if (Schema::hasTable('wheel_measurements') && ! Schema::hasColumn('wheel_measurements', 'dot_month')) {
            Schema::table('wheel_measurements', fn (Blueprint $table) => $table->unsignedTinyInteger('dot_month')->nullable()->after('dot_year'));
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('wheel_measurements') && Schema::hasColumn('wheel_measurements', 'dot_month')) Schema::table('wheel_measurements', fn (Blueprint $table) => $table->dropColumn('dot_month'));
        if (Schema::hasTable('tire_sets') && Schema::hasColumn('tire_sets', 'dot_month')) Schema::table('tire_sets', fn (Blueprint $table) => $table->dropColumn('dot_month'));
    }
};
