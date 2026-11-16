<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\Cms\MediaController as CmsMediaController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PublicSite\MediaController;
use App\Http\Controllers\PublicSite\PageController;
use App\Http\Controllers\Staff\DashboardController as StaffDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::name('public.')->group(function (): void {
    Route::get('oferta', [PageController::class, 'offer'])->name('offer');
    Route::get('cennik', [PageController::class, 'pricing'])->name('pricing');
    Route::get('aktualnosci', [PageController::class, 'news'])->name('news.index');
    Route::get('aktualnosci/{slug}', [PageController::class, 'newsShow'])->name('news.show');
    Route::get('galeria', [PageController::class, 'gallery'])->name('gallery');
    Route::get('kontakt', [PageController::class, 'contact'])->name('contact');
    Route::get('grafik', [PageController::class, 'schedule'])->name('schedule');
    Route::get('regulamin', [PageController::class, 'regulations'])->name('regulations');
    Route::get('media/{mediaAsset}', MediaController::class)->name('media.show');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('admin/cms/media')->name('admin.cms.media.')->middleware(['can:access-admin-panel', 'throttle:30,1'])->group(function (): void {
        Route::get('/{mediaAsset}', [CmsMediaController::class, 'show'])->name('show');
        Route::post('/{placement}/{owner}', [CmsMediaController::class, 'store'])->whereNumber('owner')->name('store');
        Route::delete('/{placement}/{owner}', [CmsMediaController::class, 'destroy'])->whereNumber('owner')->name('destroy');
    });
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('admin', AdminDashboardController::class)->middleware('can:access-admin-panel')->name('admin.dashboard');
    Route::get('admin/audyt', [AuditLogController::class, 'index'])
        ->middleware(['can:access-admin-panel', 'throttle:60,1'])->name('admin.audit.index');
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
