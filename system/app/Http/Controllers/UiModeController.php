<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UiModeController extends Controller
{
    public function choose(Request $request): View
    {
        return view('auth.choose-mode', ['currentMode' => $request->session()->get('ui_mode')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['mode' => ['required', 'in:technician,portal']]);
        $request->session()->put('ui_mode', $data['mode']);
        return redirect()->route($data['mode'] === 'technician' ? 'workday' : 'dashboard')
            ->with('success', $data['mode'] === 'technician' ? 'Teknikermodus er aktiv.' : 'Full portal er aktiv.');
    }
}
