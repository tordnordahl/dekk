<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class WorkOrder extends Model { protected $guarded=[]; protected $casts=['started_at'=>'datetime','completed_at'=>'datetime','customer_signed_at'=>'datetime']; public function customer():BelongsTo{return $this->belongsTo(Customer::class);} public function vehicle():BelongsTo{return $this->belongsTo(Vehicle::class);} public function booking():BelongsTo{return $this->belongsTo(Booking::class);} public function quote():BelongsTo{return $this->belongsTo(Quote::class);} public function assignedUser():BelongsTo{return $this->belongsTo(User::class,'assigned_user_id');} public function tasks():HasMany{return $this->hasMany(WorkOrderTask::class)->orderBy('position');} public function reservations():HasMany{return $this->hasMany(StockReservation::class);} }
