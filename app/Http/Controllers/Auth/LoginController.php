<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\AuditTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

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
        if ($this->tooManyAttempts($key)) {
            throw ValidationException::withMessages([
                'email' => 'Too many login attempts. Please try again in '.$this->availableIn($key).' seconds.',
            ]);
        }

        try {
            $authenticated = Auth::attempt($credentials, $request->boolean('remember'));
        } catch (Throwable $e) {
            Log::error('Credential verification failed unexpectedly.', [
                'email_hash' => hash('sha256', Str::lower($credentials['email'])),
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw ValidationException::withMessages([
                'email' => 'Sign in could not be completed right now. Please retry. If the issue continues, ask an administrator to reset this account password.',
            ]);
        }

        if (! $authenticated) {
            $this->hitRateLimit($key);
            throw ValidationException::withMessages(['email' => 'The provided credentials are incorrect.']);
        }

        $user = $request->user();
        if (! $user || $user->status !== 'active') {
            Auth::logout();
            throw ValidationException::withMessages(['email' => 'This user account is not active.']);
        }

        // Session rotation is the one post-authentication step that must succeed.
        // All persistent session directories are verified during container startup;
        // if this still fails, do not leave a half-authenticated request alive.
        try {
            $request->session()->regenerate();
            $request->session()->put('auth_session_version', (int) ($user->session_version ?? 1));
        } catch (Throwable $e) {
            Auth::guard('web')->logout();
            Log::critical('Secure login session could not be persisted.', [
                'user_id' => $user->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->clearRateLimit($key);

        // These are useful operational side effects, but neither may turn valid
        // credentials into a 500 response. Readiness reports schema/audit drift so
        // administrators still see and repair the underlying problem.
        try {
            $user->forceFill(['last_login_at' => now()])->saveQuietly();
        } catch (Throwable $e) {
            Log::error('Login succeeded but last_login_at could not be updated.', [
                'user_id' => $user->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }

        $auditTrail->recordSafely('authentication', 'login', 'user', (string) $user->id);

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request, AuditTrail $auditTrail): RedirectResponse
    {
        if ($request->user()) {
            $auditTrail->recordSafely('authentication', 'logout', 'user', (string) $request->user()->id);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function tooManyAttempts(string $key): bool
    {
        try {
            return RateLimiter::tooManyAttempts($key, 5);
        } catch (Throwable $e) {
            Log::warning('Login rate-limit read failed; authentication remains available.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function availableIn(string $key): int
    {
        try {
            return max(1, RateLimiter::availableIn($key));
        } catch (Throwable) {
            return 60;
        }
    }

    private function hitRateLimit(string $key): void
    {
        try {
            RateLimiter::hit($key, 60);
        } catch (Throwable $e) {
            Log::warning('Login rate-limit write failed.', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }
    }

    private function clearRateLimit(string $key): void
    {
        try {
            RateLimiter::clear($key);
        } catch (Throwable $e) {
            Log::warning('Login rate-limit clear failed.', ['exception' => $e::class, 'message' => $e->getMessage()]);
        }
    }
}
