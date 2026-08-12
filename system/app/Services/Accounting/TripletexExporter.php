<?php

namespace App\Services\Accounting;

use App\Models\InvoiceExport;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TripletexExporter implements AccountingExporter
{
    public function __construct(private readonly AccountingPlatformSettings $settings) {}

    public function test(array $credentials): array
    {
        $client = $this->client($credentials);
        $response = $client->get('/company'); $this->ensure($response, 'Tripletex-tilkoblingen');
        return ['provider' => 'tripletex', 'company' => $response->json('value.name') ?: 'Tripletex'];
    }

    public function export(InvoiceExport $invoice, array $credentials): string
    {
        $client = $this->client($credentials);
        $orderId = (int) $invoice->external_order_id;
        if (!$orderId) {
            $customerId = $this->customer($client, $invoice->customer_snapshot);
            $orderResponse = $client->post('/order', ['customer' => ['id' => $customerId], 'orderDate' => now()->toDateString(),
                'deliveryDate' => now()->toDateString(), 'reference' => $invoice->reference, 'isPrioritizeAmountsIncludingVat' => true]);
            $this->ensure($orderResponse, 'Tripletex ordre'); $orderId = (int)$orderResponse->json('value.id');
            if (!$orderId) throw new RuntimeException('Tripletex returnerte ingen ordre-ID.');
            $invoice->forceFill(['external_order_id' => (string) $orderId, 'provider_metadata' => ['stage' => 'order_created']])->save();
            foreach ($invoice->lines as $line) {
                $response = $client->post('/order/orderline', ['order' => ['id' => $orderId],
                    'description' => $line['description'], 'count' => (float)($line['quantity'] ?? 1),
                    'unitPriceIncludingVatCurrency' => ((int)$line['unit_price_cents']) / 100]);
                $this->ensure($response, 'Tripletex ordrelinje');
            }
            $invoice->forceFill(['provider_metadata' => ['stage' => 'lines_created']])->save();
        }
        $invoiceResponse = $client->put('/order/'.$orderId.'/:invoice?'.http_build_query(['invoiceDate' => now()->toDateString(),
            'sendToCustomer' => 'false']), []);
        $this->ensure($invoiceResponse, 'Tripletex faktura');
        $externalId = (string)($invoiceResponse->json('value.id') ?: $orderId);
        $invoice->forceFill(['provider_metadata' => ['stage' => 'invoice_created', 'order_id' => $orderId]])->save();
        return $externalId;
    }

    private function customer(PendingRequest $client, array $customer): int
    {
        $params = ['fields' => 'id,name,email,organizationNumber'];
        if (!empty($customer['organization_number'])) $params['organizationNumber'] = preg_replace('/\D/', '', $customer['organization_number']);
        elseif (!empty($customer['email'])) $params['email'] = $customer['email'];
        if (count($params) > 1) {
            $response = $client->get('/customer', $params); $this->ensure($response, 'Tripletex kundesøk');
            $match = collect($response->json('values', []))->first(); if ($match) return (int)$match['id'];
        }
        $response = $client->post('/customer', array_filter(['name' => $customer['name'], 'email' => $customer['email'] ?? null,
            'phoneNumberMobile' => $customer['phone'] ?? null, 'organizationNumber' => $customer['organization_number'] ?? null,
            'isCustomer' => true, 'isPrivateIndividual' => empty($customer['organization_number']),
            'invoiceSendMethod' => !empty($customer['email']) ? 'EMAIL' : null], fn($value)=>$value!==null&&$value!==''));
        $this->ensure($response, 'Opprettelse av Tripletex-kunde'); return (int)$response->json('value.id');
    }

    private function client(array $credentials): PendingRequest
    {
        $base = rtrim((string)($credentials['base_url'] ?? 'https://tripletex.no/v2'), '/');
        $test=str_contains($base,'api-test.tripletex.tech');$platform=$this->settings->tripletex();$consumer=$test?($platform['test_consumer_token']??null):($platform['consumer_token']??null);
        if(($credentials['auth_mode']??'internal')==='commercial'){
            if(!$consumer)throw new RuntimeException('Tripletex consumer token mangler på serveren.');
            $session=Http::baseUrl($base)->acceptJson()->asJson()->timeout(20)->post('/token/session/:create',['consumerToken'=>$consumer,'employeeToken'=>$credentials['api_key'],'expirationDate'=>now()->addDay()->toDateString()]);
        }else{
            $session = Http::baseUrl($base)->acceptJson()->asJson()->timeout(20)->post('/token/session/:createFromRefreshToken',['refreshToken' => $credentials['api_key'], 'ttlSeconds' => 3600]);
        }
        $this->ensure($session, 'Tripletex innlogging'); $token = $session->json('value.token') ?: $session->json('token');
        if (!$token) throw new RuntimeException('Tripletex returnerte ikke et session token.');
        return Http::baseUrl($base)->withBasicAuth((string)($credentials['company_id'] ?? '0'), (string)$token)
            ->acceptJson()->asJson()->timeout(25);
    }
    private function ensure($response, string $operation): void { if (!$response->successful()) throw new RuntimeException($operation.' feilet (HTTP '.$response->status().'): '.mb_substr((string)($response->json('message') ?: $response->body()), 0, 500)); }
}
