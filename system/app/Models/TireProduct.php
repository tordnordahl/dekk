<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class TireProduct extends Model { use SoftDeletes; protected $guarded = []; protected $casts = ['active'=>'boolean','studded'=>'boolean']; }
