<?php

namespace App\Services;

use Illuminate\Database\Migrations\Migrator;
use Throwable;

class DatabaseUpdateStatus
{
    public function __construct(private readonly Migrator $migrator) {}

    public function inspect(): array
    {
        try {
            $files = $this->migrator->getMigrationFiles(database_path('migrations'));
            $ran = $this->migrator->repositoryExists() ? $this->migrator->getRepository()->getRan() : [];
            $pending = array_values(array_diff(array_keys($files), $ran));
            return ['available' => true, 'current' => $pending === [], 'pending' => $pending, 'ran_count' => count($ran), 'error' => null];
        } catch (Throwable $exception) {
            report($exception);
            return ['available' => false, 'current' => false, 'pending' => [], 'ran_count' => 0, 'error' => 'Databasestatus kunne ikke leses. Kontroller databasetilkoblingen og loggen.'];
        }
    }
}
