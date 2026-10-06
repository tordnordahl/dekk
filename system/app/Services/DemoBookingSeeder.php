<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkBay;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DemoBookingSeeder
{
    public function seed(Organization $organization, Branch $branch, bool $refreshFuture = true): array
    {
        $customers=$organization->customers()->with('vehicles')->get()->filter(fn($customer)=>$customer->vehicles->isNotEmpty())->values();
        if($customers->isEmpty())throw new RuntimeException('Demomiljøet mangler kunder med kjøretøy.');
        $technicians=User::where('is_super_admin',false)->where('organization_id',$organization->id)->where('branch_id',$branch->id)->where('role','technician')->where('active',true)->get();
        $bays=WorkBay::where('organization_id',$organization->id)->where('branch_id',$branch->id)->where('active',true)->get();

        return DB::transaction(function()use($organization,$branch,$customers,$technicians,$bays,$refreshFuture){
            if($refreshFuture)Booking::where('organization_id',$organization->id)->where('reference','like','DEMO-CAL-%')->delete();
            $future=0;$historical=0;
            $weekdaySlots=[[8,0],[8,10],[8,20],[9,15],[9,25],[10,30],[11,30],[12,15],[13,30],[14,45],[15,0],[16,0]];
            $weekendSlots=[[9,0],[10,0],[11,15],[12,30],[14,0]];
            for($offset=0;$offset<7;$offset++){
                $date=now('Europe/Oslo')->startOfDay()->addDays($offset);$slots=$date->isWeekend()?$weekendSlots:$weekdaySlots;
                foreach($slots as$slotIndex=>[$hour,$minute]){$start=$date->copy()->setTime($hour,$minute);$customer=$customers[($offset*13+$slotIndex)%$customers->count()];$this->upsert($organization,$branch,$customer,$technicians,$bays,'DEMO-CAL-'.$start->format('Ymd-Hi').'-'.$slotIndex,$start,$slotIndex,'scheduled',$slotIndex%5===0?'pending':'confirmed','[DEMO] Rullerende og hektisk syvdagerskalender.');$future++;}
            }
            if(!Booking::where('organization_id',$organization->id)->where('reference','like','DEMO-HIST-%')->exists()){
                $statuses=['completed','completed','completed','cancelled','no_show','completed'];
                for($i=1;$i<=72;$i++){$start=now('Europe/Oslo')->subDays(($i%90)+1)->setTime(8+(($i%8)),($i%4)*15);$customer=$customers[($i*7)%$customers->count()];$status=$statuses[$i%count($statuses)];$this->upsert($organization,$branch,$customer,$technicians,$bays,'DEMO-HIST-'.str_pad((string)$i,3,'0',STR_PAD_LEFT),$start,$i,$status,'confirmed','[DEMO] Historisk booking for rapporter og kundehistorikk.');$historical++;}
            }else $historical=Booking::where('organization_id',$organization->id)->where('reference','like','DEMO-HIST-%')->count();
            return ['future'=>$future,'historical'=>$historical];
        });
    }

    private function upsert(Organization$organization,Branch$branch,$customer,$technicians,$bays,string$reference,$start,int$seed,string$status,string$confirmation,string$notes):void
    {
        $services=['Dekkhotell og sesongskift','Sesongskift','Kontroll og balansering','Hjulvask og kontroll'];
        $booking=Booking::firstOrNew(['organization_id'=>$organization->id,'reference'=>$reference]);
        if(!$booking->public_id)$booking->public_id=(string)Str::uuid();
        $booking->fill(['branch_id'=>$branch->id,'customer_id'=>$customer->id,'vehicle_id'=>$customer->vehicles->first()->id,'assigned_user_id'=>$technicians->isNotEmpty()?$technicians[$seed%$technicians->count()]->id:null,'work_bay_id'=>$bays->isNotEmpty()?$bays[$seed%$bays->count()]->id:null,'service_name'=>$services[$seed%count($services)],'agreed_price_cents'=>[69900,89900,129900,49900][$seed%4],'starts_at'=>$start,'ends_at'=>$start->copy()->addMinutes([35,45,55][$seed%3]),'status'=>$status,'confirmation_status'=>$confirmation,'confirmation_responded_at'=>$confirmation==='confirmed'?$start->copy()->subDays(2):null,'notes'=>$notes])->save();
    }
}
