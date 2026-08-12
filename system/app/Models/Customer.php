<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Customer extends Model
{
    use SoftDeletes;
    protected $guarded = [];
    protected $casts = ['marketing_consent' => 'boolean'];
    public function vehicles(): HasMany { return $this->hasMany(Vehicle::class); }
    public function bookings(): HasMany { return $this->hasMany(Booking::class); }
    public function quotes(): HasMany { return $this->hasMany(Quote::class); }
    public function workOrders(): HasMany { return $this->hasMany(WorkOrder::class); }
    public function conversations(): HasMany { return $this->hasMany(ConversationMessage::class)->latest(); }
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
}
