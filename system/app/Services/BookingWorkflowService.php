<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\WorkOrder;
use Illuminate\Support\Str;

class BookingWorkflowService
{
    public function createWorkOrder(Booking $booking): WorkOrder
    {
        $booking->loadMissing(['customer', 'services']);
        $order = WorkOrder::firstOrCreate(['booking_id' => $booking->id], [
            'public_id'=>(string)Str::uuid(),'organization_id'=>$booking->organization_id,'branch_id'=>$booking->branch_id,
            'customer_id'=>$booking->customer_id,'vehicle_id'=>$booking->vehicle_id,'assigned_user_id'=>$booking->assigned_user_id,
            'reference'=>'AO-'.now()->format('ymd').'-'.strtoupper(Str::random(5)),'status'=>'ready',
            'notes'=>'Opprettet automatisk fra booking '.$booking->reference,
        ]);
        foreach (['Kontroller booking og kjøretøy','Finn og skann hjulsett','Utfør '.$booking->service_name,'Kontroller moment, lufttrykk og TPMS','Sluttkontroll og dokumentasjon'] as $position=>$name) {
            $order->tasks()->firstOrCreate(['name'=>$name],['required'=>true,'position'=>$position+1]);
        }
        return $order;
    }
}
