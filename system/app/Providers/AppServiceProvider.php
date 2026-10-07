<?php

namespace App\Providers;

use App\Models\TireSet;
use App\Services\TestDataGuard;
use App\Services\TireHotelService;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Mail::extend('native', fn () => \Symfony\Component\Mailer\Transport::fromDsn('native://default'));

        TireSet::saved(function (TireSet $set): void {
            $isAtHotel = $set->received_at
                && in_array($set->status, ['received', 'stored', 'picked', 'workshop'], true);

            if ($isAtHotel && Schema::hasTable('hotel_agreements')) {
                app(TireHotelService::class)->ensureAgreement($set);
            }
        });

        // Front-end assets must follow the actual web request. This keeps CSS
        // and JavaScript working after a deployment even if an old ASSET_URL
        // from localhost was accidentally copied into production.
        if (! $this->app->runningInConsole()) {
            $request = request();
            $forwardedProto = strtolower((string) $request->headers->get('x-forwarded-proto'));
            $scheme = $request->isSecure() || str_contains($forwardedProto, 'https') ? 'https' : 'http';
            $baseUrl = rtrim($request->getBaseUrl(), '/');
            $this->app['url']->useAssetOrigin($scheme.'://'.$request->getHttpHost().$baseUrl);
        }

        Event::listen(MessageSending::class, function (MessageSending $event): ?bool {
            $guard = app(TestDataGuard::class);
            foreach ($event->message->getTo() as $recipient) {
                if ($guard->email($recipient->getAddress())) return false;
            }
            $settings = app(\App\Services\MailConfigurationService::class)->serverSettings();
            app(\App\Services\MailSendLimiter::class)->reserve($event->message, $settings);
            return null;
        });
    }
}
