<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Booking extends Model
{
    protected $guarded = [];
    protected $casts = ['starts_at' => 'datetime', 'ends_at' => 'datetime', 'is_drop_in'=>'boolean', 'confirmation_requested_at'=>'datetime','confirmation_reminder_sent_at'=>'datetime','confirmation_deadline_at'=>'datetime','confirmation_responded_at'=>'datetime'];
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function vehicle(): BelongsTo { return $this->belongsTo(Vehicle::class); }
    public function assignedUser(): BelongsTo { return $this->belongsTo(User::class, 'assigned_user_id'); }
    public function workBay(): BelongsTo { return $this->belongsTo(WorkBay::class); }
    public function workOrder(): HasOne { return $this->hasOne(WorkOrder::class); }
    public function checkoutPayment(): HasOne { return $this->hasOne(CheckoutPayment::class); }
    public function services(): BelongsToMany { return $this->belongsToMany(ServiceProduct::class, 'booking_service_product')->withPivot(['service_name','price_cents','duration_minutes','position'])->orderByPivot('position')->withTimestamps(); }
}
