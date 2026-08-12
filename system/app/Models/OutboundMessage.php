<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OutboundMessage extends Model{protected $guarded=[];protected $hidden=['delivery_token_hash'];protected $casts=['scheduled_at'=>'datetime','sent_at'=>'datetime','failed_at'=>'datetime','delivered_at'=>'datetime','bounced_at'=>'datetime','provider_metadata'=>'array'];public function customer(){return $this->belongsTo(Customer::class);}}
