<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Organization;
use App\Models\OutboundMessage;
use App\Models\TireProduct;
use App\Services\DemoAccessService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ResetDemoEnvironment extends Command
{
    protected $signature = 'demo:reset {--sets=600}';
    protected $description = 'Nullstill og bygg opp den isolerte demovirksomheten på nytt';

    public function handle(DemoAccessService $demo): int
    {
        $sets = max(50, min(2000, (int) $this->option('sets')));
        try {
            $summary = DB::transaction(function () use ($sets, $demo) {
                $old = Organization::where('organization_number', 'DEMO-DEKKPILOT')->lockForUpdate()->first();
                if ($old) {
                    if ($old->users()->where('is_super_admin', true)->exists()) {
                        throw new RuntimeException('Demonullstillingen ble stoppet fordi en superadmin feilaktig er knyttet til demovirksomheten. Flytt kontoen før demoen nullstilles.');
                    }
                    // Users use nullOnDelete for normal tenants. Demo users must be
                    // removed explicitly so the globally unique demo email is freed.
                    $old->users()->delete();
                    $old->delete();
                }
                if ($this->call('demo:scale-inventory', ['--sets' => $sets]) !== self::SUCCESS) {
                    throw new RuntimeException('Kunne ikke bygge demodata.');
                }
                $demo->prepare();
                $org = Organization::where('organization_number', 'DEMO-DEKKPILOT')->firstOrFail();
                OutboundMessage::where('organization_id', $org->id)->whereIn('status', ['queued','processing'])->update([
                    'status'=>'cancelled', 'last_error'=>'Blokkert permanent: demo- og testdata sendes aldri eksternt.',
                ]);
                return [
                    'organization_id'=>$org->id, 'customers'=>$org->customers()->count(),
                    'vehicles'=>$org->vehicles()->count(), 'sets'=>$org->tireSets()->count(),
                    'bookings'=>Booking::where('organization_id',$org->id)->where('starts_at','>=',today())->count(),
                    'products'=>TireProduct::where('organization_id',$org->id)->count(),
                ];
            });
            foreach ($summary as $key => $value) $this->line($key.': '.$value);
            $this->info('Demoen ble nullstilt og bygget opp på nytt.');
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
