<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vehicle extends Model
{
    use SoftDeletes;
    protected $guarded = [];
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function tireSets(): HasMany { return $this->hasMany(TireSet::class); }
    public function bookings(): HasMany { return $this->hasMany(Booking::class); }
}
