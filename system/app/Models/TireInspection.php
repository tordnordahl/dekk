<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class TireInspection extends Model { protected $guarded=[]; protected $casts=['inspected_at'=>'datetime']; public function tireSet():BelongsTo{return $this->belongsTo(TireSet::class);} public function measurements():HasMany{return $this->hasMany(WheelMeasurement::class);} public function inspector():BelongsTo{return $this->belongsTo(User::class,'inspected_by');} }
