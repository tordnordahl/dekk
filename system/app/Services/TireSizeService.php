<?php
namespace App\Services;
class TireSizeService{
 public function key(?string$value):string{return strtoupper(preg_replace('/[^A-Z0-9]/i','',(string)$value));}
 public function numericKey(?string$value):string{return preg_replace('/\D/','',(string)$value);}
 public function format(?string$value):string{$raw=strtoupper(trim((string)$value));$digits=$this->numericKey($raw);if(preg_match('/^(\d{3})(\d{2})(\d{2})$/',$digits,$m))return$m[1].'/'.$m[2].' R'.$m[3];return preg_replace('/\s+/',' ',$raw);}
}
