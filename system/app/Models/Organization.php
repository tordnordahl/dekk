<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    protected $fillable = ['suspended_at','suspension_reason','stripe_free_month_count','stripe_latest_invoice','stripe_synced_at','public_id', 'name', 'organization_number', 'email', 'phone', 'timezone', 'locale', 'subscription_status', 'stripe_customer_id', 'stripe_subscription_id', 'subscription_ends_at','brreg_verified_at','brreg_data','billing_model','billing_discount_percent','billing_discount_code','billing_discount_ends_at','stripe_checkout_key','stripe_checkout_session_id','stripe_free_month_key','stripe_free_month_granted_at','stripe_free_month_applied_at','stripe_cancel_at_period_end'];
    protected $casts = ['suspended_at'=>'datetime','stripe_latest_invoice'=>'array','stripe_synced_at'=>'datetime','subscription_ends_at'=>'datetime','brreg_verified_at'=>'datetime','brreg_data'=>'array','billing_discount_ends_at'=>'date','stripe_free_month_granted_at'=>'datetime','stripe_free_month_applied_at'=>'datetime','stripe_cancel_at_period_end'=>'boolean'];
    public function hasSubscriptionAccess(): bool
    {
        if ($this->suspended_at) return false;
        if ($this->billing_model !== 'stripe') return in_array($this->subscription_status, ['active', 'trialing', 'past_due'], true);
        return filled($this->stripe_subscription_id)
            && in_array($this->subscription_status, ['active', 'trialing'], true)
            && $this->subscription_ends_at?->isFuture();
    }

    public function branches(): HasMany { return $this->hasMany(Branch::class); }
    public function users(): HasMany { return $this->hasMany(User::class); }
    public function customers(): HasMany { return $this->hasMany(Customer::class); }
    public function vehicles(): HasMany { return $this->hasMany(Vehicle::class); }
    public function bookings(): HasMany { return $this->hasMany(Booking::class); }
    public function tireSets(): HasMany { return $this->hasMany(TireSet::class); }
}
