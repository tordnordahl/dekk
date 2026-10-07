<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->boolean('exclude_from_portfolio')->default(false)->index();
        });
        DB::table('organizations')->where('organization_number', 'DEMO-DEKKPILOT')
            ->update(['exclude_from_portfolio' => true]);
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table): void {
            $table->dropColumn('exclude_from_portfolio');
        });
    }
};
