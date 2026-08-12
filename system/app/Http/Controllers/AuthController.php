<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use App\Services\DemoAccessService;
use RuntimeException;
use Illuminate\View\View;
use App\Services\DatabaseUpdateStatus;

class AuthController extends Controller
{
    public function form(): View { return view('auth.login'); }

    public function login(Request $request, DatabaseUpdateStatus $updates): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'string'], 'password' => ['required', 'string']]);
        if (strtolower($credentials['email']) === 'test' && hash_equals('test', $credentials['password'])) {
            return $this->openDemo($request, app(DemoAccessService::class));
        }
        if (! filter_var($credentials['email'], FILTER_VALIDATE_EMAIL)) {
            return back()->withErrors(['email' => 'Skriv inn en gyldig e-postadresse.'])->onlyInput('email');
        }
        $key = 'login:'.strtolower($credentials['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['email' => 'For mange forsøk. Prøv igjen om '.RateLimiter::availableIn($key).' sekunder.']);
        }
        if (!Auth::attempt([...$credentials, 'active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            return back()->withErrors(['email' => 'E-post eller passord er feil.'])->onlyInput('email');
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->forget('two_factor_confirmed');
        $request->user()->forceFill(['last_login_at' => now()])->save();
        if ($request->user()->two_factor_confirmed_at) {
            $destination=$request->user()->is_super_admin===true&&!$updates->inspect()['current']?route('superadmin.system-update'):route('dashboard');
            $request->session()->put('url.intended',$destination);
            return redirect()->route('two-factor.challenge');
        }
        if ($request->user()->is_super_admin === true && ! $updates->inspect()['current']) {
            return redirect()->route('superadmin.system-update')->with('success', 'DekkPilot kontrollerte databasen ved innlogging. Se status før du fortsetter.');
        }
        $request->session()->forget('ui_mode');
        if ($this->isPhone($request)) return redirect()->route('ui-mode.choose');
        $request->session()->put('ui_mode', 'portal');
        return redirect()->intended(route('dashboard'));
    }

    public function demo(Request $request, DemoAccessService $demo): RedirectResponse
    {
        return $this->openDemo($request, $demo);
    }

    private function openDemo(Request $request, DemoAccessService $demo): RedirectResponse
    {
        try { $user = $demo->prepare(); }
        catch (RuntimeException $exception) { return redirect()->route('login')->withErrors(['email' => $exception->getMessage()]); }
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('demo_read_only', true);
        $request->session()->forget('ui_mode');
        if ($this->isPhone($request)) return redirect()->route('ui-mode.choose');
        $request->session()->put('ui_mode', 'portal');
        return redirect()->route('dashboard')->with('success', 'Demoen er oppdatert med bookinger for de neste dagene.');
    }

    private function isPhone(Request $request): bool
    {
        $agent = strtolower((string) $request->userAgent());
        return (bool) preg_match('/iphone|ipod|android.*mobile|windows phone|blackberry|opera mini|mobile/', $agent);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->forget('demo_read_only');
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('login');
    }
}
