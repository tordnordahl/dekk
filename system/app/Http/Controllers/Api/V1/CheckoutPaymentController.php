<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CheckoutPayment;
use App\Services\CheckoutPaymentService;
use App\Services\MerchantStripeService;
use App\Services\MerchantPaymentSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutPaymentController extends Controller
{
    public function pending(Request $request): JsonResponse
    {
        $items=CheckoutPayment::with(['booking.vehicle','booking.customer'])->where('organization_id',$request->user()->organization_id)
            ->whereIn('status',['pending','processing'])->whereNull('stripe_checkout_key')
            ->where(fn($q)=>$q->whereNull('payment_method')->orWhere('payment_method','terminal'))
            ->where('expires_at','>',now())->latest()->limit(50)->get()->map(fn($p)=>[
                'id'=>$p->public_id,'reference'=>$p->terminal_reference,'amount_cents'=>$p->amount_cents,'currency'=>$p->currency,
                'registration_number'=>$p->booking?->vehicle?->registration_number,'service'=>$p->booking?->service_name,'expires_at'=>$p->expires_at?->toIso8601String(),
            ]);
        return response()->json(['data'=>$items]);
    }

    public function processing(Request $request,CheckoutPayment $payment,MerchantStripeService $stripe): JsonResponse
    {
        $this->tenant($request,$payment);
        abort_unless(app(MerchantPaymentSettings::class)->get($payment->organization_id,'terminal')['active']??false,409);
        return $stripe->locked($payment,function($payment) {
            abort_if($payment->stripe_checkout_key || !in_array($payment->payment_method,[null,'terminal'],true)
                || in_array($payment->status,['paid','expired'],true) || $payment->expires_at->isPast(),409);
            $payment->update(['status'=>'processing','payment_method'=>'terminal','provider'=>'terminal','provider_status'=>'WAITING_FOR_CARD',
                'provider_payload'=>['attempt_expires_at'=>now()->addSeconds(60)->toIso8601String()]]);
            return response()->json(['data'=>['id'=>$payment->public_id,'status'=>'processing','expires_in'=>60]]);
        });
    }

    public function complete(Request $request,CheckoutPayment $payment,CheckoutPaymentService $service,MerchantStripeService $stripe): JsonResponse
    {
        $this->tenant($request,$payment);
        $data=$request->validate(['provider_reference'=>['required','string','max:255']]);
        return $stripe->locked($payment,function($payment) use($service,$data) {
            $deadline=data_get($payment->provider_payload,'attempt_expires_at');
            if($payment->stripe_checkout_key || $payment->payment_method!=='terminal' || $payment->status!=='processing' || !$deadline || now()->gt(now()->parse($deadline))) {
                return response()->json(['message'=>'Ingen gyldig terminalbetaling pågår.'],409);
            }
            $service->complete($payment,'terminal',$data['provider_reference'],'APPROVED');
            return response()->json(['data'=>['id'=>$payment->public_id,'status'=>'paid']]);
        });
    }

    private function tenant(Request $request,CheckoutPayment $payment): void
    {
        abort_unless($payment->organization_id===$request->user()->organization_id,404);
    }
}
