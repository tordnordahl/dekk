<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelAgreement extends Model
{
    protected $guarded = [];
    protected $casts = ['starts_on'=>'date','renews_on'=>'date','ends_on'=>'date','charge_due_at'=>'date','billed_at'=>'datetime','auto_renew'=>'boolean','terms_accepted_at'=>'datetime'];
    public function customer(): BelongsTo{return $this->belongsTo(Customer::class);}
    public function vehicle(): BelongsTo{return $this->belongsTo(Vehicle::class);}
    public function tireSet(): BelongsTo{return $this->belongsTo(TireSet::class);}
}
