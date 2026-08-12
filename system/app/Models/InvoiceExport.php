<?php
namespace App\Models;use Illuminate\Database\Eloquent\Model;
class InvoiceExport extends Model{protected $guarded=[];protected $casts=['customer_snapshot'=>'array','lines'=>'array','provider_metadata'=>'array','queued_at'=>'datetime','exported_at'=>'datetime','failed_at'=>'datetime'];}
