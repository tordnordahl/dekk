<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);
        $middleware->appendToGroup('web', \App\Http\Middleware\ConfigureMail::class);
        $middleware->validateCsrfTokens(except: ['webhooks/twilio/*']);
        $middleware->alias(['api.token' => \App\Http\Middleware\ApiToken::class, '2fa'=>\App\Http\Middleware\EnsureTwoFactorConfirmed::class, 'admin' => \App\Http\Middleware\AdminOnly::class, 'subscribed'=>\App\Http\Middleware\EnsureSubscribed::class, 'superadmin'=>\App\Http\Middleware\SuperAdminOnly::class, 'impersonate'=>\App\Http\Middleware\ImpersonateTenant::class, 'demo.readonly'=>\App\Http\Middleware\DemoReadOnly::class]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('quotes:follow-up')->dailyAt('09:00')->withoutOverlapping();
        $schedule->command('bookings:confirmations')->hourly()->withoutOverlapping();
        $schedule->command('communications:process')->everyMinute()->withoutOverlapping();
        $schedule->command('accounting:process')->everyMinute()->withoutOverlapping();
        // Dagens ubetalte jobber faktureres samlet etter stengetid.
        $schedule->command('checkout:invoice-expired')->dailyAt('23:55')->withoutOverlapping();
        $schedule->command('billing:prepare')->monthlyOn(1, '02:30')->withoutOverlapping();
        $schedule->command('backup:database')->dailyAt('03:15')->withoutOverlapping();
        $schedule->command('system:health')->everyFiveMinutes()->withoutOverlapping();
        $schedule->command('inventory:release-expired')->hourly()->withoutOverlapping();
        $schedule->command('accounting:verify')->dailyAt('04:00')->withoutOverlapping();
        $schedule->command('privacy:cleanup')->dailyAt('04:30')->withoutOverlapping();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
