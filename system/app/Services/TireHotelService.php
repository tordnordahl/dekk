<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\HotelAgreement;
use App\Models\ServiceProduct;
use App\Models\TireSet;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TireHotelService
{
    public function ensureAgreement(TireSet $set): HotelAgreement
    {
        $set->loadMissing('vehicle.customer');

        $existing = HotelAgreement::query()
            ->where('organization_id', $set->organization_id)
            ->where('vehicle_id', $set->vehicle_id)
            ->whereIn('status', ['draft', 'active', 'paused'])
            ->latest()
            ->first();

        if ($existing) {
            if ($existing->status !== 'active') {
                $existing->update(['status' => 'active', 'ends_on' => null]);
            }

            return $existing;
        }

        $price = (int) (ServiceProduct::query()
            ->where('organization_id', $set->organization_id)
            ->where('category', 'storage')
            ->where('active', true)
            ->value('fixed_price_cents') ?? 129900);

        $branchId = $set->vehicle->customer->branch_id ?: Branch::query()
            ->where('organization_id', $set->organization_id)
            ->where('active', true)
            ->value('id');

        if (! $branchId) {
            throw new \RuntimeException('Kan ikke opprette hotellavtale fordi virksomheten mangler en aktiv avdeling.');
        }

        $attributes = [
            'public_id' => (string) Str::uuid(),
            'organization_id' => $set->organization_id,
            'branch_id' => $branchId,
            'customer_id' => $set->vehicle->customer_id,
            'vehicle_id' => $set->vehicle_id,
            'tire_set_id' => null,
            'status' => 'active',
            'starts_on' => today(),
            'renews_on' => today()->addMonthsNoOverflow(6),
            'price_cents' => $price,
            'auto_renew' => true,
            'notes' => 'Automatisk opprettet da hjulsett ble tatt inn på dekkhotell.',
        ];

        // Allows application files to be uploaded before update.php runs the migration.
        if (Schema::hasColumn('hotel_agreements', 'billing_status')) {
            $attributes['billing_status'] = 'pending';
            $attributes['charge_due_at'] = today();
        }

        return HotelAgreement::create($attributes);
    }
}
