<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\StorageLocation;
use App\Models\TireSet;
use App\Models\Vehicle;
use App\Models\User;
use App\Models\WorkBay;
use App\Models\ServiceSetting;
use App\Models\TireProduct;
use App\Models\IntegrationSetting;
use App\Models\Organization;
use App\Models\ServiceProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use App\Services\VehicleLookupService;
use Throwable;
use App\Services\DefaultServiceCatalog;

class AdminController extends Controller
{
    public function hub(Request $request): View
    {
        $org = $request->user()->organization_id;
        return view('admin.hub', ['counts' => [
            'employees'=>User::where('is_super_admin',false)->where('organization_id',$org)->count(),
            'services'=>ServiceProduct::where('organization_id',$org)->where('active',true)->count(),
            'exports'=>\App\Models\InvoiceExport::where('organization_id',$org)->whereIn('status',['ready','failed'])->count(),
            'messages'=>DB::table('outbound_messages')->where('organization_id',$org)->whereIn('status',['queued','failed'])->count(),
            'locations'=>StorageLocation::where('organization_id',$org)->where('active',true)->count(),
            'vegvesen'=>IntegrationSetting::where('organization_id',$org)->where('provider','vegvesen')->where('active',true)->exists(),
        ]]);
    }

    public function portals(Request $request): View
    {
        return view('admin.portals', [
            'organization' => Organization::findOrFail($request->user()->organization_id),
        ]);
    }

    public function openCustomerPortal(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'registration_number' => ['required', 'string', 'max:20'],
        ]);
        $registration = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $data['registration_number']));
        $vehicle = Vehicle::query()
            ->where('organization_id', $request->user()->organization_id)
            ->where('registration_number', $registration)
            ->first();

        if (! $vehicle) {
            return back()->withErrors([
                'registration_number' => 'Fant ingen kunde med registreringsnummer '.$registration.'.',
            ])->withInput();
        }

        DB::table('audit_logs')->insert([
            'organization_id' => $request->user()->organization_id,
            'user_id' => $request->user()->id,
            'action' => 'customer_portal.previewed',
            'subject_type' => Customer::class,
            'subject_id' => $vehicle->customer_id,
            'ip_address' => $request->ip(),
            'metadata' => json_encode(['registration_number' => $registration]),
            'created_at' => now(),
        ]);

        return redirect()->route('admin.portals.customer-preview', $vehicle->customer_id);
    }

    public function index(Request $request, DefaultServiceCatalog $defaultServices): View
    {
        $org = $request->user()->organization_id;
        $defaultServices->seed($org);
        return view('admin.index', [
            'employees' => User::where('is_super_admin',false)->where('organization_id', $org)->orderBy('name')->get(),
            'workBays' => WorkBay::where('organization_id', $org)->orderBy('code')->get(),
            'settings' => ServiceSetting::firstOrCreate(['branch_id' => $request->user()->branch_id], ['organization_id' => $org]),
            'products' => TireProduct::where('organization_id', $org)->latest()->limit(20)->get(),
            'vegvesenConfigured' => IntegrationSetting::where('organization_id', $org)->where('provider', 'vegvesen')->where('active', true)->exists() || filled(config('services.vegvesen.api_key')),
            'services' => ServiceProduct::where('organization_id', $org)->orderBy('category')->orderBy('name')->get(),
            'counts' => [
                'customers' => Customer::where('organization_id', $org)->count(),
                'vehicles' => Vehicle::where('organization_id', $org)->count(),
                'tire_sets' => TireSet::where('organization_id', $org)->count(),
                'bookings' => Booking::where('organization_id', $org)->count(),
                'dummy_customers' => Customer::where('organization_id', $org)->where('notes', 'like', '[DUMMY]%')->count(),
            ],
        ]);
    }

    public function generateDummyData(Request $request, VehicleLookupService $lookup): RedirectResponse
    {
        $data = $request->validate(['registration_numbers' => ['required','string','max:1000']]);
        $user = $request->user();
        $org = $user->organization_id;
        $branch = $user->branch_id;
        $registrations = array_values(array_unique(array_filter(array_map(fn($value)=>strtoupper(preg_replace('/[^A-Z0-9]/i','',$value)), preg_split('/[\s,;]+/', $data['registration_numbers'])))));
        if (!$registrations || count($registrations) > 20) return back()->withErrors(['registration_numbers'=>'Oppgi mellom 1 og 20 registreringsnumre.'])->withInput();
        $vehicles=[]; $lookupErrors=[];
        foreach($registrations as $registration) {
            try { $vehicles[]=$lookup->lookup($registration,$org); }
            catch(Throwable $e) { $lookupErrors[]=$registration.': '.$e->getMessage(); }
        }
        if ($lookupErrors) return back()->withErrors(['registration_numbers'=>'Ingen dummydata ble opprettet. Rett disse oppslagene: '.implode(' | ',$lookupErrors)])->withInput();
        $existing=Vehicle::where('organization_id',$org)->whereIn('registration_number',$registrations)->pluck('registration_number')->all();
        if ($existing) return back()->withErrors(['registration_numbers'=>'Disse registreringsnumrene finnes allerede: '.implode(', ',$existing)])->withInput();
        $locations = StorageLocation::where('organization_id', $org)->pluck('id')->all();
        $firstNames = ['Nora','Emil','Sofie','Jakob','Ingrid','Henrik','Maja','Oliver','Thea','Magnus','Ida','Aksel','Sara','Jonas','Emma','Sander'];
        $lastNames = ['Hansen','Johansen','Olsen','Larsen','Andersen','Nilsen','Berg','Solberg','Haugen','Dahl','Moen','Kristiansen'];
        $tireBrands = ['Continental','Nokian','Michelin','Goodyear','Bridgestone'];

        DB::transaction(function () use ($vehicles, $org, $branch, $locations, $firstNames, $lastNames, $tireBrands, $request) {
            foreach ($vehicles as $i => $vehicleData) {
                $sequence = (int) Customer::withTrashed()->where('organization_id', $org)->max('id') + 1;
                $name = $firstNames[array_rand($firstNames)].' '.$lastNames[array_rand($lastNames)];
                $postalPlaces = [['0182','OSLO'],['5003','BERGEN'],['7010','TRONDHEIM'],['4006','STAVANGER'],['3015','DRAMMEN']];
                [$postalCode, $city] = $postalPlaces[array_rand($postalPlaces)];
                $customer = Customer::create([
                    'public_id' => (string) Str::uuid(), 'organization_id' => $org, 'branch_id' => $branch,
                    'customer_number' => 'K'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT), 'name' => $name,
                    'email' => 'dummy'.$sequence.'@example.no', 'phone' => '9'.random_int(1000000, 9999999),
                    'postal_code' => $postalCode, 'city' => $city,
                    'notes' => '[DUMMY] Generert fra adminverktøyet.',
                ]);
                $vehicle = Vehicle::create([
                    'public_id' => (string) Str::uuid(), 'organization_id' => $org, 'customer_id' => $customer->id,
                    'registration_number' => $vehicleData['registration_number'], 'make' => $vehicleData['make'], 'model' => $vehicleData['model'],
                    'model_year' => $vehicleData['model_year'], 'vin' => $vehicleData['vin'], 'recommended_tire_size'=>$vehicleData['recommended_tire_size'],
                ]);
                $set = TireSet::create([
                    'public_id' => (string) Str::uuid(), 'organization_id' => $org, 'vehicle_id' => $vehicle->id,
                    'storage_location_id' => $locations ? $locations[array_rand($locations)] : null,
                    'code' => 'HJ-D'.strtoupper(Str::random(7)), 'season' => random_int(0, 1) ? 'winter' : 'summer',
                    'kind' => 'complete_wheels', 'manufacturer' => $tireBrands[array_rand($tireBrands)],
                    'size' => $vehicleData['recommended_tire_size'],
                    'quantity' => 4, 'minimum_tread_depth' => random_int(25, 80) / 10,
                    'dot_year' => random_int((int) now()->year - 6, (int) now()->year),
                    'status' => $locations ? 'stored' : 'received', 'condition_notes' => '[DUMMY]', 'received_at' => now()->subDays(random_int(1, 180)),
                ]);
                app(\App\Services\TireInspectionService::class)->backfill($set);
                if ($i % 2 === 0) {
                    $starts = now()->addDays(random_int(0, 30))->setTime(random_int(8, 15), [0, 15, 30, 45][array_rand([0, 1, 2, 3])]);
                    Booking::create([
                        'public_id' => (string) Str::uuid(), 'organization_id' => $org, 'branch_id' => $branch,
                        'customer_id' => $customer->id, 'vehicle_id' => $vehicle->id, 'reference' => 'DUMMY-'.strtoupper(Str::random(7)),
                        'service_name' => 'Sesongskift', 'starts_at' => $starts, 'ends_at' => $starts->copy()->addMinutes(45),
                        'status' => 'scheduled', 'notes' => '[DUMMY] Generert fra adminverktøyet.',
                    ]);
                }
            }
            DB::table('audit_logs')->insert(['organization_id' => $org, 'user_id' => $request->user()->id, 'action' => 'dummy_data.generated', 'ip_address' => $request->ip(), 'metadata' => json_encode(['customers' => count($vehicles),'vehicle_source'=>'vegvesen','registrations'=>$vehicles ? array_column($vehicles,'registration_number') : []]), 'created_at' => now()]);
        });
        return back()->with('success', count($vehicles).' dummy-kunder er opprettet med ekte tekniske kjøretøydata fra Statens vegvesen.');
    }

    public function deleteDummyData(Request $request): RedirectResponse
    {
        $request->validate(['confirmation' => ['required', 'in:SLETT DUMMYDATA']]);
        $org = $request->user()->organization_id;
        $ids = Customer::where('organization_id', $org)->where('notes', 'like', '[DUMMY]%')->pluck('id');
        DB::transaction(function () use ($ids, $org, $request) {
            Booking::where('organization_id', $org)->whereIn('customer_id', $ids)->delete();
            Customer::withTrashed()->whereIn('id', $ids)->forceDelete();
            DB::table('audit_logs')->insert(['organization_id' => $org, 'user_id' => $request->user()->id, 'action' => 'dummy_data.deleted', 'ip_address' => $request->ip(), 'metadata' => json_encode(['customers' => $ids->count()]), 'created_at' => now()]);
        });
        return back()->with('success', $ids->count().' dummy-kunder og tilknyttet data er slettet.');
    }
}
