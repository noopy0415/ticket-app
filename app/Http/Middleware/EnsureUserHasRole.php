<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Allow the request only when the user has one of the given roles.
     *
     * Usage: `role:organizer` or `role:organizer,admin`
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $userRole = $request->user()?->role?->value;

        if (! in_array($userRole, $roles, true)) {
            abort(403);
        }

        return $next($request);
    }
}
