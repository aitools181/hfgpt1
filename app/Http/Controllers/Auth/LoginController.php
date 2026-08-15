<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/login');
    }

    public function store(Request $request, AuditTrail $auditTrail): RedirectResponse
    {
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = Str::lower($credentials['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'The provided credentials are incorrect.']);
        }

        if ($request->user()->status !== 'active') {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'This user account is not active.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('auth_session_version', (int) $request->user()->session_version);
        $request->user()->forceFill(['last_login_at' => now()])->saveQuietly();
        $auditTrail->record('authentication', 'login', 'user', (string) $request->user()->id);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request, AuditTrail $auditTrail): RedirectResponse
    {
        if ($request->user()) {
            $auditTrail->record('authentication', 'logout', 'user', (string) $request->user()->id);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
