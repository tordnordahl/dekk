<?php

namespace App\Services;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Crypt;

class StripeSettings
{
    public function get(): array
    {
        $defaults = config('services.stripe');
        $row = PlatformSetting::where('key', 'stripe.billing')->first();
        return $row ? array_replace($defaults, json_decode(Crypt::decryptString($row->encrypted_value), true, 512, JSON_THROW_ON_ERROR)) : $defaults;
    }

    public function save(array $values): void
    {
        $settings = $this->get();
        foreach (['secret', 'price_id', 'webhook_secret', 'portal_configuration_id', 'webhook_endpoint_id'] as $key) {
            if (filled($values[$key] ?? null)) $settings[$key] = trim($values[$key]);
        }
        PlatformSetting::updateOrCreate(['key' => 'stripe.billing'], [
            'encrypted_value' => Crypt::encryptString(json_encode($settings, JSON_THROW_ON_ERROR)),
        ]);
    }
}
