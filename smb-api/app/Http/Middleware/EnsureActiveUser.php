<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->is_active) {
            if ($user) {
                activity('authentication')->causedBy($user)->log('auth.session.blocked_inactive');
            }

            throw new AuthorizationException('Akun dinonaktifkan.');
        }

        return $next($request);
    }
}
