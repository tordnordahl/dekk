<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class DemoAccessService
{
    public function prepare(): User
    {
        $organization = Organization::where('organization_number', 'DEMO-DEKKPILOT')->first();
        if (! $organization) {
            throw new RuntimeException('Demovirksomheten er ikke installert ennå.');
        }

        return DB::transaction(function () use ($organization) {
            $branch = $organization->branches()->firstOrFail();
            $user = User::updateOrCreate(
                ['organization_id' => $organization->id, 'email' => 'demo@dekkpilot.no'],
                ['branch_id' => $branch->id, 'name' => 'Testbruker', 'password' => Hash::make(Str::random(48)), 'role' => 'owner', 'active' => true]
            );
            $customers = $organization->customers()->with('vehicles')->get()->filter(fn ($customer) => $customer->vehicles->isNotEmpty())->values();
            if ($customers->isEmpty()) {
                throw new RuntimeException('Demovirksomheten mangler testdata.');
            }

            $slots = [[8, 0], [9, 15], [10, 30], [12, 15], [13, 30], [14, 45], [16, 0]];
            for ($day = 0; $day < 8; $day++) {
                $date = now('Europe/Oslo')->addDays($day);
                if ($date->isWeekend()) continue;
                foreach ($slots as $slotIndex => [$hour, $minute]) {
                    $customer = $customers[($day + $slotIndex) % $customers->count()];
                    $startsAt = $date->copy()->setTime($hour, $minute, 0);
                    $booking = Booking::firstOrNew(['organization_id' => $organization->id, 'reference' => 'DEMO-CAL-'.$startsAt->format('Ymd-Hi')]);
                    if (! $booking->public_id) $booking->public_id = (string) Str::uuid();
                    $booking->fill(['branch_id' => $branch->id, 'customer_id' => $customer->id, 'vehicle_id' => $customer->vehicles->first()->id, 'service_name' => $slotIndex % 3 === 0 ? 'Dekkhotell og sesongskift' : ($slotIndex % 3 === 1 ? 'Sesongskift' : 'Kontroll og balansering'), 'agreed_price_cents' => [69900, 89900, 129900][$slotIndex % 3], 'starts_at' => $startsAt, 'ends_at' => $startsAt->copy()->addMinutes(45), 'status' => 'scheduled', 'notes' => '[DEMO] Automatisk oppdatert visningsbooking'])->save();
                }
            }

            return $user;
        });
    }
}
