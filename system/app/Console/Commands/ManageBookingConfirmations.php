<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\ServiceSetting;
use App\Services\CommunicationService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ManageBookingConfirmations extends Command
{
    protected $signature = 'bookings:confirmations';
    protected $description = 'Påminn ubekreftede bookinger og kanseller dem etter svarfristen';

    public function handle(CommunicationService $communication): int
    {
        $reminded = 0; $cancelled = 0;
        Booking::with('customer')->where('confirmation_status', 'pending')->whereNull('confirmation_reminder_sent_at')->where('starts_at', '<=', now()->addDays(7))->where('starts_at', '>', now())->chunkById(100, function ($items) use ($communication, &$reminded) {
            foreach ($items as $booking) {
                $token = $this->rotateToken($booking); $deadline = now()->addHours(24);
                $body = "Viktig: Bekreft timen {$booking->starts_at->format('d.m.Y H:i')} innen 24 timer, ellers slettes reservasjonen.\n".route('booking.confirm.show', $token);
                if ($booking->customer->email) $communication->queue($booking->organization_id, $booking->customer, 'email', $booking->customer->email, 'Bekreft timen innen 24 timer', $body, 'transactional', $booking->id);
                $settings = ServiceSetting::where('branch_id', $booking->branch_id)->first();
                if ($booking->customer->phone && ($settings?->sms_enabled ?? false) && ($settings?->sms_booking_reminder_enabled ?? true)) $communication->queue($booking->organization_id, $booking->customer, 'sms', $booking->customer->phone, null, $body, 'transactional', $booking->id);
                $booking->update(['confirmation_reminder_sent_at' => now(), 'confirmation_deadline_at' => $deadline]); $reminded++;
            }
        });
        Booking::where('confirmation_status', 'pending')->whereNotNull('confirmation_deadline_at')->where('confirmation_deadline_at', '<', now())->chunkById(100, function ($items) use (&$cancelled) { foreach ($items as $booking) { $booking->update(['status' => 'cancelled', 'confirmation_status' => 'declined', 'confirmation_responded_at' => now()]); $cancelled++; } });
        $this->info("Påminnet: {$reminded}. Kansellert: {$cancelled}."); return self::SUCCESS;
    }

    private function rotateToken(Booking $booking): string
    {
        $plain = Str::random(64); $booking->update(['confirmation_token_hash' => hash('sha256', $plain)]); return $plain;
    }
}
