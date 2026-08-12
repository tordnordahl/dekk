<?php

namespace App\Services;

use App\Models\ServiceProduct;
use Illuminate\Support\Str;

class DefaultServiceCatalog
{
    public function seed(int $organizationId): void
    {
        foreach ($this->items() as $item) {
            ServiceProduct::firstOrCreate(
                ['organization_id' => $organizationId, 'code' => $item['code']],
                ['public_id' => (string) Str::uuid(), ...$item, 'active' => true]
            );
        }
    }

    public function items(): array
    {
        return [
            ['code'=>'HOTELL','name'=>'Dekkhotell','description'=>'Oppbevaring og administrasjon av kundens hjulsett.','category'=>'storage','fixed_price_cents'=>129900,'vat_rate'=>25,'duration_minutes'=>20],
            ['code'=>'SKIFT','name'=>'Sesongskift','description'=>'Skifte av fire komplette hjul.','category'=>'tire_change','fixed_price_cents'=>69900,'vat_rate'=>25,'duration_minutes'=>40],
            ['code'=>'PLUGG','name'=>'Plugging av dekk','description'=>'Kontroll og reparasjon av mindre punktering.','category'=>'repair','fixed_price_cents'=>59900,'vat_rate'=>25,'duration_minutes'=>30],
            ['code'=>'OMLEGG','name'=>'Omlegging og balansering','description'=>'Omlegging og balansering av fire dekk.','category'=>'workshop','fixed_price_cents'=>129900,'vat_rate'=>25,'duration_minutes'=>60],
            ['code'=>'BALANS','name'=>'Balansering av hjul','description'=>'Kontroll og balansering av hjul.','category'=>'workshop','fixed_price_cents'=>79900,'vat_rate'=>25,'duration_minutes'=>45],
            ['code'=>'VASK','name'=>'Hjulvask','description'=>'Grundig vask av kundens hjulsett.','category'=>'other','fixed_price_cents'=>29900,'vat_rate'=>25,'duration_minutes'=>20],
            ['code'=>'TPMS','name'=>'TPMS-service','description'=>'Kontroll og service av dekktrykksensorer.','category'=>'workshop','fixed_price_cents'=>49900,'vat_rate'=>25,'duration_minutes'=>30],
            ['code'=>'ETTER','name'=>'Etterstramming','description'=>'Kontroll og etterstramming av hjulbolter.','category'=>'other','fixed_price_cents'=>0,'vat_rate'=>25,'duration_minutes'=>10],
        ];
    }
}
