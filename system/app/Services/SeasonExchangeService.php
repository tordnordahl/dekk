<?php
namespace App\Services;
use App\Models\{TireSet,Vehicle,StorageLocation,TireInspection,User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SeasonExchangeService
{
    public function exchange(User $user, TireSet $outgoing, array $data): TireSet
    {
        return DB::transaction(function() use($user,$outgoing,$data) {
            // Serialize exchanges for a vehicle; repeat submissions cannot deliver the same set twice.
            Vehicle::whereKey($outgoing->vehicle_id)->lockForUpdate()->firstOrFail();
            $out=TireSet::where('organization_id',$user->organization_id)->lockForUpdate()->findOrFail($outgoing->id);
            if (!$out->received_at || $out->status==='delivered') $this->invalid('Hjulsettet er allerede utlevert eller ikke mottatt. Last siden på nytt.');
            $location=StorageLocation::where('organization_id',$user->organization_id)->where('branch_id',$user->branch_id)->where('active',true)->lockForUpdate()->findOrFail($data['storage_location_id']);
            $from=$out->only(['storage_location_id','storage_shelf_number','storage_position_number']);
            if ($data['incoming_mode']==='existing') {
                $in=TireSet::where('organization_id',$user->organization_id)->where('vehicle_id',$out->vehicle_id)->lockForUpdate()->findOrFail($data['incoming_id']);
                if ($in->id===$out->id || ($in->received_at && $in->status!=='delivered')) $this->invalid('Velg et annet hjulsett som er utlevert eller ennå ikke mottatt.');
            } else {
                $in=new TireSet(['organization_id'=>$user->organization_id,'vehicle_id'=>$out->vehicle_id,'public_id'=>Str::uuid(),'code'=>'HJ-'.Str::upper(Str::random(8)),'season'=>$data['season'],'kind'=>'complete_wheels','quantity'=>4,'manufacturer'=>$data['manufacturer']??null,'size'=>$data['size']??null,'winter_type'=>$data['season']==='winter'?($data['winter_type']??null):null,'hotel_notes'=>$data['hotel_notes']??null]);
            }
            $out->updateQuietly(['status'=>'delivered','delivered_at'=>now(),'storage_location_id'=>null,'storage_shelf_number'=>null,'storage_position_number'=>null]);
            $previous=$in->storage_location_id;
            $coordinates=app(WarehousePlacementService::class)->coordinates($location,$in->exists?$in:null,$data);
            $in->fill(['status'=>'stored','received_at'=>now(),'delivered_at'=>null,'storage_location_id'=>$location->id,...$coordinates,'minimum_tread_depth'=>null,'wash_status'=>'needed','washed'=>false,'bagged'=>false,'label_printed_at'=>null]);
            $in->saveQuietly();
            if ($data['incoming_mode']==='new') {
                $minimum=(float)collect($data['wheels'])->min('tread_depth_mm');
                $inspection=TireInspection::create(['public_id'=>Str::uuid(),'organization_id'=>$user->organization_id,'tire_set_id'=>$in->id,'inspected_by'=>$user->id,'overall_status'=>TreadAssessment::status($minimum,$in->season),'inspected_at'=>now(),'notes'=>'Mottatt ved sesongbytte.']);
                foreach($data['wheels'] as $wheel) $inspection->measurements()->create([...$wheel,'tpms_status'=>'not_checked','tire_damage'=>false,'rim_damage'=>false,'uneven_wear'=>false]);
                $in->updateQuietly(['minimum_tread_depth'=>$minimum]);
            }
            foreach([[$out,$from['storage_location_id'],null],[$in,$previous,$location->id]] as [$set,$old,$new]) {
                DB::table('storage_location_movements')->insert(['organization_id'=>$user->organization_id,'tire_set_id'=>$set->id,'from_location_id'=>$old,'to_location_id'=>$new,'moved_by'=>$user->id,'reason'=>'season_exchange','moved_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            }
            DB::table('audit_logs')->insert(['organization_id'=>$user->organization_id,'user_id'=>$user->id,'action'=>'tire_set.season_exchanged','subject_type'=>TireSet::class,'subject_id'=>$out->id,'metadata'=>json_encode(['out'=>$out->id,'in'=>$in->id,'from'=>$from,'to'=>['storage_location_id'=>$location->id,...$coordinates]]),'created_at'=>now()]);
            // Exchange does not create a new agreement, invoice or charge. Existing vehicle agreement continues.
            return $in;
        },3);
    }
    private function invalid(string $message): never { throw ValidationException::withMessages(['exchange'=>$message]); }
}
