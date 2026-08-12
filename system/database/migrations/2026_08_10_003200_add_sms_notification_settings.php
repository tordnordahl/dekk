<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_settings', function (Blueprint $table) {
            $table->boolean('sms_enabled')->default(false)->after('quote_expiry_days');
            $table->boolean('sms_booking_confirmation_enabled')->default(true)->after('sms_enabled');
            $table->boolean('sms_booking_reminder_enabled')->default(true)->after('sms_booking_confirmation_enabled');
            $table->boolean('sms_marketing_enabled')->default(false)->after('sms_booking_reminder_enabled');
        });
    }
    public function down(): void
    {
        Schema::table('service_settings', fn (Blueprint $table) => $table->dropColumn(['sms_enabled','sms_booking_confirmation_enabled','sms_booking_reminder_enabled','sms_marketing_enabled']));
    }
};
