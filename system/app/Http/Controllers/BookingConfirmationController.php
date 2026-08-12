<?php
namespace App\Http\Controllers;
use App\Models\Booking;use App\Services\BookingWorkflowService;use Illuminate\Http\RedirectResponse;use Illuminate\Http\Request;use Illuminate\Support\Facades\DB;use Illuminate\View\View;
class BookingConfirmationController extends Controller {
 public function show(string $token):View{$b=$this->find($token);return view('bookings.confirm',['booking'=>$b]);}
 public function respond(Request $r,string $token,BookingWorkflowService $workflow):RedirectResponse{$d=$r->validate(['decision'=>['required','in:confirmed,declined']]);DB::transaction(function()use($token,$d,$r,$workflow){$b=Booking::where('confirmation_token_hash',hash('sha256',$token))->lockForUpdate()->firstOrFail();abort_unless($b->confirmation_status==='pending',409,'Bookingen er allerede besvart.');$b->update(['confirmation_status'=>$d['decision'],'confirmation_responded_at'=>now(),'status'=>$d['decision']==='declined'?'cancelled':'scheduled']);if($d['decision']==='confirmed')$workflow->createWorkOrder($b);DB::table('audit_logs')->insert(['organization_id'=>$b->organization_id,'action'=>'booking.'.$d['decision'],'subject_type'=>Booking::class,'subject_id'=>$b->id,'ip_address'=>$r->ip(),'metadata'=>json_encode(['reference'=>$b->reference]),'created_at'=>now()]);});return redirect()->route('booking.confirm.show',$token);}
 private function find(string $token):Booking{abort_unless(strlen($token)===64&&ctype_alnum($token),404);return Booking::with(['customer','vehicle'])->where('confirmation_token_hash',hash('sha256',$token))->firstOrFail();}
}
