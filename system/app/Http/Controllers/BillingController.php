<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\BillingStatement;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BillingController extends Controller
{
    public function index(Request $request): View
    {
        return view('billing.index', [
            'organization' => Organization::findOrFail($request->user()->organization_id),
            'smsUsage' => DB::table('usage_events')->where('organization_id',$request->user()->organization_id)->where('type','sms')->where('occurred_at','>=',now()->startOfMonth())->sum('quantity'),
            'statements' => BillingStatement::where('organization_id',$request->user()->organization_id)->latest('period_start')->limit(24)->get(),
        ]);
    }
}
