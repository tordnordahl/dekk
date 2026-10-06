<?php

namespace App\Http\Controllers;

use App\Models\StorageLocation;
use App\Models\TireSet;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Illuminate\Support\Facades\DB;

class WarehouseController extends Controller
{
    public function admin(Request $request): View
    {
        $locations = $this->locations($request);
        $this->attachShelfCounts($locations);
        return view('admin.warehouse', ['locations' => $locations, 'stats' => $this->stats($request, $locations)]);
    }

    public function map(Request $request): View
    {
        $locations = $this->locations($request)->where('active', true)->values();
        $this->attachShelfCounts($locations);
        $search = trim((string) $request->query('q'));
        $matches = collect();
        if ($search !== '') {
            $matches = TireSet::with(['vehicle.customer','storageLocation'])->where('organization_id', $request->user()->organization_id)
                ->where(fn ($query) => $query->where('code', 'like', "%{$search}%")
                    ->orWhereHas('vehicle', fn ($vehicle) => $vehicle->where('registration_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', "%{$search}%"))))
                ->whereNotIn('status', ['delivered'])->limit(50)->get();
        }
        return view('warehouse.map', ['locations' => $locations, 'matches' => $matches, 'search' => $search, 'stats' => $this->stats($request, $locations), 'highlightedLocationIds' => $matches->pluck('storage_location_id')->filter()->unique()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request); $data['capacity'] = $data['shelf_count'] * $data['sets_per_shelf'];
        $index = StorageLocation::where('organization_id', $request->user()->organization_id)->where('branch_id', $request->user()->branch_id)->count();
        $data['map_x'] = ($index % 5) * 19 + 3;
        $data['map_y'] = min(84, intdiv($index, 5) * 18 + 5);
        StorageLocation::create([...$data, 'public_id' => (string) Str::uuid(), 'organization_id' => $request->user()->organization_id, 'branch_id' => $request->user()->branch_id, 'active' => true]);
        return back()->with('success', 'Lagerplassen er lagt til på kartet.');
    }

    public function update(Request $request, StorageLocation $location): RedirectResponse
    {
        $this->owns($request, $location);
        abort_if($location->archived_at,404);
        $data = $this->validated($request, $location); $data['capacity'] = $data['shelf_count'] * $data['sets_per_shelf'];
        DB::transaction(function () use ($location, $data) {
            $location = StorageLocation::whereKey($location->id)->lockForUpdate()->firstOrFail();
            $sets = $location->tireSets()->whereNotIn('status', ['delivered'])->lockForUpdate()->get();
            $outside = $sets->contains(fn ($set) => $set->storage_shelf_number > $data['shelf_count'] || $set->storage_position_number > $data['sets_per_shelf']);
            $fullHeight = $sets->whereNotNull('storage_shelf_number')->groupBy('storage_shelf_number')->contains(fn ($items) => $items->count() > $data['sets_per_shelf']);
            if ($sets->count() > $data['capacity'] || $outside || $fullHeight) {
                throw \Illuminate\Validation\ValidationException::withMessages(['location' => 'Flytt hjulsett som står utenfor de nye målene før du reduserer lengde eller høyde.']);
            }
            $location->update($data);
        });
        return back()->with('success', $location->code.' er oppdatert.');
    }

    public function toggle(Request $request, StorageLocation $location): RedirectResponse
    {
        $this->owns($request, $location);
        abort_if($location->archived_at,404);
        if ($location->active && $location->tireSets()->whereNotIn('status', ['delivered'])->exists()) return back()->withErrors(['location' => 'Flytt hjulsettene ut av '.$location->code.' før plassen deaktiveres.']);
        $location->update(['active' => ! $location->active]);
        return back()->with('success', $location->code.' er '.($location->active ? 'aktivert' : 'deaktivert').'.');
    }

    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $organizationId = $request->user()->organization_id;
        $branchId = $request->user()->branch_id;
        $activeIds = StorageLocation::where('organization_id', $organizationId)
            ->where('branch_id', $branchId)->where('active', true)->pluck('id');
        abort_unless($activeIds->count() === count($data['ids'])
            && $activeIds->diff($data['ids'])->isEmpty(), 422, 'Reollisten er endret. Last siden på nytt og prøv igjen.');

        DB::transaction(function () use ($data, $request, $organizationId, $branchId): void {
            foreach ($data['ids'] as $index => $id) {
                StorageLocation::where('id', $id)->where('organization_id', $organizationId)
                    ->where('branch_id', $branchId)->where('active', true)
                    ->update(['pick_order' => ($index + 1) * 10]);
            }
            DB::table('audit_logs')->insert([
                'organization_id' => $organizationId, 'user_id' => $request->user()->id,
                'action' => 'warehouse.locations.reordered', 'ip_address' => $request->ip(),
                'metadata' => json_encode(['count' => count($data['ids'])]), 'created_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Rekkefølgen er lagret.']);
    }

    public function move(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'map_x' => ['required', 'numeric', 'between:0,92'],
            'map_y' => ['required', 'numeric', 'between:0,88'],
        ]);
        $location = StorageLocation::where('id', $data['id'])
            ->where('organization_id', $request->user()->organization_id)
            ->where('branch_id', $request->user()->branch_id)->where('active', true)->firstOrFail();
        $location->update(['map_x' => (int) round($data['map_x']), 'map_y' => (int) round($data['map_y'])]);

        return response()->json(['message' => $location->code.' er flyttet.', 'position' => [
            'x' => $location->map_x, 'y' => $location->map_y,
        ]]);
    }

    private function validated(Request $request, ?StorageLocation $location = null): array
    {
        $branch = $request->user()->branch_id;
        return $request->validate([
            'code' => ['required','string','max:30',Rule::unique('storage_locations')->where('branch_id', $branch)->ignore($location?->id)],
            'label' => ['nullable','string','max:100'], 'zone' => ['required','string','max:50'],
            'aisle' => ['nullable','string','max:30'], 'rack' => ['nullable','string','max:30'],
            'location_type' => ['required',Rule::in(['rack','floor','receiving','staging','workshop'])],
            'shelf_count' => ['required','integer','between:1,20'], 'sets_per_shelf' => ['required','integer','between:1,50'], 'pick_order' => ['nullable','integer','between:0,9999'],
        ]);
    }

    private function locations(Request $request)
    {
        return StorageLocation::withCount(['tireSets' => fn ($query) => $query->whereNotIn('status', ['delivered'])])->where('organization_id', $request->user()->organization_id)->where('branch_id', $request->user()->branch_id)->whereNull('archived_at')->orderBy('pick_order')->orderBy('code')->get();
    }

    private function stats(Request $request, $locations): array
    {
        return ['locations' => $locations->where('active', true)->count(), 'capacity' => $locations->where('active', true)->sum('capacity'), 'occupied' => $locations->where('active', true)->sum('tire_sets_count'), 'unplaced' => TireSet::where('organization_id', $request->user()->organization_id)->whereNull('storage_location_id')->whereNotIn('status', ['delivered'])->count()];
    }

    private function attachShelfCounts($locations): void
    {
        if ($locations->isEmpty()) return;
        $counts = DB::table('tire_sets')->whereNull('deleted_at')->selectRaw('storage_location_id, storage_shelf_number, count(*) total')->whereIn('storage_location_id', $locations->pluck('id'))->whereNotIn('status', ['delivered'])->groupBy('storage_location_id', 'storage_shelf_number')->get()->groupBy('storage_location_id');
        $locations->each(fn ($location) => $location->setAttribute('shelf_counts', ($counts[$location->id] ?? collect())->pluck('total', 'storage_shelf_number')));
    }

    private function owns(Request $request, StorageLocation $location): void
    {
        abort_unless($location->organization_id === $request->user()->organization_id && $location->branch_id === $request->user()->branch_id, 404);
    }
}
