<?php
namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class BrregService
{
    public function lookup(string $organizationNumber): array
    {
        $org=preg_replace('/\D/','',$organizationNumber);
        if(!preg_match('/^\d{9}$/',$org)) throw new RuntimeException('Organisasjonsnummeret må bestå av ni sifre.');
        try {
            $response=Http::acceptJson()->withHeaders(['User-Agent'=>'DekkPilot virksomhetskontroll'])
                ->connectTimeout(3)->timeout(10)->get('https://data.brreg.no/enhetsregisteret/api/enheter/'.$org);
        } catch (Throwable $e) { throw new RuntimeException('Brønnøysundregistrene svarer ikke akkurat nå. Prøv igjen senere.',0,$e); }
        if($response->status()===404) throw new RuntimeException('Organisasjonsnummeret finnes ikke i Enhetsregisteret.');
        if($response->status()===410) throw new RuntimeException('Virksomheten er fjernet fra Enhetsregisterets åpne data.');
        if(!$response->successful()) throw new RuntimeException('Brønnøysundregistrene svarer ikke akkurat nå. Prøv igjen senere.');
        $data=$response->json();
        if(!is_array($data)||!filled($data['navn']??null)) throw new RuntimeException('Brønnøysundregistrene returnerte ufullstendige virksomhetsopplysninger.');
        if(($data['erSlettet']??false)===true) throw new RuntimeException('Virksomheten er slettet i Enhetsregisteret.');
        return [
            'organization_number'=>$org,'name'=>$data['navn'],
            'organization_type'=>data_get($data,'organisasjonsform.kode'),
            'organization_type_name'=>data_get($data,'organisasjonsform.beskrivelse'),
            'address'=>implode(', ',data_get($data,'forretningsadresse.adresse',[])),
            'postal_code'=>data_get($data,'forretningsadresse.postnummer'),'city'=>data_get($data,'forretningsadresse.poststed'),
            'country'=>data_get($data,'forretningsadresse.landkode','NO'),
            'postal_address'=>implode(', ',data_get($data,'postadresse.adresse',[])),
            'postal_postal_code'=>data_get($data,'postadresse.postnummer'),'postal_city'=>data_get($data,'postadresse.poststed'),
            'postal_country'=>data_get($data,'postadresse.landkode','NO'),
            'registered_in_vat_register'=>(bool)($data['registrertIMvaregisteret']??false),
            'registered_at'=>$data['registreringsdatoEnhetsregisteret']??null,
            'website'=>$data['hjemmeside']??null,'phone'=>$data['telefon']??$data['mobil']??null,
            'registry_email'=>$data['epostadresse']??null,
            'industry_code'=>data_get($data,'naeringskode1.kode'),'industry_name'=>data_get($data,'naeringskode1.beskrivelse'),
            'bankrupt'=>(bool)($data['konkurs']??false),'liquidating'=>(bool)($data['underAvvikling']??false),
        ];
    }

    // Optional lookups run in parallel; unavailable authority data must not block registration.
    public function privateDetails(string $org, array $previous=[]): array
    {
        if(!preg_match('/^\d{9}$/',$org)) return $previous;
        $urls=[
            'roles'=>'https://data.brreg.no/enhetsregisteret/api/enheter/'.$org.'/roller',
            'signatur'=>'https://data.brreg.no/fullmakt/enheter/'.$org.'/signatur',
            'prokura'=>'https://data.brreg.no/fullmakt/enheter/'.$org.'/prokura',
        ];
        try {
            $responses=Http::pool(function(Pool $pool) use($urls) {
                $requests=[];
                foreach($urls as $key=>$url) $requests[]=$pool->as($key)->acceptJson()->connectTimeout(2)->timeout(4)->get($url);
                return $requests;
            });
        } catch (Throwable) { $responses=[]; }
        foreach($urls as $key=>$url) {
            $response=$responses[$key]??null;
            if($response instanceof \Illuminate\Http\Client\Response && $response->successful() && is_array($response->json())) {
                $raw=$response->json();
                $valid=$key==='roles'?isset($raw['rollegrupper']):isset($raw['status']);
                if($valid) {
                    $previous[$key]=['data'=>$this->minimize($raw),'fetched_at'=>now()->toIso8601String(),'source'=>$url,'last_attempt_at'=>now()->toIso8601String(),'available'=>true];
                    continue;
                }
            }
            $previous[$key]=array_merge($previous[$key]??[],['available'=>false,'last_attempt_at'=>now()->toIso8601String(),'source'=>$url]);
        }
        return $previous;
    }

    // Keep names, roles, combinations and interpretation status; no birth dates or identity numbers.
    private function minimize(array $data): array
    {
        $allowed=['rollegrupper','roller','type','kode','beskrivelse','person','navn','fornavn','mellomnavn','etternavn',
            'enhet','organisasjonsnummer','avregistrert','fratraadt','rekkefolge','sistEndret',
            'status','rutineStatus','regelStatus','kombinasjonStatus','regelIdent','tekstforklaring',
            'signeringsGrunnlag','muligeSigneringsRoller','personRolleGrunnlag','rolle','rolleFritekst',
            'signaturProkuraRoller','signaturProkuraFritekst','signeringsKombinasjon','kombinasjon','kombinasjonsId','personRolleKombinasjon'];
        $result=[];
        foreach($data as $key=>$value) {
            if(!is_int($key)&&!in_array($key,$allowed,true)) continue;
            $result[$key]=is_array($value)?$this->minimize($value):$value;
        }
        return $result;
    }

    public function profile(array $data): array
    {
        return array_intersect_key($data,array_flip(['address','postal_code','city','country','postal_address','postal_postal_code','postal_city','postal_country','website']));
    }
}
