<?php
namespace App\Services;
use Illuminate\Support\Facades\File;
class SuperAdminDiagnostics
{
 public function enabled():bool{return is_file($this->flag());}
 public function set(bool$enabled):void{if($enabled){File::ensureDirectoryExists(dirname($this->flag()),0700);file_put_contents($this->flag(),now()->toIso8601String(),LOCK_EX);@chmod($this->flag(),0600);}elseif(is_file($this->flag()))unlink($this->flag());}
 public function latest(int$maxBytes=160000):string{$files=glob(storage_path('logs/*.log'))?:[];usort($files,fn($a,$b)=>filemtime($b)<=>filemtime($a));$file=$files[0]??null;if(!$file||!is_readable($file))return'Ingen lesbar loggfil ble funnet.';$size=filesize($file);$handle=fopen($file,'rb');if(!$handle)return'Loggfilen kunne ikke åpnes.';if($size>$maxBytes)fseek($handle,-$maxBytes,SEEK_END);$text=stream_get_contents($handle)?:'';fclose($handle);$text=preg_replace('/(password|passwd|secret|token|api[_-]?key)(["\'\s:=]+)([^\s,"\']+)/i','$1$2[SKJULT]',$text);$text=preg_replace('/(mysql|pgsql):\/\/[^\s@]+@/i','$1://[SKJULT]@',$text);return mb_substr($text,-$maxBytes);}
 private function flag():string{return storage_path('app/diagnostics.enabled');}
}
