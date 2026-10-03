<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class TireSet extends Model
{
    use SoftDeletes;
    protected $guarded = [];
    protected $casts = ['received_at' => 'datetime', 'delivered_at' => 'datetime', 'last_counted_at' => 'datetime', 'label_printed_at' => 'datetime', 'minimum_tread_depth' => 'decimal:1', 'washed' => 'boolean', 'bagged' => 'boolean'];
    public function vehicle(): BelongsTo { return $this->belongsTo(Vehicle::class); }
    public function storageLocation(): BelongsTo { return $this->belongsTo(StorageLocation::class); }
    public function inspections(): HasMany { return $this->hasMany(TireInspection::class)->latest('inspected_at'); }
    public function movements(): HasMany { return $this->hasMany(StorageLocationMovement::class)->with(['fromLocation','toLocation'])->latest('moved_at'); }

    public function scopeNeedsReplacement(Builder $query): Builder
    {
        return $query->where(function (Builder $query) {
            $query->where('minimum_tread_depth', '<', 3)
                ->orWhere('dot_year', '<=', now()->year - 10);
        });
    }

    public function getAgeYearsAttribute(): ?int
    {
        return $this->dot_year ? max(0, now()->year - (int) $this->dot_year) : null;
    }

    public function getStorageLabelAttribute(): string
    {
        if (!$this->storage_location_id) return 'Ikke plassert';
        $parts = [$this->storageLocation?->code ?? 'Ukjent rad/reol'];
        $parts[] = $this->storage_position_number ? 'Lengde '.$this->storage_position_number : 'Lengde ikke angitt';
        if ($this->storage_shelf_number) $parts[] = 'Høyde '.$this->storage_shelf_number;
        return implode(' · ', $parts);
    }

    public function getAgeAssessmentAttribute(): ?string
    {
        if ($this->age_years === null) return null;
        if ($this->age_years >= 10) return 'replace';
        if ($this->age_years >= 5) return 'inspect';
        return 'normal';
    }

    public function getReplacementReasonsAttribute(): array
    {
        $reasons = [];
        if ($this->minimum_tread_depth !== null && (float) $this->minimum_tread_depth < 3) $reasons[] = 'low_tread';
        if ($this->age_assessment === 'replace') $reasons[] = 'age';
        return $reasons;
    }
}
