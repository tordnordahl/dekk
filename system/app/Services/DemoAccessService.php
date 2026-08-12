<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class DemoAccessService
{
    public function __construct(private readonly DemoBookingSeeder $bookings) {}
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

            $this->bookings->seed($organization,$branch,true);

            return $user;
        });
    }
}
