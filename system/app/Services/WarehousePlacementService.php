<?php

namespace App\Services;

use App\Models\StorageLocation;

class WarehousePlacementService
{
    public function nextShelf(StorageLocation $location): ?int
    {
        $counts = $location->tireSets()->whereNotIn('status', ['delivered'])->whereNotNull('storage_shelf_number')->selectRaw('storage_shelf_number, count(*) total')->groupBy('storage_shelf_number')->pluck('total', 'storage_shelf_number');
        for ($shelf = 1; $shelf <= max(1, (int) $location->shelf_count); $shelf++) {
            if ((int) ($counts[$shelf] ?? 0) < max(1, (int) $location->sets_per_shelf)) return $shelf;
        }
        return null;
    }
}
