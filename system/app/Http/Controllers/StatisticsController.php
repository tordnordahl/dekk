<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Quote;
use App\Models\ServiceSetting;
use App\Models\TireProduct;
use App\Models\TireSet;
use App\Models\User;
use App\Models\WorkBay;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StatisticsController extends Controller
{
    public function index(Request $request): View
    {
        $org=$request->user()->organization_id;$branch=$request->user()->branch_id;
        $settings=ServiceSetting::where('branch_id',$branch)->first();
        $minutesPerChange=(($settings?->minutes_per_wheel??8)*4)+($settings?->booking_buffer_minutes??5);
        $upcoming=Booking::where('organization_id',$org)->where('branch_id',$branch)->whereBetween('starts_at',[today(),today()->addDays(14)->endOfDay()])->whereNotIn('status',['cancelled','no_show'])->get();
        $dailyMinutes=$upcoming->groupBy(fn($booking)=>$booking->starts_at->toDateString())->map(fn($bookings)=>$bookings->sum(fn($booking)=>max(5,$booking->starts_at->diffInMinutes($booking->ends_at))));
        $storedCount=TireSet::where('organization_id',$org)->where('status','stored')->count();
        $planningMinutes=max((int)($dailyMinutes->max()??0),(int)ceil(($storedCount*$minutesPerChange)/20));
        $recommended=$planningMinutes>0?max(1,(int)ceil($planningMinutes/420)):0;
        $active=User::where('organization_id',$org)->where('branch_id',$branch)->where('active',true)->where('role','technician')->count();
        $bays=WorkBay::where('branch_id',$branch)->where('active',true)->count();
        $today=Booking::where('organization_id',$org)->where('branch_id',$branch)->whereDate('starts_at',today())->get();
        $confirmationBase=$upcoming->whereIn('confirmation_status',['pending','confirmed','declined']);
        $confirmed=$confirmationBase->where('confirmation_status','confirmed')->count();
        $confirmationRate=$confirmationBase->count()?(int)round($confirmed/$confirmationBase->count()*100):0;

        $sentPipelineCents=(int)Quote::where('organization_id',$org)->whereIn('status',['sent','viewed'])->sum('total_cents');
        $quotedSetIds=Quote::where('organization_id',$org)->whereNotNull('source_tire_set_id')->whereIn('status',['draft','sent','viewed','accepted'])->pluck('source_tire_set_id');
        $unsentSets=TireSet::where('organization_id',$org)->where('minimum_tread_depth','<',3)->whereNotNull('size')->whereNotIn('id',$quotedSetIds)->whereHas('vehicle.customer',fn($query)=>$query->whereNotNull('email'))->get(['id','size','season','quantity']);
        $keys=$unsentSets->map(fn($set)=>$set->size.'|'.$set->season)->unique();
        $prices=TireProduct::where('organization_id',$org)->where('active',true)->where('stock_quantity','>=',4)->whereIn('size',$unsentSets->pluck('size')->unique())->get()->filter(fn($product)=>$keys->contains($product->size.'|'.$product->season))->groupBy(fn($product)=>$product->size.'|'.$product->season)->map->max('price_cents');
        $unsentOpportunities=$unsentSets->filter(fn($set)=>$prices->has($set->size.'|'.$set->season));
        $unsentPotentialCents=(int)$unsentOpportunities->sum(fn($set)=>$prices[$set->size.'|'.$set->season]*max(4,(int)$set->quantity));
        $pipelineCents=$sentPipelineCents+$unsentPotentialCents;

        $top=Customer::where('organization_id',$org)->where('type','business')->withCount(['vehicles','bookings as completed_jobs_count'=>fn($q)=>$q->where('status','completed')])->withSum(['bookings as completed_revenue_cents'=>fn($q)=>$q->where('status','completed')],'agreed_price_cents')->withSum(['quotes as accepted_quote_cents'=>fn($q)=>$q->where('status','accepted')],'total_cents')->get()->map(function($customer){$customer->business_value_cents=(int)$customer->completed_revenue_cents+(int)$customer->accepted_quote_cents;return$customer;})->sortByDesc('business_value_cents')->take(5)->values();
        $state=$recommended===0?'Ingen belastning beregnet':($active>=$recommended?'Bemanningen ser god ut':'Flere teknikere anbefales');
        $detail=$bays===0?'Legg inn arbeidsbukker for å beregne kapasiteten ferdig.':($recommended>$bays?'Arbeidsbukkene blir flaskehalsen ved full bemanning.':'Bukkekapasiteten dekker anbefalt bemanning.');

        return view('statistics.index',[
            'customerCount'=>Customer::where('organization_id',$org)->count(),'businessCount'=>Customer::where('organization_id',$org)->where('type','business')->count(),'storedCount'=>$storedCount,
            'businessPulse'=>['pipelineCents'=>$pipelineCents,'sentPipelineCents'=>$sentPipelineCents,'unsentPotentialCents'=>$unsentPotentialCents,'unsentOpportunityCount'=>$unsentOpportunities->count(),'todayCompleted'=>$today->where('status','completed')->count(),'todayCount'=>$today->count(),'confirmationRate'=>$confirmationRate,'confirmedCount'=>$confirmed],
            'staffing'=>['recommended'=>$recommended,'active'=>$active,'bays'=>$bays,'minutes_per_change'=>$minutesPerChange,'peak_daily_minutes'=>$planningMinutes,'state'=>$state,'detail'=>$detail],
            'topBusinessCustomers'=>$top,
        ]);
    }
}
