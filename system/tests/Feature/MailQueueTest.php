<?php
namespace Tests\Feature;

use App\Exceptions\MailRateLimited;
use App\Models\{Organization, Branch, User, OutboundMessage};
use App\Services\{CommunicationService, MailSendLimiter};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Cache, Mail};
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class MailQueueTest extends TestCase
{
    use RefreshDatabase;
    private function user(): User {
        $org=Organization::create(['public_id'=>Str::uuid(),'name'=>'Verksted','subscription_status'=>'active']);
        $branch=Branch::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'name'=>'Hoved','code'=>'H']);
        return User::factory()->create(['organization_id'=>$org->id,'branch_id'=>$branch->id,'role'=>'owner','active'=>true]);
    }
    private function email(): Email {return (new Email)->from('sender@example.no')->to('recipient@example.no')->subject('Test')->text('Test');}
    public function test_customer_mail_test_is_queued_and_server_details_are_hidden(): void {
        Mail::fake();$user=$this->user();
        $this->actingAs($user)->post(route('admin.system.test-mail'),['recipient'=>'recipient@example.no'])->assertSessionHasNoErrors();
        Mail::assertNothingSent();
        $this->assertDatabaseHas('outbound_messages',['organization_id'=>$user->organization_id,'status'=>'queued','recipient'=>'recipient@example.no']);
        foreach(['admin.system','help','admin.communications'] as $route) {
            $this->get(route($route))->assertOk()->assertDontSee('Domeneshop')->assertDontSee('SMTP')->assertDontSee('Databasebackup');
        }
        $this->get(route('superadmin.server-mail'))->assertForbidden();
        $message=OutboundMessage::first();$message->update(['last_error'=>'SMTP smtp.domeneshop.no private server detail']);
        $this->get(route('admin.communications'))->assertOk()->assertDontSee('smtp.domeneshop.no')->assertSee('Venter på neste utsending.');
    }
    public function test_native_limits_are_shared_and_sliding_and_count_attempts(): void {
        Cache::forget('mail:send-attempts');$limiter=app(MailSendLimiter::class);$settings=['transport'=>'native','messages_per_minute'=>60];
        $limiter->reserve($this->email(),$settings);
        try {$limiter->reserve($this->email(),$settings);$this->fail('Second send must wait');}catch(MailRateLimited $e){$this->assertGreaterThanOrEqual(600,$e->retryAfter);}
        $this->travel(2)->seconds();$limiter->reserve($this->email(),$settings);
        $this->assertCount(2,Cache::get('mail:send-attempts'));
        foreach([[60,60],[3600,1500],[86400,5000]] as [$window,$count]) {
            Cache::put('mail:send-attempts',array_fill(0,$count,['time'=>(float)now()->format('U.u')-$window+10,'count'=>1,'large'=>false]),86401);
            try {$limiter->reserve($this->email(),$settings);$this->fail('Window must block');}catch(MailRateLimited $e){$this->assertGreaterThanOrEqual(600,$e->retryAfter);}
        }
        $this->travel(11)->seconds();$limiter->reserve($this->email(),$settings);
        $this->assertCount(1,Cache::get('mail:send-attempts'));
    }
    public function test_large_messages_and_lower_admin_limit_wait_longer(): void {
        Cache::forget('mail:send-attempts');$limiter=app(MailSendLimiter::class);
        $limiter->reserve($this->email()->text(str_repeat('a',600000)),['transport'=>'native']);
        $this->travel(1)->seconds();
        try {$limiter->reserve($this->email(),['transport'=>'native']);$this->fail('Large message needs longer spacing');}catch(MailRateLimited){}
        $this->travel(2)->seconds();$limiter->reserve($this->email(),['transport'=>'native']);
        $this->travel(2)->seconds();
        $this->expectException(MailRateLimited::class);
        $limiter->reserve($this->email(),['transport'=>'native','messages_per_minute'=>1]);
    }
    public function test_rate_limit_defers_without_exhausting_retry_budget(): void {
        $user=$this->user();config(['mail.default'=>'smtp']);
        $message=app(CommunicationService::class)->queue($user->organization_id,null,'email','recipient@example.no','Test','Test');
        $message->update(['attempts'=>2]);
        $this->mock(MailSendLimiter::class, fn($mock)=>$mock->shouldReceive('reserve')->once()->andThrow(new MailRateLimited(3600)));
        $this->artisan('communications:process')->assertExitCode(0);
        $message->refresh();$this->assertSame('queued',$message->status);$this->assertSame(2,$message->attempts);
        $this->assertTrue($message->scheduled_at->greaterThan(now()->addMinutes(59)));
        $this->assertNull($message->failed_at);
    }
}
