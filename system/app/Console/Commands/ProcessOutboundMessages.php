<?php

namespace App\Console\Commands;

use App\Models\OutboundMessage;
use App\Services\TestDataGuard;
use App\Services\TwilioSmsService;
use App\Services\MailConfigurationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

class ProcessOutboundMessages extends Command
{
    protected $signature = 'communications:process {--limit=50}';
    protected $description = 'Send planlagte e-poster og SMS-er fra kommunikasjonskøen';

    public function handle(TwilioSmsService $sms, TestDataGuard $guard, MailConfigurationService $mailConfiguration): int
    {
        $items = OutboundMessage::with('customer')->where('status','queued')->where('scheduled_at','<=',now())->orderBy('id')->limit((int)$this->option('limit'))->get();
        foreach ($items as $message) {
            if ($guard->organization($message->organization_id) || $guard->customer($message->customer)) {
                $message->update(['status'=>'cancelled','last_error'=>'Blokkert permanent: demo- eller testdata sendes aldri eksternt.']);
                continue;
            }
            $claimed = OutboundMessage::whereKey($message->id)->where('status','queued')->update(['status'=>'processing','attempts'=>DB::raw('attempts + 1')]);
            if (!$claimed) continue;
            $message->refresh();
            try {
                $reference = null;
                if ($message->channel === 'email') {
                    $configured = $mailConfiguration->configure($message->organization_id);
                    if (($configured['server']['transport'] ?? 'log') === 'log') throw new \RuntimeException('E-posttransport er ikke aktivert.');
                    $quoteId = data_get($message->provider_metadata, 'quote_id');
                    if ($quoteId) {
                        $quote = \App\Models\Quote::where('organization_id', $message->organization_id)->find($quoteId);
                        if (!$quote || $quote->expires_at->isPast() || $quote->status !== 'draft') {
                            $message->update(['status'=>'cancelled', 'last_error'=>'Tilbudet er utløpt eller allerede behandlet.']);
                            continue;
                        }
                    }
                    $sent = Mail::to($message->recipient)->send(app(\App\Services\CommunicationService::class)->mailable($message));
                    if ($sent === null && !app()->runningUnitTests()) throw new \RuntimeException('E-posten ble stoppet før utsending.');
                    if ($quoteId) \App\Models\Quote::whereKey($quoteId)->where('status','draft')->update(['status'=>'sent','sent_at'=>now()]);
                    $rate = max(1, (int) ($configured['server']['messages_per_minute'] ?? 60));
                    if (!app()->runningUnitTests()) usleep((int) max(2_000_000, ceil(60_000_000 / $rate)));
                } else {
                    $reference = $sms->send($message->organization_id, $message->recipient, $message->body, $message);
                    DB::table('usage_events')->updateOrInsert(
                        ['organization_id'=>$message->organization_id,'type'=>'sms','source_type'=>OutboundMessage::class,'source_id'=>$message->id],
                        ['quantity'=>1,'unit_price_cents'=>0,'metadata'=>json_encode(['provider'=>'twilio','provider_reference'=>$reference]),'occurred_at'=>now(),'created_at'=>now(),'updated_at'=>now()]
                    );
                }
                $message->update(['status'=>'sent','sent_at'=>now(),'provider_reference'=>$reference,'last_error'=>null]);
            } catch (\App\Exceptions\MailRateLimited $exception) {
                $message->update(['status'=>'queued','attempts'=>max(0,$message->attempts-1),'scheduled_at'=>now()->addSeconds($exception->retryAfter),'last_error'=>$exception->getMessage()]);
            } catch (Throwable $exception) {
                report($exception);
                $failed = $message->attempts >= 3;
                $message->update(['status'=>$failed?'failed':'queued','failed_at'=>$failed?now():null,'scheduled_at'=>now()->addMinutes(10),'last_error'=>mb_substr($exception->getMessage(),0,1000)]);
            }
        }
        $this->info($items->count().' meldinger behandlet.');
        return self::SUCCESS;
    }
}
