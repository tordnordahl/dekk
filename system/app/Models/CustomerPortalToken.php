<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class CustomerPortalToken extends Model { protected $guarded=[]; protected $hidden=['token_hash']; protected $casts=['expires_at'=>'datetime','last_used_at'=>'datetime','revoked_at'=>'datetime']; }
