<?php
namespace Tests\Feature;
use App\Services\{ScheduledOperations,SystemOperationsService};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Artisan,Cache};
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;
class ScheduledOperationsTest extends TestCase
{
 use RefreshDatabase;
 public function test_http_and_cli_share_a_full_ten_minute_interval():void {
  $this->travelTo(now()->startOfMinute());
  $ops=app(SystemOperationsService::class);$token=str_repeat('a',64);
  $ops->put('system.cron',['token_hash'=>hash('sha256',$token)]);
  Artisan::shouldReceive('call')->with('schedule:run',['--no-interaction'=>true])->twice()->andReturn(0);
  $this->assertTrue(app(ScheduledOperations::class)->run('ssh')['ok']);
  $this->get(route('system.cron',$token))->assertStatus(202)->assertJsonPath('status','interval_not_elapsed');
  $this->travel(599)->seconds();
  $this->assertSame('interval_not_elapsed',app(ScheduledOperations::class)->run('ssh')['status']);
  $this->travel(1)->seconds();
  $this->get(route('system.cron',$token))->assertOk()->assertJsonPath('status','completed');
  $this->assertTrue($ops->status()['cron_ok']);
  $this->get(route('system.cron',str_repeat('b',64)))->assertNotFound();
 }
 public function test_overlapping_run_is_skipped_and_failures_still_enforce_interval():void {
  $lock=Cache::lock('system:cron-run',3600);$lock->get();
  $this->assertSame('already_running',app(ScheduledOperations::class)->run('ssh')['status']);$lock->release();
  Artisan::shouldReceive('call')->once()->andReturn(1);
  $this->assertFalse(app(ScheduledOperations::class)->run('ssh')['ok']);
  $this->assertFalse(app(SystemOperationsService::class)->status()['cron_ok']);
  $this->assertSame('interval_not_elapsed',app(ScheduledOperations::class)->run('http')['status']);
 }
 public function test_all_scheduled_jobs_align_with_ten_minute_cron_and_oslo_time():void {
  Artisan::call('list',['--raw'=>true]);
  $events=app(Schedule::class)->events();$this->assertCount(12,$events);
  foreach($events as $event){
   $this->assertSame('Europe/Oslo',$event->timezone);
   $cron=new \Cron\CronExpression($event->expression);
   $time=new \DateTimeImmutable('2026-11-01 00:00:00',new \DateTimeZone('Europe/Oslo'));
   $next=$cron->getNextRunDate($time,0,true);$following=$cron->getNextRunDate($next);
   $this->assertSame(0,(int)$next->format('i')%10,$event->command);
   $this->assertGreaterThanOrEqual(600,$following->getTimestamp()-$next->getTimestamp(),$event->command);
  }
 }
}
