<?php
namespace App\Console\Commands;

use App\Services\BackupRetention;
use Illuminate\Console\Command;

class PruneBackups extends Command
{
    protected $signature='backup:prune {--dry-run : Vis antall uten å slette}';
    protected $description='Fjerner databasebackuper eldre enn 14 dager fra lokal mappe og konfigurert speil';
    public function handle(BackupRetention $retention): int
    {
        $dry=(bool)$this->option('dry-run');
        $result=$retention->prune($dry);
        $this->info(($dry?'Ville fjernet':'Fjernet').' '.$result['files'].' backuper eldre enn 14 dager ('.$result['bytes'].' byte).');
        return self::SUCCESS;
    }
}
