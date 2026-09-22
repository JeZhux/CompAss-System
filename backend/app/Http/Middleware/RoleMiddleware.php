<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        if (! $request->user()) {
            throw new AuthenticationException();
        }

        // F-01: deactivated accounts keep no usable session — reject even
        // when a pre-deactivation session object is cached on the request.
        if (! (bool) $request->user()->fresh()?->is_active) {
            throw new AuthenticationException('This account has been deactivated.');
        }

        $roles = array_merge(...array_map(
            fn (string $r): array => explode(',', $r),
            $roles
        ));

        $roles = array_filter($roles, fn (string $r): bool => $r !== '');

        $userRole = strtolower((string) $request->user()->role);
        $routeRoles = array_map('strtolower', $roles);

        if (! empty($routeRoles) && ! in_array($userRole, $routeRoles)) {
            throw new AuthorizationException();
        }

        return $next($request);
    }
}
