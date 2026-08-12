<?php

namespace App\Services;

use App\Models\CheckoutPayment;
use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class VippsPaymentService
{
    public function configuration(int $organizationId): array
    {
        $setting = IntegrationSetting::where('organization_id', $organizationId)->where('provider', 'payment_vipps')->where('active', true)->first();
        if (! $setting) return [];
        return json_decode(Crypt::decryptString($setting->encrypted_credentials), true, 512, JSON_THROW_ON_ERROR);
    }

    public function configured(int $organizationId): bool
    {
        $config = $this->configuration($organizationId);
        return filled($config['client_id'] ?? null) && filled($config['client_secret'] ?? null)
            && filled($config['subscription_key'] ?? null) && filled($config['msn'] ?? null);
    }

    public function create(CheckoutPayment $payment, string $returnUrl): string
    {
        $config = $this->required($payment->organization_id);
        $reference = preg_replace('/[^A-Za-z0-9-]/', '', $payment->terminal_reference);
        $response = Http::withHeaders($this->headers($config, $this->token($payment->organization_id, $config)) + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->acceptJson()->asJson()->timeout(25)->post($this->base($config).'/epayment/v1/payments', [
            'amount' => ['currency' => $payment->currency, 'value' => $payment->amount_cents],
            'paymentMethod' => ['type' => 'WALLET'],
            'reference' => $reference,
            'returnUrl' => $returnUrl,
            'userFlow' => 'WEB_REDIRECT',
            'customerInteraction' => 'CUSTOMER_PRESENT',
            'paymentDescription' => mb_substr('DekkPilot '.$payment->booking?->service_name, 0, 100),
        ]);
        if (! $response->successful()) throw new RuntimeException('Vipps kunne ikke starte betalingen (HTTP '.$response->status().'). '.mb_substr($response->body(), 0, 500));
        $redirect = (string) data_get($response->json(), 'redirectUrl');
        if ($redirect === '') throw new RuntimeException('Vipps returnerte ingen betalingsadresse.');
        $payment->update(['provider' => 'vipps', 'payment_method' => 'vipps', 'provider_reference' => $reference, 'provider_status' => 'CREATED', 'status' => 'processing']);
        return $redirect;
    }

    public function synchronize(CheckoutPayment $payment): CheckoutPayment
    {
        if ($payment->payment_method !== 'vipps' || $payment->status === 'paid') return $payment;
        $config = $this->required($payment->organization_id);
        $reference = (string) $payment->provider_reference;
        $response = Http::withHeaders($this->headers($config, $this->token($payment->organization_id, $config)))->acceptJson()->timeout(20)
            ->get($this->base($config).'/epayment/v1/payments/'.rawurlencode($reference));
        if (! $response->successful()) throw new RuntimeException('Kunne ikke kontrollere Vipps-betalingen (HTTP '.$response->status().').');
        $payload = $response->json();
        $state = strtoupper((string) ($payload['state'] ?? 'UNKNOWN'));
        $payment->update(['provider_status' => $state, 'provider_payload' => $payload]);
        if ($state === 'AUTHORIZED') return $this->capture($payment, $config);
        if (in_array($state, ['ABORTED', 'EXPIRED', 'TERMINATED'], true)) $payment->update(['status' => 'failed', 'last_error' => 'Vipps-betalingen ble '.mb_strtolower($state).'.']);
        return $payment->fresh();
    }

    private function capture(CheckoutPayment $payment, array $config): CheckoutPayment
    {
        $response = Http::withHeaders($this->headers($config, $this->token($payment->organization_id, $config)) + [
            'Idempotency-Key' => (string) Str::uuid(),
        ])->acceptJson()->asJson()->timeout(25)->post($this->base($config).'/epayment/v1/payments/'.rawurlencode((string) $payment->provider_reference).'/capture', [
            'modificationAmount' => ['currency' => $payment->currency, 'value' => $payment->amount_cents],
        ]);
        if (! $response->successful()) throw new RuntimeException('Vipps godkjente betalingen, men capture feilet (HTTP '.$response->status().').');
        $payload = $response->json();
        if ((int) data_get($payload, 'aggregate.capturedAmount.value', 0) < $payment->amount_cents) throw new RuntimeException('Vipps bekreftet ikke hele beløpet som trukket.');
        return app(CheckoutPaymentService::class)->complete($payment, 'vipps', (string) $payment->provider_reference, 'CAPTURED', $payload);
    }

    private function token(int $organizationId, array $config): string
    {
        return Cache::remember('vipps-payment-token-'.$organizationId, now()->addMinutes(50), function () use ($config) {
            $response = Http::withHeaders([
                'client_id' => $config['client_id'], 'client_secret' => $config['client_secret'],
                'Ocp-Apim-Subscription-Key' => $config['subscription_key'], 'Merchant-Serial-Number' => $config['msn'],
            ])->acceptJson()->timeout(20)->post($this->base($config).'/accesstoken/get');
            if (! $response->successful() || blank($response->json('access_token'))) throw new RuntimeException('Vipps API-innlogging feilet (HTTP '.$response->status().').');
            return (string) $response->json('access_token');
        });
    }

    private function headers(array $config, string $token): array
    {
        return ['Authorization' => 'Bearer '.$token, 'Ocp-Apim-Subscription-Key' => $config['subscription_key'],
            'Merchant-Serial-Number' => $config['msn'], 'Vipps-System-Name' => 'DekkPilot',
            'Vipps-System-Version' => '1.0', 'Vipps-System-Plugin-Name' => 'DekkPilot', 'Vipps-System-Plugin-Version' => '1.0'];
    }

    private function required(int $organizationId): array
    {
        $config = $this->configuration($organizationId);
        if (! $this->configured($organizationId)) throw new RuntimeException('Vipps er ikke ferdig konfigurert for dette verkstedet.');
        return $config;
    }

    private function base(array $config): string { return ! empty($config['test']) ? 'https://apitest.vipps.no' : 'https://api.vipps.no'; }
}
