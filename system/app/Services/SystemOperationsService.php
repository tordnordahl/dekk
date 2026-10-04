<?php
namespace App\Services;
use App\Models\PlatformSetting;use Illuminate\Support\Facades\Crypt;use Throwable;
class SystemOperationsService{
 public function get(string$key,array$default=[]):array{$row=PlatformSetting::where('key',$key)->first();if(!$row)return$default;try{return json_decode(Crypt::decryptString($row->encrypted_value),true,512,JSON_THROW_ON_ERROR);}catch(Throwable){return$default;}}
 public function put(string$key,array$value,?int$userId=null):void{PlatformSetting::updateOrCreate(['key'=>$key],['encrypted_value'=>Crypt::encryptString(json_encode($value,JSON_THROW_ON_ERROR)),'updated_by'=>$userId]);}
 public function status():array{$health=$this->get('system.health');$cron=$this->get('system.cron');$last=!empty($cron['last_run_at'])?now()->parse($cron['last_run_at']):null;return['health'=>$health,'cron'=>$cron,'cron_ok'=>$last&&$last->gt(now()->subMinutes(25))&&($cron['last_exit_code']??null)===0,'last_run'=>$last];}
}
