<?php

namespace App\Services;

class PostalCodeService
{
    private static ?array $postalCodes = null;

    public function city(string $postalCode): ?string
    {
        $postalCode = trim($postalCode);
        if (! preg_match('/^\d{4}$/', $postalCode)) {
            return null;
        }

        return $this->all()[$postalCode] ?? null;
    }

    private function all(): array
    {
        if (self::$postalCodes !== null) {
            return self::$postalCodes;
        }

        self::$postalCodes = [];
        $path = resource_path('data/norwegian-postal-codes.tsv');
        $handle = is_file($path) ? fopen($path, 'rb') : false;
        if ($handle === false) {
            return self::$postalCodes;
        }

        while (($row = fgetcsv($handle, 0, "\t", '"', '\\')) !== false) {
            if (isset($row[0], $row[1]) && preg_match('/^\d{4}$/', $row[0])) {
                self::$postalCodes[$row[0]] = trim($row[1]);
            }
        }
        fclose($handle);

        return self::$postalCodes;
    }
}
