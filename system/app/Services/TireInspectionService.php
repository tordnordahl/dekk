<?php
namespace App\Services;
use App\Models\TireInspection;use App\Models\TireSet;use Illuminate\Support\Facades\DB;use Illuminate\Support\Str;
class TireInspectionService{
 public function recordUniform(TireSet$set,float$depth,?int$userId=null,?int$dotYear=null,string$notes='Måling registrert.'):TireInspection{return DB::transaction(function()use($set,$depth,$userId,$dotYear,$notes){$depth=max(0,min(20,$depth));$status=$depth<3?'replace':($depth<4?'attention':'good');$inspection=TireInspection::create(['public_id'=>(string)Str::uuid(),'organization_id'=>$set->organization_id,'tire_set_id'=>$set->id,'inspected_by'=>$userId,'overall_status'=>$status,'notes'=>$notes,'inspected_at'=>now()]);foreach(['front_left','front_right','rear_left','rear_right']as$position)$inspection->measurements()->create(['position'=>$position,'tread_depth_mm'=>$depth,'dot_year'=>$dotYear??$set->dot_year,'tpms_status'=>'not_checked','tire_damage'=>false,'rim_damage'=>false,'uneven_wear'=>false]);$set->update(['minimum_tread_depth'=>$depth,'dot_year'=>$dotYear??$set->dot_year]);return$inspection;});}
 public function backfill(TireSet$set):?TireInspection{if($set->minimum_tread_depth===null||$set->inspections()->exists())return null;return$this->recordUniform($set,(float)$set->minimum_tread_depth,null,$set->dot_year,'Opprettet fra tidligere registrert mønsterdybde.');}
 public function recordNewTires(TireSet$set,?int$userId=null):TireInspection{return$this->recordUniform($set,8.0,$userId,now()->year,'Nye dekk montert. Startmåling 8,0 mm.');}
}
