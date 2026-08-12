<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\CheckoutPayment;
use App\Models\IntegrationSetting;
use App\Models\InvoiceExport;
use App\Models\Organization;
use App\Models\Vehicle;
use App\Services\Accounting\AccountingPlatformSettings;
use App\Services\CheckoutPaymentService;
use App\Services\VippsPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class CheckoutPortalController extends Controller
{
    private function enabled(?int $organizationId = null): bool
    {
        return (bool) (app(AccountingPlatformSettings::class)->zettle()['pilot_enabled'] ?? false)
            || IntegrationSetting::where('organization_id', $organizationId)->whereIn('provider', ['payment_terminal', 'payment_vipps'])->where('active', true)->exists();
    }

    public function show(Organization $organization): View
    {
        return view('checkout.show', ['organization' => $organization, 'pilotEnabled' => $this->enabled($organization->id)]);
    }

    public function lookup(Request $request, Organization $organization): View|RedirectResponse
    {
        if (! $this->enabled($organization->id)) return back()->withErrors(['registration_number' => 'Selvbetjent betaling er ikke aktivert ennå. Kontakt verkstedet.']);
        $data = $request->validate(['registration_number' => ['required', 'string', 'max:20']]);
        $reg = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $data['registration_number']));
        $vehicle = Vehicle::where('organization_id', $organization->id)->where('registration_number', $reg)->first();
        if (! $vehicle) return back()->withErrors(['registration_number' => 'Vi fant ingen ferdig jobb på dette registreringsnummeret.'])->withInput();
        $booking = Booking::where('organization_id', $organization->id)->where('vehicle_id', $vehicle->id)->where('status', 'completed')->latest('updated_at')->first();
        $invoice = $booking ? InvoiceExport::where('booking_id', $booking->id)->first() : null;
        if (! $booking || ! $invoice || $invoice->status === 'exported') return back()->withErrors(['registration_number' => 'Vi fant ingen ubetalt, ferdig jobb på dette registreringsnummeret.'])->withInput();
        $plain = Str::random(64);
        $payment = CheckoutPayment::firstOrCreate(['booking_id' => $booking->id, 'invoice_export_id' => $invoice->id], [
            'public_id' => (string) Str::uuid(), 'organization_id' => $organization->id, 'amount_cents' => $invoice->total_cents,
            'terminal_reference' => 'DP-'.Str::upper(Str::random(18)), 'lookup_token_hash' => hash('sha256', $plain), 'expires_at' => now()->addHours(24),
        ]);
        if (! $payment->wasRecentlyCreated) {
            $plain = Str::random(64);
            $payment->update(['lookup_token_hash' => hash('sha256', $plain)]);
        }
        return redirect()->route('checkout.payment', [$payment, $plain]);
    }

    public function showPayment(CheckoutPayment $payment, string $token): View
    {
        $this->validToken($payment, $token);
        $payment->load(['booking.customer', 'booking.vehicle', 'invoiceExport']);
        $organization = Organization::findOrFail($payment->organization_id);
        return $this->paymentView($organization, $payment->booking->vehicle, $payment->booking, $payment->invoiceExport, $payment, $token);
    }

    public function start(Request $request, CheckoutPayment $payment, string $token, VippsPaymentService $vipps): RedirectResponse
    {
        $this->validToken($payment, $token);
        $customer = $payment->booking()->with('customer')->first()?->customer;
        $data = $request->validate([
            'payment_method' => ['required', 'in:terminal,vipps'], 'receipt_channel' => ['required', 'in:email,sms,print,none'],
            'receipt_recipient' => ['nullable', 'string', 'max:255'],
        ]);
        $recipient = trim((string) ($data['receipt_recipient'] ?? ''));
        if ($data['receipt_channel'] === 'email') {
            $recipient = $recipient ?: (string) $customer?->email;
            if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) return back()->withErrors(['receipt_recipient' => 'Skriv inn en gyldig e-postadresse.']);
        } elseif ($data['receipt_channel'] === 'sms') {
            $recipient = preg_replace('/[^0-9+]/', '', $recipient ?: (string) $customer?->phone);
            if (! preg_match('/^(?:\+47)?[49]\d{7}$/', $recipient)) return back()->withErrors(['receipt_recipient' => 'Skriv inn et gyldig norsk mobilnummer.']);
        } else $recipient = '';
        if ($payment->status === 'failed') $payment->update(['terminal_reference' => 'DP-'.Str::upper(Str::random(18)), 'provider_reference' => null, 'provider_status' => null, 'status' => 'pending']);
        $payment->update(['payment_method' => $data['payment_method'], 'receipt_channel' => $data['receipt_channel'], 'receipt_recipient' => $recipient ?: null, 'last_error' => null]);
        if ($data['payment_method'] === 'terminal') {
            $payment->update(['provider' => 'terminal', 'provider_status' => 'WAITING_FOR_CARD', 'status' => 'processing']);
            return back()->with('success', 'Beløpet er klart. Følg instruksjonene på bankterminalen.');
        }
        try {
            return redirect()->away($vipps->create($payment, route('checkout.vipps.return', [$payment, $token])));
        } catch (Throwable $exception) {
            report($exception);
            $payment->update(['status' => 'failed', 'last_error' => $exception->getMessage()]);
            return back()->withErrors(['payment' => $exception->getMessage()]);
        }
    }

    public function vippsReturn(CheckoutPayment $payment, string $token, VippsPaymentService $vipps): RedirectResponse
    {
        $this->validToken($payment, $token);
        try { $payment = $vipps->synchronize($payment); } catch (Throwable $exception) { report($exception); $payment->update(['last_error' => $exception->getMessage()]); }
        if ($payment->status === 'paid') return redirect()->route('checkout.receipt', [$payment, $token]);
        return redirect()->route('checkout.payment', [$payment, $token])->withErrors(['payment' => $payment->last_error ?: 'Vipps-betalingen er ikke bekreftet.']);
    }

    public function status(CheckoutPayment $payment, string $token, VippsPaymentService $vipps): JsonResponse
    {
        $this->validToken($payment, $token);
        if ($payment->payment_method === 'vipps' && $payment->status === 'processing') {
            try { $payment = $vipps->synchronize($payment); } catch (Throwable $exception) { report($exception); }
        }
        return response()->json(['status' => $payment->status, 'provider_status' => $payment->provider_status, 'paid_at' => $payment->paid_at?->toIso8601String()]);
    }

    public function receipt(CheckoutPayment $payment, string $token): View
    {
        $this->validToken($payment, $token);
        abort_unless($payment->status === 'paid', 404);
        $payment->load(['booking.customer', 'booking.vehicle', 'invoiceExport']);
        return view('checkout.receipt', compact('payment'));
    }

    public function complete(Request $request, CheckoutPayment $payment, CheckoutPaymentService $service): RedirectResponse
    {
        $data = $request->validate(['provider_reference' => ['required', 'string', 'max:255']]);
        abort_unless($payment->organization_id === $request->user()->organization_id, 404);
        $service->complete($payment, 'terminal', $data['provider_reference'], 'APPROVED');
        return back()->with('success', 'Terminalbetalingen er bekreftet. Faktura sendes ikke.');
    }

    private function paymentView(Organization $organization, Vehicle $vehicle, Booking $booking, InvoiceExport $invoice, CheckoutPayment $payment, string $plain): View
    {
        $terminal = IntegrationSetting::where('organization_id', $organization->id)->where('provider', 'payment_terminal')->where('active', true)->first();
        $terminalConfig = $terminal ? json_decode(Crypt::decryptString($terminal->encrypted_credentials), true) : [];
        return view('checkout.payment', compact('organization', 'vehicle', 'booking', 'invoice', 'payment', 'plain') + [
            'terminalEnabled' => (bool) $terminal, 'terminalName' => $terminalConfig['name'] ?? 'Bankterminal',
            'vippsEnabled' => app(VippsPaymentService::class)->configured($organization->id),
        ]);
    }

    private function validToken(CheckoutPayment $payment, string $token): void
    {
        abort_unless(hash_equals((string) $payment->lookup_token_hash, hash('sha256', $token)), 404);
    }
}
