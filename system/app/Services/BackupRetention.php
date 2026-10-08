<?php
namespace App\Services;

use RuntimeException;

class BackupRetention
{
    public const DAYS = 14;

    public function prune(bool $dryRun=false): array
    {
        $directories=[storage_path('app/backups')];
        $mirror=trim((string)config('backups.mirror_path',''));
        if($mirror!=='') {
            if(!str_starts_with($mirror,DIRECTORY_SEPARATOR)) throw new RuntimeException('Speilmappen må være en absolutt sti.');
            $directories[]=$mirror;
        }
        $count=0;$bytes=0;$cutoff=now()->subDays(self::DAYS)->getTimestamp();
        foreach(array_unique($directories) as $directory) {
            if(!is_dir($directory)) continue;
            if(is_link($directory)||!is_readable($directory)) throw new RuntimeException('Backupmappe kan ikke leses trygt.');
            foreach(new \DirectoryIterator($directory) as $file) {
                if($file->isLink()||!$file->isFile()||!preg_match('/\Adekkpilot-\d{8}-\d{6}\.sql\.gz\z/',$file->getFilename())) continue;
                if($file->getMTime()>=$cutoff) continue;
                $size=$file->getSize();
                if(!$dryRun&&!unlink($file->getPathname())) throw new RuntimeException('Kunne ikke fjerne utløpt backup.');
                $count++;$bytes+=$size;
            }
        }
        return ['files'=>$count,'bytes'=>$bytes];
    }
}
