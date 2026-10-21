<?php

namespace App\Http\Middleware;

use App\Support\UploadTickets;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/** Yuklash chiptasi (UploadTickets) faqat o'z yo'liga bitta POST uchun ishlaydi; javobdan keyin o'chiriladi. */
class RestrictUploadTicket
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        $token = $bearer ? PersonalAccessToken::findToken($bearer) : null;
        if (! $token || $token->name !== UploadTickets::TOKEN_NAME) {
            return $next($request);
        }
        $path = (string) preg_replace('#^v1/#', '', $request->path());
        if (! $request->isMethod('POST') || ! $token->can('upload:'.$path) || ($token->expires_at && $token->expires_at->isPast())) {
            abort(403, "Yuklash chiptasi bu so'rov uchun emas yoki muddati o'tgan.");
        }
        try {
            return $next($request);
        } finally {
            $token->delete();
            Auth::forgetGuards();
        }
    }
}
