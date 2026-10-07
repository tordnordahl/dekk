<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\OutboundMessage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class CommunicationService
{
    public function queueQuote(\App\Models\Quote $quote, string $token): OutboundMessage
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($quote, $token) {
            $message = $this->queue($quote->organization_id, $quote->customer, 'email', $quote->customer->email, 'Dekkanbefaling '.$quote->reference, 'Tilbud '.$quote->reference, 'transactional', null, $quote->created_by);
            $message->update(['provider_metadata'=>['quote_id'=>$quote->id, 'quote_token'=>\Illuminate\Support\Facades\Crypt::encryptString($token)]]);
            return $message;
        });
    }

    public function mailable(OutboundMessage $message): \Illuminate\Mail\Mailable
    {
        $metadata = $message->provider_metadata ?? [];
        if (!empty($metadata['quote_id'])) {
            $quote = \App\Models\Quote::where('organization_id', $message->organization_id)->findOrFail($metadata['quote_id']);
            return new \App\Mail\QuoteMail($quote, route('quote.public.show', \Illuminate\Support\Facades\Crypt::decryptString($metadata['quote_token'])));
        }
        return new \App\Mail\OutboundMail($message->subject ?? 'Melding fra DekkPilot', $message->body);
    }

    public function queue(int $organizationId, ?Customer $customer, string $channel, string $recipient, ?string $subject, string $body, string $purpose='transactional', ?int $bookingId=null, ?int $createdBy=null): OutboundMessage
    {
        $blocked=app(TestDataGuard::class)->organization($organizationId)||app(TestDataGuard::class)->customer($customer);
        if($purpose==='marketing'&&$customer){$unsubscribe=URL::temporarySignedRoute('communications.unsubscribe',now()->addYear(),['customer'=>$customer->public_id]);$body.=($channel==='email'?"\n\nDu kan melde deg av markedsføring her:\n":"\nAvmelding: ").$unsubscribe;}
        return OutboundMessage::create(['public_id'=>(string)Str::uuid(),'organization_id'=>$organizationId,'customer_id'=>$customer?->id,'booking_id'=>$bookingId,'created_by'=>$createdBy,'channel'=>$channel,'purpose'=>$purpose,'recipient'=>$recipient,'subject'=>$subject,'body'=>$body,'status'=>$blocked?'cancelled':'queued','scheduled_at'=>now(),'last_error'=>$blocked?'Blokkert permanent: demo- eller testdata sendes aldri eksternt.':null]);
    }
}
