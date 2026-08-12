<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageLocation extends Model
{
    protected $guarded = [];
    protected $casts = ['active' => 'boolean'];
    public function tireSets(): HasMany { return $this->hasMany(TireSet::class); }
}
