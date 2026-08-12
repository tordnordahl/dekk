<?php

namespace App\Services\Accounting;

use App\Models\InvoiceExport;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class UniMicroExporter implements AccountingExporter
{
    public function test(array $credentials): array
    {
        $response=$this->client($credentials)->get('/api/biz/companysettings');
        $this->ensure($response,'Uni Micro-tilkoblingen');
        return ['provider'=>'unimicro','company'=>$response->json('CompanyName')?:$response->json('Name')?:'Uni Micro'];
    }

    public function export(InvoiceExport $invoice,array $credentials): string
    {
        $customer=$invoice->customer_snapshot;
        $items=collect($invoice->lines)->values()->map(function(array $line,int $index):array{
            $gross=(int)($line['unit_price_cents']??$line['total_cents']??0);$rate=(float)($line['vat_rate']??25);$net=(int)round($gross/(1+$rate/100));$vat=$gross-$net;
            return ['ItemText'=>$line['description'],'Unit'=>'stk','NumberOfItems'=>(float)($line['quantity']??1),'PriceExVat'=>$net/100,'PriceIncVat'=>$gross/100,'VatPercent'=>$rate,'SumVat'=>$vat/100,'SumVatCurrency'=>$vat/100,'SumTotalExVat'=>$net/100,'SumTotalIncVat'=>$gross/100,'SumTotalExVatCurrency'=>$net/100,'SumTotalIncVatCurrency'=>$gross/100,'SortIndex'=>$index+1,'_isDirty'=>true,'_createguid'=>(string)Str::uuid()];
        })->all();
        $info=['Name'=>$customer['name'],'Addresses'=>[],'Phones'=>[],'Emails'=>[],'_createguid'=>(string)Str::uuid()];
        if(!empty($customer['phone']))$info['DefaultPhone']=['Number'=>$customer['phone'],'Type'=>150102,'_createguid'=>(string)Str::uuid()];
        if(!empty($customer['email']))$info['DefaultEmail']=['EmailAddress'=>$customer['email'],'_createguid'=>(string)Str::uuid()];
        if(!empty($customer['address'])||!empty($customer['postal_code']))$info['InvoiceAddress']=['AddressLine1'=>$customer['address']??'','PostalCode'=>$customer['postal_code']??'','City'=>$customer['city']??'','CountryCode'=>'NO','_createguid'=>(string)Str::uuid()];
        $days=(int)($credentials['payment_days']??14);
        $response=$this->client($credentials)->post('/api/biz/invoices',['InvoiceDate'=>now()->toDateString(),'PaymentDueDate'=>now()->addDays($days)->toDateString(),'OurReference'=>$invoice->reference,'CustomerID'=>0,'Customer'=>['ID'=>0,'OrgNumber'=>$customer['organization_number']??null,'Info'=>$info,'_createguid'=>(string)Str::uuid()],'CustomerName'=>$customer['name'],'Items'=>$items,'PaymentInfoTypeID'=>(int)($credentials['payment_info_type_id']??5),'DistributionPlanID'=>(int)($credentials['distribution_plan_id']??15)]);
        $this->ensure($response,'Uni Micro faktura');$id=(string)($response->json('ID')?:$response->json('id'));
        if($id==='')throw new RuntimeException('Uni Micro returnerte ingen faktura-ID.');return$id;
    }

    private function client(array $credentials):PendingRequest
    {
        $base=rtrim((string)($credentials['api_base_url']??'https://test.unimicro.no'),'/');
        return Http::baseUrl($base)->withToken((string)$credentials['api_key'])->withHeaders(['CompanyKey'=>(string)$credentials['company_key']])->acceptJson()->asJson()->timeout(30);
    }
    private function ensure($response,string $operation):void{if(!$response->successful())throw new RuntimeException($operation.' feilet (HTTP '.$response->status().'): '.mb_substr((string)($response->json('Message')?:$response->json('message')?:$response->body()),0,700));}
}
