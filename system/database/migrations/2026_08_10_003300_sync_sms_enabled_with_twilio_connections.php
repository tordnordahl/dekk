<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('service_settings')->update(['sms_enabled' => false]);
        $organizations = DB::table('integration_settings')->where('provider', 'twilio')->where('active', true)->pluck('organization_id');
        if ($organizations->isNotEmpty()) DB::table('service_settings')->whereIn('organization_id', $organizations)->update(['sms_enabled' => true]);
    }
    public function down(): void {}
};
