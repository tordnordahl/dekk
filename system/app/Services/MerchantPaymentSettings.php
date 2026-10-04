<?php
namespace App\Services;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Crypt;

class MerchantPaymentSettings
{
    public function get(int $org, string $provider): array
    {
        $row=IntegrationSetting::where('organization_id',$org)->where('provider','payment_'.$provider)->first();
        if (!$row) return ['active'=>false];
        return array_merge(json_decode(Crypt::decryptString($row->encrypted_credentials),true,512,JSON_THROW_ON_ERROR),['active'=>(bool)$row->active]);
    }

    public function save(int $org, string $provider, array $data, bool $active, int $user): void
    {
        unset($data['active']);
        IntegrationSetting::updateOrCreate(['organization_id'=>$org,'provider'=>'payment_'.$provider],[
            'encrypted_credentials'=>Crypt::encryptString(json_encode($data,JSON_THROW_ON_ERROR)),
            'active'=>$active,'updated_by'=>$user,
        ]);
    }
}
