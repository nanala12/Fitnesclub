<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $challengedId = $request->session()->get('login.id');
        $challenged = is_int($challengedId) || is_string($challengedId)
            ? User::whereKey($challengedId)->first()
            : null;

        if ($request->user()?->isSuspended() || $challenged?->isSuspended()) {
            return $this->reject($request);
        }

        $response = $next($request);

        // Also cover authentication completed by 2FA or a passkey on this request.
        if ($request->user()?->isSuspended()) {
            return $this->reject($request);
        }

        return $response;
    }

    private function reject(Request $request): Response
    {
        Auth::guard('web')->logout();
        Inertia::share('auth', ['user' => null]);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => 'Dostęp do konta został zawieszony.'], 403);
        }

        return Inertia::render('auth/Suspended')->toResponse($request)->setStatusCode(403);
    }
}
