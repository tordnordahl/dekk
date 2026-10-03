<?php

namespace App\Services;

use App\Models\StorageLocation;
use App\Models\TireSet;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WarehousePlacementService
{
    public static function rules(): array
    {
        return [
            'storage_shelf_number' => ['nullable', 'integer', 'between:1,20', 'required_with:storage_position_number'],
            'storage_position_number' => ['nullable', 'integer', 'between:1,50', 'required_with:storage_shelf_number'],
        ];
    }

    /** Call inside the transaction that saves the tire set, so the rack stays locked. */
    public function coordinates(?StorageLocation $location, ?TireSet $set = null, array $input = []): array
    {
        $height = $input['storage_shelf_number'] ?? null;
        $length = $input['storage_position_number'] ?? null;
        if (!$location) {
            if ($height || $length) $this->invalid('Velg rad/reol før du velger lengde og høyde.');
            return ['storage_shelf_number' => null, 'storage_position_number' => null];
        }

        if (DB::transactionLevel() === 0) throw new \LogicException('Lagerplassering krever en transaksjon.');
        $location = StorageLocation::whereKey($location->id)->lockForUpdate()->firstOrFail();
        if (!$location->active) $this->invalid('Denne raden/reolen er deaktivert.');
        $occupants = $location->tireSets()->whereNotIn('status', ['delivered'])
            ->when($set, fn ($query) => $query->where('id', '!=', $set->id))
            ->lockForUpdate()->get(['id', 'storage_shelf_number', 'storage_position_number']);

        // Reserve capacity for legacy sets without inventing their physical length.
        $available = function (int $h, int $l) use ($location, $occupants): bool {
            $onShelf = $occupants->where('storage_shelf_number', $h);
            return $occupants->count() < $location->shelf_count * $location->sets_per_shelf
                && $onShelf->count() < $location->sets_per_shelf
                && !$onShelf->contains('storage_position_number', $l);
        };

        if ($height || $length) {
            if (!$height || !$length || $height < 1 || $height > $location->shelf_count || $length < 1 || $length > $location->sets_per_shelf) {
                $this->invalid('Velg lengde 1–'.$location->sets_per_shelf.' og høyde 1–'.$location->shelf_count.' på '.$location->code.'.');
            }
            if (!$available((int) $height, (int) $length)) $this->invalid('Den valgte plassen er opptatt, eller høyden er full. Velg en annen plass.');
            return ['storage_shelf_number' => (int) $height, 'storage_position_number' => (int) $length];
        }

        if ($set && (int) $set->storage_location_id === $location->id && $set->storage_shelf_number) {
            if (!$set->storage_position_number && $set->status !== 'delivered') {
                // Keep legacy height until staff record the actual length manually.
                return ['storage_shelf_number' => $set->storage_shelf_number, 'storage_position_number' => null];
            }
            $h = (int) $set->storage_shelf_number; $l = (int) $set->storage_position_number;
            if ($h >= 1 && $l >= 1 && $h <= $location->shelf_count && $l <= $location->sets_per_shelf && $available($h, $l)) {
                return ['storage_shelf_number' => $h, 'storage_position_number' => $l];
            }
        }
        for ($h = 1; $h <= $location->shelf_count; $h++) {
            for ($l = 1; $l <= $location->sets_per_shelf; $l++) {
                if ($available($h, $l)) return ['storage_shelf_number' => $h, 'storage_position_number' => $l];
            }
        }
        $this->invalid($location->code.' er full. Velg en annen rad/reol.');
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['storage_location_id' => $message]);
    }
}
