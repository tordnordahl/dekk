<?php
namespace App\Services;

use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

class ScheduledOperations
{
    public function __construct(private readonly SystemOperationsService $settings) {}

    public function run(string $source): array
    {
        $lock=Cache::lock('system:cron-run',3600);
        if (!$lock->get()) return ['ok'=>true,'status'=>'already_running'];
        try {
            $state=$this->settings->get('system.cron');
            if (!empty($state['last_run_at']) && now()->timestamp-\Carbon\Carbon::parse($state['last_run_at'])->timestamp<600) {
                return ['ok'=>true,'status'=>'interval_not_elapsed'];
            }
            $state['last_run_at']=now()->toIso8601String();
            $state['source']=$source;
            $state['last_exit_code']=null;
            $this->settings->put('system.cron',$state);
            $failed=false;
            Event::listen(ScheduledTaskFailed::class, function() use (&$failed) { $failed=true; });
            Event::listen(ScheduledTaskFinished::class, function($event) use (&$failed) {
                if ($event->task->exitCode!==null && $event->task->exitCode!==0) $failed=true;
            });
            try { $exit=Artisan::call('schedule:run',['--no-interaction'=>true]); }
            catch (\Throwable $e) {
                report($e);
                $exit=1;
            }
            $state=$this->settings->get('system.cron');
            $state['last_finished_at']=now()->toIso8601String();
            $state['last_exit_code']=$failed?1:$exit;
            $this->settings->put('system.cron',$state);
            return ['ok'=>$state['last_exit_code']===0,'status'=>'completed','ran_at'=>$state['last_run_at']];
        } finally { $lock->release(); }
    }
}
