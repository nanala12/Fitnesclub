<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        if ($request->user()?->role === UserRole::Admin) {
            return to_route('admin.dashboard');
        }

        if ($request->user()?->role === UserRole::Employee) {
            return to_route('staff.dashboard');
        }

        return Inertia::render('Dashboard', [
            'accountCreatedAt' => $request->user()?->created_at?->toISOString(),
        ]);
    }
}
