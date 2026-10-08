<?php
namespace App\Services;

use App\Models\TireProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TireBrandCatalog
{
    public const DEFAULTS = ['Nokian','Continental','Michelin','Goodyear','Bridgestone','Pirelli','Dunlop','Hankook','Falken','Yokohama','Toyo','Vredestein','Kumho','Nexen','BFGoodrich','Cooper','General Tire','Gislaved','Maxxis','Uniroyal'];

    public function names(int $organizationId): Collection
    {
        return collect(self::DEFAULTS)
            ->merge(DB::table('tire_brands')->where('organization_id',$organizationId)->pluck('name'))
            ->merge(TireProduct::where('organization_id',$organizationId)->distinct()->pluck('brand'))
            ->filter(fn($name)=>filled($name))
            ->unique(fn($name)=>mb_strtolower(trim($name)))
            ->sortBy(fn($name)=>mb_strtolower($name))->values();
    }
}
