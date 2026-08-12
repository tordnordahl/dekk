<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class VehicleOwnershipPeriod extends Model{
 protected $guarded=[];protected $casts=['started_at'=>'datetime','ended_at'=>'datetime'];
 public function vehicle():BelongsTo{return $this->belongsTo(Vehicle::class);}
 public function customer():BelongsTo{return $this->belongsTo(Customer::class);}
}
