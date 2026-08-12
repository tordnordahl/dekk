<?php
namespace App\Models;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
class CheckoutPayment extends Model{protected$guarded=[];protected$casts=['expires_at'=>'datetime','paid_at'=>'datetime','invoiced_at'=>'datetime'];public function booking():BelongsTo{return$this->belongsTo(Booking::class);}public function invoiceExport():BelongsTo{return$this->belongsTo(InvoiceExport::class);}}
