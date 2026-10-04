<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\StorageLocation;
use App\Models\User;
use App\Services\BrregService;
use App\Services\DefaultServiceCatalog;
use App\Models\ServiceSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

class RegistrationController extends Controller
{
    private const LEGAL_VERSION = '2026-10-04';

    public function form(): View
    {
        return view('auth.register');
    }

    public function lookup(Request $request, BrregService $brreg): JsonResponse
    {
        $request->validate(['organization_number' => ['required', 'string', 'max:20']]);

        try {
            return response()->json($brreg->lookup((string) $request->query('organization_number')));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function store(Request $request, BrregService $brreg, DefaultServiceCatalog $defaultServices): RedirectResponse
    {
        $data = $request->validate([
            'organization_number' => ['required', 'string', 'max:20'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
            'eula' => ['accepted'],
            'privacy' => ['accepted'],
            'price_terms' => ['accepted'],
        ]);

        try {
            $verified = $brreg->lookup($data['organization_number']);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['organization_number' => $exception->getMessage()]);
        }

        if (Organization::where('organization_number', $verified['organization_number'])->exists()) {
            throw ValidationException::withMessages(['organization_number' => 'Virksomheten har allerede en konto. Logg inn eller kontakt oss.']);
        }

        $user = DB::transaction(function () use ($data, $verified, $request, $defaultServices) {
            $organization = Organization::create([
                'public_id' => (string) Str::uuid(),
                'name' => $verified['name'],
                'organization_number' => $verified['organization_number'],
                'email' => strtolower($data['email']),
                'timezone' => 'Europe/Oslo',
                'locale' => 'nb',
                'subscription_status' => 'incomplete',
                'brreg_verified_at' => now(),
                'brreg_data' => $verified,
                'billing_model' => 'stripe',
            ]);
            $branch = Branch::create(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'name' => 'Hovedavdeling', 'code' => 'HOVED', 'active' => true]);
            $user = User::create(['organization_id' => $organization->id, 'branch_id' => $branch->id, 'name' => $data['name'], 'email' => strtolower($data['email']), 'password' => $data['password'], 'role' => 'owner', 'active' => true]);
            ServiceSetting::create(['organization_id'=>$organization->id,'branch_id'=>$branch->id,'minutes_per_wheel'=>8,'booking_buffer_minutes'=>5,'quote_expiry_days'=>14]);
            $defaultServices->seed($organization->id);

            foreach (['MOTTAK', 'A-01', 'A-02', 'B-01', 'B-02'] as $index => $code) {
                StorageLocation::create(['public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'branch_id' => $branch->id, 'code' => $code, 'zone' => $index === 0 ? 'Mottak' : substr($code, 0, 1), 'location_type' => $index === 0 ? 'receiving' : 'rack', 'map_x' => ($index % 4) * 23 + 3, 'map_y' => intdiv($index, 4) * 20 + 5, 'map_width' => 18, 'map_height' => 14, 'pick_order' => $index + 1, 'capacity' => 20, 'active' => true]);
            }

            foreach (['eula', 'privacy', 'pricing'] as $document) {
                DB::table('legal_acceptances')->insert(['organization_id' => $organization->id, 'user_id' => $user->id, 'document' => $document, 'version' => self::LEGAL_VERSION, 'ip_address' => $request->ip(), 'user_agent' => Str::limit((string) $request->userAgent(), 500, ''), 'accepted_at' => now()]);
            }

            return $user;
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('billing')->with('success', 'Kontoen er opprettet. Aktiver abonnementet hos Stripe for å åpne DekkPilot.');
    }
}
