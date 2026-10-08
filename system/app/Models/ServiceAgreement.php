<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ServiceAgreement extends Model
{
 public $timestamps=false;
 protected $guarded=['id'];
 protected function casts(): array { return ['content'=>'array','published_at'=>'datetime']; }
 protected static function booted(): void {
  static::updating(fn()=>throw new \LogicException('Publiserte avtaler kan ikke endres. Publiser en ny versjon.'));
  static::deleting(fn()=>throw new \LogicException('Publiserte avtaler skal bevares som dokumentasjon.'));
 }
 public function getVersionAttribute(): string { return 'DP-'.$this->id; }
}
