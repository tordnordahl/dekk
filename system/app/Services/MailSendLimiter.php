<?php
namespace App\Services;

use App\Exceptions\MailRateLimited;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Mime\Email;

// Webhosting limits: https://help.domeneshop.no/nb/articles/588854-hvor-mye-epost-kan-jeg-sende-fra-webhosting
class MailSendLimiter
{
    public function reserve(Email $email, array $settings): void
    {
        $native = in_array($settings['transport'] ?? '', ['native', 'sendmail'], true);
        if (($settings['transport'] ?? '') === 'log') return;
        if (!$native && strtolower(trim($settings['host'] ?? '')) === 'smtp.domeneshop.no') {
            throw new \RuntimeException('Automatisk utsending krever lokal servermail på Domeneshop. Endre transport i superadmin.');
        }
        $size = strlen($email->toString());
        if ($size > 100 * 1024 * 1024) throw new \RuntimeException('E-posten overskrider størrelsesgrensen.');
        $count = count(array_unique(array_map(fn ($address) => strtolower($address->getAddress()), [...$email->getTo(), ...$email->getCc(), ...$email->getBcc()])));
        // Queue entries contain one recipient; count all envelope recipients defensively.
        if ($native && $count > 1) throw new \RuntimeException('Masseutsending må deles i én kømelding per mottaker.');
        $minute = max(1, min($native ? 60 : 600, (int) ($settings['messages_per_minute'] ?? 60)));
        $lock = Cache::lock('mail:send-limit-lock', 10);
        if (!$lock->get()) throw new MailRateLimited();
        try {
            $now = (float) now()->format('U.u');
            $attempts = array_values(array_filter(Cache::get('mail:send-attempts', []), fn ($a) => $a['time'] > $now - 86400));
            $windows = $native ? [[1,1], [60,$minute], [3600,1500], [86400,5000]] : [[60,$minute]];
            foreach ($windows as [$seconds, $maximum]) {
                $recent = array_values(array_filter($attempts, fn ($a) => $a['time'] > $now - $seconds));
                if (array_sum(array_column($recent, 'count')) + $count > $maximum) {
                    throw new MailRateLimited(max(600, (int) ceil($recent[0]['time'] + $seconds - $now)));
                }
            }
            $last = end($attempts);
            // Large messages use at least two seconds; respect a lower administrator limit too.
            $gap = max(60 / $minute, ($native && ($size > 512 * 1024 || ($last['large'] ?? false))) ? 2 : 0);
            if ($last && $now - $last['time'] < $gap) throw new MailRateLimited();
            $attempts[] = ['time'=>$now, 'count'=>$count, 'large'=>$size > 512 * 1024];
            Cache::put('mail:send-attempts', $attempts, 86401);
        } finally {
            $lock->release();
        }
    }
}
