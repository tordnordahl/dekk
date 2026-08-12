<?php

namespace App\Services;

use App\Models\IntegrationSetting;
use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

class MailConfigurationService
{
    public function serverSettings(): array
    {
        $defaults = [
            'transport' => config('mail.default', 'log'),
            'host' => config('mail.mailers.smtp.host'),
            'port' => config('mail.mailers.smtp.port', 587),
            'security' => ((int) config('mail.mailers.smtp.port') === 465 ? 'ssl' : 'tls'),
            'username' => config('mail.mailers.smtp.username'),
            'password' => null,
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name', 'DekkPilot'),
            'messages_per_minute' => 60,
        ];
        if (!Schema::hasTable('platform_settings')) return $defaults;
        $row = PlatformSetting::where('key', 'mail.server')->first();
        if (!$row) return $defaults;
        try { return array_merge($defaults, json_decode(Crypt::decryptString($row->encrypted_value), true, 512, JSON_THROW_ON_ERROR)); }
        catch (Throwable) { return $defaults; }
    }

    public function tenantSettings(?int $organizationId): array
    {
        if (!$organizationId || !Schema::hasTable('integration_settings')) return [];
        $row = IntegrationSetting::where('organization_id', $organizationId)->where('provider', 'email_sender')->where('active', true)->first();
        if (!$row) return [];
        try { return json_decode(Crypt::decryptString($row->encrypted_credentials), true, 512, JSON_THROW_ON_ERROR); }
        catch (Throwable) { return []; }
    }

    public function configure(?int $organizationId = null): array
    {
        $server = $this->serverSettings();
        $tenant = $this->tenantSettings($organizationId);
        $transport = ($server['transport'] ?? null) === 'sendmail' ? 'native' : (in_array($server['transport'] ?? '', ['smtp', 'native', 'log'], true) ? $server['transport'] : 'log');
        $scheme = ($server['security'] ?? 'tls') === 'ssl' ? 'smtps' : null;
        $native = $transport === 'native';
        config([
            'mail.default' => $transport,
            'mail.mailers.smtp.host' => $server['host'] ?? null,
            'mail.mailers.smtp.port' => (int) ($server['port'] ?? 587),
            'mail.mailers.smtp.scheme' => $scheme,
            'mail.mailers.smtp.username' => $server['username'] ?? null,
            'mail.mailers.smtp.password' => $server['password'] ?? null,
            'mail.from.address' => $native ? ($server['from_address'] ?? 'noreply@localhost') : ($tenant['from_address'] ?? $server['from_address'] ?? 'noreply@localhost'),
            'mail.from.name' => $native ? ($server['from_name'] ?? 'DekkPilot') : ($tenant['from_name'] ?? $server['from_name'] ?? 'DekkPilot'),
            'mail.reply_to.address' => $tenant['reply_to'] ?? ($native ? ($tenant['from_address'] ?? null) : null),
        ]);
        Mail::purge();
        return ['server' => $server, 'tenant' => $tenant, 'active_from' => config('mail.from.address')];
    }
}
