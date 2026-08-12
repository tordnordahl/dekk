<?php
namespace App\Services;
use Symfony\Component\Process\Process;
use RuntimeException;

class GitDeploymentService
{
    private string $path;private string $git;private string $remote;
    public function __construct(){ $this->path=(string)config('deployment.repository_path');$this->git=(string)config('deployment.git_binary');$this->remote=(string)config('deployment.remote','origin'); }
    public function inspect(bool $fetch=false):array
    {
        if(!is_dir($this->path)||!is_dir($this->path.'/.git'))return['available'=>false,'error'=>'Prosjektmappen er ikke et Git-repository.','path'=>$this->path];
        try{
            $root=trim($this->run(['rev-parse','--show-toplevel']));if(realpath($root)!==realpath($this->path))throw new RuntimeException('Git-roten stemmer ikke med konfigurert prosjektmappe.');
            $branch=(string)(config('deployment.branch')?:trim($this->run(['branch','--show-current'])));if(!preg_match('/^[A-Za-z0-9._\/-]+$/',$branch))throw new RuntimeException('Ugyldig Git-gren.');
            $url=trim($this->run(['remote','get-url',$this->remote]));if(!preg_match('~^(?:https://github\.com/|git@github\.com:)~i',$url))throw new RuntimeException('Av sikkerhetsgrunner tillates bare GitHub som remote.');
            $dirty=trim($this->run(['status','--porcelain','--untracked-files=no']))!=='';
            if($fetch)$this->run(['-c','core.hooksPath=/dev/null','fetch','--prune',$this->remote,$branch],120);
            $remoteRef='refs/remotes/'.$this->remote.'/'.$branch;$remoteExists=$this->ok(['show-ref','--verify','--quiet',$remoteRef]);
            $ahead=$behind=0;$files=[];
            if($remoteExists){$counts=preg_split('/\s+/',trim($this->run(['rev-list','--left-right','--count','HEAD...'.$remoteRef])));$ahead=(int)($counts[0]??0);$behind=(int)($counts[1]??0);if($behind>0)$files=array_values(array_filter(explode("\n",trim($this->run(['diff','--name-only','HEAD..'.$remoteRef])))));}
            return['available'=>true,'path'=>$root,'branch'=>$branch,'remote'=>$this->remote,'remote_url'=>$this->maskUrl($url),'dirty'=>$dirty,'ahead'=>$ahead,'behind'=>$behind,'files'=>$files,'vendor_required'=>in_array('system/composer.lock',$files,true)||in_array('composer.lock',$files,true),'database_required'=>(bool)array_filter($files,fn($f)=>str_starts_with($f,'system/database/migrations/')),'checked_remote'=>$fetch,'head'=>trim($this->run(['rev-parse','--short','HEAD']))];
        }catch(\Throwable $e){return['available'=>false,'error'=>$e->getMessage(),'path'=>$this->path];}
    }
    public function deploy():array
    {
        $before=$this->inspect(true);if(!($before['available']??false))throw new RuntimeException($before['error']??'Git-kontrollen feilet.');
        if($before['dirty'])throw new RuntimeException('Serveren har lokale endringer. Oppdateringen er stoppet for å unngå overskriving.');
        if($before['ahead']>0)throw new RuntimeException('Serveren har commits som ikke finnes på GitHub. Oppdateringen er stoppet.');
        if(array_filter($before['files'],fn($f)=>preg_match('~(^|/)(\.env(?:\.|$)|storage/|public/storage/)~',$f)))throw new RuntimeException('Oppdateringen forsøker å endre en beskyttet miljø- eller lagringsfil og er derfor stoppet.');
        if($before['vendor_required'])throw new RuntimeException('Oppdateringen endrer composer.lock og krever ny vendor-mappe. Last opp vendor manuelt før denne versjonen aktiveres.');
        if($before['behind']<1)return$before+['updated'=>false];
        $this->run(['-c','core.hooksPath=/dev/null','merge','--ff-only','refs/remotes/'.$this->remote.'/'.$before['branch']],120);
        return array_merge($this->inspect(false),['updated'=>true,'previous_head'=>$before['head'],'updated_files'=>$before['files'],'database_required'=>$before['database_required']]);
    }
    private function run(array $args,int $timeout=30):string{$p=new Process([$this->git,...$args],$this->path,null,null,$timeout);$p->run();if(!$p->isSuccessful()){$error=trim($p->getErrorOutput()?:$p->getOutput())?:'Git-kommandoen feilet.';$error=preg_replace('~https://[^\s/@]+@~','https://***@',$error);throw new RuntimeException(mb_substr($error,0,700));}return$p->getOutput();}
    private function ok(array $args):bool{$p=new Process([$this->git,...$args],$this->path,null,null,10);$p->run();return$p->isSuccessful();}
    private function maskUrl(string $url):string{return preg_replace('~(https://)[^/@]+@~','$1***@',$url);}
}
