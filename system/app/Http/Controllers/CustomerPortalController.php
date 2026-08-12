<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerPortalToken;
use App\Models\Quote;
use App\Models\ServiceProduct;
use App\Models\TireProduct;
use App\Models\TireSet;
use App\Models\Vehicle;
use App\Services\AcceptedQuoteWorkflow;
use App\Services\BookingAvailabilityService;
use App\Services\CommunicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CustomerPortalController extends Controller
{
    public function invite(Request $request, Customer $customer, CommunicationService $communication): RedirectResponse
    {
        abort_unless($customer->organization_id === $request->user()->organization_id, 404);
        if (! $customer->email) return back()->withErrors(['portal' => 'Kunden må ha e-postadresse.']);

        $plain = Str::random(64);
        CustomerPortalToken::where('customer_id', $customer->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        CustomerPortalToken::create(['organization_id' => $customer->organization_id, 'customer_id' => $customer->id, 'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDays(30)]);
        $url = route('portal.show', $plain);
        $communication->queue($customer->organization_id, $customer, 'email', $customer->email, 'Din kundeportal hos DekkPilot', "Hei {$customer->name}. Se biler, hjul, tilstand, tilbud og avtaler her:\n{$url}\nLenken er personlig og gyldig i 30 dager.", 'transactional', null, $request->user()->id);
        return back()->with('success', 'Sikker portalinvitasjon er lagt i e-postkøen.');
    }

    public function show(string $token, BookingAvailabilityService $availability): View
    {
        $access = $this->access($token);
        return $this->renderPortal($access->customer_id, $token, $availability, false);
    }

    public function adminPreview(Request $request, Customer $customer, BookingAvailabilityService $availability): View
    {
        abort_unless($customer->organization_id === $request->user()->organization_id, 404);
        return $this->renderPortal($customer->id, 'preview', $availability, true);
    }

    private function renderPortal(int $customerId, string $token, BookingAvailabilityService $availability, bool $adminPreview): View
    {
        $customer = Customer::with([
            'organization',
            'vehicles.ownershipPeriods',
            'vehicles.tireSets.inspections.measurements',
            'bookings' => fn ($query) => $query->where('starts_at', '>=', now())->whereNotIn('status', ['cancelled', 'no_show'])->orderBy('starts_at'),
            'quotes' => fn ($query) => $query->with(['items', 'vehicle', 'sourceTireSet'])->latest()->limit(20),
            'workOrders' => fn ($query) => $query->with(['vehicle', 'tasks'])->latest()->limit(20),
        ])->findOrFail($customerId);
        $customer->vehicles->each(function($vehicle)use($customer){$period=$vehicle->ownershipPeriods->where('customer_id',$customer->id)->sortByDesc('started_at')->first();if(!$period)return;$vehicle->tireSets->each(function($set)use($period){$set->setRelation('inspections',$set->inspections->filter(fn($inspection)=>$inspection->inspected_at&&$inspection->inspected_at->gte($period->started_at)&&(!$period->ended_at||$inspection->inspected_at->lte($period->ended_at)))->values());});});
        $services = ServiceProduct::where('organization_id', $customer->organization_id)->where('active', true)->orderBy('name')->get();
        $preferredVehicleId = (int) session('portal_preferred_vehicle_id', 0);
        $preferredServiceId = optional($services->first(fn ($service) => preg_match('/dekk|hjul|skift|monter/i', $service->name)))->id;
        $selectedServices = $preferredVehicleId && $preferredServiceId ? $services->where('id', $preferredServiceId) : $services->take(1);
        $branchId = $customer->branch_id ?: DB::table('branches')->where('organization_id',$customer->organization_id)->where('active',true)->value('id');
        $slots = $selectedServices->isEmpty() || !$branchId ? collect() : $availability->slots($customer->organization_id, $branchId, $selectedServices, null, 5);
        $openQuotes = $customer->quotes->whereIn('status', ['sent', 'viewed'])->whereNotNull('source_tire_set_id')->keyBy('source_tire_set_id');
        $completedQuoteSetIds = $customer->quotes->where('status', 'accepted')->pluck('source_tire_set_id')->filter();
        $lowTreadSets = $customer->vehicles->flatMap(fn ($vehicle) => $vehicle->tireSets->map(function ($set) use ($vehicle) {
            $set->setRelation('vehicle', $vehicle);
            return $set;
        }))->filter(fn ($set) => $set->minimum_tread_depth !== null && (float) $set->minimum_tread_depth < 3 && filled($set->size) && ! $completedQuoteSetIds->contains($set->id));
        $recommendations = $lowTreadSets->map(function ($set) use ($openQuotes) {
            $quantity = $this->replacementQuantity($set);
            return [
                'set' => $set,
                'quote' => $openQuotes->get($set->id),
                'quantity' => $quantity,
                'products' => $this->portalProductOptions($set, $quantity),
            ];
        })->filter(fn ($recommendation) => $recommendation['quote'] || $recommendation['products']->isNotEmpty())->values();
        return view('portal.show', compact('customer', 'token', 'services', 'slots', 'adminPreview', 'recommendations', 'preferredVehicleId', 'preferredServiceId'));
    }

    public function availability(Request $request, string $token, BookingAvailabilityService $availability)
    {
        $access = $this->access($token);
        $data = $request->validate(['service_product_ids'=>['required','array','min:1','max:10'],'service_product_ids.*'=>['integer','distinct'],'date'=>['nullable','date_format:Y-m-d']]);
        $services = ServiceProduct::where('organization_id',$access->organization_id)->where('active',true)->whereIn('id',$data['service_product_ids'])->get();
        abort_unless($services->count() === count($data['service_product_ids']), 422);
        $branchId = Customer::whereKey($access->customer_id)->value('branch_id') ?: DB::table('branches')->where('organization_id',$access->organization_id)->where('active',true)->value('id');
        abort_unless($branchId, 422, 'Verkstedet mangler en aktiv avdeling.');
        return response()->json(['data'=>$availability->slots($access->organization_id,$branchId,$services,$data['date']??null,10)]);
    }

    public function createBooking(Request $request, string $token, BookingAvailabilityService $availability): RedirectResponse
    {
        $access = $this->access($token);
        $data = $request->validate(['vehicle_id'=>['required','integer'],'service_product_ids'=>['required','array','min:1','max:10'],'service_product_ids.*'=>['integer','distinct'],'starts_at'=>['required','date','after:now']]);
        $customer = Customer::findOrFail($access->customer_id);
        $vehicle = Vehicle::where('customer_id',$customer->id)->findOrFail($data['vehicle_id']);
        $services = ServiceProduct::where('organization_id',$access->organization_id)->where('active',true)->whereIn('id',$data['service_product_ids'])->get();
        abort_unless($services->count() === count($data['service_product_ids']), 422);
        $branchId = $customer->branch_id ?: DB::table('branches')->where('organization_id',$access->organization_id)->where('active',true)->value('id');
        $starts = now()->parse($data['starts_at'])->startOfMinute();
        DB::transaction(function () use ($availability,$access,$branchId,$services,$starts,$customer,$vehicle) {
            DB::table('bookings')->where('branch_id',$branchId)->where('starts_at','<',$starts->copy()->addMinutes($services->sum('duration_minutes')))->where('ends_at','>',$starts)->lockForUpdate()->get();
            abort_unless($availability->stillAvailable($access->organization_id,$branchId,$services,$starts),409,'Tiden ble nettopp tatt. Velg en annen ledig tid.');
            $ordered=$services->sortBy('name')->values(); $plain=Str::random(64);
            $booking=Booking::create(['public_id'=>(string)Str::uuid(),'organization_id'=>$access->organization_id,'branch_id'=>$branchId,'customer_id'=>$customer->id,'vehicle_id'=>$vehicle->id,'service_product_id'=>$ordered->first()->id,'reference'=>'B-'.now()->format('ymd').'-'.strtoupper(Str::random(5)),'service_name'=>$ordered->pluck('name')->join(' + '),'agreed_price_cents'=>$ordered->sum('fixed_price_cents'),'starts_at'=>$starts,'ends_at'=>$starts->copy()->addMinutes($ordered->sum('duration_minutes')),'status'=>'scheduled','confirmation_status'=>'confirmed','confirmation_token_hash'=>hash('sha256',$plain),'confirmation_requested_at'=>now(),'confirmation_responded_at'=>now()]);
            foreach($ordered as $position=>$service)$booking->services()->attach($service->id,['service_name'=>$service->name,'price_cents'=>$service->fixed_price_cents,'duration_minutes'=>$service->duration_minutes,'position'=>$position]);
            DB::table('audit_logs')->insert(['organization_id'=>$access->organization_id,'action'=>'portal.booking.created','subject_type'=>Booking::class,'subject_id'=>$booking->id,'ip_address'=>request()->ip(),'metadata'=>json_encode(['vehicle_id'=>$vehicle->id]),'created_at'=>now()]);
        });
        return back()->with('success','Timen er bestilt og bekreftet.');
    }

    public function booking(Request $request, string $token, int $booking): RedirectResponse
    {
        $access = $this->access($token);
        $item = Booking::where('customer_id', $access->customer_id)->where('starts_at', '>=', now())->whereNotIn('status', ['completed', 'cancelled', 'no_show'])->findOrFail($booking);
        $data = $request->validate(['action' => ['required', 'in:cancel,reschedule'], 'requested_at' => ['nullable', 'required_if:action,reschedule', 'date', 'after:now']]);
        if ($data['action'] === 'cancel') {
            $item->update(['status' => 'cancelled', 'confirmation_status' => 'declined', 'confirmation_responded_at' => now()]);
        } else {
            $item->update(['notes' => trim($item->notes."\nKunden ønsker ny tid: ".$data['requested_at']), 'confirmation_status' => 'pending']);
        }
        return back()->with('success', $data['action'] === 'cancel' ? 'Timen er avbestilt.' : 'Ønske om ny tid er sendt til verkstedet.');
    }

    public function quote(Request $request, string $token, int $quote, AcceptedQuoteWorkflow $workflow): RedirectResponse
    {
        $access = $this->access($token);
        $data = $request->validate(['decision' => ['required', 'in:accepted,declined'], 'quote_item_id' => ['nullable', 'integer'], 'terms_accepted' => ['exclude_unless:decision,accepted', 'required', 'accepted']]);

        DB::transaction(function () use ($access, $data, $quote, $request, $workflow) {
            $item = Quote::with('items')->where('customer_id', $access->customer_id)->lockForUpdate()->findOrFail($quote);
            abort_if($item->expires_at->isPast(), 410, 'Tilbudet er utløpt.');
            abort_unless(in_array($item->status, ['sent', 'viewed'], true), 409, 'Tilbudet er allerede besvart.');
            $updates = ['status' => $data['decision'], 'responded_at' => now(), 'response_ip' => $request->ip()];
            if ($data['decision'] === 'accepted') {
                $selected = $item->items->count() > 1 ? $item->items->firstWhere('id', (int) ($data['quote_item_id'] ?? 0)) : $item->items->first();
                abort_unless($selected, 422, 'Velg ett dekkalternativ.');
                $updates += ['selected_quote_item_id' => $selected->id, 'subtotal_cents' => $selected->line_total_cents, 'total_cents' => $selected->line_total_cents, 'purchase_terms_accepted_at' => now(), 'purchase_terms_version' => '2026-08-09'];
            }
            $item->update($updates);
            if ($data['decision'] === 'accepted') $workflow->create($item->fresh('items'));
            DB::table('audit_logs')->insert(['organization_id' => $item->organization_id, 'action' => 'portal.quote.'.$data['decision'], 'subject_type' => Quote::class, 'subject_id' => $item->id, 'ip_address' => $request->ip(), 'metadata' => json_encode(['customer_portal' => true, 'selected_quote_item_id' => $updates['selected_quote_item_id'] ?? null]), 'created_at' => now()]);
        });
        return back()->with('success', $data['decision'] === 'accepted' ? 'Tilbudet er godkjent. Verkstedet følger opp.' : 'Tilbudet er avslått.');
    }

    public function orderTires(Request $request, string $token, TireSet $tireSet, AcceptedQuoteWorkflow $workflow): RedirectResponse
    {
        $access = $this->access($token);
        $data = $request->validate([
            'tire_product_id' => ['required', 'integer'],
            'terms_accepted' => ['required', 'accepted'],
        ]);

        $vehicle = Vehicle::where('customer_id', $access->customer_id)->findOrFail($tireSet->vehicle_id);
        abort_unless($tireSet->organization_id === $access->organization_id && (float) $tireSet->minimum_tread_depth < 3 && filled($tireSet->size), 404);
        $branchId = Customer::whereKey($access->customer_id)->value('branch_id')
            ?: DB::table('branches')->where('organization_id', $access->organization_id)->where('active', true)->value('id');
        abort_unless($branchId, 422, 'Verkstedet mangler en aktiv avdeling.');

        DB::transaction(function () use ($access, $data, $tireSet, $vehicle, $branchId, $request, $workflow) {
            $existing = Quote::where('customer_id', $access->customer_id)->where('source_tire_set_id', $tireSet->id)
                ->whereIn('status', ['sent', 'viewed', 'accepted'])->lockForUpdate()->first();
            abort_if($existing, 409, 'Dette hjulsettet har allerede et aktivt eller godkjent tilbud.');

            $quantity = $this->replacementQuantity($tireSet);
            $options = $this->portalProductOptions($tireSet, $quantity, true);
            $selected = $options->firstWhere('id', (int) $data['tire_product_id']);
            abort_unless($selected, 422, 'Dekket er ikke lenger tilgjengelig. Velg et annet alternativ.');

            $total = $selected->price_cents * $quantity;
            $quote = Quote::create([
                'public_id' => (string) Str::uuid(), 'organization_id' => $access->organization_id,
                'branch_id' => $branchId, 'customer_id' => $access->customer_id, 'vehicle_id' => $vehicle->id,
                'source_tire_set_id' => $tireSet->id, 'reference' => 'KP-'.now()->format('ymd').'-'.strtoupper(Str::random(5)),
                'status' => 'accepted', 'access_token_hash' => hash('sha256', Str::random(64)),
                'subtotal_cents' => $total, 'vat_cents' => 0, 'total_cents' => $total,
                'message' => 'Bestilt av kunden i kundeportalen.', 'responded_at' => now(),
                'purchase_terms_accepted_at' => now(), 'purchase_terms_version' => '2026-08-09',
                'response_ip' => $request->ip(), 'expires_at' => now()->addDays(30),
            ]);
            $labels = ['Det beste', 'Bra valg', 'Godt valg'];
            foreach ($options as $position => $product) {
                $item = $quote->items()->create([
                    'tire_product_id' => $product->id, 'description' => trim("{$product->brand} {$product->model} {$product->size}"),
                    'recommendation_label' => $labels[$position] ?? 'Godt valg', 'position' => $position + 1,
                    'quantity' => $quantity, 'unit_price_cents' => $product->price_cents, 'line_total_cents' => $product->price_cents * $quantity,
                ]);
                if ($product->id === $selected->id) $quote->selected_quote_item_id = $item->id;
            }
            $quote->save();
            DB::table('audit_logs')->insert([
                'organization_id' => $access->organization_id, 'action' => 'portal.tires.ordered',
                'subject_type' => Quote::class, 'subject_id' => $quote->id, 'ip_address' => $request->ip(),
                'metadata' => json_encode(['customer_portal' => true, 'tire_set_id' => $tireSet->id, 'tire_product_id' => $selected->id]),
                'created_at' => now(),
            ]);
            $workflow->create($quote->fresh('items'));
        });

        return redirect()->to(route('portal.show', $token).'#bestill')
            ->with('success', 'Dekkene er reservert. Velg en ledig time for montering.')
            ->with('portal_preferred_vehicle_id', $vehicle->id);
    }

    private function replacementQuantity(TireSet $tireSet): int
    {
        $tireSet->loadMissing('inspections.measurements');
        $inspection = $tireSet->inspections->sortByDesc('inspected_at')->first();
        if (! $inspection || $inspection->measurements->isEmpty()) return 4;
        $mustReplace = $inspection->measurements->filter(fn ($wheel) =>
            ($wheel->tread_depth_mm !== null && (float) $wheel->tread_depth_mm < 3) || $wheel->tire_damage
        );
        if ($mustReplace->isEmpty()) return 4;
        if ($mustReplace->count() === 1) return 2;
        if ($mustReplace->count() === 2) {
            $axles = $mustReplace->map(fn ($wheel) => str_starts_with($wheel->position, 'front_') ? 'front' : 'rear')->unique();
            return $axles->count() === 1 ? 2 : 4;
        }
        return 4;
    }

    private function portalProductOptions(TireSet $tireSet, int $quantity, bool $lock = false)
    {
        $query = TireProduct::where('organization_id', $tireSet->organization_id)->where('active', true)
            ->where('stock_quantity', '>=', $quantity)->where('size', $tireSet->size)->where('season', $tireSet->season);
        if ($lock) $query->lockForUpdate();
        $manufacturer = Str::lower(trim((string) $tireSet->manufacturer));
        $model = Str::lower(trim((string) $tireSet->model));
        $availability=app(\App\Services\InventoryAvailabilityService::class);
        return $query->get()->filter(fn($product)=>$availability->available($product)>=$quantity)->sort(function ($left, $right) use ($manufacturer, $model) {
            $score = fn ($product) => [
                $manufacturer !== '' && Str::lower(trim($product->brand)) === $manufacturer ? 0 : 1,
                $model !== '' && Str::lower(trim($product->model)) === $model ? 0 : 1,
                -$product->price_cents,
            ];
            return $score($left) <=> $score($right);
        })->take(3)->values();
    }

    private function access(string $token): CustomerPortalToken
    {
        abort_unless(strlen($token) === 64 && ctype_alnum($token), 404);
        $access = CustomerPortalToken::where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->firstOrFail();
        abort_if($access->expires_at->isPast(), 410, 'Portallenken er utløpt.');
        $access->update(['last_used_at' => now()]);
        return $access;
    }
}
