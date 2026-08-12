<?php
namespace App\Services;
use Symfony\Component\Process\Process;
use RuntimeException;

class GitDeploymentService
{
    private const PROTECTED_PATHS=['.env','system/.env','.env.production','system/.env.production','storage/','system/storage/','public/storage/','system/public/storage/','composer.lock','system/composer.lock','.DS_Store'];
    private string $path;private string $git;private string $remote;
    public function __construct(){ $this->path=(string)config('deployment.repository_path');$this->git=(string)config('deployment.git_binary');$this->remote=(string)config('deployment.remote','origin'); }
    public function inspect(bool $fetch=false):array
    {
        if(!is_dir($this->path)||!is_dir($this->path.'/.git'))return['available'=>false,'error'=>'Prosjektmappen er ikke et Git-repository.','path'=>$this->path];
        try{
            $root=trim($this->run(['rev-parse','--show-toplevel']));if(realpath($root)!==realpath($this->path))throw new RuntimeException('Git-roten stemmer ikke med konfigurert prosjektmappe.');
            $branch=(string)(config('deployment.branch')?:trim($this->run(['branch','--show-current'])));if(!preg_match('/^[A-Za-z0-9._\/-]+$/',$branch))throw new RuntimeException('Ugyldig Git-gren.');
            $url=trim($this->run(['remote','get-url',$this->remote]));if(!preg_match('~^(?:https://github\.com/|git@github\.com:)~i',$url))throw new RuntimeException('Av sikkerhetsgrunner tillates bare GitHub som remote.');
            $localLines=array_values(array_filter(explode("\n",trim($this->run(['status','--porcelain','--untracked-files=no'])))));
            $localFiles=array_values(array_filter(array_map(fn($line)=>trim(substr($line,3)),$localLines)));
            $dirtyFiles=array_values(array_filter($localFiles,fn($file)=>!$this->protected($file)));
            $dirty=$dirtyFiles!==[];
            if($fetch)$this->run(['-c','core.hooksPath=/dev/null','fetch','--prune',$this->remote,$branch],120);
            $remoteRef='refs/remotes/'.$this->remote.'/'.$branch;$remoteExists=$this->ok(['show-ref','--verify','--quiet',$remoteRef]);
            $ahead=$behind=0;$files=[];
            if($remoteExists){$counts=preg_split('/\s+/',trim($this->run(['rev-list','--left-right','--count','HEAD...'.$remoteRef])));$ahead=(int)($counts[0]??0);$behind=(int)($counts[1]??0);if($behind>0)$files=array_values(array_filter(explode("\n",trim($this->run(['diff','--name-only','HEAD..'.$remoteRef])))));}
            $candidates=array_values(array_unique(array_merge($files,$localFiles)));$ignored=array_values(array_filter($candidates,fn($f)=>$this->protected($f)));$selectable=array_values(array_filter($candidates,fn($f)=>!$this->protected($f)));
            $lockPath=in_array('system/composer.lock',$candidates,true)?'system/composer.lock':(in_array('composer.lock',$candidates,true)?'composer.lock':null);
            $vendor=$lockPath&&$remoteExists?$this->verifyVendor($remoteRef,$lockPath):['required'=>false,'ready'=>true,'message'=>null];
            return['available'=>true,'path'=>$root,'branch'=>$branch,'remote'=>$this->remote,'remote_url'=>$this->maskUrl($url),'dirty'=>$dirty,'dirty_files'=>$dirtyFiles,'ahead'=>$ahead,'behind'=>$behind,'files'=>$selectable,'ignored_files'=>$ignored,'vendor_required'=>$vendor['required'],'vendor_ready'=>$vendor['ready'],'vendor_message'=>$vendor['message'],'database_required'=>(bool)array_filter($selectable,fn($f)=>str_starts_with($f,'system/database/migrations/')),'checked_remote'=>$fetch,'head'=>trim($this->run(['rev-parse','--short','HEAD']))];
        }catch(\Throwable $e){return['available'=>false,'error'=>$e->getMessage(),'path'=>$this->path];}
    }
    public function initialize(string $repositoryUrl,string $branch):array
    {
        if(!is_dir($this->path))throw new RuntimeException('Den konfigurerte prosjektmappen finnes ikke.');
        $gitExists=is_dir($this->path.'/.git');
        if($gitExists&&$this->ok(['rev-parse','--verify','HEAD']))throw new RuntimeException('Prosjektmappen er allerede initialisert med Git. Bruk statuskontrollen i stedet.');
        $repositoryUrl=trim($repositoryUrl);$branch=trim($branch);
        if(!preg_match('~^(?:https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:\.git)?|git@github\.com:[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:\.git)?)$~i',$repositoryUrl))throw new RuntimeException('Oppgi en gyldig GitHub repository-adresse uten brukernavn, token eller passord.');
        if(!preg_match('/^[A-Za-z0-9._\/-]+$/',$branch))throw new RuntimeException('Ugyldig Git-gren.');
        try{
            // Eldre Git-versjoner på webhotell støtter ikke `git init -b`.
            // Initialiser først, og sett deretter ønsket branch eksplisitt.
            if(!$gitExists)$this->run(['init'],30);
            $this->run(['symbolic-ref','HEAD','refs/heads/'.$branch],15);
            if($this->ok(['remote','get-url',$this->remote]))$this->run(['remote','set-url',$this->remote,$repositoryUrl],15);
            else $this->run(['remote','add',$this->remote,$repositoryUrl],15);
            $this->run(['-c','core.hooksPath=/dev/null','fetch','--prune',$this->remote,$branch],120);
            $remoteRef='refs/remotes/'.$this->remote.'/'.$branch;
            if(!$this->ok(['show-ref','--verify','--quiet',$remoteRef]))throw new RuntimeException('Fant ikke grenen '.$branch.' på GitHub.');
            // Knytter Git-indeksen til GitHub-versjonen uten å skrive én eneste
            // fil til arbeidsmappen. Eventuelle avvik blir dermed synlige og
            // må håndteres før første pull.
            $this->run(['reset','--mixed',$remoteRef],30);
            $this->run(['branch','--set-upstream-to',$this->remote.'/'.$branch,$branch],15);
            return $this->inspect(false);
        }catch(\Throwable $e){$message=$e->getMessage();if(str_contains($message,"could not read Username for 'https://github.com'"))$message='GitHub avviste HTTPS-tilkoblingen fordi repositoryet er privat. Legg serverens offentlige SSH-nøkkel inn som en read-only Deploy Key i GitHub, og koble deretter til med SSH-adressen git@github.com:eier/repository.git. Vanlig GitHub-passord støttes ikke.';elseif(str_contains($message,'Repository not found'))$message='GitHub fant ikke repositoryet, eller serveren mangler tilgang. Kontroller adressen og bruk en read-only Deploy Key hvis repositoryet er privat.';elseif(str_contains($message,'Permission denied (publickey)'))$message='GitHub avviste serverens SSH-nøkkel. Legg serverens offentlige nøkkel inn som read-only Deploy Key på repositoryet og prøv igjen.';throw new RuntimeException('Førstegangsoppsettet stoppet uten å overskrive programfiler: '.$message,0,$e);}
    }
    public function deploy(array $selectedFiles=[]):array
    {
        $before=$this->inspect(true);if(!($before['available']??false))throw new RuntimeException($before['error']??'Git-kontrollen feilet.');
        if($before['ahead']>0)throw new RuntimeException('Serveren har commits som ikke finnes på GitHub. Oppdateringen er stoppet.');
        if($before['vendor_required'])throw new RuntimeException('Oppdateringen endrer composer.lock og krever ny vendor-mappe. Last opp vendor manuelt før denne versjonen aktiveres.');
        if($before['behind']<1)return$before+['updated'=>false];
        return $this->synchronize($selectedFiles);
    }
    public function synchronize(array $selectedFiles=[]):array
    {
        $before=$this->inspect(true);if(!($before['available']??false))throw new RuntimeException($before['error']??'Git-kontrollen feilet.');
        $remoteRef='refs/remotes/'.$this->remote.'/'.$before['branch'];
        $tracked=array_values(array_filter(explode("\n",trim($this->run(['ls-files'])))));
        $remoteTracked=array_values(array_filter(explode("\n",trim($this->run(['ls-tree','-r','--name-only',$remoteRef])))));
        $sensitive=array_values(array_unique(array_filter(array_merge($tracked,$remoteTracked),fn($file)=>$this->protected($file))));
        $changed=array_values(array_filter(explode("\n",trim($this->run(['diff','--name-only','HEAD',$remoteRef])))));
        $local=array_values(array_filter(explode("\n",trim($this->run(['status','--porcelain','--untracked-files=no'])))));
        $files=array_values(array_unique(array_merge($changed,array_map(fn($line)=>trim(substr($line,3)),$local))));
        $eligible=array_values(array_filter($files,fn($file)=>!$this->protected($file)));
        $requested=$selectedFiles?array_values(array_unique(array_map(fn($file)=>ltrim(str_replace('\\','/',$file),'/'),$selectedFiles))):$eligible;
        $updated=array_values(array_intersect($eligible,$requested));
        if(!$updated)throw new RuntimeException('Velg minst én programfil som skal oppdateres.');
        $excluded=array_values(array_diff($eligible,$updated));
        $ignored=array_values(array_unique(array_merge(array_filter($files,fn($file)=>$this->protected($file)),$excluded)));
        $allowedRemote=array_values(array_intersect($remoteTracked,$updated));
        $allowedRemoved=array_values(array_intersect(array_diff($tracked,$remoteTracked),$updated));
        $previous=$before['head'];
        // Sjekk ut bare tillatte filer i håndterbare grupper. Beskyttede og
        // usporede filer røres aldri, og git clean brukes ikke.
        foreach(array_chunk(array_intersect($updated,$tracked),100)as$chunk)$this->run(['update-index','--no-skip-worktree','--',...$chunk],30);
        foreach(array_chunk($allowedRemote,100)as$chunk)$this->run(['checkout',$remoteRef,'--',...$chunk],120);
        foreach(array_chunk($allowedRemoved,100)as$chunk)$this->run(['rm','-f','--',...$chunk],120);
        $this->run(['reset','--soft',$remoteRef],30);$this->run(['reset'],30);
        $preserved=array_values(array_unique(array_merge($sensitive,$excluded)));
        foreach(array_chunk(array_intersect($preserved,$remoteTracked),100)as$chunk)$this->run(['update-index','--skip-worktree','--',...$chunk],30);
        return array_merge($this->inspect(false),['updated'=>true,'previous_head'=>$previous,'updated_files'=>$updated,'ignored_files'=>$ignored,'database_required'=>(bool)array_filter($updated,fn($f)=>str_starts_with($f,'system/database/migrations/'))]);
    }
    private function run(array $args,int $timeout=30):string{$p=new Process([$this->git,...$args],$this->path,null,null,$timeout);$p->run();if(!$p->isSuccessful()){$error=trim($p->getErrorOutput()?:$p->getOutput())?:'Git-kommandoen feilet.';$error=preg_replace('~https://[^\s/@]+@~','https://***@',$error);throw new RuntimeException(mb_substr($error,0,700));}return$p->getOutput();}
    private function ok(array $args):bool{$p=new Process([$this->git,...$args],$this->path,null,null,10);$p->run();return$p->isSuccessful();}
    private function maskUrl(string $url):string{return preg_replace('~(https://)[^/@]+@~','$1***@',$url);}
    private function verifyVendor(string $remoteRef,string $lockPath):array
    {
        $localLock=$this->path.'/'.$lockPath;$vendorDir=dirname($localLock).'/vendor';$installedPath=$vendorDir.'/composer/installed.json';
        if(!is_file($localLock))return['required'=>true,'ready'=>false,'message'=>'composer.lock mangler på serveren.'];
        try{$remoteLock=$this->run(['show',$remoteRef.':'.$lockPath]);}catch(\Throwable){return['required'=>true,'ready'=>false,'message'=>'Kunne ikke lese composer.lock fra GitHub.'];}
        $localContents=file_get_contents($localLock);
        if(!is_string($localContents)||!hash_equals(hash('sha256',$remoteLock),hash('sha256',$localContents)))return['required'=>true,'ready'=>false,'message'=>'Serverens composer.lock er ikke samme versjon som oppdateringen på GitHub.'];
        if(!is_file($vendorDir.'/autoload.php')||!is_file($installedPath))return['required'=>true,'ready'=>false,'message'=>'composer.lock er riktig, men vendor er ikke komplett.'];
        try{$lock=json_decode($localContents,true,512,JSON_THROW_ON_ERROR);$installed=json_decode((string)file_get_contents($installedPath),true,512,JSON_THROW_ON_ERROR);}catch(\Throwable){return['required'=>true,'ready'=>false,'message'=>'Composer-metadata kunne ikke leses. Last opp vendor på nytt.'];}
        $installedPackages=$installed['packages']??$installed[0]['packages']??[];$versions=[];foreach($installedPackages as$package)if(isset($package['name'],$package['version']))$versions[$package['name']]=$package['version'];
        $missing=[];foreach(($lock['packages']??[])as$package){$name=$package['name']??null;$version=$package['version']??null;if(!$name||!$version||($versions[$name]??null)!==$version)$missing[]=$name?:'ukjent pakke';}
        if($missing)return['required'=>true,'ready'=>false,'message'=>'Vendor mangler eller har feil versjon av: '.implode(', ',array_slice($missing,0,6)).(count($missing)>6?' m.fl.':'').'.'];
        return['required'=>false,'ready'=>true,'message'=>'Manuelt opplastet composer.lock og vendor samsvarer med GitHub.'];
    }
    private function protected(string $path):bool
    {
        $path=ltrim(str_replace('\\','/',$path),'/');
        // Laravel trenger disse små filene i Git for å opprette riktig
        // mappestruktur og beskytte storage mot direkte webtilgang. De
        // inneholder ingen driftsdata eller hemmeligheter.
        if(basename($path)==='.DS_Store')return true;
        if((str_starts_with($path,'storage/')||str_starts_with($path,'system/storage/'))&&in_array(basename($path),['.gitignore','.htaccess'],true))return false;
        foreach(self::PROTECTED_PATHS as$p){if(str_ends_with($p,'/')){if(str_starts_with($path,$p))return true;}elseif($path===$p)return true;}
        return false;
    }
}
