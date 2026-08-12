<?php

namespace App\Http\Controllers;

use App\Services\DatabaseUpdateStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class SuperAdminSystemUpdateController extends Controller
{
    public function show(DatabaseUpdateStatus $updates): View
    {
        return view('superadmin.system-update', ['updateStatus' => $updates->inspect()]);
    }

    public function run(Request $request, DatabaseUpdateStatus $updates): RedirectResponse
    {
        $before = $updates->inspect();
        if (! $before['available']) return back()->withErrors(['update' => $before['error']]);

        $lockPath = storage_path('app/system-update.lock');
        $lock = fopen($lockPath, 'c+');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) fclose($lock);
            return back()->withErrors(['update' => 'En systemoppdatering kjører allerede. Vent litt og prøv igjen.']);
        }

        try {
            if (! $before['current']) {
                $exit = Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]);
                if ($exit !== 0) throw new \RuntimeException('Migreringen returnerte feilkode '.$exit.'.');
            }
            $cacheExit = Artisan::call('optimize:clear');
            if ($cacheExit !== 0) throw new \RuntimeException('Systembufferet kunne ikke tømmes.');
            $opcacheReset = function_exists('opcache_reset') ? @opcache_reset() : null;
            $after = $updates->inspect();
            if (! $after['available'] || ! $after['current']) throw new \RuntimeException('Én eller flere migreringer gjenstår etter oppdateringen.');
            DB::table('audit_logs')->insert(['organization_id' => $request->user()->organization_id, 'user_id' => $request->user()->id, 'action' => 'superadmin.system.updated', 'ip_address' => $request->ip(), 'metadata' => json_encode(['migrations' => $before['pending'], 'caches_cleared' => true, 'opcache_reset' => $opcacheReset]), 'created_at' => now()]);
            $message = $before['current'] ? 'Filoppdateringen er fullført, og Laravel-cache og PHP OPcache er tømt.' : count($before['pending']).' databaseoppdateringer ble installert, og all systemcache ble tømt.';
            return redirect()->route('superadmin.system-update')->with('success', $message);
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['update' => 'Oppdateringen stoppet. Ingen flere steg kjøres. Se serverloggen før du prøver igjen.']);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
