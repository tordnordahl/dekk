<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $positions = [];
        DB::table('storage_locations')->whereNull('map_x')->orderBy('branch_id')->orderBy('id')->get()->each(function ($location) use (&$positions) {
            $index = $positions[$location->branch_id] ?? 0;
            DB::table('storage_locations')->where('id', $location->id)->update([
                'location_type' => strtoupper($location->code) === 'MOTTAK' ? 'receiving' : 'rack',
                'map_x' => ($index % 4) * 23 + 3,
                'map_y' => min(88, intdiv($index, 4) * 20 + 5),
                'pick_order' => $index + 1,
            ]);
            $positions[$location->branch_id] = $index + 1;
        });
    }

    public function down(): void {}
};
