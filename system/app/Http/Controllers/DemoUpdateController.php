<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Organization;
use App\Models\OutboundMessage;
use App\Models\TireProduct;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class DemoUpdateController extends Controller
{
    public function show(): View
    {
        return view('superadmin.demo-update');
    }

    public function run(Request $request): RedirectResponse
    {
        $data = $request->validate(['sets' => ['required', 'integer', 'between:50,2000']]);

        try {
            set_time_limit(240);
            $exit = Artisan::call('demo:reset', ['--sets' => $data['sets']]);
            if ($exit !== 0) {
                throw new RuntimeException('Demonullstillingen stoppet. Eksisterende demo er beholdt.');
            }

            $organization = Organization::where('organization_number', 'DEMO-DEKKPILOT')->firstOrFail();
            OutboundMessage::where('organization_id', $organization->id)
                ->whereIn('status', ['queued', 'processing'])
                ->update(['status' => 'cancelled', 'last_error' => 'Blokkert permanent: demo- og testdata sendes aldri eksternt.']);

            DB::table('audit_logs')->insert([
                'organization_id' => $organization->id,
                'user_id' => $request->user()->id,
                'action' => 'demo.refreshed',
                'subject_type' => Organization::class,
                'subject_id' => $organization->id,
                'ip_address' => $request->ip(),
                'metadata' => json_encode(['sets_requested' => $data['sets']]),
                'created_at' => now(),
            ]);

            return back()->with('demo_report', [
                $organization->customers()->count().' kunder',
                $organization->vehicles()->count().' biler',
                $organization->tireSets()->count().' hjulsett',
                Booking::where('organization_id', $organization->id)->where('starts_at', '>=', today())->count().' kommende bookinger',
                TireProduct::where('organization_id', $organization->id)->count().' dekkprodukter',
            ])->with('success', 'Demoen er nullstilt og bygget på nytt.');
        } catch (Throwable $exception) {
            report($exception);
            return back()->withErrors(['demo' => $exception->getMessage()]);
        }
    }
}
