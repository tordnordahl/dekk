<?php

namespace App\Http\Middleware;

use App\Services\MailConfigurationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfigureMail
{
    public function __construct(private readonly MailConfigurationService $configuration)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->configuration->configure($request->user()?->organization_id);
        return $next($request);
    }
}
