<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WheelMeasurement extends Model { protected $guarded=[]; protected $casts=['tread_depth_mm'=>'decimal:1','tire_damage'=>'boolean','rim_damage'=>'boolean','uneven_wear'=>'boolean']; }
