<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class VehicleLookupService
{
    public function lookup(string $registrationNumber, int $organizationId): array
    {
        $setting = IntegrationSetting::where('organization_id', $organizationId)->where('provider', 'vegvesen')->where('active', true)->first();
        $apiKey = $setting ? (string) data_get(json_decode(Crypt::decryptString($setting->encrypted_credentials), true), 'api_key') : (string) config('services.vegvesen.api_key');
        if ($apiKey === '') {
            throw new RuntimeException('Kjøretøyoppslag er klart, men API-nøkkel fra Statens vegvesen mangler. Legg den inn under Administrasjon → Team og drift → Statens vegvesen – biloppslag.');
        }

        $registrationNumber = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $registrationNumber));
        $response = Http::acceptJson()
            ->withHeaders(['SVV-Authorization' => 'Apikey '.$apiKey])
            ->timeout(10)
            ->retry(2, 200)
            ->get((string) config('services.vegvesen.endpoint'), ['kjennemerke' => $registrationNumber]);

        if ($response->status() === 404) throw new RuntimeException('Fant ingen bil med dette registreringsnummeret.');
        if (!$response->successful()) throw new RuntimeException('Statens vegvesen svarte ikke som forventet. Prøv igjen senere.');

        $vehicle = data_get($response->json(), 'kjoretoydataListe.0');
        if (!$vehicle) throw new RuntimeException('Fant ingen tekniske kjøretøydata.');
        $technical = data_get($vehicle, 'godkjenning.tekniskGodkjenning.tekniskeData', []);
        $firstRegistration = data_get($vehicle, 'forstegangsregistrering.registrertForstegangNorgeDato');
        $tireDimensions = $this->valuesForKeys($technical, ['dekkdimensjon', 'dekkDimensjon']);
        $rimDimensions = $this->valuesForKeys($technical, ['felgdimensjon', 'felgDimensjon']);

        return [
            'registration_number' => $registrationNumber,
            'make' => data_get($technical, 'generelt.merke.0.merke'),
            'model' => data_get($technical, 'generelt.handelsbetegnelse.0'),
            'model_year' => $firstRegistration ? (int) substr((string) $firstRegistration, 0, 4) : null,
            'vin' => data_get($technical, 'generelt.understellsnummer'),
            'recommended_tire_size' => $tireDimensions[0] ?? null,
            'tire_dimensions' => $tireDimensions,
            'rim_dimensions' => $rimDimensions,
        ];
    }

    private function valuesForKeys(array $data, array $wanted): array
    {
        $values = [];
        $walk = function (mixed $node) use (&$walk, &$values, $wanted): void {
            if (!is_array($node)) return;
            foreach ($node as $key => $value) {
                if (in_array((string) $key, $wanted, true)) {
                    foreach (is_array($value) ? $value : [$value] as $dimension) {
                        if (is_scalar($dimension) && trim((string) $dimension) !== '') $values[] = trim((string) $dimension);
                    }
                }
                if (is_array($value)) $walk($value);
            }
        };
        $walk($data);
        return array_values(array_unique($values));
    }
}
