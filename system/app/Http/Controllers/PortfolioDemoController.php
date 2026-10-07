<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PortfolioDemoController extends Controller
{
    public function __invoke(Request $request, Organization $organization): RedirectResponse
    {
        $request->validate(['excluded' => ['required', 'boolean']]);
        $excluded = $request->boolean('excluded');
        if ($organization->organization_number === 'DEMO-DEKKPILOT' && ! $excluded) {
            return back()->withErrors(['excluded' => 'Det innebygde demomiljøet skal alltid utelates.']);
        }
        DB::transaction(function () use ($request, $organization, $excluded): void {
            $before = (bool) $organization->exclude_from_portfolio;
            $organization->forceFill(['exclude_from_portfolio' => $excluded])->save();
            DB::table('audit_logs')->insert([
                'organization_id' => $organization->id,
                'user_id' => $request->user()->id,
                'action' => 'superadmin.customer.portfolio_demo',
                'subject_type' => Organization::class,
                'subject_id' => $organization->id,
                'ip_address' => $request->ip(),
                'metadata' => json_encode(['before' => $before, 'excluded' => $excluded]),
                'created_at' => now(),
            ]);
        });

        return back()->with('success', $excluded
            ? 'Demomiljøet utelates fra Jovia Digital-API og statistikk.'
            : 'Virksomheten inngår igjen i Jovia Digital-API og statistikk.');
    }
}
