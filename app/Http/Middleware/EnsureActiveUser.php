<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->status !== 'active') {
            return $this->logout($request, 'This user account is not active.');
        }

        if ($user) {
            $sessionVersion = $request->session()->get('auth_session_version');
            if ($sessionVersion === null) {
                // Transparently upgrade sessions created before session-version enforcement.
                // If a password was reset after that legacy session was created, force re-login.
                if ($user->password_changed_at !== null) {
                    return $this->logout($request, 'Your password was changed. Please sign in again.');
                }
                $request->session()->put('auth_session_version', (int) $user->session_version);
            } elseif ((int) $sessionVersion !== (int) $user->session_version) {
                return $this->logout($request, 'Your session has expired. Please sign in again.');
            }
        }

        return $next($request);
    }

    private function logout(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('error', $message);
    }
}
