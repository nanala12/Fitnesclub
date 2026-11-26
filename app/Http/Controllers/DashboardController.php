<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        if ($request->user()?->role === UserRole::Admin) {
            return to_route('admin.dashboard');
        }

        if ($request->user()?->role === UserRole::Employee) {
            return to_route('staff.memberships.index');
        }

        return to_route('memberships.index');
    }
}
