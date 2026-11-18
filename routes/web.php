<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\Cms\GalleryController;
use App\Http\Controllers\Admin\Cms\HomepageContentController;
use App\Http\Controllers\Admin\Cms\MediaController as CmsMediaController;
use App\Http\Controllers\Admin\Cms\NewsPostController;
use App\Http\Controllers\Admin\Cms\RegulationController;
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
    Route::get('oferta/{offerTile}', [PageController::class, 'offerShow'])->whereNumber('offerTile')->name('offer.show');
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
    Route::prefix('admin/cms/media')->name('admin.cms.media.')->middleware('can:access-admin-panel')->group(function (): void {
        Route::get('/{mediaAsset}', [CmsMediaController::class, 'show'])->middleware('throttle:120,1,cms-media-preview:')->name('show');
        Route::post('/{placement}/{owner}', [CmsMediaController::class, 'store'])->whereNumber('owner')->middleware('throttle:30,1,cms-content-write:')->name('store');
        Route::delete('/{placement}/{owner}', [CmsMediaController::class, 'destroy'])->whereNumber('owner')->middleware('throttle:30,1,cms-content-write:')->name('destroy');
    });
    Route::prefix('admin/cms/strona-glowna/{section}')->name('admin.cms.homepage.')->middleware('can:access-admin-panel')->group(function (): void {
        Route::get('/', [HomepageContentController::class, 'index'])->middleware('throttle:60,1,cms-content-read:')->name('index');
        Route::get('/nowy', [HomepageContentController::class, 'create'])->name('create');
        Route::post('/', [HomepageContentController::class, 'store'])->middleware('throttle:30,1,cms-content-write:')->name('store');
        Route::get('/{item}/edytuj', [HomepageContentController::class, 'edit'])->whereNumber('item')->name('edit');
        Route::patch('/{item}', [HomepageContentController::class, 'update'])->whereNumber('item')->middleware('throttle:30,1,cms-content-write:')->name('update');
        Route::patch('/{item}/widocznosc', [HomepageContentController::class, 'visibility'])->whereNumber('item')->middleware('throttle:30,1,cms-content-write:')->name('visibility');
        Route::patch('/{item}/kolejnosc', [HomepageContentController::class, 'move'])->whereNumber('item')->middleware('throttle:30,1,cms-content-write:')->name('move');
        Route::delete('/{item}', [HomepageContentController::class, 'destroy'])->whereNumber('item')->middleware('throttle:30,1,cms-content-write:')->name('destroy');
    });
    Route::prefix('admin/cms/aktualnosci')->name('admin.cms.news.')->middleware('can:access-admin-panel')->group(function (): void {
        Route::get('/', [NewsPostController::class, 'index'])->middleware('throttle:60,1,cms-content-read:')->name('index');
        Route::get('/nowa', [NewsPostController::class, 'create'])->name('create');
        Route::post('/', [NewsPostController::class, 'store'])->middleware('throttle:30,1,cms-content-write:')->name('store');
        Route::get('/{newsPost}/edytuj', [NewsPostController::class, 'edit'])->whereNumber('newsPost')->name('edit');
        Route::patch('/{newsPost}', [NewsPostController::class, 'update'])->whereNumber('newsPost')->middleware('throttle:30,1,cms-content-write:')->name('update');
        Route::patch('/{newsPost}/widocznosc', [NewsPostController::class, 'visibility'])->whereNumber('newsPost')->middleware('throttle:30,1,cms-content-write:')->name('visibility');
        Route::delete('/{newsPost}', [NewsPostController::class, 'destroy'])->whereNumber('newsPost')->middleware('throttle:30,1,cms-content-write:')->name('destroy');
    });
    Route::prefix('admin/cms/galeria')->name('admin.cms.gallery.')->middleware('can:access-admin-panel')->group(function (): void {
        Route::get('/', [GalleryController::class, 'index'])->middleware('throttle:60,1,cms-content-read:')->name('index');
        Route::get('/kategorie/nowa', [GalleryController::class, 'categoryCreate'])->name('categories.create');
        Route::post('/kategorie', [GalleryController::class, 'categoryStore'])->middleware('throttle:30,1,cms-content-write:')->name('categories.store');
        Route::get('/kategorie/{category}/edytuj', [GalleryController::class, 'categoryEdit'])->whereNumber('category')->name('categories.edit');
        Route::patch('/kategorie/{category}', [GalleryController::class, 'categoryUpdate'])->whereNumber('category')->middleware('throttle:30,1,cms-content-write:')->name('categories.update');
        Route::patch('/kategorie/{category}/widocznosc', [GalleryController::class, 'categoryVisibility'])->whereNumber('category')->middleware('throttle:30,1,cms-content-write:')->name('categories.visibility');
        Route::patch('/kategorie/{category}/kolejnosc', [GalleryController::class, 'categoryMove'])->whereNumber('category')->middleware('throttle:30,1,cms-content-write:')->name('categories.move');
        Route::delete('/kategorie/{category}', [GalleryController::class, 'categoryDestroy'])->whereNumber('category')->middleware('throttle:30,1,cms-content-write:')->name('categories.destroy');
        Route::get('/zdjecia/nowe', [GalleryController::class, 'imageCreate'])->name('images.create');
        Route::post('/zdjecia', [GalleryController::class, 'imageStore'])->middleware('throttle:30,1,cms-content-write:')->name('images.store');
        Route::get('/zdjecia/{image}/edytuj', [GalleryController::class, 'imageEdit'])->whereNumber('image')->name('images.edit');
        Route::patch('/zdjecia/{image}', [GalleryController::class, 'imageUpdate'])->whereNumber('image')->middleware('throttle:30,1,cms-content-write:')->name('images.update');
        Route::patch('/zdjecia/{image}/widocznosc', [GalleryController::class, 'imageVisibility'])->whereNumber('image')->middleware('throttle:30,1,cms-content-write:')->name('images.visibility');
        Route::patch('/zdjecia/{image}/kolejnosc', [GalleryController::class, 'imageMove'])->whereNumber('image')->middleware('throttle:30,1,cms-content-write:')->name('images.move');
        Route::delete('/zdjecia/{image}', [GalleryController::class, 'imageDestroy'])->whereNumber('image')->middleware('throttle:30,1,cms-content-write:')->name('images.destroy');
    });
    Route::prefix('admin/cms/regulamin')->name('admin.cms.regulations.')->middleware('can:access-admin-panel')->group(function (): void {
        Route::get('/', [RegulationController::class, 'index'])->middleware('throttle:60,1,cms-content-read:')->name('index');
        Route::get('/nowa-wersja', [RegulationController::class, 'create'])->name('create');
        Route::post('/', [RegulationController::class, 'store'])->middleware('throttle:30,1,cms-content-write:')->name('store');
        Route::get('/{regulationVersion}/edytuj', [RegulationController::class, 'edit'])->whereNumber('regulationVersion')->name('edit');
        Route::patch('/{regulationVersion}', [RegulationController::class, 'update'])->whereNumber('regulationVersion')->middleware('throttle:30,1,cms-content-write:')->name('update');
        Route::post('/{regulationVersion}/publikuj', [RegulationController::class, 'publish'])->whereNumber('regulationVersion')->middleware('throttle:30,1,cms-content-write:')->name('publish');
        Route::delete('/{regulationVersion}', [RegulationController::class, 'destroy'])->whereNumber('regulationVersion')->middleware('throttle:30,1,cms-content-write:')->name('destroy');
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
