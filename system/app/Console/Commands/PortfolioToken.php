<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class PortfolioToken extends Command
{
    protected $signature = 'jovia:portfolio-token';

    protected $description = 'Generer separat lesetoken for Jovia Digital (endrer ikke konfigurasjonen)';

    public function handle(): int
    {
        $token = bin2hex(random_bytes(32));
        $this->warn('Hemmelig token vises én gang. Ikke del terminalutskriften eller sjekk token inn i Git.');
        $this->line('Token til admin-portalen: '.$token);
        $this->line('JOVIA_PORTFOLIO_TOKEN_HASH='.hash('sha256', $token));
        $this->info('Lagre bare hashen på DekkPilot-serveren. API-et er ikke aktivert av denne kommandoen.');

        return self::SUCCESS;
    }
}
