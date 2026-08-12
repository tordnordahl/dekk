<?php

namespace App\Services\Accounting;

use App\Models\InvoiceExport;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FikenExporter implements AccountingExporter
{
    public function test(array $credentials): array
    {
        $response = $this->client($credentials)->get('/companies/'.rawurlencode($this->slug($credentials)));
        $this->ensure($response, 'Fiken-tilkoblingen');
        return ['provider' => 'fiken', 'company' => $response->json('name') ?: $this->slug($credentials)];
    }

    public function export(InvoiceExport $invoice, array $credentials): string
    {
        $client = $this->client($credentials, (string) ($invoice->request_key ?: $invoice->public_id));
        $base = '/companies/'.rawurlencode($this->slug($credentials));
        $companyResponse=$client->get($base);$this->ensure($companyResponse,'Fiken foretaksstatus');
        $vatRegistered=(string)$companyResponse->json('vatType')!=='no' && (!$companyResponse->json('vatRegistrationDate') || now()->toDateString()>=(string)$companyResponse->json('vatRegistrationDate'));
        $customerId = $this->contact($client, $base, $invoice->customer_snapshot);
        $lines = collect($invoice->lines)->map(function (array $line) use ($credentials,$vatRegistered): array {
            $grossUnit = (int) ($line['unit_price_cents'] ?? $line['total_cents']); $rate = (float) ($line['vat_rate'] ?? 25);
            $net = $vatRegistered?(int)round($grossUnit/(1+$rate/100)):$grossUnit;
            return ['description' => $line['description'], 'quantity' => (float) ($line['quantity'] ?? 1), 'unitPrice' => $net,
                'vatType' => $vatRegistered?$this->vatType($rate):'OUTSIDE',
                'incomeAccount' => $vatRegistered?(string)($line['income_account']??$credentials['income_account']??'3000'):'3200'];
        })->all();
        $response = $client->post($base.'/invoices/drafts', ['type'=>'invoice','customerId'=>$customerId,
            'issueDate'=>now()->toDateString(),'daysUntilDueDate'=>(int)($credentials['payment_days']??14),
            'ourReference'=>$invoice->reference,'orderReference'=>$invoice->reference,'currency'=>'NOK','lines'=>$lines]);
        $this->ensure($response, 'Fiken fakturautkast');
        return $this->locationId($response->header('Location'));
    }

    private function contact(PendingRequest $client, string $base, array $customer): int
    {
        $filters = array_filter(['organizationNumber' => $customer['organization_number'] ?? null, 'email' => $customer['email'] ?? null]);
        foreach ($filters as $field => $value) {
            $response = $client->get($base.'/contacts', [$field => $value, 'page' => 0, 'pageSize' => 10]);
            $this->ensure($response, 'Fiken kundesøk');
            $match = collect($response->json())->first(fn ($item) => $field === 'organizationNumber'
                ? preg_replace('/\D/', '', (string)($item['organizationNumber'] ?? '')) === preg_replace('/\D/', '', (string)$value)
                : strcasecmp((string)($item['email'] ?? ''), (string)$value) === 0);
            if ($match && isset($match['contactId'])) return (int) $match['contactId'];
        }
        $response = $client->post($base.'/contacts', array_filter(['name' => $customer['name'],
            'organizationNumber' => $customer['organization_number'] ?? null, 'email' => $customer['email'] ?? null,
            'phoneNumber' => $customer['phone'] ?? null, 'customer' => true]));
        $this->ensure($response, 'Opprettelse av Fiken-kunde');
        return (int) $this->locationId($response->header('Location'));
    }

    private function client(array $credentials, ?string $requestId = null): PendingRequest
    {
        return Http::baseUrl('https://api.fiken.no/api/v2')->withToken($credentials['api_key'])->acceptJson()
            ->asJson()->withHeaders(['X-Request-ID' => $requestId ?: (string) \Illuminate\Support\Str::uuid()])
            ->timeout(25);
    }
    private function slug(array $credentials): string { return trim((string)($credentials['company_slug'] ?? '')); }
    private function vatType(float $rate): string { return match (true) { $rate >= 24 => 'HIGH', $rate >= 14 => 'MEDIUM', $rate > 0 => 'LOW', default => 'NONE' }; }
    private function locationId(?string $location): string { $id = basename((string)$location); if ($id === '') throw new RuntimeException('Leverandøren returnerte ingen ekstern ID.'); return $id; }
    private function ensure($response, string $operation): void { if (!$response->successful()) throw new RuntimeException($operation.' feilet (HTTP '.$response->status().'): '.mb_substr((string)($response->json('message') ?: $response->body()), 0, 500)); }
}
