<?php
namespace App\Console\Commands;

use App\Models\Quote;
use App\Models\ServiceSetting;
use App\Models\TireProduct;
use App\Models\TireSet;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SendTireFollowUps extends Command
{
    protected $signature = 'quotes:follow-up {--dry-run : Vis treff uten å sende}';
    protected $description = 'Send sikre dekktilbud til kunder med slitte eller gamle dekk';

    public function handle(): int
    {
        $sent=0; $skipped=0;
        TireSet::with(['vehicle.customer'])->whereNotNull('size')->where(function($q){$q->where('minimum_tread_depth','<',3)->orWhere('dot_year','<=',now()->year-8);})->chunkById(100,function($sets)use(&$sent,&$skipped){
            foreach($sets as $set){
                $customer=$set->vehicle?->customer;
                if(!$customer?->email || str_starts_with((string)$customer->notes,'[DUMMY]')){$skipped++;continue;}
                $recent=Quote::where('organization_id',$set->organization_id)->where('vehicle_id',$set->vehicle_id)->whereIn('status',['draft','sent','viewed','accepted'])->where('created_at','>=',now()->subDays(90))->exists();
                if($recent){$skipped++;continue;}
                $product=TireProduct::where('organization_id',$set->organization_id)->where('active',true)->where('stock_quantity','>',0)->where('size',$set->size)->where('season',$set->season)->orderBy('price_cents')->first();
                if(!$product){$skipped++;continue;}
                if($this->option('dry-run')){$this->line("{$customer->name}: {$set->size} → {$product->brand} {$product->model}");$sent++;continue;}
                $plain=Str::random(64);$quantity=max(1,$set->quantity);$total=$product->price_cents*$quantity;
                $days=ServiceSetting::where('branch_id',$customer->branch_id)->value('quote_expiry_days')??14;
                $quote=DB::transaction(function()use($set,$customer,$product,$plain,$quantity,$total,$days){$quote=Quote::create(['public_id'=>(string)Str::uuid(),'organization_id'=>$set->organization_id,'branch_id'=>$customer->branch_id,'customer_id'=>$customer->id,'vehicle_id'=>$set->vehicle_id,'reference'=>'T-'.now()->format('ymd').'-'.strtoupper(Str::random(5)),'access_token_hash'=>hash('sha256',$plain),'subtotal_cents'=>$total,'vat_cents'=>0,'total_cents'=>$total,'message'=>'Vi anbefaler utskifting basert på registrert mønsterdybde eller dekkenes alder.','expires_at'=>now()->addDays($days)]);$quote->items()->create(['tire_product_id'=>$product->id,'description'=>"{$product->brand} {$product->model} {$product->size}",'quantity'=>$quantity,'unit_price_cents'=>$product->price_cents,'line_total_cents'=>$total]);return $quote->load(['customer','vehicle','items']);});
                try{app(\App\Services\CommunicationService::class)->queueQuote($quote,$plain);$sent++;}catch(\Throwable $e){report($e);$skipped++;}
            }
        });
        $this->info("Lagt i kø/klare: {$sent}. Hoppet over: {$skipped}.");
        return self::SUCCESS;
    }
}
