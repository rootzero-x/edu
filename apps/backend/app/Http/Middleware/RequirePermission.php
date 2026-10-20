<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Ishlatish: ->middleware('perm:course.manage') */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if (! $user || ! $user->active || ! $user->role->can($permission)) {
            return response()->json(['message' => 'Ruxsat yo\'q.'], 403);
        }

        return $next($request);
    }
}
