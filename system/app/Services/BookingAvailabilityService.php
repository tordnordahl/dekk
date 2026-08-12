<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\ServiceProduct;
use App\Models\ServiceSetting;
use App\Models\User;
use App\Models\WorkBay;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BookingAvailabilityService
{
    public function slots(int $organizationId, int $branchId, Collection $services, ?string $from = null, int $limit = 10): Collection
    {
        $settings = ServiceSetting::where('branch_id', $branchId)->first();
        $duration = max(5, (int) $services->sum('duration_minutes'));
        $notice = (int) ($settings?->minimum_booking_notice_hours ?? 2);
        $horizon = min(365, (int) ($settings?->booking_horizon_days ?? 120));
        $day = $from ? Carbon::parse($from)->startOfDay() : today();
        if ($day->lt(today())) $day = today();
        $lastDay = today()->addDays($horizon)->endOfDay();
        $existing = Booking::where('organization_id', $organizationId)->where('branch_id', $branchId)
            ->whereNotIn('status', ['cancelled', 'no_show'])->where('starts_at', '<=', $lastDay)
            ->where('ends_at', '>=', $day)->get(['starts_at', 'ends_at']);
        $closures = DB::table('branch_closures')->where('branch_id', $branchId)->where('starts_at', '<=', $lastDay)
            ->where('ends_at', '>=', $day)->get();
        $result = collect();

        while ($day->lte($lastDay) && $result->count() < $limit) {
            [$opens, $closes] = $this->hours($day, $settings?->weekly_hours);
            if (!$opens) { $day->addDay(); continue; }
            for ($slot = $day->copy()->setTimeFromTimeString($opens); $slot->copy()->addMinutes($duration)->lte($day->copy()->setTimeFromTimeString($closes)); $slot->addMinutes(15)) {
                $end = $slot->copy()->addMinutes($duration);
                if ($slot->lt(now()->addHours($notice))) continue;
                if ($closures->contains(fn ($item) => Carbon::parse($item->starts_at)->lt($end) && Carbon::parse($item->ends_at)->gt($slot))) continue;
                $capacity = $this->capacity($organizationId, $branchId, $slot, $end);
                $occupied = $existing->filter(fn ($booking) => $booking->starts_at->lt($end) && $booking->ends_at->gt($slot))->count();
                if ($capacity > $occupied) $result->push([
                    'starts_at' => $slot->format('Y-m-d\TH:i'), 'ends_at' => $end->format('Y-m-d\TH:i'),
                    'date' => $slot->translatedFormat('D d. M'), 'time' => $slot->format('H:i'), 'end_time' => $end->format('H:i'),
                    'available_capacity' => $capacity - $occupied,
                ]);
                if ($result->count() >= $limit) break;
            }
            $day->addDay();
        }
        return $result->unique('starts_at')->values();
    }

    public function stillAvailable(int $organizationId, int $branchId, Collection $services, Carbon $startsAt): bool
    {
        return $this->slots($organizationId, $branchId, $services, $startsAt->toDateString(), 100)
            ->contains('starts_at', $startsAt->format('Y-m-d\TH:i'));
    }

    private function hours(Carbon $day, ?array $weeklyHours): array
    {
        $defaults = [1=>['08:00','16:00'],2=>['08:00','16:00'],3=>['08:00','16:00'],4=>['08:00','16:00'],5=>['08:00','16:00']];
        $value = $weeklyHours[$day->dayOfWeekIso] ?? $weeklyHours[strtolower($day->format('D'))] ?? $defaults[$day->dayOfWeekIso] ?? null;
        if (!$value || ($value['closed'] ?? false)) return [null, null];
        return array_is_list($value) ? [$value[0], $value[1]] : [$value['open'] ?? null, $value['close'] ?? null];
    }

    private function capacity(int $organizationId, int $branchId, Carbon $start, Carbon $end): int
    {
        $bays = WorkBay::where('branch_id', $branchId)->where('active', true)->count();
        $technicians = User::where('organization_id', $organizationId)->where('branch_id', $branchId)->where('active', true)->where('role', 'technician')->count();
        $shifts = DB::table('employee_availabilities')->where('organization_id', $organizationId)->where('type', 'shift')
            ->where('starts_at', '<=', $start)->where('ends_at', '>=', $end)->distinct()->count('user_id');
        if ($shifts > 0) $technicians = $shifts;
        $absent = DB::table('employee_availabilities')->where('organization_id', $organizationId)->where('type', 'absence')
            ->where('starts_at', '<', $end)->where('ends_at', '>', $start)->distinct()->count('user_id');
        $technicians = max(0, $technicians - $absent);
        return $bays > 0 && $technicians > 0 ? min($bays, $technicians) : max(1, $bays, $technicians);
    }
}
