<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetUserTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $user = $request->user();
            $tz = is_object($user) ? ($user->timezone ?? null) : null;
            if (is_string($tz) && $tz !== '') {
                \date_default_timezone_set($tz);
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return $next($request);
    }
}

