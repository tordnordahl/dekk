<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = ['public_id', 'name', 'organization_number', 'email', 'phone', 'timezone', 'locale', 'subscription_status', 'stripe_customer_id', 'stripe_subscription_id', 'subscription_ends_at','brreg_verified_at','brreg_data','billing_model','billing_discount_percent','billing_discount_code','billing_discount_ends_at'];
    protected $casts = ['subscription_ends_at'=>'datetime','brreg_verified_at'=>'datetime','brreg_data'=>'array','billing_discount_ends_at'=>'date'];
    public function branches(): HasMany { return $this->hasMany(Branch::class); }
    public function users(): HasMany { return $this->hasMany(User::class); }
    public function customers(): HasMany { return $this->hasMany(Customer::class); }
    public function vehicles(): HasMany { return $this->hasMany(Vehicle::class); }
    public function bookings(): HasMany { return $this->hasMany(Booking::class); }
    public function tireSets(): HasMany { return $this->hasMany(TireSet::class); }
}
