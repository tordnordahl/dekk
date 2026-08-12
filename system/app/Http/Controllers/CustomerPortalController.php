<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerPortalToken;
use App\Models\Quote;
use App\Models\ServiceProduct;
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
        $customer = Customer::with([
            'organization',
            'vehicles.tireSets.inspections.measurements',
            'bookings' => fn ($query) => $query->where('starts_at', '>=', now())->whereNotIn('status', ['cancelled', 'no_show'])->orderBy('starts_at'),
            'quotes' => fn ($query) => $query->with(['items', 'vehicle', 'sourceTireSet'])->latest()->limit(20),
            'workOrders' => fn ($query) => $query->with(['vehicle', 'tasks'])->latest()->limit(20),
        ])->findOrFail($access->customer_id);
        $services = ServiceProduct::where('organization_id', $customer->organization_id)->where('active', true)->orderBy('name')->get();
        $selectedServices = $services->take(1);
        $branchId = $customer->branch_id ?: DB::table('branches')->where('organization_id',$customer->organization_id)->where('active',true)->value('id');
        $slots = $selectedServices->isEmpty() || !$branchId ? collect() : $availability->slots($customer->organization_id, $branchId, $selectedServices, null, 5);
        return view('portal.show', compact('customer', 'token', 'services', 'slots'));
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

    private function access(string $token): CustomerPortalToken
    {
        abort_unless(strlen($token) === 64 && ctype_alnum($token), 404);
        $access = CustomerPortalToken::where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->firstOrFail();
        abort_if($access->expires_at->isPast(), 410, 'Portallenken er utløpt.');
        $access->update(['last_used_at' => now()]);
        return $access;
    }
}
