<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ServiceSetting extends Model { protected $guarded = []; protected $casts=['weekly_hours'=>'array','sms_enabled'=>'boolean','sms_booking_confirmation_enabled'=>'boolean','sms_booking_reminder_enabled'=>'boolean','sms_marketing_enabled'=>'boolean','label_reminders_enabled'=>'boolean']; }
