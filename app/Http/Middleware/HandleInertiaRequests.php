<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'roles' => $user->roles->map(fn ($role) => [
                        'name' => $role->name,
                        'slug' => $role->slug,
                        'module' => $role->module,
                        'zone_id' => $role->pivot->zone_id,
                        'center_id' => $role->pivot->center_id,
                        'is_primary' => (bool) $role->pivot->is_primary,
                    ])->values(),
                    'permissions' => $user->roles->flatMap->permissions->pluck('slug')->unique()->values(),
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'completionReport' => fn () => $request->session()->get('completion_report'),
                'newBadges' => fn () => $request->session()->get('new_badges', []),
            ],
        ];
    }
}
