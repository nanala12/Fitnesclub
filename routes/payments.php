<?php

use App\Http\Controllers\Payments\Przelewy24NotificationController;
use Illuminate\Support\Facades\Route;

// Server-to-server only: no browser session, account middleware or CSRF exemption for other routes.
Route::post('payments/przelewy24/notification', Przelewy24NotificationController::class)
    ->middleware('throttle:120,1,p24-notification:')->name('payments.przelewy24.notification');
