<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('staff/Dashboard', [
            'accountCreatedAt' => $request->user()?->created_at?->toISOString(),
        ]);
    }
}
