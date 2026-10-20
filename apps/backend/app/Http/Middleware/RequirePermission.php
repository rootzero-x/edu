<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Ishlatish: ->middleware('perm:course.manage') yoki bir nechtasidan biri: 'perm:course.manage|course.author' */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        if (! $user || ! collect(explode('|', $permission))->contains(fn ($p) => $user->hasPermission($p))) {
            return response()->json(['message' => 'Ruxsat yo\'q.'], 403);
        }

        return $next($request);
    }
}
