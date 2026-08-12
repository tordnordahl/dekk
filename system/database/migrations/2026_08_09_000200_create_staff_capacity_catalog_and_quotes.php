<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_bays', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 20);
            $table->enum('type', ['tire_lift', 'vehicle_lift', 'workstation'])->default('tire_lift');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'code']);
        });

        Schema::create('service_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('minutes_per_wheel')->default(8);
            $table->unsignedSmallInteger('booking_buffer_minutes')->default(5);
            $table->unsignedSmallInteger('quote_expiry_days')->default(14);
            $table->timestamps();
            $table->unique('branch_id');
        });

        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('work_bay_id')->nullable()->after('branch_id')->constrained()->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->after('work_bay_id')->constrained('users')->nullOnDelete();
        });

        Schema::create('tire_products', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('sku', 64);
            $table->string('brand');
            $table->string('model');
            $table->string('size', 64);
            $table->enum('season', ['summer', 'winter', 'all_season']);
            $table->boolean('studded')->default(false);
            $table->unsignedInteger('price_cents');
            $table->unsignedInteger('cost_cents')->nullable();
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['organization_id', 'sku']);
            $table->index(['organization_id', 'size', 'season']);
        });

        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 32);
            $table->enum('status', ['draft', 'sent', 'viewed', 'accepted', 'declined', 'expired'])->default('draft');
            $table->string('access_token_hash', 64)->unique();
            $table->unsignedInteger('subtotal_cents');
            $table->unsignedInteger('vat_cents')->default(0);
            $table->unsignedInteger('total_cents');
            $table->text('message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('expires_at');
            $table->string('response_ip', 45)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'reference']);
        });

        Schema::create('quote_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quote_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tire_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedInteger('unit_price_cents');
            $table->unsignedInteger('line_total_cents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_items');
        Schema::dropIfExists('quotes');
        Schema::dropIfExists('tire_products');
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropConstrainedForeignId('work_bay_id');
        });
        Schema::dropIfExists('service_settings');
        Schema::dropIfExists('work_bays');
    }
};
