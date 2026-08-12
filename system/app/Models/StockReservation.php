<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
class StockReservation extends Model { protected $guarded=[]; protected $casts=['expires_at'=>'datetime']; public function product():BelongsTo{return $this->belongsTo(TireProduct::class,'tire_product_id');} }
