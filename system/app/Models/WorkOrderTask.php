<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WorkOrderTask extends Model { protected $guarded=[]; protected $casts=['required'=>'boolean','completed'=>'boolean','completed_at'=>'datetime']; }
