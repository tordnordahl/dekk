<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $guarded = [];
    protected $hidden = ['encrypted_value'];
}
