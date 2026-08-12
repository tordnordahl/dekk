<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageLocationMovement extends Model
{
    protected $guarded = [];
    protected $casts = ['moved_at'=>'datetime'];
    public function fromLocation(): BelongsTo{return $this->belongsTo(StorageLocation::class,'from_location_id');}
    public function toLocation(): BelongsTo{return $this->belongsTo(StorageLocation::class,'to_location_id');}
}
