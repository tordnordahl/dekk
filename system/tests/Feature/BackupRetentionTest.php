<?php
namespace Tests\Feature;

use App\Models\{Organization,Branch,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{File,Artisan};
use Illuminate\Support\Str;
use Tests\TestCase;

class BackupRetentionTest extends TestCase
{
    use RefreshDatabase;
    public function test_only_expired_backup_files_are_removed_from_local_and_mirror(): void {
        $original=storage_path();$root=sys_get_temp_dir().'/dekk-backup-test-'.Str::uuid();
        $local=$root.'/app/backups';$mirror=$root.'/mirror';
        File::makeDirectory($local,0700,true);File::makeDirectory($mirror,0700,true);
        $this->app->useStoragePath($root);config(['backups.mirror_path'=>$mirror]);
        $this->travelTo(now()->startOfSecond());
        try {
            foreach([$local,$mirror] as $dir) {
                foreach(['dekkpilot-20260101-000001.sql.gz'=>15,'dekkpilot-20260101-000002.sql.gz'=>13,'dekkpilot-20260101-000003.sql.gz'=>14,'unrelated.txt'=>30,'dekkpilot-20260101-000004.sql'=>30] as $name=>$days) {
                    file_put_contents($dir.'/'.$name,'fixture');touch($dir.'/'.$name,now()->subDays($days)->timestamp);
                }
                symlink($dir.'/unrelated.txt',$dir.'/dekkpilot-20260101-000005.sql.gz');
            }
            $this->artisan('backup:prune',['--dry-run'=>true])->expectsOutputToContain('Ville fjernet 2')->assertSuccessful();
            $this->assertFileExists($local.'/dekkpilot-20260101-000001.sql.gz');
            $this->artisan('backup:prune')->expectsOutputToContain('Fjernet 2')->assertSuccessful();
            foreach([$local,$mirror] as $dir) {
                $this->assertFileDoesNotExist($dir.'/dekkpilot-20260101-000001.sql.gz');
                foreach(['dekkpilot-20260101-000002.sql.gz','dekkpilot-20260101-000003.sql.gz','unrelated.txt','dekkpilot-20260101-000004.sql','dekkpilot-20260101-000005.sql.gz'] as $name) $this->assertFileExists($dir.'/'.$name);
            }
            $this->artisan('backup:prune')->expectsOutputToContain('Fjernet 0')->assertSuccessful();
        } finally {
            $this->app->useStoragePath($original);File::deleteDirectory($root);$this->travelBack();
        }
    }
    public function test_backup_controls_are_superadmin_only_with_fixed_retention(): void {
        $org=Organization::create(['public_id'=>Str::uuid(),'name'=>'Verksted','subscription_status'=>'active']);
        $branch=Branch::create(['public_id'=>Str::uuid(),'organization_id'=>$org->id,'name'=>'Hoved','code'=>'H']);
        $user=User::factory()->create(['organization_id'=>$org->id,'branch_id'=>$branch->id,'role'=>'owner','active'=>true]);
        $this->actingAs($user)->get(route('admin.system'))->assertOk()->assertDontSee('Databasebackup')->assertDontSee('cron');
        $this->get(route('superadmin.backups'))->assertForbidden();
        $this->post(route('superadmin.backups.create'))->assertForbidden();
        $this->post(route('admin.system.backup'),['retention'=>365])->assertForbidden();
        $user->update(['is_super_admin'=>true]);
        $this->get(route('admin.system'))->assertOk()->assertDontSee('Databasebackup')->assertDontSee('cron');
        $this->get(route('superadmin.backups'))->assertOk()->assertSee('Maks 14 dager')->assertDontSee('name="retention"',false)->assertDontSee('cron');
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        Artisan::shouldReceive('call')->once()->with('backup:database')->andReturn(0);
        Artisan::shouldReceive('output')->once()->andReturn('Backup OK');
        $this->post(route('superadmin.backups.create'),['retention'=>365])->assertRedirect()->assertSessionHasNoErrors();
    }
}
