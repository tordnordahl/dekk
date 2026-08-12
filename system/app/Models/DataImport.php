<?php
namespace App\Models;use Illuminate\Database\Eloquent\Model;
class DataImport extends Model{protected $guarded=[];protected $casts=['headers'=>'array','preview'=>'array','mapping'=>'array','errors'=>'array','completed_at'=>'datetime'];}
