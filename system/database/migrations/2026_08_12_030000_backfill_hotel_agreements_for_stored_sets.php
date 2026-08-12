<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hotel_agreements', function (Blueprint $table): void {
            $table->enum('billing_status', ['pending', 'invoiced', 'waived'])->default('pending')->after('price_cents');
            $table->date('charge_due_at')->nullable()->after('billing_status');
            $table->timestamp('billed_at')->nullable()->after('charge_due_at');
            $table->index(['organization_id', 'billing_status']);
        });

        $sets = DB::table('tire_sets')
            ->join('vehicles', 'vehicles.id', '=', 'tire_sets.vehicle_id')
            ->join('customers', 'customers.id', '=', 'vehicles.customer_id')
            ->whereNull('tire_sets.deleted_at')
            ->whereNotNull('tire_sets.received_at')
            ->whereIn('tire_sets.status', ['received', 'stored', 'picked', 'workshop'])
            ->select('tire_sets.organization_id', 'tire_sets.vehicle_id', 'customers.id as customer_id', 'customers.branch_id')
            ->orderBy('tire_sets.vehicle_id')
            ->get()
            ->unique(fn ($set) => $set->organization_id.'-'.$set->vehicle_id);

        foreach ($sets as $set) {
            $exists = DB::table('hotel_agreements')
                ->where('organization_id', $set->organization_id)
                ->where('vehicle_id', $set->vehicle_id)
                ->whereIn('status', ['draft', 'active', 'paused'])
                ->exists();

            if ($exists) {
                continue;
            }

            $branchId = $set->branch_id ?: DB::table('branches')
                ->where('organization_id', $set->organization_id)
                ->where('active', true)
                ->value('id');

            if (! $branchId) {
                continue;
            }

            $price = (int) (DB::table('service_products')
                ->where('organization_id', $set->organization_id)
                ->where('category', 'storage')
                ->where('active', true)
                ->value('fixed_price_cents') ?? 129900);

            DB::table('hotel_agreements')->insert([
                'public_id' => (string) Str::uuid(),
                'organization_id' => $set->organization_id,
                'branch_id' => $branchId,
                'customer_id' => $set->customer_id,
                'vehicle_id' => $set->vehicle_id,
                'tire_set_id' => null,
                'status' => 'active',
                'starts_on' => today(),
                'renews_on' => today()->addYear(),
                'price_cents' => $price,
                'billing_status' => 'pending',
                'charge_due_at' => today(),
                'auto_renew' => true,
                'notes' => 'Automatisk opprettet for eksisterende hjulsett på lager.',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('hotel_agreements', function (Blueprint $table): void {
            $table->dropIndex(['organization_id', 'billing_status']);
            $table->dropColumn(['billing_status', 'charge_due_at', 'billed_at']);
        });
    }
};
