<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\TireSet;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class PortfolioController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);
        $included = static fn () => Organization::query()->where('exclude_from_portfolio', false)
            ->where(fn ($query) => $query->whereNull('organization_number')->orWhere('organization_number', '!=', 'DEMO-DEKKPILOT'));
        $counted = static fn (string $model) => $model::query()->whereIn('organization_id', $included()->select('id'));
        $organizations = $included()->select([
            'id', 'public_id', 'name', 'created_at', 'subscription_status',
            'subscription_ends_at', 'billing_model', 'stripe_subscription_id',
            'free_access_until', 'free_access_started_at', 'suspended_at',
            'stripe_free_month_count', 'stripe_free_month_applied_at', 'stripe_cancel_at_period_end',
        ])->withCount(['customers', 'vehicles', 'tireSets', 'branches', 'users', 'bookings'])
            ->withCount(['tireSets as stored_sets_count' => fn ($query) => $query->where('status', 'stored')])
            ->withSum(['tireSets as tire_units_sum' => fn ($query) => $query->where('kind', '!=', 'rims')], 'quantity')
            ->orderBy('id')->paginate($validated['per_page'] ?? 100);

        $customers = $organizations->getCollection()->map(static function (Organization $organization): array {
            $trialEnd = $organization->free_access_until;
            $trialSource = $trialEnd ? 'free_access_until' : null;
            if (! $trialEnd && $organization->stripe_free_month_applied_at && $organization->stripe_free_month_count > 0) {
                $trialEnd = $organization->stripe_free_month_applied_at->copy()
                    ->addMonthsNoOverflow((int) $organization->stripe_free_month_count);
                $trialSource = 'discount_estimate';
            }
            $status = match (true) {
                (bool) $organization->suspended_at => 'suspended',
                $organization->hasFreeAccess() => 'trialing',
                $trialEnd?->isFuture() && $organization->subscription_status === 'active' => 'trialing',
                $organization->subscription_status === 'trialing' => 'trialing',
                in_array($organization->subscription_status, ['canceled', 'ended'], true) => 'canceled',
                $organization->subscription_status === 'active'
                    && $organization->hasSubscriptionAccess() => 'active',
                default => 'unknown',
            };

            return [
                'id' => (string) $organization->public_id,
                'name' => $organization->name,
                'status' => $status,
                'subscription_status' => $organization->subscription_status,
                // Until a dedicated onboarding field exists, registration is the source.
                'onboarded_at' => $organization->created_at?->toDateString(),
                'onboarding_date_source' => 'registration',
                'monthly_price_minor' => 24900,
                'trial_started_at' => $organization->free_access_started_at?->toIso8601String()
                    ?? $organization->stripe_free_month_applied_at?->toIso8601String(),
                'trial_ends_at' => $trialEnd?->toIso8601String(),
                'trial_months_granted' => (int) $organization->stripe_free_month_count,
                'trial_date_source' => $trialSource,
                'cancel_at_period_end' => (bool) $organization->stripe_cancel_at_period_end,
                'subscription_ends_at' => $organization->subscription_ends_at?->toIso8601String(),
                'statistics' => [
                    'end_customers' => (int) $organization->customers_count,
                    'vehicles' => (int) $organization->vehicles_count,
                    'sets' => (int) $organization->tire_sets_count,
                    'tire_units' => (int) $organization->tire_units_sum,
                    'stored_sets' => (int) $organization->stored_sets_count,
                    'branches' => (int) $organization->branches_count,
                    'employees' => (int) $organization->users_count,
                    'bookings' => (int) $organization->bookings_count,
                ],
            ];
        })->values()->all();

        $requestId = (string) Str::uuid();
        $payload = [
            'schema_version' => 1,
            'system' => 'dekkpilot',
            'generated_at' => now()->toIso8601String(),
            'request_id' => $requestId,
            'currency' => 'NOK',
            'price_includes_vat' => true,
            'payment_confirmed' => false,
            'customers' => $customers,
            'statistics' => [
                'organizations' => $included()->count(),
                'end_customers' => $counted(Customer::class)->count(),
                'vehicles' => $counted(Vehicle::class)->count(),
                'sets' => $counted(TireSet::class)->count(),
                'tire_units' => (int) $counted(TireSet::class)->where('kind', '!=', 'rims')->sum('quantity'),
                'stored_sets' => $counted(TireSet::class)->where('status', 'stored')->count(),
                'branches' => $counted(Branch::class)->count(),
                'employees' => $counted(User::class)->count(),
                'bookings' => $counted(Booking::class)->count(),
                'completed_bookings' => $counted(Booking::class)->where('status', 'completed')->count(),
                'new_customers_last_30_days' => $counted(Customer::class)->where('created_at', '>=', now()->subDays(30))->count(),
                'new_organizations_last_30_days' => $included()->where('created_at', '>=', now()->subDays(30))->count(),
            ],
            'pagination' => [
                'page' => $organizations->currentPage(),
                'last_page' => $organizations->lastPage(),
                'total' => $organizations->total(),
            ],
        ];
        $key = base64_decode((string) config('portfolio.recipient_public_key'), true);
        $ciphertext = sodium_crypto_box_seal(json_encode($payload, JSON_THROW_ON_ERROR), $key);

        // Never log token, key, customer names, or decrypted payload.
        Log::info('portfolio.overview.read', [
            'request_id' => $requestId,
            'page' => $organizations->currentPage(),
            'records' => count($customers),
        ]);

        return response()->json([
            'schema_version' => 1,
            'algorithm' => 'sodium-sealed-box',
            'recipient_key_id' => hash('sha256', $key),
            'ciphertext' => base64_encode($ciphertext),
            'request_id' => $requestId,
        ]);
    }
}
