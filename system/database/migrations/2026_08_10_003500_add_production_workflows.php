<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_exports', function (Blueprint $table) {
            $table->uuid('request_key')->nullable()->after('provider');
            $table->string('external_order_id')->nullable()->after('external_id');
            $table->json('provider_metadata')->nullable()->after('external_order_id');
            $table->unique(['provider', 'request_key'], 'invoice_provider_request_unique');
        });

        Schema::table('service_settings', function (Blueprint $table) {
            $table->json('weekly_hours')->nullable()->after('quote_expiry_days');
            $table->unsignedSmallInteger('booking_horizon_days')->default(120)->after('weekly_hours');
            $table->unsignedSmallInteger('minimum_booking_notice_hours')->default(2)->after('booking_horizon_days');
            $table->unsignedTinyInteger('preparation_days')->default(1)->after('minimum_booking_notice_hours');
        });

        Schema::create('branch_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'starts_at', 'ends_at']);
        });

        Schema::create('employee_availabilities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['shift', 'absence']);
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'starts_at', 'ends_at']);
        });

        Schema::create('hotel_agreements', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tire_set_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', ['draft', 'active', 'paused', 'ended'])->default('draft');
            $table->date('starts_on');
            $table->date('renews_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->unsignedInteger('price_cents');
            $table->boolean('auto_renew')->default(true);
            $table->string('terms_version', 32)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status', 'renews_on']);
        });

        Schema::create('storage_location_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tire_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_location_id')->nullable()->constrained('storage_locations')->nullOnDelete();
            $table->foreignId('to_location_id')->nullable()->constrained('storage_locations')->nullOnDelete();
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 80)->nullable();
            $table->timestamp('moved_at');
            $table->timestamps();
            $table->index(['tire_set_id', 'moved_at']);
        });

        Schema::table('tire_sets', function (Blueprint $table) {
            $table->boolean('washed')->default(false)->after('condition_notes');
            $table->boolean('bagged')->default(false)->after('washed');
            $table->decimal('recommended_pressure_front_bar', 3, 1)->nullable()->after('bagged');
            $table->decimal('recommended_pressure_rear_bar', 3, 1)->nullable()->after('recommended_pressure_front_bar');
            $table->timestamp('last_counted_at')->nullable()->after('delivered_at');
            $table->foreignId('last_counted_by')->nullable()->after('last_counted_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('expires_at');
            $table->string('device_name')->nullable()->after('name');
        });

        Schema::create('usage_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('unit_price_cents')->default(0);
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['organization_id', 'type', 'source_type', 'source_id'], 'usage_source_unique');
            $table->index(['organization_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_events');
        Schema::table('personal_access_tokens', fn (Blueprint $table) => $table->dropColumn(['revoked_at', 'device_name']));
        Schema::table('tire_sets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('last_counted_by');
            $table->dropColumn(['washed', 'bagged', 'recommended_pressure_front_bar', 'recommended_pressure_rear_bar', 'last_counted_at']);
        });
        Schema::dropIfExists('storage_location_movements');
        Schema::dropIfExists('hotel_agreements');
        Schema::dropIfExists('employee_availabilities');
        Schema::dropIfExists('branch_closures');
        Schema::table('service_settings', fn (Blueprint $table) => $table->dropColumn(['weekly_hours', 'booking_horizon_days', 'minimum_booking_notice_hours', 'preparation_days']));
        Schema::table('invoice_exports', function (Blueprint $table) {
            $table->dropUnique('invoice_provider_request_unique');
            $table->dropColumn(['request_key', 'external_order_id', 'provider_metadata']);
        });
    }
};
