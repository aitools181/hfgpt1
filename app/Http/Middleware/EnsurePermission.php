<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        $allowed = $user && collect($permissions)->contains(fn (string $permission): bool => $user->hasPermission($permission));

        abort_unless($allowed, 403, 'You do not have permission to perform this action.');
        return $next($request);
    }
}
