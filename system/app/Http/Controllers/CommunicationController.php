<?php

namespace App\Http\Controllers;

use App\Mail\OutboundMail;
use App\Models\Customer;
use App\Models\IntegrationSetting;
use App\Models\OutboundMessage;
use App\Models\ServiceSetting;
use App\Services\CommunicationService;
use App\Services\TestDataGuard;
use App\Services\TwilioSmsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CommunicationController extends Controller
{
    public function unsubscribe(Request $request, string $customer): Response
    {
        abort_unless($request->hasValidSignature(),403);$item=Customer::where('public_id',$customer)->firstOrFail();$item->update(['marketing_consent'=>false]);DB::table('audit_logs')->insert(['organization_id'=>$item->organization_id,'action'=>'customer.marketing.unsubscribed','subject_type'=>Customer::class,'subject_id'=>$item->id,'ip_address'=>$request->ip(),'metadata'=>json_encode(['source'=>'signed_link']),'created_at'=>now()]);return response('<!doctype html><html lang="nb"><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Avmeldt</title><body style="font-family:system-ui;max-width:600px;margin:60px auto;padding:24px"><h1>Du er meldt av</h1><p>Du vil ikke lenger motta markedsføring. Viktige meldinger om avtalte timer og aktive kundeforhold kan fortsatt sendes.</p></body></html>',200,['Content-Type'=>'text/html; charset=UTF-8']);
    }
    public function index(Request $request): View
    {
        $org = $request->user()->organization_id;
        $smsSettings = ServiceSetting::firstOrCreate(['branch_id' => $request->user()->branch_id], ['organization_id' => $org]);
        return view('admin.communications', ['messages' => OutboundMessage::where('organization_id', $org)->latest()->paginate(50), 'customers' => Customer::where('organization_id', $org)->orderBy('name')->get(), 'twilioConfigured' => IntegrationSetting::where('organization_id', $org)->where('provider', 'twilio')->where('active', true)->exists(), 'smsSettings' => $smsSettings, 'stats' => OutboundMessage::where('organization_id', $org)->selectRaw('status, count(*) total')->groupBy('status')->pluck('total', 'status')]);
    }

    public function send(Request $request, CommunicationService $service): RedirectResponse
    {
        $data = $request->validate(['audience' => ['required',Rule::in(['customer','all_consented'])], 'customer_id' => ['nullable','integer'], 'channel' => ['required',Rule::in(['email','sms'])], 'subject' => ['nullable','string','max:200'], 'body' => ['required','string','max:5000']]);
        $org = $request->user()->organization_id;
        $settings = ServiceSetting::where('branch_id', $request->user()->branch_id)->first();
        if ($data['channel'] === 'sms' && ! ($settings?->sms_enabled ?? false)) return back()->withErrors(['channel' => 'SMS-varsling er slått av i innstillingene.']);
        if ($data['channel'] === 'sms' && $data['audience'] === 'all_consented' && ! ($settings?->sms_marketing_enabled ?? false)) return back()->withErrors(['channel' => 'SMS-kampanjer må aktiveres eksplisitt i SMS-innstillingene.']);
        $query = Customer::where('organization_id', $org);
        if ($data['audience'] === 'customer') $query->whereKey($data['customer_id']); else $query->where('marketing_consent', true);
        $queued = 0;
        $query->chunkById(100, function ($customers) use (&$queued, $data, $service, $org, $request) { foreach ($customers as $customer) { $recipient = $data['channel'] === 'email' ? $customer->email : $customer->phone; if (! $recipient) continue; $service->queue($org, $customer, $data['channel'], $recipient, $data['subject'] ?? null, $data['body'], $data['audience'] === 'all_consented' ? 'marketing' : 'transactional', null, $request->user()->id); $queued++; } });
        return back()->with('success', $queued.' meldinger er lagt i utsendingskøen.');
    }

    public function previewDraft(Request $request): Response { $data=$request->validate(['subject'=>['nullable','string','max:200'],'body'=>['required','string','max:5000']]); return $this->renderEmail($data['subject'] ?: 'Melding fra DekkPilot', $data['body']); }
    public function previewMessage(Request $request, OutboundMessage $message): Response { abort_unless($message->organization_id === $request->user()->organization_id && $message->channel === 'email', 404); return response(app(\App\Services\CommunicationService::class)->mailable($message)->render(), 200, ['Content-Type'=>'text/html; charset=UTF-8']); }
    private function renderEmail(string $subject, string $body): Response { return response((new OutboundMail($subject, $body))->render(), 200, ['Content-Type' => 'text/html; charset=UTF-8']); }

    public function saveTwilio(Request $request): RedirectResponse
    {
        $data = $request->validate(['account_sid' => ['required','string','regex:/^AC[a-zA-Z0-9]{32}$/'], 'api_key' => ['required','string','regex:/^SK[a-zA-Z0-9]{32}$/'], 'api_secret' => ['required','string','min:16','max:200'], 'from' => ['required','string','regex:/^(MG[a-zA-Z0-9]{32}|\+[1-9]\d{7,14})$/']]);
        $org = $request->user()->organization_id;
        IntegrationSetting::updateOrCreate(['organization_id' => $org, 'provider' => 'twilio'], ['encrypted_credentials' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)), 'active' => true, 'updated_by' => $request->user()->id]);
        ServiceSetting::updateOrCreate(['branch_id' => $request->user()->branch_id], ['organization_id' => $org, 'sms_enabled' => true]);
        DB::table('audit_logs')->insert(['organization_id' => $org, 'user_id' => $request->user()->id, 'action' => 'integration.twilio.updated', 'ip_address' => $request->ip(), 'metadata' => json_encode(['credential_changed' => true]), 'created_at' => now()]);
        return back()->with('success', 'Twilio er koblet til. Send en test-SMS før varsler tas i bruk.');
    }

    public function saveSmsSettings(Request $request): RedirectResponse
    {
        $data = ['sms_enabled' => $request->boolean('sms_enabled'), 'sms_booking_confirmation_enabled' => $request->boolean('sms_booking_confirmation_enabled'), 'sms_booking_reminder_enabled' => $request->boolean('sms_booking_reminder_enabled'), 'sms_marketing_enabled' => $request->boolean('sms_marketing_enabled')];
        ServiceSetting::updateOrCreate(['branch_id' => $request->user()->branch_id], [...$data, 'organization_id' => $request->user()->organization_id]);
        DB::table('audit_logs')->insert(['organization_id' => $request->user()->organization_id, 'user_id' => $request->user()->id, 'action' => 'sms.settings.updated', 'ip_address' => $request->ip(), 'metadata' => json_encode($data), 'created_at' => now()]);
        return back()->with('success', 'Innstillingene for SMS-varsling er lagret.');
    }

    public function twilioStatus(Request $request,string $message,string $token): Response
    {
        abort_unless(strlen($token)===64&&ctype_alnum($token),404);$message=OutboundMessage::where('public_id',$message)->where('delivery_token_hash',hash('sha256',$token))->firstOrFail();$data=$request->validate(['MessageSid'=>['required','string','max:100'],'MessageStatus'=>['required','string','max:40'],'ErrorCode'=>['nullable','string','max:40'],'ErrorMessage'=>['nullable','string','max:500']]);if($message->provider_reference&&!hash_equals($message->provider_reference,$data['MessageSid']))abort(403);$status=strtolower($data['MessageStatus']);$updates=['delivery_status'=>$status,'provider_metadata'=>['error_code'=>$data['ErrorCode']??null,'error_message'=>$data['ErrorMessage']??null]];if($status==='delivered')$updates['delivered_at']=now();if(in_array($status,['failed','undelivered'],true)){$updates['bounced_at']=now();DB::table('usage_events')->where('organization_id',$message->organization_id)->where('type','sms')->where('source_type',OutboundMessage::class)->where('source_id',$message->id)->delete();}$message->update($updates);DB::table('audit_logs')->insert(['organization_id'=>$message->organization_id,'action'=>'communication.sms.'.$status,'subject_type'=>OutboundMessage::class,'subject_id'=>$message->id,'metadata'=>json_encode(['error_code'=>$data['ErrorCode']??null]),'created_at'=>now()]);return response('',204);
    }

    public function testSms(Request $request, TwilioSmsService $sms, TestDataGuard $guard): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required','string','max:30']]);
        $org = $request->user()->organization_id;
        if ($guard->organization($org)) return back()->withErrors(['phone' => 'Test-SMS er deaktivert i demo- og testmiljøet.']);
        if (! IntegrationSetting::where('organization_id', $org)->where('provider', 'twilio')->where('active', true)->exists()) return back()->withErrors(['phone' => 'Koble til Twilio før du sender en test.']);
        try { $recipient = $sms->normalizeNumber($data['phone']); } catch (Throwable $exception) { return back()->withErrors(['phone' => $exception->getMessage()])->withInput(); }
        $message = OutboundMessage::create(['public_id' => (string) Str::uuid(), 'organization_id' => $org, 'created_by' => $request->user()->id, 'channel' => 'sms', 'purpose' => 'transactional', 'recipient' => $recipient, 'body' => 'DekkPilot test: SMS-integrasjonen fungerer. Ingen handling er nødvendig.', 'status' => 'processing', 'attempts' => 1, 'scheduled_at' => now()]);
        try { $reference = $sms->send($org, $recipient, $message->body, $message); $message->update(['status' => 'sent', 'sent_at' => now(), 'provider_reference' => $reference]); DB::table('audit_logs')->insert(['organization_id' => $org, 'user_id' => $request->user()->id, 'action' => 'integration.twilio.test_sent', 'ip_address' => $request->ip(), 'metadata' => json_encode(['message_id' => $message->id]), 'created_at' => now()]); return back()->with('success', 'Test-SMS er sendt til '.$recipient.'.'); }
        catch (Throwable $exception) { report($exception); $message->update(['status' => 'failed', 'failed_at' => now(), 'last_error' => mb_substr($exception->getMessage(), 0, 1000)]); return back()->withErrors(['phone' => $exception->getMessage()])->withInput(); }
    }
}
