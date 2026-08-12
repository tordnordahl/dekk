<?php
namespace App\Models;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\SoftDeletes;
class ServiceProduct extends Model {use SoftDeletes;protected $guarded=[];protected $casts=['active'=>'boolean','vat_rate'=>'decimal:2'];}
