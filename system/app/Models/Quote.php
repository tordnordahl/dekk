<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Quote extends Model {
    protected $guarded = [];
    protected $hidden = ['access_token_hash'];
    protected $casts = ['sent_at'=>'datetime','viewed_at'=>'datetime','responded_at'=>'datetime','expires_at'=>'datetime','purchase_terms_accepted_at'=>'datetime'];
    public function organization(): BelongsTo { return $this->belongsTo(Organization::class); }
    public function customer(): BelongsTo { return $this->belongsTo(Customer::class); }
    public function vehicle(): BelongsTo { return $this->belongsTo(Vehicle::class); }
    public function sourceTireSet(): BelongsTo { return $this->belongsTo(TireSet::class, 'source_tire_set_id'); }
    public function items(): HasMany { return $this->hasMany(QuoteItem::class); }
}
