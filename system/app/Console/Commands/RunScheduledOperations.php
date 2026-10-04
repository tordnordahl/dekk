<?php
namespace App\Console\Commands;
use App\Services\ScheduledOperations;
use Illuminate\Console\Command;
class RunScheduledOperations extends Command
{
    protected $signature='system:cron';
    protected $description='Kjør planlagte jobber, maksimalt én gang per ti minutter';
    public function handle(ScheduledOperations $operations, \App\Services\SystemOperationsService $settings): int
    {
        // Small differences in PHP startup time must not skip a whole ten-minute slot.
        $last=$settings->get('system.cron')['last_run_at']??null;
        $remaining=$last?600-(now()->timestamp-\Carbon\Carbon::parse($last)->timestamp):0;
        if ($remaining>0 && $remaining<=5) sleep($remaining);
        $result=$operations->run('ssh');
        $this->line(json_encode($result));
        return $result['ok']?self::SUCCESS:self::FAILURE;
    }
}
