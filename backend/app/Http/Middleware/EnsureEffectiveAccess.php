<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEffectiveAccess
{
    public function handle(Request $request, Closure $next, string ...$allowed): Response
    {
        $user = $request->user();
        $roles = $user?->getRoleNames()->all() ?? [];
        $positions = $user?->getActivePositionCodes() ?? [];

        abort_unless(
            collect($allowed)->intersect(array_merge($roles, $positions))->isNotEmpty(),
            403,
            'Anda tidak memiliki akses ke fitur ini.'
        );

        return $next($request);
    }
}
