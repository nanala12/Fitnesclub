<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Staff\DashboardController as StaffDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('admin', AdminDashboardController::class)->middleware('can:access-admin-panel')->name('admin.dashboard');
    Route::get('panel-pracownika', StaffDashboardController::class)->middleware('can:access-staff-panel')->name('staff.dashboard');
});

require __DIR__.'/settings.php';
