<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tire_inspections', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tire_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('overall_status', ['good', 'attention', 'replace'])->default('good');
            $table->text('notes')->nullable();
            $table->timestamp('inspected_at');
            $table->timestamps();
            $table->index(['organization_id', 'inspected_at']);
        });

        Schema::create('wheel_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tire_inspection_id')->constrained()->cascadeOnDelete();
            $table->enum('position', ['front_left', 'front_right', 'rear_left', 'rear_right']);
            $table->decimal('tread_depth_mm', 4, 1)->nullable();
            $table->unsignedSmallInteger('dot_year')->nullable();
            $table->boolean('tire_damage')->default(false);
            $table->boolean('rim_damage')->default(false);
            $table->boolean('uneven_wear')->default(false);
            $table->enum('tpms_status', ['ok', 'warning', 'missing', 'not_checked'])->default('not_checked');
            $table->string('photo_path')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tire_inspection_id', 'position']);
        });

        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reference', 32);
            $table->enum('status', ['draft', 'ready', 'in_progress', 'quality_check', 'completed', 'cancelled'])->default('draft');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('customer_signed_at')->nullable();
            $table->string('customer_signature_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'reference']);
            $table->index(['branch_id', 'status']);
        });

        Schema::create('work_order_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('required')->default(true);
            $table->boolean('completed')->default(false);
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tire_product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('quantity');
            $table->enum('status', ['reserved', 'consumed', 'released'])->default('reserved');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'status']);
        });

        Schema::create('customer_portal_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('direction', ['inbound', 'outbound']);
            $table->enum('channel', ['email', 'sms', 'portal', 'note']);
            $table->text('body');
            $table->string('provider_reference')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'created_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->string('cost_center')->nullable();
            $table->string('invoice_reference')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn(['cost_center', 'invoice_reference']));
        Schema::dropIfExists('conversation_messages');
        Schema::dropIfExists('customer_portal_tokens');
        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('work_order_tasks');
        Schema::dropIfExists('work_orders');
        Schema::dropIfExists('wheel_measurements');
        Schema::dropIfExists('tire_inspections');
    }
};
