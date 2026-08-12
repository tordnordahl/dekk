<?php

namespace App\Services;

use App\Models\StockReservation;
use App\Models\TireProduct;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InventoryAvailabilityService
{
    public function reserved(int $productId): int
    {
        return (int) StockReservation::where('tire_product_id',$productId)->where('status','reserved')
            ->where(fn($q)=>$q->whereNull('expires_at')->orWhere('expires_at','>',now()))->sum('quantity');
    }

    public function available(TireProduct $product): int
    {
        return max(0,(int)$product->stock_quantity-$this->reserved($product->id));
    }

    public function reserve(int $quoteId,int $workOrderId,int $productId,int $organizationId,int $quantity): StockReservation
    {
        return DB::transaction(function()use($quoteId,$workOrderId,$productId,$organizationId,$quantity){
            $existing=StockReservation::where('quote_id',$quoteId)->where('tire_product_id',$productId)->lockForUpdate()->first();
            if($existing)return$existing;
            $product=TireProduct::where('organization_id',$organizationId)->lockForUpdate()->findOrFail($productId);
            if($this->available($product)<$quantity)throw new RuntimeException('Ikke nok tilgjengelige dekk. Andre aktive reservasjoner er trukket fra lageret.');
            return StockReservation::create(['organization_id'=>$organizationId,'work_order_id'=>$workOrderId,'quote_id'=>$quoteId,'tire_product_id'=>$productId,'quantity'=>$quantity,'status'=>'reserved','expires_at'=>now()->addDays(30)]);
        });
    }
}
