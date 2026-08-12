<?php

namespace App\Services;

use App\Models\Quote;
use App\Models\StockReservation;
use App\Models\WorkOrder;
use Illuminate\Support\Str;

class AcceptedQuoteWorkflow
{
    public function create(Quote $quote): WorkOrder
    {
        $quote->loadMissing(['items', 'customer', 'vehicle']);
        $selected = $quote->items->firstWhere('id', $quote->selected_quote_item_id) ?? $quote->items->first();
        $order = WorkOrder::firstOrCreate(['quote_id' => $quote->id], [
            'public_id' => (string) Str::uuid(), 'organization_id' => $quote->organization_id,
            'branch_id' => $quote->branch_id, 'customer_id' => $quote->customer_id,
            'vehicle_id' => $quote->vehicle_id, 'reference' => 'AO-'.now()->format('ymd').'-'.strtoupper(Str::random(5)),
            'status' => 'ready', 'notes' => 'Opprettet automatisk fra tilbud '.$quote->reference,
        ]);
        foreach (['Kontroller bil og arbeidsordre', 'Monter valgte dekk', 'Kontroller TPMS og lufttrykk', 'Momentkontroll', 'Sluttkontroll og dokumentasjon'] as $position => $name) {
            $order->tasks()->firstOrCreate(['name' => $name], ['required' => true, 'position' => $position + 1]);
        }
        if ($selected?->tire_product_id) StockReservation::firstOrCreate(
            ['quote_id' => $quote->id, 'tire_product_id' => $selected->tire_product_id],
            ['organization_id' => $quote->organization_id, 'work_order_id' => $order->id, 'quantity' => $selected->quantity, 'status' => 'reserved', 'expires_at' => now()->addDays(30)]
        );
        return $order;
    }
}
