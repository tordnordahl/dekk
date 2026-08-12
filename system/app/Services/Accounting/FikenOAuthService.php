<?php

namespace App\Services\Accounting;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FikenOAuthService
{
    public function __construct(private readonly AccountingPlatformSettings $settings) {}

    public function configured(): bool
    {
        $settings = $this->settings->fiken();
        return filled($settings['client_id'] ?? null) && filled($settings['client_secret'] ?? null);
    }

    public function clientId(): string { return (string) ($this->settings->fiken()['client_id'] ?? ''); }

    public function exchange(string $code, string $state): array
    {
        return $this->token(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>route('admin.accounting.fiken.callback'),'state'=>$state]);
    }

    public function refresh(IntegrationSetting $setting, array $credentials): array
    {
        if (($credentials['auth_mode'] ?? null) !== 'oauth' || empty($credentials['refresh_token'])) return $credentials;
        if (!empty($credentials['expires_at']) && now()->addMinutes(5)->lt(\Carbon\Carbon::parse($credentials['expires_at']))) return $credentials;
        $fresh = $this->token(['grant_type'=>'refresh_token','refresh_token'=>$credentials['refresh_token']]);
        $credentials['api_key']=$fresh['access_token'];
        $credentials['refresh_token']=$fresh['refresh_token'] ?? $credentials['refresh_token'];
        $credentials['expires_at']=now()->addSeconds(max(60,(int)($fresh['expires_in']??3600)))->toIso8601String();
        $setting->update(['encrypted_credentials'=>Crypt::encryptString(json_encode($credentials,JSON_THROW_ON_ERROR))]);
        return $credentials;
    }

    private function token(array $form): array
    {
        if (!$this->configured()) throw new RuntimeException('Fiken OAuth er ikke konfigurert av plattformeier.');
        $settings=$this->settings->fiken();
        $response=Http::asForm()->withBasicAuth((string)$settings['client_id'],(string)$settings['client_secret'])->timeout(20)->post('https://fiken.no/oauth/token',$form);
        if(!$response->successful()||!$response->json('access_token'))throw new RuntimeException('Fiken-autorisasjon feilet: '.mb_substr((string)($response->json('error_description')?:$response->body()),0,500));
        return $response->json();
    }
}
