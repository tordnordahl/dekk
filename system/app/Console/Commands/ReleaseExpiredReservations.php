<?php
namespace App\Console\Commands;
use App\Models\StockReservation;use Illuminate\Console\Command;use Illuminate\Support\Facades\DB;
class ReleaseExpiredReservations extends Command{protected$signature='inventory:release-expired';protected$description='Frigir utløpte lagerreservasjoner';public function handle():int{$count=DB::transaction(fn()=>StockReservation::where('status','reserved')->whereNotNull('expires_at')->where('expires_at','<=',now())->update(['status'=>'released','updated_at'=>now()]));$this->info($count.' reservasjoner frigitt.');return self::SUCCESS;}}
