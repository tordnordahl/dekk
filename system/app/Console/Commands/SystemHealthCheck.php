<?php
namespace App\Console\Commands;
use App\Models\InvoiceExport;use App\Models\OutboundMessage;use App\Services\SystemOperationsService;use Illuminate\Console\Command;use Illuminate\Support\Facades\Cache;use Illuminate\Support\Facades\DB;
class SystemHealthCheck extends Command{
 protected$signature='system:health';protected$description='Kontrollerer database, lagring, backup, køer og regnskapseksport';
 public function handle(SystemOperationsService$ops):int{$issues=[];
  try{DB::select('select 1');}catch(\Throwable){$issues[]='Databasen svarer ikke.';}
  $probe=storage_path('framework/cache/health-'.getmypid());if(@file_put_contents($probe,'ok')===false)$issues[]='Lagringsområdet er ikke skrivbart.';else @unlink($probe);
  $stuckMessages=OutboundMessage::where('status','processing')->where('updated_at','<',now()->subMinutes(15))->update(['status'=>'queued','scheduled_at'=>now(),'last_error'=>'Automatisk hentet tilbake etter fastlåst behandling.']);
  $stuckInvoices=InvoiceExport::where('status','processing')->where('updated_at','<',now()->subMinutes(15))->update(['status'=>'queued','queued_at'=>now(),'last_error'=>'Automatisk hentet tilbake etter fastlåst behandling.']);
  if($stuckMessages)$issues[]=$stuckMessages.' fastlåste meldinger ble lagt tilbake i kø.';if($stuckInvoices)$issues[]=$stuckInvoices.' fastlåste fakturaer ble lagt tilbake i kø.';
  $oldMessages=OutboundMessage::where('status','queued')->where('scheduled_at','<',now()->subMinutes(25))->count();$oldInvoices=InvoiceExport::where('status','queued')->where('queued_at','<',now()->subMinutes(25))->count();
  if($oldMessages)$issues[]=$oldMessages.' meldinger har ventet mer enn 25 minutter.';if($oldInvoices)$issues[]=$oldInvoices.' fakturaer har ventet mer enn 25 minutter.';
  $invalidExports=InvoiceExport::where('status','exported')->whereNull('external_id')->count();if($invalidExports)$issues[]=$invalidExports.' eksporterte fakturaer mangler ekstern ID og må avstemmes.';
  $files=glob(storage_path('app/backups/dekkpilot-*.sql.gz'))?:[];usort($files,fn($a,$b)=>filemtime($b)<=>filemtime($a));$latest=$files[0]??null;$backupAge=$latest?(int)floor((time()-filemtime($latest))/3600):null;
  if(!$latest)$issues[]='Ingen databasebackup er funnet.';elseif($backupAge>30)$issues[]='Siste databasebackup er eldre enn 30 timer.';elseif(!$this->validGzip($latest))$issues[]='Siste databasebackup kan ikke leses som gzip.';
  $free=@disk_free_space(storage_path());$payload=['checked_at'=>now()->toIso8601String(),'healthy'=>$issues===[],'issues'=>$issues,'stuck_messages_recovered'=>$stuckMessages,'stuck_invoices_recovered'=>$stuckInvoices,'backup_age_hours'=>$backupAge,'backup_file'=>$latest?basename($latest):null,'backup_sha256'=>$latest?hash_file('sha256',$latest):null,'disk_free_human'=>$free!==false?$this->bytes((float)$free):'Ukjent'];
  Cache::put('system.health',$payload,now()->addMinutes(10));$ops->put('system.health',$payload);$this->line(json_encode($payload));return$issues===[]?self::SUCCESS:self::FAILURE;}
 private function validGzip(string$file):bool{$handle=@gzopen($file,'rb');if(!$handle)return false;$data=@gzread($handle,256);gzclose($handle);return is_string($data)&&str_contains($data,'--');}
 private function bytes(float$bytes):string{foreach(['B','KB','MB','GB','TB']as$unit){if($bytes<1024)return number_format($bytes,1,',',' ').' '.$unit;$bytes/=1024;}return number_format($bytes,1,',',' ').' PB';}
}
