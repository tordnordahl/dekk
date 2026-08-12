<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IntegrationSetting extends Model
{
    protected $guarded = [];
    protected $hidden = ['encrypted_credentials'];
    protected $casts = ['active' => 'boolean'];
}
