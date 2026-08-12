<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\CheckoutPayment;
use App\Models\StorageLocation;
use App\Models\TireSet;
use App\Models\TireInspection;
use App\Models\Vehicle;
use App\Models\WorkBay;
use App\Models\ServiceSetting;
use App\Models\User;
use App\Models\ServiceProduct;
use App\Models\Quote;
use App\Services\CommunicationService;
use App\Services\VehicleLookupService;
use App\Services\Accounting\AccountingExportService;
use App\Services\WarehousePlacementService;
use App\Services\BookingWorkflowService;
use App\Services\TireHotelService;
use App\Services\PostalCodeService;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationsController extends Controller
{
    public function dashboard(Request $request): View
    {
        $org = $request->user()->organization_id;
        $branch = $request->user()->branch_id;
        $storedCount = TireSet::where('organization_id', $org)->where('status', 'stored')->count();
        $capacityBookings = Booking::where('organization_id', $org)->where('branch_id', $branch)
            ->whereNotIn('status', ['cancelled', 'no_show'])
            ->where('starts_at', '>=', today())->where('starts_at', '<', today()->addDays(7))
            ->get(['id', 'starts_at', 'ends_at']);
        $settings = ServiceSetting::where('organization_id', $org)->where('branch_id', $branch)->first();
        $hours = $settings?->weekly_hours ?? [1=>['08:00','16:00'],2=>['08:00','16:00'],3=>['08:00','16:00'],4=>['08:00','16:00'],5=>['08:00','16:00']];
        $bayCount = WorkBay::where('branch_id', $branch)->where('active', true)->count();
        $technicianCount = User::where('organization_id', $org)->where('branch_id', $branch)->where('active', true)->where('role', 'technician')->count();
        $parallelCapacity = $bayCount > 0 && $technicianCount > 0 ? min($bayCount, $technicianCount) : max(1, $bayCount, $technicianCount);
        $occupancy = collect(range(0, 6))->map(function (int $offset) use ($capacityBookings, $hours, $parallelCapacity) {
            $day = today()->addDays($offset);
            $dayHours = $hours[$day->dayOfWeek] ?? null;
            $openMinutes = $dayHours && count($dayHours) >= 2
                ? max(0, $day->copy()->setTimeFromTimeString($dayHours[0])->diffInMinutes($day->copy()->setTimeFromTimeString($dayHours[1]), false))
                : 0;
            $dayBookings = $capacityBookings->filter(fn ($booking) => $booking->starts_at->isSameDay($day));
            $bookedMinutes = (int) $dayBookings->sum(fn ($booking) => max(0, $booking->starts_at->diffInMinutes($booking->ends_at, false)));
            $availableMinutes = $openMinutes * $parallelCapacity;
            return [
                'date' => $day->toDateString(),
                'label' => $offset === 0 ? 'I dag' : ($offset === 1 ? 'I morgen' : ucfirst($day->translatedFormat('D'))),
                'short_date' => $day->format('d.m'),
                'bookings' => $dayBookings->count(),
                'percent' => $availableMinutes > 0 ? (int) round(($bookedMinutes / $availableMinutes) * 100) : null,
                'closed' => $availableMinutes === 0,
            ];
        });
        return view('dashboard', [
            'customerCount' => Customer::where('organization_id', $org)->count(),
            'storedCount' => $storedCount,
            'todayCount' => Booking::where('organization_id',$org)->whereDate('starts_at',today())->count(),
            'warningCount' => TireSet::where('organization_id', $org)->where(fn ($q) => $q->where('minimum_tread_depth', '<', 3)->orWhere('dot_year', '<', now()->year - 8))->count(),
            'bookings' => Booking::with(['customer', 'vehicle', 'workOrder'])->where('organization_id', $org)->whereDate('starts_at', today())->orderBy('starts_at')->get(),
            'occupancy' => $occupancy,
        ]);
    }

    public function customers(Request $request): View
    {
        $org = $request->user()->organization_id;
        $query = Customer::with('vehicles')->where('organization_id', $org);
        if ($search = trim((string) $request->query('q'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->orWhereHas('vehicles', fn ($v) => $v->where('registration_number', 'like', "%{$search}%")));
        }
        $vehicleCustomer = null;
        if ($publicId = trim((string) $request->query('new_vehicle'))) {
            $vehicleCustomer = Customer::where('organization_id', $org)->where('public_id', $publicId)->first();
        }
        return view('customers.index', ['customers' => $query->latest()->paginate(20)->withQueryString(), 'vehicleCustomer' => $vehicleCustomer]);
    }

    public function postalCode(string $postalCode, PostalCodeService $postalCodes): JsonResponse
    {
        $city = $postalCodes->city($postalCode);
        abort_unless($city, 404, 'Postnummeret finnes ikke i Postens register.');

        return response()->json(['postal_code' => $postalCode, 'city' => $city]);
    }

    public function customer(Request $request, Customer $customer): View
    {
        abort_unless($customer->organization_id===$request->user()->organization_id,404);
        $customer->load(['vehicles.tireSets.storageLocation','vehicles.tireSets.inspections.measurements','bookings'=>fn($q)=>$q->with('vehicle')->latest('starts_at')->limit(30),'quotes'=>fn($q)=>$q->with(['vehicle','items'])->latest()->limit(20),'workOrders'=>fn($q)=>$q->with(['vehicle','tasks'])->latest()->limit(20),'conversations']);
        $agreements=\App\Models\HotelAgreement::with(['vehicle','tireSet'])->where('customer_id',$customer->id)->latest()->get();
        return view('customers.show',compact('customer','agreements'));
    }

    public function vehicleHistory(Request $request, Vehicle $vehicle): View
    {
        abort_unless((int)$vehicle->organization_id===(int)$request->user()->organization_id,404);
        $vehicle->load(['customer','ownershipPeriods.customer','tireSets.storageLocation','tireSets.inspections.measurements']);
        $bookings=Booking::with('customer')->where('organization_id',$vehicle->organization_id)->where('vehicle_id',$vehicle->id)->latest('starts_at')->get();
        $quotes=\App\Models\Quote::with('customer')->where('organization_id',$vehicle->organization_id)->where('vehicle_id',$vehicle->id)->latest()->get();
        $orders=\App\Models\WorkOrder::with(['customer','tasks'])->where('organization_id',$vehicle->organization_id)->where('vehicle_id',$vehicle->id)->latest()->get();
        $agreements=\App\Models\HotelAgreement::with('customer')->where('organization_id',$vehicle->organization_id)->where('vehicle_id',$vehicle->id)->latest()->get();
        return view('vehicles.history',compact('vehicle','bookings','quotes','orders','agreements'));
    }

    public function exportCustomer(Request $request, Customer $customer): StreamedResponse
    {
        abort_unless($customer->organization_id===$request->user()->organization_id,404);abort_unless(in_array($request->user()->role,['owner','admin','manager'],true),403);$customer->load(['vehicles.tireSets.inspections.measurements','bookings.services','quotes.items','workOrders.tasks','conversations']);DB::table('audit_logs')->insert(['organization_id'=>$customer->organization_id,'user_id'=>$request->user()->id,'action'=>'customer.data.exported','subject_type'=>Customer::class,'subject_id'=>$customer->id,'ip_address'=>$request->ip(),'created_at'=>now()]);$json=json_encode(['exported_at'=>now()->toIso8601String(),'customer'=>$customer->toArray()],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);return response()->streamDownload(fn()=>print($json),'kunde-'.$customer->customer_number.'.json',['Content-Type'=>'application/json; charset=UTF-8']);
    }

    public function destroyCustomer(Request $request, Customer $customer): RedirectResponse
    {
        $org = (int) $request->user()->organization_id;
        abort_unless((int) $customer->organization_id === $org, 404);
        abort_unless(in_array($request->user()->role, ['owner', 'admin', 'manager'], true), 403);

        $request->validate([
            'confirmation' => ['required', Rule::in([$customer->customer_number])],
        ], [
            'confirmation.in' => 'Skriv kundenummeret nøyaktig slik det vises for å bekrefte slettingen.',
        ]);

        $summary = [
            'customer_number' => $customer->customer_number,
            'name' => $customer->name,
            'vehicles' => $customer->vehicles()->count(),
            'bookings' => $customer->bookings()->count(),
            'quotes' => $customer->quotes()->count(),
            'work_orders' => $customer->workOrders()->count(),
        ];

        DB::transaction(function () use ($customer, $request, $org, $summary): void {
            DB::table('audit_logs')->insert([
                'organization_id' => $org,
                'user_id' => $request->user()->id,
                'action' => 'customer.permanently_deleted',
                'subject_type' => Customer::class,
                'subject_id' => $customer->id,
                'ip_address' => $request->ip(),
                'metadata' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            $customer->forceDelete();
        });

        return redirect()->route('customers')->with('success', $summary['name'].' og tilknyttede kundedata er slettet.');
    }

    public function tireSet(Request $request, TireSet $tireSet): View
    {
        abort_unless($tireSet->organization_id===$request->user()->organization_id,404);
        $tireSet->load(['vehicle.customer','storageLocation','inspections.measurements','movements']);
        $locations=StorageLocation::withCount(['tireSets'=>fn($q)=>$q->whereNotIn('status',['delivered'])])->where('organization_id',$tireSet->organization_id)->where('active',true)->orderBy('code')->get();
        $agreement=\App\Models\HotelAgreement::where('tire_set_id',$tireSet->id)->latest()->first();
        return view('inventory.show',compact('tireSet','locations','agreement'));
    }

    public function storeCustomer(Request $request, PostalCodeService $postalCodes): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', 'in:private,business'], 'name' => ['required', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:32'], 'organization_number' => ['nullable', 'string', 'max:32'], 'address' => ['nullable', 'string', 'max:255'], 'postal_code' => ['required', 'digits:4'], 'notes' => ['nullable', 'string', 'max:4000'], 'uses_tire_hotel' => ['nullable', 'boolean']]);
        $city = $postalCodes->city($data['postal_code']);
        if (! $city) {
            return back()->withErrors(['postal_code' => 'Postnummeret finnes ikke i Postens register.'])->withInput();
        }
        $data['city'] = $city;
        $usesTireHotel = (bool) ($data['uses_tire_hotel'] ?? false);
        unset($data['uses_tire_hotel']);
        $org = $request->user()->organization_id;
        $customer = DB::transaction(function () use ($data, $request, $org) {
            $next = (int) Customer::withTrashed()->where('organization_id', $org)->max('id') + 1;
            return Customer::create([...$data, 'public_id' => (string) Str::uuid(), 'organization_id' => $org, 'branch_id' => $request->user()->branch_id, 'customer_number' => 'K'.str_pad((string) $next, 6, '0', STR_PAD_LEFT)]);
        });
        return redirect()->route('customers', ['new_vehicle' => $customer->public_id, 'tire_hotel' => $usesTireHotel ? 1 : null])->with('success', 'Kunden er opprettet. Legg til kjøretøyet.');
    }

    public function updateCustomer(Request $request, Customer $customer, PostalCodeService $postalCodes): RedirectResponse
    {
        abort_unless($customer->organization_id === $request->user()->organization_id, 404);
        abort_unless(in_array($request->user()->role, ['owner','admin','manager','customer_service'], true), 403);
        $data = $request->validate([
            'type'=>['required','in:private,business'], 'name'=>['required','string','max:255'],
            'email'=>['nullable','email','max:255'], 'phone'=>['nullable','string','max:32'],
            'organization_number'=>['nullable','string','max:32'], 'address'=>['nullable','string','max:255'],
            'postal_code'=>['required','digits:4'], 'notes'=>['nullable','string','max:4000'],
        ]);
        $city = $postalCodes->city($data['postal_code']);
        if (! $city) return back()->withErrors(['postal_code'=>'Postnummeret finnes ikke i Postens register.'])->withInput();
        $customer->update([...$data, 'city'=>$city]);
        DB::table('audit_logs')->insert(['organization_id'=>$customer->organization_id,'user_id'=>$request->user()->id,'action'=>'customer.updated','subject_type'=>Customer::class,'subject_id'=>$customer->id,'ip_address'=>$request->ip(),'created_at'=>now()]);
        return back()->with('success','Kundeopplysningene er oppdatert.');
    }

    public function storeVehicle(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($customer->organization_id === $request->user()->organization_id, 404);
        $request->merge(['registration_number' => strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $request->input('registration_number')))]);
        $data = $request->validate(['registration_number' => ['required', 'string', 'alpha_num', 'max:20'], 'make' => ['nullable', 'string', 'max:100'], 'model' => ['nullable', 'string', 'max:100'], 'model_year' => ['nullable', 'integer', 'between:1900,2100'], 'mileage' => ['nullable', 'integer', 'min:0'], 'vin' => ['nullable', 'string', 'max:32'], 'recommended_tire_size' => ['nullable', 'string', 'max:100'], 'uses_tire_hotel' => ['nullable', 'boolean']]);
        $usesTireHotel = (bool) ($data['uses_tire_hotel'] ?? false);
        unset($data['uses_tire_hotel']);
        $existing=Vehicle::with('customer')->where('organization_id',$customer->organization_id)->where('registration_number',$data['registration_number'])->first();
        if($existing){
            if(!$request->boolean('transfer_existing'))return back()->withErrors(['registration_number'=>$data['registration_number'].' er registrert på '.$existing->customer->name.'. Du kan flytte bilen til '.$customer->name.' uten å miste historikken.'])->withInput()->with('existing_vehicle',['id'=>$existing->id,'customer'=>$existing->customer->name,'registration_number'=>$existing->registration_number]);
            abort_unless((int)$request->integer('existing_vehicle_id')===(int)$existing->id,422);
            $this->moveVehicleOwnership($existing,$customer,$request);
            return redirect()->route('customers.show',$customer)->with('success',$existing->registration_number.' er flyttet til '.$customer->name.'. Bilens historikk er bevart og avgrenses etter eierperiode i kundeportalen.');
        }
        try {
            DB::transaction(function () use ($data, $customer, $usesTireHotel): void {
                $vehicle = Vehicle::create([...$data, 'public_id' => (string) Str::uuid(), 'organization_id' => $customer->organization_id, 'customer_id' => $customer->id]);
                \App\Models\VehicleOwnershipPeriod::create(['organization_id'=>$customer->organization_id,'vehicle_id'=>$vehicle->id,'customer_id'=>$customer->id,'started_at'=>now(),'changed_by'=>auth()->id()]);
                foreach ($usesTireHotel ? ['summer', 'winter'] : [] as $season) {
                    TireSet::create([
                        'public_id' => (string) Str::uuid(),
                        'organization_id' => $customer->organization_id,
                        'vehicle_id' => $vehicle->id,
                        'code' => 'HJ-'.strtoupper(Str::random(8)),
                        'season' => $season,
                        'kind' => 'complete_wheels',
                        'size' => $vehicle->recommended_tire_size,
                        'quantity' => 4,
                        'status' => 'received',
                        'received_at' => null,
                    ]);
                }
            });
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() === '23000') return back()->withErrors(['registration_number'=>'Registreringsnummeret finnes allerede i virksomheten. Flytt eksisterende kjøretøy til denne kunden i stedet.'])->withInput();
            throw $exception;
        }
        $message = $usesTireHotel ? 'Kjøretøyet er registrert. Sommer- og vinterhjul er klargjort, men ikke markert som innlevert.' : 'Kjøretøyet er registrert.';
        if ($request->boolean('onboarding')) return redirect()->route('customers')->with('success', $usesTireHotel ? 'Kunden og kjøretøyet er registrert. Sommer- og vinterhjul er klargjort, men ikke markert som innlevert.' : 'Kunden og kjøretøyet er registrert.');
        return back()->with('success', $message);
    }

    public function transferVehicle(Request $request, Customer $customer, Vehicle $vehicle): RedirectResponse
    {
        $org=(int)$request->user()->organization_id;
        abort_unless((int)$customer->organization_id===$org&&(int)$vehicle->organization_id===$org&&(int)$vehicle->customer_id===(int)$customer->id,404);
        abort_unless(in_array($request->user()->role,['owner','admin','manager','customer_service'],true),403);
        $data=$request->validate(['new_customer_id'=>['required','integer',Rule::exists('customers','id')->where(fn($q)=>$q->where('organization_id',$org)->whereNull('deleted_at'))]]);
        if((int)$data['new_customer_id']===(int)$customer->id)return back()->withErrors(['new_customer_id'=>'Velg en annen kunde.']);
        $newCustomer=Customer::where('organization_id',$org)->findOrFail($data['new_customer_id']);
        $this->moveVehicleOwnership($vehicle,$newCustomer,$request);
        return redirect()->route('customers.show',$newCustomer)->with('success',$vehicle->registration_number.' er flyttet til '.$newCustomer->name.'. Bil- og hjulhistorikken er bevart, mens kundehistorikken er avgrenset etter eierperiode.');
    }

    private function moveVehicleOwnership(Vehicle $vehicle, Customer $newCustomer, Request $request): void
    {
        $oldCustomerId=(int)$vehicle->customer_id;$changedAt=now();$org=(int)$vehicle->organization_id;
        if($oldCustomerId===(int)$newCustomer->id)return;
        DB::transaction(function()use($vehicle,$newCustomer,$request,$oldCustomerId,$changedAt,$org){
            \App\Models\VehicleOwnershipPeriod::where('vehicle_id',$vehicle->id)->whereNull('ended_at')->update(['ended_at'=>$changedAt,'updated_at'=>$changedAt]);
            \App\Models\VehicleOwnershipPeriod::create(['organization_id'=>$org,'vehicle_id'=>$vehicle->id,'customer_id'=>$newCustomer->id,'started_at'=>$changedAt,'changed_by'=>$request->user()->id]);
            \App\Models\HotelAgreement::where('organization_id',$org)->where('vehicle_id',$vehicle->id)->whereIn('status',['draft','active','paused'])->update(['status'=>'ended','ends_on'=>today(),'updated_at'=>$changedAt]);
            $vehicle->update(['customer_id'=>$newCustomer->id]);
            DB::table('audit_logs')->insert(['organization_id'=>$org,'user_id'=>$request->user()->id,'action'=>'vehicle.ownership.transferred','subject_type'=>Vehicle::class,'subject_id'=>$vehicle->id,'ip_address'=>$request->ip(),'metadata'=>json_encode(['registration_number'=>$vehicle->registration_number,'from_customer_id'=>$oldCustomerId,'to_customer_id'=>$newCustomer->id,'history_preserved'=>true]),'created_at'=>$changedAt]);
        });
        $vehicle->tireSets()->whereIn('status',['received','stored','picked','workshop'])->get()->each(fn($set)=>app(\App\Services\TireHotelService::class)->ensureAgreement($set));
    }

    public function archiveVehicle(Request $request, Customer $customer, Vehicle $vehicle): RedirectResponse
    {
        $org=(int)$request->user()->organization_id;
        abort_unless((int)$customer->organization_id===$org&&(int)$vehicle->organization_id===$org&&(int)$vehicle->customer_id===(int)$customer->id,404);
        abort_unless(in_array($request->user()->role,['owner','admin','manager'],true),403);
        $sets=$vehicle->tireSets()->count();$agreements=\App\Models\HotelAgreement::where('vehicle_id',$vehicle->id)->count();$bookings=Booking::where('vehicle_id',$vehicle->id)->count();$quotes=\App\Models\Quote::where('vehicle_id',$vehicle->id)->count();$orders=\App\Models\WorkOrder::where('vehicle_id',$vehicle->id)->count();
        if($sets||$agreements||$bookings||$quotes||$orders)return back()->withErrors(['vehicle'=>'Kjøretøyet har historikk og kan derfor ikke slettes uten datatap ('.$sets.' hjulsett, '.$agreements.' avtaler, '.$bookings.' timer, '.$quotes.' tilbud og '.$orders.' arbeidsordrer). Flytt bilen til ny kunde i stedet.']);
        $registration=$vehicle->registration_number;$archived='ARK'.$vehicle->id.'-'.strtoupper(Str::random(6));
        DB::transaction(function()use($vehicle,$registration,$archived,$request,$org,$customer){$vehicle->update(['registration_number'=>$archived,'notes'=>trim(($vehicle->notes?"{$vehicle->notes}\n":'').'Arkivert registreringsnummer: '.$registration)]);$vehicle->delete();DB::table('audit_logs')->insert(['organization_id'=>$org,'user_id'=>$request->user()->id,'action'=>'vehicle.archived','subject_type'=>Vehicle::class,'subject_id'=>$vehicle->id,'ip_address'=>$request->ip(),'metadata'=>json_encode(['registration_number'=>$registration,'customer_id'=>$customer->id,'archived_key'=>$archived]),'created_at'=>now()]);});
        return redirect()->route('customers.show',$customer)->with('success',$registration.' er fjernet fra kunden. Registreringsnummeret kan nå brukes på et nytt kjøretøy.');
    }

    public function lookupVehicle(Request $request, Customer $customer, VehicleLookupService $lookup): RedirectResponse
    {
        abort_unless($customer->organization_id === $request->user()->organization_id, 404);
        $data = $request->validate(['registration_number' => ['required', 'string', 'max:20']]);
        try {
            return back()->with('vehicle_lookup', ['customer_id' => $customer->id, ...$lookup->lookup($data['registration_number'], $customer->organization_id)]);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['registration_number' => $e->getMessage()]);
        }
    }

    public function inventory(Request $request): View
    {
        $org = $request->user()->organization_id;
        $search = trim((string) $request->query('q'));
        $sets = TireSet::with(['vehicle.customer', 'storageLocation'])->where('organization_id', $org)->whereNotNull('received_at');
        $vehicles = Vehicle::with('customer')->where('organization_id', $org);
        if ($search !== '') {
            $filter = fn ($q) => $q->where('registration_number', 'like', "%{$search}%")
                ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%"));
            $sets->whereHas('vehicle', $filter);
            $vehicles->where($filter);
        }
        $season = (string) $request->query('season');
        $status = (string) $request->query('status');
        $condition = (string) $request->query('condition');
        $locationId = (string) $request->query('location_id');
        if (in_array($season, ['summer','winter','all_season'], true)) $sets->where('season', $season);
        if (in_array($status, ['received','stored','picked','workshop','delivered'], true)) $sets->where('status', $status);
        if ($condition === 'attention') $sets->where(fn ($q) => $q->where('minimum_tread_depth','<',3)->orWhere('dot_year','<=',now()->year-8));
        if ($condition === 'critical') $sets->where(fn ($q) => $q->where('minimum_tread_depth','<',1.6)->orWhere('dot_year','<=',now()->year-10));
        if ($condition === 'good') $sets->where(fn ($q) => $q->whereNull('minimum_tread_depth')->orWhere('minimum_tread_depth','>=',3))->where(fn ($q) => $q->whereNull('dot_year')->orWhere('dot_year','>',now()->year-8));
        if ($locationId === 'unplaced') $sets->whereNull('storage_location_id');
        elseif (ctype_digit($locationId)) $sets->where('storage_location_id', (int) $locationId);
        match ((string) $request->query('sort', 'newest')) {
            'registration_asc' => $sets->orderBy(Vehicle::select('registration_number')->whereColumn('vehicles.id', 'tire_sets.vehicle_id')),
            'registration_desc' => $sets->orderByDesc(Vehicle::select('registration_number')->whereColumn('vehicles.id', 'tire_sets.vehicle_id')),
            'tread_low' => $sets->orderByRaw('minimum_tread_depth IS NULL')->orderBy('minimum_tread_depth'),
            'tread_high' => $sets->orderByDesc('minimum_tread_depth'),
            'location_asc' => $sets->orderBy(StorageLocation::select('code')->whereColumn('storage_locations.id', 'tire_sets.storage_location_id')),
            'location_desc' => $sets->orderByDesc(StorageLocation::select('code')->whereColumn('storage_locations.id', 'tire_sets.storage_location_id')),
            'status_asc' => $sets->orderBy('status'),
            'status_desc' => $sets->orderByDesc('status'),
            default => $sets->latest(),
        };
        return view('inventory.index', [
            'sets' => $sets->paginate(50)->withQueryString(),
            'vehicles' => $vehicles->orderBy('registration_number')->limit(100)->get(),
            'locations' => StorageLocation::withCount(['tireSets' => fn ($q) => $q->whereNotNull('received_at')->whereIn('status', ['received','stored','picked','workshop'])])->where('organization_id', $org)->where('active', true)->orderBy('code')->get(),
            'inventoryStats' => [
                'stored' => TireSet::where('organization_id', $org)->whereNotNull('received_at')->where('status', 'stored')->count(),
                'received' => TireSet::where('organization_id', $org)->whereNotNull('received_at')->where('status', 'received')->count(),
                'unplaced' => TireSet::where('organization_id', $org)->whereNotNull('received_at')->whereNull('storage_location_id')->whereNotIn('status', ['delivered'])->count(),
                'attention' => TireSet::where('organization_id', $org)->whereNotNull('received_at')->where(fn ($q) => $q->where('minimum_tread_depth','<',3)->orWhere('dot_year','<=',now()->year-8))->count(),
            ],
        ]);
    }

    public function storeTireSet(Request $request, WarehousePlacementService $placement, TireHotelService $hotel): RedirectResponse
    {
        $org = $request->user()->organization_id;
        $data = $request->validate(['vehicle_id' => ['required', 'integer'], 'storage_location_id' => ['nullable', 'integer'], 'season' => ['required', 'in:summer,winter,all_season'], 'kind' => ['required', 'in:complete_wheels,tires,rims'], 'manufacturer' => ['nullable', 'string', 'max:100'], 'size' => ['nullable', 'string', 'max:50'], 'dot_year' => ['nullable', 'integer', 'between:1990,2100'], 'wheels' => ['required', 'array', 'size:4'], 'wheels.*.position' => ['required', 'distinct', 'in:front_left,front_right,rear_left,rear_right'], 'wheels.*.tread_depth_mm' => ['required', 'numeric', 'between:0,20']]);
        abort_unless(Vehicle::where('organization_id', $org)->whereKey($data['vehicle_id'])->exists(), 422);
        $location = null; $shelf = null;
        if (!empty($data['storage_location_id'])) { $location = StorageLocation::where('organization_id', $org)->whereKey($data['storage_location_id'])->firstOrFail(); $shelf = $placement->nextShelf($location); if ($shelf === null) return back()->withErrors(['storage_location_id' => $location->code.' er full. Velg en annen reol.'])->withInput(); }
        $wheels = $data['wheels']; unset($data['wheels']);
        $minimum = (float) collect($wheels)->min('tread_depth_mm');
        $set = DB::transaction(function () use ($data, $wheels, $minimum, $shelf, $org, $request) {
            $set = TireSet::create([...$data, 'minimum_tread_depth' => $minimum, 'storage_shelf_number' => $shelf, 'public_id' => (string) Str::uuid(), 'organization_id' => $org, 'code' => 'HJ-'.strtoupper(Str::random(8)), 'status' => $data['storage_location_id'] ? 'stored' : 'received', 'received_at' => now()]);
            $status = $minimum < 3 ? 'replace' : ($minimum < 4 ? 'attention' : 'good');
            $inspection = TireInspection::create(['public_id' => (string) Str::uuid(), 'organization_id' => $org, 'tire_set_id' => $set->id, 'inspected_by' => $request->user()->id, 'overall_status' => $status, 'inspected_at' => now()]);
            foreach ($wheels as $wheel) $inspection->measurements()->create([...$wheel, 'dot_year' => $data['dot_year'] ?? null, 'tpms_status' => 'not_checked', 'tire_damage' => false, 'rim_damage' => false, 'uneven_wear' => false]);
            return $set;
        });
        $hotel->ensureAgreement($set);
        return back()->with('success', 'Hjulsettet er registrert på dekkhotell. Hotellavtale og fakturerbart grunnlag er opprettet.')->with('label_url', route('tire-sets.labels', ['ids' => $set->id]));
    }

    public function updateTireSetStatus(Request $request, TireSet $tireSet, WarehousePlacementService $placement, TireHotelService $hotel): RedirectResponse
    {
        abort_unless($tireSet->organization_id === $request->user()->organization_id, 404);
        $data = $request->validate(['status' => ['required', 'in:received,stored,picked,workshop,delivered'], 'storage_location_id' => ['nullable', 'integer']]);
        $location = null;
        if (! empty($data['storage_location_id'])) $location = StorageLocation::where('organization_id', $tireSet->organization_id)->whereKey($data['storage_location_id'])->firstOrFail();
        $updates = ['status' => $data['status']];
        if (!$tireSet->received_at) $updates['received_at'] = now();
        if (array_key_exists('storage_location_id', $data)) { $updates['storage_location_id'] = $data['storage_location_id']; $updates['storage_shelf_number'] = $location ? $placement->nextShelf($location) : null; if ($location && $updates['storage_shelf_number'] === null) return back()->withErrors(['storage_location_id' => $location->code.' er full.']); }
        if ($data['status'] === 'delivered') $updates['delivered_at'] = now();
        elseif ($tireSet->delivered_at) $updates['delivered_at'] = null;
        $fromLocation = $tireSet->storage_location_id;
        DB::transaction(function () use ($tireSet, $updates, $request, $fromLocation) {
            $tireSet->update($updates);
            $toLocation = $tireSet->storage_location_id;
            if ($fromLocation !== $toLocation) DB::table('storage_location_movements')->insert(['organization_id'=>$tireSet->organization_id,'tire_set_id'=>$tireSet->id,'from_location_id'=>$fromLocation,'to_location_id'=>$toLocation,'moved_by'=>$request->user()->id,'reason'=>'web_workflow','moved_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        });
        if(in_array($tireSet->status,['received','stored','picked','workshop'],true))$hotel->ensureAgreement($tireSet);
        return back()->with('success', 'Hjulsettet er flyttet til «'.['received'=>'Mottatt','stored'=>'På lager','picked'=>'Plukket','workshop'=>'Verksted','delivered'=>'Utlevert'][$data['status']].'».');
    }

    public function tireLabels(Request $request): View
    {
        $ids = collect(explode(',', (string) $request->query('ids')))->filter(fn ($id) => ctype_digit($id))->map(fn ($id) => (int) $id)->unique()->take(100);
        abort_if($ids->isEmpty(), 404);
        $sets = TireSet::with(['vehicle.customer', 'storageLocation'])->where('organization_id', $request->user()->organization_id)->whereIn('id', $ids)->get();
        abort_if($sets->isEmpty(), 404);
        $qrCodes = $sets->mapWithKeys(function (TireSet $set): array {
            $result = (new Builder(
                writer: new SvgWriter(),
                data: strtoupper($set->code),
                errorCorrectionLevel: ErrorCorrectionLevel::Medium,
                size: 240,
                margin: 8,
            ))->build();

            return [$set->id => $result->getDataUri()];
        });

        return view('inventory.labels', ['sets' => $sets, 'qrCodes' => $qrCodes]);
    }

    public function markLabelsPrinted(Request $request): JsonResponse
    {
        $data=$request->validate(['ids'=>['required','array','between:1,100'],'ids.*'=>['integer']]);
        return response()->json(['updated'=>TireSet::where('organization_id',$request->user()->organization_id)->whereIn('id',$data['ids'])->update(['label_printed_at'=>now()])]);
    }

    public function bookings(Request $request): View
    {
        $org = $request->user()->organization_id;
        $settings = ServiceSetting::where('branch_id', $request->user()->branch_id)->first();
        $bookingQuery = Booking::with(['customer', 'vehicle.tireSets', 'assignedUser', 'workBay', 'checkoutPayment'])->where('organization_id', $org);
        if ($request->query('date') === 'today') $bookingQuery->whereDate('starts_at', today());
        elseif (!$request->filled('from')) $bookingQuery->where('starts_at', '>=', today())->where('starts_at', '<', today()->addDays(61));
        if ($request->filled('from')) $bookingQuery->whereDate('starts_at','>=',$request->date('from'));
        if ($request->filled('to')) $bookingQuery->whereDate('starts_at','<=',$request->date('to'));
        if (in_array($request->query('status'), ['scheduled','arrived','in_progress','completed','cancelled','no_show'], true)) $bookingQuery->where('status',$request->query('status'));
        $bookings = $bookingQuery->orderBy('starts_at')->paginate(30)->withQueryString();
        $bookings->getCollection()->each(function ($booking) {
            $booking->setAttribute('completion_tire_sets', $booking->vehicle?->tireSets?->map(fn ($set) => [
                'id'=>$set->id, 'code'=>$set->code, 'season'=>$set->season, 'size'=>$set->size, 'status'=>$set->status,
            ])->sortBy(fn($set)=>(['delivered'=>0,'workshop'=>1,'picked'=>2,'received'=>3,'stored'=>4][$set['status']]??99))->values()->all() ?? []);
        });
        $workBays = WorkBay::where('branch_id', $request->user()->branch_id)->where('active', true)->orderBy('code')->get();
        $employees = User::where('organization_id', $org)->where('active', true)->orderBy('name')->get();
        $calendarEmployees = $employees->where('branch_id', $request->user()->branch_id)->values();
        $capacityBookings = Booking::where('organization_id', $org)->where('branch_id', $request->user()->branch_id)->whereNotIn('status', ['cancelled', 'no_show'])->where('starts_at', '>=', today()->subDay())->where('starts_at', '<', today()->addDays(62))->get(['id','starts_at','ends_at']);
        if ($workBays->isNotEmpty()) $bookings->getCollection()->each(function ($booking) use ($capacityBookings, $workBays) {$simultaneous=$capacityBookings->filter(fn($other)=>$other->starts_at->lt($booking->ends_at)&&$other->ends_at->gt($booking->starts_at))->count();$booking->setAttribute('capacity_overbooked',$simultaneous>$workBays->count());});
        $services = ServiceProduct::where('organization_id',$org)->where('active',true)->orderBy('name')->get();
        $bookingPrefill = null;
        if ($request->query('new') === '1' && $request->filled('customer_id')) {
            $customer = Customer::with(['vehicles' => fn ($query) => $query->orderBy('registration_number')])
                ->where('organization_id', $org)->find($request->integer('customer_id'));
            if ($customer) {
                $preferredVehicleId = $request->filled('vehicle_id')
                    ? $customer->vehicles->firstWhere('id', $request->integer('vehicle_id'))?->id
                    : null;
                $bookingPrefill = [
                    'customer' => [
                        'id' => $customer->id,
                        'name' => $customer->name,
                        'customer_number' => $customer->customer_number,
                        'vehicles' => $customer->vehicles->map(fn ($vehicle) => [
                            'id' => $vehicle->id,
                            'registration_number' => $vehicle->registration_number,
                            'make' => $vehicle->make,
                            'model' => $vehicle->model,
                        ])->values(),
                    ],
                    'vehicle_id' => $preferredVehicleId,
                ];
            }
        }
        $availabilitySlots = collect();$availabilityService = null;
        if ($request->query('view') === 'available' && $services->isNotEmpty()) {
            $availabilityService = $services->firstWhere('id',(int)$request->query('service_id')) ?? $services->first();
            $day = $request->filled('available_date') ? now()->parse($request->query('available_date'))->startOfDay() : today();if($day->lt(today()))$day=today();
            $duration=max(5,(int)$availabilityService->duration_minutes);$bayCount=$workBays->count();$technicianCount=User::where('organization_id',$org)->where('branch_id',$request->user()->branch_id)->where('active',true)->where('role','technician')->count();$capacity=$bayCount>0&&$technicianCount>0?min($bayCount,$technicianCount):max(1,$bayCount,$technicianCount);
            $existing=Booking::where('organization_id',$org)->where('branch_id',$request->user()->branch_id)->whereNotIn('status',['cancelled','no_show'])->where('starts_at','<',$day->copy()->addDays(31)->endOfDay())->where('ends_at','>',$day)->get(['starts_at','ends_at']);
            for($checked=0;$checked<30&&$availabilitySlots->count()<40;$checked++,$day->addDay()){if($day->isWeekend())continue;for($slot=$day->copy()->setTime(8,0);$slot->copy()->addMinutes($duration)->lte($day->copy()->setTime(16,0));$slot->addMinutes(15)){if($slot->isPast())continue;$end=$slot->copy()->addMinutes($duration);$occupied=$existing->filter(fn($booking)=>$booking->starts_at->lt($end)&&$booking->ends_at->gt($slot))->count();if($occupied<$capacity){$availabilitySlots->push(['starts_at'=>$slot->format('Y-m-d\TH:i'),'day_key'=>$slot->toDateString(),'day'=>$slot->translatedFormat('l d. F'),'time'=>$slot->format('H:i'),'end'=>$end->format('H:i')]);if($availabilitySlots->count()>=40)break;}}}
        }
        $calendarDate = $request->filled('calendar_date') ? now()->parse($request->query('calendar_date'))->startOfDay() : today();
        $calendarSplit = in_array($request->query('split'), ['all','bay','employee'], true) ? $request->query('split') : 'all';
        $weeklyHours = $settings?->weekly_hours ?? [1=>['08:00','16:00'],2=>['08:00','16:00'],3=>['08:00','16:00'],4=>['08:00','16:00'],5=>['08:00','16:00']];
        $calendarHours = $weeklyHours[$calendarDate->dayOfWeek] ?? null;
        $calendarSlots = collect();
        if (is_array($calendarHours) && count($calendarHours) >= 2) {
            $calendarStart = $calendarDate->copy()->setTimeFromTimeString($calendarHours[0]);
            $calendarEnd = $calendarDate->copy()->setTimeFromTimeString($calendarHours[1]);
            if ($calendarEnd->gt($calendarStart)) for ($slot = $calendarStart->copy(); $slot->lt($calendarEnd); $slot->addMinutes(15)) $calendarSlots->push($slot->copy());
        }
        $calendarBookings = Booking::with(['customer','vehicle','assignedUser','workBay'])->where('organization_id',$org)->where('branch_id',$request->user()->branch_id)->whereNotIn('status',['cancelled','no_show'])->where('starts_at','<',$calendarDate->copy()->addDay())->where('ends_at','>',$calendarDate)->orderBy('starts_at')->get();
        $parallelCapacity = $workBays->count() > 0 && $calendarEmployees->count() > 0 ? min($workBays->count(), $calendarEmployees->count()) : max(1, $workBays->count(), $calendarEmployees->count());
        $calendarLanes = match ($calendarSplit) {
            'bay' => $workBays->map(fn($bay)=>['key'=>'bay-'.$bay->id,'label'=>$bay->code,'type'=>'bay','id'=>$bay->id])->prepend(['key'=>'bay-none','label'=>'Ikke tildelt','type'=>'bay','id'=>null])->values(),
            'employee' => $calendarEmployees->map(fn($employee)=>['key'=>'employee-'.$employee->id,'label'=>$employee->name,'type'=>'employee','id'=>$employee->id])->prepend(['key'=>'employee-none','label'=>'Ikke tildelt','type'=>'employee','id'=>null])->values(),
            default => collect([['key'=>'all','label'=>'Hele verkstedet','type'=>'all','id'=>null]]),
        };
        return view('bookings.index', ['bookings' => $bookings, 'workBays' => $workBays, 'employees' => $employees, 'services' => $services, 'suggestedDuration' => ($settings?->minutes_per_wheel ?? 8) * 4 + ($settings?->booking_buffer_minutes ?? 5), 'capacityBookings'=>$capacityBookings,'availabilitySlots'=>$availabilitySlots,'availabilityService'=>$availabilityService,'bookingPrefill'=>$bookingPrefill,'calendarDate'=>$calendarDate,'calendarSplit'=>$calendarSplit,'calendarSlots'=>$calendarSlots,'calendarBookings'=>$calendarBookings,'calendarLanes'=>$calendarLanes,'parallelCapacity'=>$parallelCapacity]);
    }

    public function bookingCustomerSearch(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:2', 'max:60']]);
        $term = trim($data['q']);
        $customers = Customer::with(['vehicles' => fn ($query) => $query->orderBy('registration_number')])
            ->where('organization_id', $request->user()->organization_id)
            ->where(function ($query) use ($term) {
                $query->where('name', 'like', "%{$term}%")
                    ->orWhere('customer_number', 'like', "%{$term}%")
                    ->orWhereHas('vehicles', fn ($vehicles) => $vehicles->where('registration_number', 'like', '%'.strtoupper(preg_replace('/\s+/', '', $term)).'%'));
            })
            ->orderBy('name')->limit(20)->get();

        return response()->json(['data' => $customers->map(fn ($customer) => [
            'id' => $customer->id,
            'name' => $customer->name,
            'customer_number' => $customer->customer_number,
            'type' => $customer->type,
            'vehicles' => $customer->vehicles->map(fn ($vehicle) => [
                'id' => $vehicle->id,
                'registration_number' => $vehicle->registration_number,
                'make' => $vehicle->make,
                'model' => $vehicle->model,
            ])->values(),
        ])->values()]);
    }

    public function bookingAvailability(Request $request): JsonResponse
    {
        $data = $request->validate(['service_product_ids' => ['required', 'array', 'min:1', 'max:20'], 'service_product_ids.*' => ['required', 'integer', 'distinct'], 'date' => ['nullable', 'date_format:Y-m-d'], 'limit' => ['nullable', 'integer', 'between:1,10'], 'vehicle_count' => ['nullable', 'integer', 'between:1,20']]);
        $user = $request->user();
        $services = ServiceProduct::where('organization_id', $user->organization_id)->where('active', true)->whereIn('id', $data['service_product_ids'])->get();
        abort_unless($services->count() === count($data['service_product_ids']), 422);
        $duration = max(5, (int) $services->sum('duration_minutes'));
        $requiredCapacity = max(1, (int) ($data['vehicle_count'] ?? 1));
        $bayCount = WorkBay::where('branch_id', $user->branch_id)->where('active', true)->count();
        $technicianCount = User::where('organization_id', $user->organization_id)->where('branch_id', $user->branch_id)->where('active', true)->where('role', 'technician')->count();
        $capacity = $bayCount > 0 && $technicianCount > 0 ? min($bayCount, $technicianCount) : max(1, $bayCount, $technicianCount);
        $day = isset($data['date']) ? now()->parse($data['date'])->startOfDay() : today();
        if ($day->lt(today())) $day = today();
        $limit = (int) ($data['limit'] ?? 10);
        $slots = collect();
        $existing = Booking::where('organization_id', $user->organization_id)->where('branch_id', $user->branch_id)->whereNotIn('status', ['cancelled', 'no_show'])->where('starts_at', '<', $day->copy()->addDays(61)->endOfDay())->where('ends_at', '>', $day)->get(['starts_at', 'ends_at']);
        for ($daysChecked = 0; $daysChecked < 60 && $slots->count() < $limit; $daysChecked++, $day->addDay()) {
            if ($day->isWeekend()) continue;
            for ($slot = $day->copy()->setTime(8, 0); $slot->copy()->addMinutes($duration)->lte($day->copy()->setTime(16, 0)); $slot->addMinutes(15)) {
                if ($slot->isPast()) continue;
                $end = $slot->copy()->addMinutes($duration);
                $occupied = $existing->filter(fn ($booking) => $booking->starts_at->lt($end) && $booking->ends_at->gt($slot))->count();
                if (($occupied + $requiredCapacity) <= $capacity) {
                    $slots->push(['starts_at' => $slot->format('Y-m-d\TH:i'), 'date' => $slot->translatedFormat('D d. M'), 'time' => $slot->format('H:i'), 'ends_at' => $end->format('H:i')]);
                    if ($slots->count() >= $limit) break;
                }
            }
        }
        return response()->json(['data' => $slots->unique('starts_at')->values(), 'duration' => $duration, 'capacity' => $capacity]);
    }

    public function storeBooking(Request $request, CommunicationService $communication): RedirectResponse
    {
        $org = $request->user()->organization_id;
        $data = $request->validate(['customer_id' => ['required', 'integer'], 'vehicle_ids' => ['required', 'array', 'min:1', 'max:20'], 'vehicle_ids.*' => ['required', 'integer', 'distinct'], 'work_bay_id' => ['nullable', 'integer'], 'assigned_user_id' => ['nullable', 'integer'], 'service_product_ids' => ['required','array','min:1','max:20'], 'service_product_ids.*' => ['required','integer','distinct'], 'is_drop_in'=>['nullable','boolean'], 'starts_at' => ['nullable', 'required_unless:is_drop_in,1', 'date'], 'duration' => ['nullable', 'integer', 'between:5,1440'], 'notes' => ['nullable', 'string', 'max:4000']]);
        $customer = Customer::where('organization_id', $org)->findOrFail($data['customer_id']);
        $vehicles = Vehicle::where('organization_id', $org)->where('customer_id', $customer->id)->whereIn('id', $data['vehicle_ids'])->get();
        abort_unless($vehicles->count() === count($data['vehicle_ids']), 422);
        if (!empty($data['assigned_user_id'])) abort_unless(User::where('organization_id', $org)->where('active', true)->whereKey($data['assigned_user_id'])->exists(), 422);
        $services = ServiceProduct::where('organization_id',$org)->where('active',true)->whereIn('id',$data['service_product_ids'])->get();
        abort_unless($services->count() === count($data['service_product_ids']), 422);
        $service = $services->firstWhere('id', (int) $data['service_product_ids'][0]) ?? $services->first();
        $serviceNames = $services->sortBy(fn ($item) => array_search($item->id, $data['service_product_ids']))->pluck('name')->join(' + ');
        $totalPrice = (int) $services->sum('fixed_price_cents');
        $totalDuration = max(5, (int) $services->sum('duration_minutes'));
        $dropIn = $request->boolean('is_drop_in');
        $starts = $dropIn ? now() : now()->parse($data['starts_at']);
        $ends = $starts->copy()->addMinutes($totalDuration);
        if (!empty($data['work_bay_id'])) abort_unless(WorkBay::where('branch_id', $request->user()->branch_id)->whereKey($data['work_bay_id'])->exists(), 422);
        $activeBayCount=WorkBay::where('branch_id',$request->user()->branch_id)->where('active',true)->count();
        $simultaneous=Booking::where('organization_id',$org)->where('branch_id',$request->user()->branch_id)->whereNotIn('status',['cancelled','no_show'])->where('starts_at','<',$ends)->where('ends_at','>',$starts)->count();
        $vehicleCount = $vehicles->count();
        $willOverbook=$activeBayCount>0&&($simultaneous+$vehicleCount)>$activeBayCount;
        $bookings = DB::transaction(function () use ($vehicles, $data, $org, $request, $service, $services, $serviceNames, $totalPrice, $starts, $ends, $dropIn) {
            return $vehicles->map(function ($vehicle) use ($data, $org, $request, $service, $services, $serviceNames, $totalPrice, $starts, $ends, $vehicles, $dropIn) {
                $plain = $dropIn ? null : Str::random(64);
                $booking = Booking::create(['public_id' => (string) Str::uuid(), 'organization_id' => $org, 'branch_id' => $request->user()->branch_id, 'work_bay_id' => $vehicles->count() === 1 ? ($data['work_bay_id'] ?? null) : null, 'assigned_user_id' => $vehicles->count() === 1 ? ($data['assigned_user_id'] ?? null) : null, 'service_product_id'=>$service->id, 'customer_id' => $data['customer_id'], 'vehicle_id' => $vehicle->id, 'reference' => 'B-'.now()->format('ymd').'-'.strtoupper(Str::random(5)), 'service_name' => $serviceNames, 'agreed_price_cents'=>$totalPrice, 'starts_at' => $starts, 'ends_at' => $ends, 'is_drop_in'=>$dropIn, 'status'=>$dropIn?'arrived':'scheduled', 'notes' => $data['notes'] ?? null, 'confirmation_status'=>$dropIn?'not_required':'pending','confirmation_token_hash'=>$plain?hash('sha256',$plain):null,'confirmation_requested_at'=>$dropIn?null:now()]);
                foreach ($services as $position => $item) $booking->services()->attach($item->id, ['service_name'=>$item->name,'price_cents'=>$item->fixed_price_cents,'duration_minutes'=>$item->duration_minutes,'position'=>$position]);
                $booking->setAttribute('plain_confirmation_token', $plain);
                return $booking;
            });
        });
        foreach ($bookings as $booking) {
            $registration = $vehicles->firstWhere('id', $booking->vehicle_id)?->registration_number;
            if ($dropIn) continue;
            $body = "Hei {$customer->name}. Vi holder av time {$starts->format('d.m.Y H:i')} for {$serviceNames} ({$registration}) til ".number_format($totalPrice/100,2,',',' ')." kr. Bekreft eller avkreft her:\n".route('booking.confirm.show',$booking->plain_confirmation_token);
            if ($customer->email) $communication->queue($org,$customer,'email',$customer->email,'Bekreft verkstedtimen',$body,'transactional',$booking->id,$request->user()->id);
            $smsSettings = ServiceSetting::where('branch_id', $request->user()->branch_id)->first();
            if ($customer->phone && ($smsSettings?->sms_enabled ?? false) && ($smsSettings?->sms_booking_confirmation_enabled ?? true)) $communication->queue($org,$customer,'sms',$customer->phone,null,$body,'transactional',$booking->id,$request->user()->id);
        }
        $response=back()->with('success',$dropIn ? ($vehicleCount === 1 ? 'Drop-in-kunden er lagt til i dagens kø.' : $vehicleCount.' drop-in-jobber er lagt til i dagens kø.') : ($vehicleCount === 1 ? 'Bookingen er opprettet.' : $vehicleCount.' bookinger er opprettet. Ressurser kan tildeles per bil i etterkant.'));
        return $willOverbook?$response->with('warning','Bookingene ble tillatt, men tidspunktet er overbooket: '.($simultaneous+$vehicleCount).' samtidige bookinger og '.$activeBayCount.' arbeidsbukker.'):$response;
    }

    public function completeBooking(Request $request, Booking $booking, AccountingExportService $accounting, BookingWorkflowService $workflow, TireHotelService $hotel): RedirectResponse
    {
        abort_unless($booking->organization_id === $request->user()->organization_id, 404);
        abort_unless(in_array($request->user()->role, ['owner','admin','manager'], true), 403);
        if (in_array($booking->status, ['cancelled','no_show'], true)) return back()->withErrors(['booking'=>'En avbrutt eller uteblitt booking kan ikke fullføres.']);
        $data=$request->validate(['return_to_hotel'=>['nullable','boolean'],'tire_set_id'=>['nullable','integer','required_if:return_to_hotel,1']]);
        $tireSet=null;
        if($request->boolean('return_to_hotel'))$tireSet=TireSet::where('organization_id',$booking->organization_id)->where('vehicle_id',$booking->vehicle_id)->findOrFail($data['tire_set_id']);
        $invoice=DB::transaction(function () use ($booking, $accounting, $request, $workflow, $tireSet, $hotel) {
            $booking->update(['status'=>'completed']);
            if($tireSet){
                $receiving=StorageLocation::where('organization_id',$booking->organization_id)->where('branch_id',$booking->branch_id)->where('active',true)->where(fn($q)=>$q->where('location_type','receiving')->orWhere('code','MOTTAK'))->orderByRaw("CASE WHEN code = 'MOTTAK' THEN 0 ELSE 1 END")->first();
                $from=$tireSet->storage_location_id;
                $tireSet->update(['status'=>'received','storage_location_id'=>$receiving?->id,'storage_shelf_number'=>null,'wash_status'=>'needed','washed'=>false,'minimum_tread_depth'=>null,'received_at'=>now(),'delivered_at'=>null]);
                if($from!==$receiving?->id)DB::table('storage_location_movements')->insert(['organization_id'=>$booking->organization_id,'tire_set_id'=>$tireSet->id,'from_location_id'=>$from,'to_location_id'=>$receiving?->id,'moved_by'=>$request->user()->id,'reason'=>'return_from_completed_booking','moved_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
                $order=$workflow->createWorkOrder($booking);$order->update(['status'=>'in_progress','completed_at'=>null]);
                foreach(['Mottak: kontroller og skann hjulsett','Vask hjulsett','Mål mønsterdybde på alle fire hjul','Kontroller tilstand og dokumenter avvik','Tildel lagerplass og sett hjulsett på lager']as$position=>$name)$order->tasks()->updateOrCreate(['name'=>$name],['required'=>true,'completed'=>false,'completed_by'=>null,'completed_at'=>null,'position'=>20+$position]);
                $hotel->ensureAgreement($tireSet);
            }
            // Fullføring lager grunnlaget, men betaling/faktura velges eksplisitt i neste steg.
            $invoice=$accounting->createFromBooking($booking, false);
            DB::table('audit_logs')->insert(['organization_id'=>$booking->organization_id,'user_id'=>$request->user()->id,'action'=>'booking.completed','subject_type'=>Booking::class,'subject_id'=>$booking->id,'metadata'=>json_encode(['invoice_export_id'=>$invoice->id,'tire_set_returned_id'=>$tireSet?->id]),'created_at'=>now()]);
            return $invoice;
        });
        $plain=Str::random(64);
        $payment=CheckoutPayment::firstOrCreate(
            ['booking_id'=>$booking->id,'invoice_export_id'=>$invoice->id],
            ['public_id'=>(string)Str::uuid(),'organization_id'=>$booking->organization_id,'amount_cents'=>$invoice->total_cents,'terminal_reference'=>'DP-'.Str::upper(Str::random(18)),'lookup_token_hash'=>hash('sha256',$plain),'expires_at'=>now()->addHours(24)]
        );
        if(!$payment->wasRecentlyCreated)$payment->update(['amount_cents'=>$invoice->total_cents,'lookup_token_hash'=>hash('sha256',$plain),'expires_at'=>now()->addHours(24),'last_error'=>null]);
        return redirect()->route('checkout.payment',[$payment,$plain])->with('success',$tireSet?'Jobben er fullført. Hjulsettet er flyttet til Mottak. Velg nå betaling.':'Jobben er fullført. Velg nå hvordan kunden skal betale.');
    }

    public function reopenBooking(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($booking->organization_id === $request->user()->organization_id, 404);
        abort_unless(in_array($request->user()->role, ['owner','admin','manager'], true), 403);
        if ($booking->status !== 'completed') return back()->withErrors(['booking'=>'Bare fullførte jobber kan åpnes igjen.']);
        $exported = false;
        DB::transaction(function () use ($booking, $request, &$exported) {
            $locked = Booking::whereKey($booking->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->status === 'completed', 409, 'Jobben er allerede åpnet igjen.');
            $locked->update(['status'=>'scheduled']);
            $invoice = \App\Models\InvoiceExport::where('booking_id',$locked->id)->lockForUpdate()->first();
            if ($invoice && in_array($invoice->status,['ready','queued','failed'],true)) $invoice->update(['status'=>'cancelled','queued_at'=>null,'failed_at'=>null,'last_error'=>'Fullføring angret av '.$request->user()->name.'.']);
            elseif ($invoice && in_array($invoice->status,['processing','exported'],true)) $exported = true;
            DB::table('audit_logs')->insert(['organization_id'=>$locked->organization_id,'user_id'=>$request->user()->id,'action'=>'booking.reopened','subject_type'=>Booking::class,'subject_id'=>$locked->id,'metadata'=>json_encode(['invoice_status'=>$invoice?->status]),'created_at'=>now()]);
        });
        $response=back()->with('success','Jobben er satt tilbake til «Venter».');
        return $exported?$response->with('warning','Fakturaen er allerede sendt eller under behandling. Kontroller behovet for kreditering i regnskapssystemet.'):$response;
    }
}
