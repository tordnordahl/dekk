<?php

namespace App\Services;

use App\Models\CheckoutPayment;

class ReceiptService
{
    public function data(CheckoutPayment $payment): array
    {
        $payment->loadMissing([
            'booking.customer.organization', 'booking.vehicle', 'booking.branch', 'invoiceExport',
        ]);

        $booking = $payment->booking;
        $customer = $booking?->customer;
        $organization = $customer?->organization;
        $branch = $booking?->branch;
        $invoice = $payment->invoiceExport;
        $brreg = (array) ($organization?->brreg_data ?? []);
        $lines = collect($invoice?->lines ?: [[
            'description' => $booking?->service_name ?: 'Verkstedtjeneste',
            'quantity' => 1,
            'unit_price_cents' => $payment->amount_cents,
            'total_cents' => $payment->amount_cents,
            'vat_rate' => ($invoice?->vat_cents ?? 0) > 0 ? 25 : 0,
        ]])->map(function (array $line): array {
            $quantity = max(1, (float) ($line['quantity'] ?? 1));
            $gross = (int) ($line['total_cents'] ?? round(((int) ($line['unit_price_cents'] ?? 0)) * $quantity));
            $rate = (float) ($line['vat_rate'] ?? 0);
            $net = $rate > 0 ? (int) round($gross / (1 + $rate / 100)) : $gross;
            return [
                'description' => (string) ($line['description'] ?? 'Vare/tjeneste'),
                'quantity' => $quantity, 'gross_cents' => $gross, 'net_cents' => $net,
                'vat_cents' => $gross - $net, 'vat_rate' => $rate,
            ];
        })->values();

        $vatGroups = $lines->groupBy(fn (array $line) => number_format($line['vat_rate'], 2, '.', ''))
            ->map(fn ($group, $rate) => [
                'rate' => (float) $rate,
                'net_cents' => (int) $group->sum('net_cents'),
                'vat_cents' => (int) $group->sum('vat_cents'),
                'gross_cents' => (int) $group->sum('gross_cents'),
            ])->values();

        $sellerAddress = $this->address([
            $branch?->address ?: data_get($brreg, 'address'),
            $branch?->postal_code ?: data_get($brreg, 'postal_code'),
            $branch?->city ?: data_get($brreg, 'city'),
        ]);
        $buyer = (array) ($invoice?->customer_snapshot ?: []);
        $buyerAddress = $this->address([
            $buyer['address'] ?? $customer?->address,
            $buyer['postal_code'] ?? $customer?->postal_code,
            $buyer['city'] ?? $customer?->city,
        ]);
        $vatRegistered = $vatGroups->sum('vat_cents') > 0
            || (bool) data_get($brreg, 'registered_in_vat_register', false)
            || (bool) data_get($brreg, 'registrertIMvaregisteret', false);
        $orgNumber = preg_replace('/\D/', '', (string) $organization?->organization_number);

        return [
            'number' => 'SK-'.str_pad((string) $payment->id, 9, '0', STR_PAD_LEFT),
            'seller_name' => $organization?->name ?: 'DekkPilot',
            'seller_org_number' => $orgNumber,
            'seller_vat_registered' => $vatRegistered,
            'seller_address' => $sellerAddress,
            'seller_phone' => $branch?->phone ?: $organization?->phone,
            'seller_email' => $branch?->email ?: $organization?->email,
            'buyer_name' => $buyer['name'] ?? $customer?->name,
            'buyer_org_number' => preg_replace('/\D/', '', (string) ($buyer['organization_number'] ?? $customer?->organization_number)),
            'buyer_address' => $buyerAddress,
            'sale_at' => $payment->paid_at,
            'delivery_at' => $booking?->ends_at ?: $payment->paid_at,
            'delivery_place' => trim(implode(', ', array_filter([$branch?->name, $sellerAddress]))),
            'booking_reference' => $booking?->reference,
            'registration_number' => $booking?->vehicle?->registration_number,
            'payment_method' => match ($payment->payment_method) {
                'vipps' => 'Vipps', 'cash' => 'Kontant', 'terminal' => 'Bankterminal', default => ucfirst((string) $payment->payment_method),
            },
            'transaction_reference' => $payment->provider_reference ?: $payment->terminal_reference,
            'currency' => $payment->currency ?: 'NOK',
            'lines' => $lines, 'vat_groups' => $vatGroups,
            'net_cents' => (int) $lines->sum('net_cents'),
            'vat_cents' => (int) $lines->sum('vat_cents'),
            'total_cents' => (int) $payment->amount_cents,
        ];
    }

    public function plainText(CheckoutPayment $payment): string
    {
        $receipt = $this->data($payment);
        $money = fn (int $cents) => number_format($cents / 100, 2, ',', ' ').' '.$receipt['currency'];
        $text = "ELEKTRONISK SALGSKVITTERING\n{$receipt['number']}\n\n{$receipt['seller_name']}";
        if ($receipt['seller_org_number']) $text .= "\nOrg.nr. {$receipt['seller_org_number']}".($receipt['seller_vat_registered'] ? ' MVA' : '');
        if ($receipt['seller_address']) $text .= "\n{$receipt['seller_address']}";
        $text .= "\n\nKjøper: {$receipt['buyer_name']}";
        if ($receipt['buyer_org_number']) $text .= "\nKjøpers org.nr.: {$receipt['buyer_org_number']}";
        if ($receipt['buyer_address']) $text .= "\n{$receipt['buyer_address']}";
        $text .= "\n\nSalgstidspunkt: ".$receipt['sale_at']?->format('d.m.Y H:i');
        $text .= "\nLevering: ".$receipt['delivery_at']?->format('d.m.Y H:i').' · '.($receipt['delivery_place'] ?: 'Verkstedet');
        $text .= "\nOrdre: {$receipt['booking_reference']} · Reg.nr.: ".($receipt['registration_number'] ?: '—')."\n";
        foreach ($receipt['lines'] as $line) $text .= "\n{$line['description']} · ".rtrim(rtrim(number_format($line['quantity'], 2, '.', ''), '0'), '.')." stk · ".$money($line['gross_cents'])." (inkl. {$line['vat_rate']} % MVA)";
        $text .= "\n\nNetto: ".$money($receipt['net_cents'])."\nMVA: ".$money($receipt['vat_cents'])."\nBETALT: ".$money($receipt['total_cents']);
        $text .= "\nBetalingsmåte: {$receipt['payment_method']}\nTransaksjon: {$receipt['transaction_reference']}";
        return $text;
    }

    private function address(array $parts): string
    {
        $street = trim((string) ($parts[0] ?? ''));
        $place = trim(implode(' ', array_filter([trim((string) ($parts[1] ?? '')), trim((string) ($parts[2] ?? ''))])));
        return trim(implode(', ', array_filter([$street, $place])));
    }
}
