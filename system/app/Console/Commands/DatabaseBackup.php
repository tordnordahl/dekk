<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class DatabaseBackup extends Command
{
    protected $signature = 'backup:database';
    protected $description = 'Tar komprimert MySQL-backup uten å eksponere passord i prosesslisten';

    public function handle(): int
    {
        if (config('database.default') !== 'mysql') {
            $this->error('Kun MySQL støttes av produksjonsbackupen.');
            return self::FAILURE;
        }
        $connection = config('database.connections.mysql');
        $directory = storage_path('app/backups');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Kunne ikke opprette backupmappe.');
        }
        $sql = $directory.'/dekkpilot-'.now()->format('Ymd-His').'.sql';
        $binary = $this->dumpBinary();
        if (!$binary) {
            $this->error('Fant ikke mysqldump. Sett absolutt sti i DB_DUMP_BINARY på serveren.');
            return self::FAILURE;
        }
        $command = [$binary, '--single-transaction', '--quick', '--skip-lock-tables', '--routines', '--triggers',
            '--host='.$connection['host'], '--port='.(string) $connection['port'], '--user='.$connection['username'],
            '--result-file='.$sql, $connection['database']];
        if (!empty($connection['unix_socket'])) $command[] = '--socket='.$connection['unix_socket'];
        $process = new Process($command, null, ['MYSQL_PWD'=>(string) $connection['password']], null, 300);
        $process->run();
        if (!$process->isSuccessful()) {
            if (is_file($sql)) unlink($sql);
            $this->error('Backup feilet: '.$process->getErrorOutput());
            return self::FAILURE;
        }
        $gz = $sql.'.gz';
        $input = fopen($sql, 'rb');
        $output = gzopen($gz, 'wb9');
        if (!$input || !$output) throw new RuntimeException('Komprimering av backup feilet.');
        while (!feof($input)) gzwrite($output, fread($input, 1024 * 1024));
        fclose($input); gzclose($output); unlink($sql); chmod($gz, 0600);
        $mirror=trim((string)config('backups.mirror_path',''));
        if($mirror!==''){
            if(!str_starts_with($mirror,DIRECTORY_SEPARATOR)){$this->error('BACKUP_MIRROR_PATH må være en absolutt sti.');return self::FAILURE;}
            if(!is_dir($mirror)&&!mkdir($mirror,0700,true)&&!is_dir($mirror)){$this->error('Kunne ikke opprette speilmappe for backup.');return self::FAILURE;}
            $target=rtrim($mirror,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($gz);
            if(!copy($gz,$target)||hash_file('sha256',$gz)!==hash_file('sha256',$target)){$this->error('Backup ble laget lokalt, men verifisert speilkopi feilet.');return self::FAILURE;}
            chmod($target,0600);
        }
        app(\App\Services\BackupRetention::class)->prune();
        $this->info(basename($gz).' · '.number_format(filesize($gz)).' bytes · SHA256 '.hash_file('sha256', $gz));
        return self::SUCCESS;
    }

    private function dumpBinary(): ?string
    {
        if (filled(env('DB_DUMP_BINARY'))) return (string) env('DB_DUMP_BINARY');
        $found = (new ExecutableFinder)->find('mysqldump');
        if ($found) return $found;
        foreach (['/Applications/MAMP/Library/bin/mysql80/bin/mysqldump','/Applications/MAMP/Library/bin/mysql57/bin/mysqldump','/usr/local/mysql/bin/mysqldump'] as $candidate) {
            if (is_executable($candidate)) return $candidate;
        }
        return null;
    }
}
