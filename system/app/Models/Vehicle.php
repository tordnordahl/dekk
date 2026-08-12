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
    public function hotelAgreements(): HasMany { return $this->hasMany(HotelAgreement::class); }
    public function ownershipPeriods(): HasMany { return $this->hasMany(VehicleOwnershipPeriod::class)->orderBy('started_at'); }
}
