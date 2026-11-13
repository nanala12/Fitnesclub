<?php

use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Staff\DashboardController as StaffDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('admin', AdminDashboardController::class)->middleware('can:access-admin-panel')->name('admin.dashboard');
    Route::get('panel-pracownika', StaffDashboardController::class)->middleware('can:access-staff-panel')->name('staff.dashboard');
    Route::prefix('admin/pracownicy')->name('admin.employees.')->middleware('can:access-admin-panel')->group(function (): void {
        Route::get('/', [EmployeeController::class, 'index'])->middleware('throttle:60,1')->name('index');
        Route::get('/nowy', [EmployeeController::class, 'create'])->name('create');
        Route::post('/', [EmployeeController::class, 'store'])->middleware('throttle:30,1')->name('store');
        Route::get('/{employee}/edytuj', [EmployeeController::class, 'edit'])->name('edit');
        Route::patch('/{employee}', [EmployeeController::class, 'update'])->middleware('throttle:30,1')->name('update');
        Route::patch('/{employee}/dostep', [EmployeeController::class, 'suspension'])->middleware('throttle:30,1')->name('suspension');
        Route::post('/{employee}/zaproszenie', [EmployeeController::class, 'invitation'])->middleware('throttle:3,1')->name('invitation');
    });
});

require __DIR__.'/settings.php';
