<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WorkBay extends Model { protected $guarded = []; protected $casts = ['active' => 'boolean']; }
