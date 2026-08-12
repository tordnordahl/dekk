<?php
namespace App\Http\Controllers;
use App\Models\User;use App\Services\TestDataGuard;use App\Services\MailConfigurationService;use Illuminate\Auth\Events\PasswordReset;use Illuminate\Http\RedirectResponse;use Illuminate\Http\Request;use Illuminate\Support\Facades\Hash;use Illuminate\Support\Facades\Password;use Illuminate\Support\Str;use Illuminate\Validation\Rules\Password as PasswordRule;use Illuminate\View\View;
class PasswordResetController extends Controller{
 public function requestForm():View{return view('auth.forgot-password');}
 public function send(Request$request,TestDataGuard$guard,MailConfigurationService$mailConfiguration):RedirectResponse{$data=$request->validate(['email'=>['required','email','max:255']]);$email=strtolower($data['email']);if(!$guard->email($email)){$mailConfiguration->configure();Password::sendResetLink(['email'=>$email]);}return back()->with('status','Hvis adressen finnes, sender vi en sikker tilbakestillingslenke. Kontroller også søppelpost.');}
 public function resetForm(Request$request,string$token):View{return view('auth.reset-password',['token'=>$token,'email'=>(string)$request->query('email')]);}
 public function reset(Request$request):RedirectResponse{$data=$request->validate(['token'=>['required'],'email'=>['required','email'],'password'=>['required','confirmed',PasswordRule::min(12)->mixedCase()->numbers()]]);$status=Password::reset($data,function(User$user,string$password){$user->forceFill(['password'=>Hash::make($password),'remember_token'=>Str::random(60)])->save();event(new PasswordReset($user));});return $status===Password::PASSWORD_RESET?redirect()->route('login')->with('status','Passordet er oppdatert. Du kan logge inn nå.'):back()->withErrors(['email'=>'Lenken er ugyldig eller utløpt. Be om en ny lenke.']);}
}
