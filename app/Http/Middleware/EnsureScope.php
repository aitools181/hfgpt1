<?php

namespace App\Http\Middleware;

use App\Models\Center;
use App\Models\Zone;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            abort(401);
        }

        $center = $request->route('center');
        if ($center instanceof Center && ! $user->canAccessCenterId($center->id)) {
            abort(403, 'Center is outside your permitted scope.');
        }

        $zone = $request->route('zone');
        if ($zone instanceof Zone && ! $user->canAccessZoneId($zone->id)) {
            abort(403, 'Zone is outside your permitted scope.');
        }

        return $next($request);
    }
}
