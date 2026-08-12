<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ConversationMessage extends Model { protected $guarded=[]; protected $casts=['delivered_at'=>'datetime','read_at'=>'datetime']; }
