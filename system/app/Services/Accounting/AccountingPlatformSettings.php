<?php

namespace App\Services\Accounting;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AccountingPlatformSettings
{
    public function fiken(): array
    {
        return array_merge([
            'client_id' => config('services.fiken.client_id'),
            'client_secret' => config('services.fiken.client_secret'),
        ], $this->read('accounting.fiken.oauth'));
    }

    public function tripletex(): array
    {
        return array_merge([
            'consumer_token' => config('services.tripletex.consumer_token'),
            'test_consumer_token' => config('services.tripletex.test_consumer_token'),
        ], $this->read('accounting.tripletex'));
    }

    public function poweroffice(): array
    {
        return array_merge([
            'app_key' => config('services.poweroffice.app_key'),
            'subscription_key' => config('services.poweroffice.subscription_key'),
        ], $this->read('accounting.poweroffice'));
    }

    public function zettle(): array
    {
        return array_merge([
            'client_id' => config('services.zettle.client_id'),
            'client_secret' => config('services.zettle.client_secret'),
        ], $this->read('sales.zettle.oauth'));
    }

    public function save(string $key, array $values, int $userId): void
    {
        if (!Schema::hasTable('platform_settings')) {
            throw new \RuntimeException('Databaseoppdateringen for regnskapsintegrasjoner mangler. Superadmin må kjøre update.php før nøklene kan lagres.');
        }
        PlatformSetting::updateOrCreate(['key' => $key], [
            'encrypted_value' => Crypt::encryptString(json_encode($values, JSON_THROW_ON_ERROR)),
            'updated_by' => $userId,
        ]);
    }

    private function read(string $key): array
    {
        if (!Schema::hasTable('platform_settings')) return [];
        $row = PlatformSetting::where('key', $key)->first();
        if (!$row) return [];
        try { return json_decode(Crypt::decryptString($row->encrypted_value), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { return []; }
    }
}
