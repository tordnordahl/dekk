<?php
namespace App\Console\Commands;use App\Services\HotelChargeService;use Illuminate\Console\Command;
class GenerateHotelCharges extends Command{protected$signature='hotel:generate-charges';protected$description='Oppretter ubetalte halvårskrav for forfalte dekkhotellavtaler';public function handle(HotelChargeService$service):int{$this->info($service->generate().' nye halvårskrav opprettet.');return self::SUCCESS;}}
