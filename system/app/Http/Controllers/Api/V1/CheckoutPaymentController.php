<?php
namespace App\Http\Controllers\Api\V1;
use App\Http\Controllers\Controller;use App\Models\CheckoutPayment;use App\Services\CheckoutPaymentService;use Illuminate\Http\JsonResponse;use Illuminate\Http\Request;
class CheckoutPaymentController extends Controller{
 public function pending(Request $request):JsonResponse{$items=CheckoutPayment::with(['booking.vehicle','booking.customer'])->where('organization_id',$request->user()->organization_id)->whereIn('status',['pending','processing'])->where('expires_at','>',now())->latest()->limit(50)->get()->map(fn($p)=>['id'=>$p->public_id,'reference'=>$p->terminal_reference,'amount_cents'=>$p->amount_cents,'currency'=>$p->currency,'registration_number'=>$p->booking?->vehicle?->registration_number,'service'=>$p->booking?->service_name,'expires_at'=>$p->expires_at?->toIso8601String()]);return response()->json(['data'=>$items]);}
 public function processing(Request $request,CheckoutPayment $payment):JsonResponse{$this->tenant($request,$payment);$payment->update(['status'=>'processing']);return response()->json(['data'=>['id'=>$payment->public_id,'status'=>'processing']]);}
 public function complete(Request $request,CheckoutPayment $payment,CheckoutPaymentService $service):JsonResponse{$this->tenant($request,$payment);$data=$request->validate(['provider_reference'=>['required','string','max:255']]);$service->complete($payment,'terminal',$data['provider_reference'],'APPROVED');return response()->json(['data'=>['id'=>$payment->public_id,'status'=>'paid']]);}
 private function tenant(Request $request,CheckoutPayment $payment):void{abort_unless($payment->organization_id===$request->user()->organization_id,404);}
}
