<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

class ThrottleAuthenticationRequests
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && $request->routeIs('register.store', 'password.email', 'password.update')) {
            return app(ThrottleRequests::class)->handle($request, $next, 'auth-forms');
        }

        return $next($request);
    }
}
