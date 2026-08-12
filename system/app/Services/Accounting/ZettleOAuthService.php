<?php
namespace App\Services\Accounting;
use App\Models\IntegrationSetting;use Illuminate\Support\Facades\Crypt;use Illuminate\Support\Facades\Http;use RuntimeException;
class ZettleOAuthService{
 public function __construct(private readonly AccountingPlatformSettings $settings){}
 public function configured():bool{$s=$this->settings->zettle();return filled($s['client_id']??null)&&filled($s['client_secret']??null);}
 public function clientId():string{return(string)($this->settings->zettle()['client_id']??'');}
 public function exchange(string $code):array{return $this->token(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>route('admin.accounting.zettle.callback')]);}
 public function refresh(IntegrationSetting $setting,array $c):array{if(!empty($c['expires_at'])&&now()->addMinutes(5)->lt($c['expires_at']))return$c;if(blank($c['refresh_token']??null))return$c;$t=$this->token(['grant_type'=>'refresh_token','refresh_token'=>$c['refresh_token']]);$c=array_merge($c,['api_key'=>$t['access_token'],'refresh_token'=>$t['refresh_token']??$c['refresh_token'],'expires_at'=>now()->addSeconds((int)($t['expires_in']??7200))->toIso8601String()]);$setting->update(['encrypted_credentials'=>Crypt::encryptString(json_encode($c,JSON_THROW_ON_ERROR))]);return$c;}
 private function token(array $form):array{$s=$this->settings->zettle();if(!$this->configured())throw new RuntimeException('Zettle Client ID og Client Secret mangler.');$r=Http::asForm()->timeout(25)->post('https://oauth.zettle.com/token',$form+['client_id'=>$s['client_id'],'client_secret'=>$s['client_secret']]);if(!$r->successful())throw new RuntimeException('Zettle-innlogging feilet (HTTP '.$r->status().'): '.mb_substr($r->body(),0,500));return$r->json();}
}
