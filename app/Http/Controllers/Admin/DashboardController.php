<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Reporting\AdminDashboard;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, AdminDashboard $dashboard): Response
    {
        Gate::forUser($request->user()->fresh())->authorize('viewSales', Order::class);
        $today = now('Europe/Warsaw');
        $request->merge(['from' => $request->input('from', $today->startOfMonth()->toDateString()), 'to' => $request->input('to', $today->toDateString())]);
        $range = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']], [
            'from.required' => 'Podaj datę początkową.', 'to.required' => 'Podaj datę końcową.',
            'from.date_format' => 'Podaj poprawną datę początkową.', 'to.date_format' => 'Podaj poprawną datę końcową.',
            'to.after_or_equal' => 'Data końcowa nie może być wcześniejsza niż początkowa.',
        ]);
        Inertia::encryptHistory();
        $response = Inertia::render('admin/Dashboard', ['dashboard' => $dashboard->handle($range['from'], $range['to'])])->toResponse($request);
        $response->headers->add(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);

        return $response;
    }
}
