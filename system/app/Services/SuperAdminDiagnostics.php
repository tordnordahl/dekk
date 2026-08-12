<?php
namespace App\Services;
use Illuminate\Support\Facades\File;
class SuperAdminDiagnostics
{
 public function enabled():bool{return is_file($this->flag());}
 public function set(bool$enabled):void{if($enabled){File::ensureDirectoryExists(dirname($this->flag()),0700);file_put_contents($this->flag(),now()->toIso8601String(),LOCK_EX);@chmod($this->flag(),0600);}elseif(is_file($this->flag()))unlink($this->flag());}
 public function latest(int$maxBytes=160000):string{$files=glob(storage_path('logs/*.log'))?:[];usort($files,fn($a,$b)=>filemtime($b)<=>filemtime($a));$file=$files[0]??null;if(!$file||!is_readable($file))return'Ingen lesbar loggfil ble funnet.';$size=filesize($file);$handle=fopen($file,'rb');if(!$handle)return'Loggfilen kunne ikke åpnes.';if($size>$maxBytes)fseek($handle,-$maxBytes,SEEK_END);$text=stream_get_contents($handle)?:'';fclose($handle);$text=preg_replace('/(password|passwd|secret|token|api[_-]?key)(["\'\s:=]+)([^\s,"\']+)/i','$1$2[SKJULT]',$text);$text=preg_replace('/(mysql|pgsql):\/\/[^\s@]+@/i','$1://[SKJULT]@',$text);return mb_substr($text,-$maxBytes);}
 public function status():array{$files=glob(storage_path('logs/*.log'))?:[];$bytes=0;$modified=null;foreach($files as$file){$bytes+=is_file($file)?(int)filesize($file):0;$time=is_file($file)?filemtime($file):false;if($time!==false&&($modified===null||$time>$modified))$modified=$time;}return['files'=>count($files),'bytes'=>$bytes,'modified_at'=>$modified?now()->setTimestamp($modified):null];}
 public function clear():array{$directory=realpath(storage_path('logs'));if($directory===false)return['files'=>0,'bytes'=>0];$files=glob($directory.'/*.log')?:[];$cleared=0;$bytes=0;foreach($files as$file){$real=realpath($file);if($real===false||dirname($real)!==$directory||!is_file($real)||!is_writable($real))continue;$bytes+=(int)filesize($real);if(file_put_contents($real,'',LOCK_EX)!==false)$cleared++;}clearstatcache();return['files'=>$cleared,'bytes'=>$bytes];}
 private function flag():string{return storage_path('app/diagnostics.enabled');}
}
