<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class PhoneDirectory1881Service
{
    public function settings(): array
    {
        $defaults=['enabled'=>false,'endpoint'=>'','auth_header'=>'X-API-Key','api_key'=>null];
        if(!Schema::hasTable('platform_settings'))return $defaults;
        $row=PlatformSetting::where('key','directory.1881')->first();
        if(!$row)return $defaults;
        try{return array_merge($defaults,json_decode(Crypt::decryptString($row->encrypted_value),true,512,JSON_THROW_ON_ERROR));}
        catch(Throwable){return $defaults;}
    }

    public function enabled(): bool
    {
        $settings=$this->settings();
        return (bool)($settings['enabled']??false)&&filled($settings['endpoint']??null)&&filled($settings['api_key']??null);
    }

    public function lookup(string $phone): array
    {
        $settings=$this->settings();
        if(!$this->enabled())throw new RuntimeException('1881-oppslag er ikke aktivert.');
        $normalized=preg_replace('/\D+/','',$phone);
        if(str_starts_with($normalized,'0047'))$normalized=substr($normalized,4);
        elseif(str_starts_with($normalized,'47')&&strlen($normalized)===10)$normalized=substr($normalized,2);
        if(strlen($normalized)!==8)throw new RuntimeException('Skriv inn et gyldig norsk telefonnummer.');
        $endpoint=str_replace(['{phone}','PHONE'],rawurlencode($normalized),(string)$settings['endpoint']);
        if(!str_contains((string)$settings['endpoint'],'{phone}')&&!str_contains((string)$settings['endpoint'],'PHONE'))$endpoint.=(str_contains($endpoint,'?')?'&':'?').'phone='.rawurlencode($normalized);
        $header=trim((string)($settings['auth_header']??'X-API-Key'))?:'X-API-Key';
        $response=Http::acceptJson()->withHeaders([$header=>(string)$settings['api_key']])->timeout(8)->retry(1,200)->get($endpoint);
        if($response->status()===404)return [];
        if(!$response->successful())throw new RuntimeException('1881-oppslaget feilet (HTTP '.$response->status().').');
        $payload=$response->json();
        $record=$this->firstRecord(is_array($payload)?$payload:[]);
        if(!$record)return [];
        $street=$this->value($record,['geography.address.street']);
        $houseNumber=$this->value($record,['geography.address.houseNumber']);
        $entrance=$this->value($record,['geography.address.entrance']);
        $structuredAddress=trim(collect([$street,$houseNumber,$entrance])->filter()->join(' '));
        return [
            'name'=>$this->value($record,['name','fullName','displayName','person.name','contact.name']),
            'address'=>$structuredAddress?:$this->value($record,['streetAddress','address.street','postalAddress.street','contact.address']),
            'postal_code'=>$this->value($record,['geography.address.postCode','postalCode','zipCode','zip','address.postalCode','postalAddress.postalCode']),
            'city'=>$this->value($record,['geography.address.postArea','city','postalCity','address.city','postalAddress.city']),
            'phone'=>$normalized,
        ];
    }

    private function firstRecord(array $payload): array
    {
        foreach(['results','items','hits','persons','contacts','data']as$key){$value=data_get($payload,$key);if(is_array($value)&&array_is_list($value)&&is_array($value[0]??null))return$value[0];if(is_array($value)&&!array_is_list($value))return$value;}
        return array_is_list($payload)?(is_array($payload[0]??null)?$payload[0]:[]):$payload;
    }

    private function value(array $record,array $paths): ?string
    {
        foreach($paths as$path){$value=data_get($record,$path);if(is_scalar($value)&&trim((string)$value)!=='')return trim((string)$value);}
        return null;
    }
}
