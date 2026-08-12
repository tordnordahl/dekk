<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tire_sets', function (Blueprint $table) {
            $table->string('wash_status', 24)->default('not_assessed')->after('washed')->index();
        });
        // Behold historisk informasjon for sett som allerede er markert vasket.
        \Illuminate\Support\Facades\DB::table('tire_sets')->where('washed', true)->update(['wash_status' => 'washed']);
    }

    public function down(): void
    {
        Schema::table('tire_sets', fn (Blueprint $table) => $table->dropColumn('wash_status'));
    }
};
