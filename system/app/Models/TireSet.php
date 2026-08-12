<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TireSet extends Model
{
    use SoftDeletes;
    protected $guarded = [];
    protected $casts = ['received_at' => 'datetime', 'delivered_at' => 'datetime', 'last_counted_at' => 'datetime', 'label_printed_at' => 'datetime', 'minimum_tread_depth' => 'decimal:1', 'washed' => 'boolean', 'bagged' => 'boolean'];
    public function vehicle(): BelongsTo { return $this->belongsTo(Vehicle::class); }
    public function storageLocation(): BelongsTo { return $this->belongsTo(StorageLocation::class); }
    public function inspections(): HasMany { return $this->hasMany(TireInspection::class)->latest('inspected_at'); }
    public function movements(): HasMany { return $this->hasMany(StorageLocationMovement::class)->with(['fromLocation','toLocation'])->latest('moved_at'); }
}
