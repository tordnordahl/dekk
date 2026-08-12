<?php

namespace App\Services\Accounting;

use App\Models\InvoiceExport;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PowerOfficeExporter implements AccountingExporter
{
    public function __construct(private readonly AccountingPlatformSettings $settings) {}

    public function test(array $credentials): array
    {
        $this->client($credentials);
        return ['provider' => 'poweroffice', 'company' => 'PowerOffice Go'];
    }

    public function export(InvoiceExport $invoice, array $credentials): string
    {
        // Authentication is production ready. Invoice transfer is deliberately
        // blocked until the PowerOffice application has been granted SalesOrders
        // privileges; payload capabilities differ between subscriptions.
        $this->client($credentials);
        throw new RuntimeException('PowerOffice er koblet til, men DekkPilot-appen mangler aktivert SalesOrders-tilgang. Aktiver API-produktet hos PowerOffice før fakturaer sendes.');
    }

    private function client(array $credentials): PendingRequest
    {
        $platform = $this->settings->poweroffice();
        $appKey = (string)($platform['app_key'] ?? '');
        $subscriptionKey = (string)($platform['subscription_key'] ?? '');
        $clientKey = (string)($credentials['api_key'] ?? '');
        if ($appKey === '' || $subscriptionKey === '' || $clientKey === '') {
            throw new RuntimeException('PowerOffice mangler app key, subscription key eller klientnøkkel.');
        }
        $demo = ($credentials['environment'] ?? 'production') === 'test';
        $root = 'https://goapi.poweroffice.net'.($demo ? '/Demo' : '');
        $response = Http::asForm()->withBasicAuth($appKey, $clientKey)
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $subscriptionKey])
            ->timeout(25)->post($root.'/OAuth/Token', ['grant_type' => 'client_credentials']);
        if (!$response->successful()) throw new RuntimeException('PowerOffice-innlogging feilet (HTTP '.$response->status().'): '.mb_substr($response->body(), 0, 500));
        $token = (string)($response->json('access_token') ?: $response->json('token'));
        if ($token === '') throw new RuntimeException('PowerOffice returnerte ikke et access token.');
        return Http::baseUrl($root.'/v2')->withToken($token)
            ->withHeaders(['Ocp-Apim-Subscription-Key' => $subscriptionKey])->acceptJson()->asJson()->timeout(25);
    }
}
