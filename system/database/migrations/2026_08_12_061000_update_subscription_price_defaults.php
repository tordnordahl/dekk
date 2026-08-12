<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('billing_statements') || DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE billing_statements MODIFY subscription_cents INT UNSIGNED NOT NULL DEFAULT 24900');
        DB::statement('ALTER TABLE billing_statements MODIFY total_cents INT UNSIGNED NOT NULL DEFAULT 24900');
    }

    public function down(): void
    {
        if (! Schema::hasTable('billing_statements') || DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE billing_statements MODIFY subscription_cents INT UNSIGNED NOT NULL DEFAULT 11900');
        DB::statement('ALTER TABLE billing_statements MODIFY total_cents INT UNSIGNED NOT NULL DEFAULT 11900');
    }
};
