<?php

namespace App\Services;

use App\Models\CheckoutPayment;
use Illuminate\Support\Facades\DB;

class CheckoutPaymentService
{
    public function complete(CheckoutPayment $payment, string $method, string $providerReference, ?string $providerStatus = null, array $payload = []): CheckoutPayment
    {
        $payment = DB::transaction(function () use ($payment, $method, $providerReference, $providerStatus, $payload) {
            $locked = CheckoutPayment::with(['booking.customer', 'booking.vehicle', 'invoiceExport'])->lockForUpdate()->findOrFail($payment->id);
            if ($locked->status === 'paid') return $locked;
            $locked->update([
                'status' => 'paid', 'payment_method' => $method, 'provider_reference' => $providerReference,
                'provider_status' => $providerStatus ?: 'paid', 'provider_payload' => $payload ?: null,
                'paid_at' => now(), 'last_error' => null,
            ]);
            $locked->invoiceExport()->where('status', '!=', 'exported')->update([
                'status' => 'cancelled',
                'last_error' => 'Betalt med '.($method === 'vipps' ? 'Vipps' : 'bankterminal').': '.$providerReference,
            ]);
            return $locked->fresh(['booking.customer', 'booking.vehicle', 'invoiceExport']);
        });

        $this->sendReceipt($payment);
        return $payment;
    }

    public function sendReceipt(CheckoutPayment $payment): void
    {
        if ($payment->receipt_sent_at || ! in_array($payment->receipt_channel, ['email', 'sms'], true)) return;
        $customer = $payment->booking?->customer;
        $recipient = trim((string) $payment->receipt_recipient);
        if (! $customer || $recipient === '') return;
        $method = $payment->payment_method === 'vipps' ? 'Vipps' : 'bankterminal';
        $amount = number_format($payment->amount_cents / 100, 2, ',', ' ');
        $body = "Kvittering fra {$payment->booking->organization?->name}\n"
            ."Betalt: {$amount} {$payment->currency}\n"
            ."Betalingsmåte: {$method}\n"
            ."Referanse: {$payment->terminal_reference}\n"
            ."Registreringsnummer: ".($payment->booking->vehicle?->registration_number ?? '—')."\n"
            ."Tjeneste: {$payment->booking->service_name}\n"
            ."Dato: ".optional($payment->paid_at)->format('d.m.Y H:i');
        app(CommunicationService::class)->queue(
            $payment->organization_id, $customer, $payment->receipt_channel, $recipient,
            $payment->receipt_channel === 'email' ? 'Kvittering '.$payment->terminal_reference : null,
            $body, 'transactional', $payment->booking_id
        );
        $payment->update(['receipt_sent_at' => now()]);
    }
}
