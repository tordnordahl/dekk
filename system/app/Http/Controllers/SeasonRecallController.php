<?php

namespace App\Http\Controllers;

use App\Models\CustomerPortalToken;
use App\Models\HotelAgreement;
use App\Models\IntegrationSetting;
use App\Models\ServiceSetting;
use App\Services\CommunicationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SeasonRecallController extends Controller
{
    public function index(Request $request): View
    {
        $season = in_array($request->query('season'), ['summer', 'winter'], true)
            ? $request->query('season')
            : (now()->month >= 8 || now()->month <= 2 ? 'winter' : 'summer');

        $customers = $this->eligibleCustomers((int) $request->user()->organization_id, $season)->get();
        $smsSettings = ServiceSetting::where('branch_id', $request->user()->branch_id)->first();

        return view('season-recall.index', [
            'season' => $season,
            'customers' => $customers,
            'emailCount' => $customers->whereNotNull('email')->count(),
            'smsCount' => $customers->whereNotNull('phone')->count(),
            'canSend' => in_array($request->user()->role, ['owner', 'admin', 'manager'], true) || $request->user()->is_super_admin,
            'smsAvailable' => (bool) ($smsSettings?->sms_enabled)
                && IntegrationSetting::where('organization_id', $request->user()->organization_id)->where('provider', 'twilio')->where('active', true)->exists(),
        ]);
    }

    public function send(Request $request, CommunicationService $communication): RedirectResponse
    {
        $data = $request->validate([
            'season' => ['required', Rule::in(['summer', 'winter'])],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['required', Rule::in(['email', 'sms']), 'distinct'],
            'confirm' => ['accepted'],
        ]);
        $org = (int) $request->user()->organization_id;
        $customers = $this->eligibleCustomers($org, $data['season'])->get();
        $smsSettings = ServiceSetting::where('branch_id', $request->user()->branch_id)->first();
        $smsReady = (bool) ($smsSettings?->sms_enabled)
            && IntegrationSetting::where('organization_id', $org)->where('provider', 'twilio')->where('active', true)->exists();
        if (in_array('sms', $data['channels'], true) && ! $smsReady) {
            return back()->withErrors(['channels' => 'SMS er ikke ferdig konfigurert for virksomheten.'])->withInput();
        }

        $seasonName = $data['season'] === 'winter' ? 'vinterhjul' : 'sommerhjul';
        $queued = 0;
        DB::transaction(function () use ($customers, $data, $communication, $org, $request, $seasonName, &$queued): void {
            foreach ($customers as $customer) {
                $plain = Str::random(64);
                CustomerPortalToken::where('customer_id', $customer->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                CustomerPortalToken::create(['organization_id'=>$org, 'customer_id'=>$customer->id, 'token_hash'=>hash('sha256', $plain), 'expires_at'=>now()->addDays(30)]);
                $url = route('portal.show', $plain);
                $body = "Hei {$customer->name}. Det er tid for sesongskift til {$seasonName}. Som dekkhotellkunde kan du se ledige tider og bestille direkte her:\n{$url}\nLenken er personlig og gyldig i 30 dager.";
                if (in_array('email', $data['channels'], true) && $customer->email) {
                    $communication->queue($org, $customer, 'email', $customer->email, 'Tid for sesongskift', $body, 'transactional', null, $request->user()->id);
                    $queued++;
                }
                if (in_array('sms', $data['channels'], true) && $customer->phone) {
                    $communication->queue($org, $customer, 'sms', $customer->phone, null, $body, 'transactional', null, $request->user()->id);
                    $queued++;
                }
            }
            DB::table('audit_logs')->insert(['organization_id'=>$org, 'user_id'=>$request->user()->id, 'action'=>'season_recall.queued', 'ip_address'=>$request->ip(), 'metadata'=>json_encode(['season'=>$data['season'], 'channels'=>$data['channels'], 'customers'=>$customers->count(), 'messages'=>$queued]), 'created_at'=>now()]);
        });

        return redirect()->route('season-recall.index', ['season'=>$data['season']])->with('success', $queued.' innkallinger er lagt trygt i meldingskøen.');
    }

    private function eligibleCustomers(int $organizationId, string $season)
    {
        return \App\Models\Customer::query()
            ->where('customers.organization_id', $organizationId)
            ->whereHas('vehicles', fn ($vehicles) => $vehicles
                ->whereHas('tireSets', fn ($sets) => $sets->where('season', $season))
                ->whereHas('hotelAgreements', fn ($agreements) => $agreements->where('status', 'active')))
            ->withCount(['vehicles as eligible_vehicles_count' => fn ($vehicles) => $vehicles
                ->whereHas('tireSets', fn ($sets) => $sets->where('season', $season))
                ->whereHas('hotelAgreements', fn ($agreements) => $agreements->where('status', 'active'))])
            ->orderBy('name');
    }
}
