<?php
namespace App\Models;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\SoftDeletes;use Illuminate\Database\Eloquent\Relations\BelongsToMany;
class ServiceProduct extends Model {use SoftDeletes;protected $guarded=[];protected $casts=['active'=>'boolean','is_favorite'=>'boolean','vat_rate'=>'decimal:2'];public function bookings():BelongsToMany{return $this->belongsToMany(Booking::class,'booking_service_product')->withTimestamps();}}
