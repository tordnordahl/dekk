<?php

namespace App\Services;

use App\Models\CheckoutPayment;
use Illuminate\Support\Facades\DB;

class CheckoutPaymentService
{
    public function __construct(private readonly ReceiptService $receipts) {}

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
                'last_error' => 'Betalt med '.match($method){'vipps'=>'Vipps','cash'=>'kontant','stripe'=>'Stripe','zettle'=>'Zettle',default=>'bankterminal'}.': '.$providerReference,
            ]);
            \App\Models\HotelCharge::where('invoice_export_id',$locked->invoice_export_id)->whereIn('status',['open','attached'])->update(['status'=>'paid','paid_at'=>now(),'updated_at'=>now()]);
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
        $receipt = $this->receipts->data($payment);
        $body = $this->receipts->plainText($payment);
        app(CommunicationService::class)->queue(
            $payment->organization_id, $customer, $payment->receipt_channel, $recipient,
            $payment->receipt_channel === 'email' ? 'Kvittering '.$receipt['number'] : null,
            $body, 'transactional', $payment->booking_id
        );
        $payment->update(['receipt_sent_at' => now()]);
    }
}
