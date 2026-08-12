<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class BillingStatement extends Model{protected $guarded=[];protected $casts=['period_start'=>'date','period_end'=>'date','finalized_at'=>'datetime'];}
