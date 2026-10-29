<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\MidtransWebhookController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\ReviewController;
use App\Http\Controllers\Api\ServiceController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('register', [AuthController::class, 'register'])->middleware('throttle:10,1');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

// Webhook gateway pembayaran (publik; keaslian dicek lewat signature)
Route::post('payments/midtrans/notification', MidtransWebhookController::class)->middleware('throttle:120,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('services', [ServiceController::class, 'index']);
    Route::get('customer/home', [CustomerController::class, 'home']);

    // Pesanan
    Route::get('customer/orders', [OrderController::class, 'index']);
    Route::get('customer/orders/{code}', [OrderController::class, 'show']);

    // Pemesanan + cari teknisi otomatis + pelacakan
    Route::post('customer/orders', [BookingController::class, 'store'])->middleware('throttle:20,1');
    Route::post('customer/orders/{code}/match', [BookingController::class, 'match'])->middleware('throttle:20,1');
    Route::post('customer/orders/{code}/cancel', [BookingController::class, 'cancel']);
    Route::get('customer/orders/{code}/tracking', [BookingController::class, 'tracking']);

    // Pembayaran & ulasan
    Route::get('customer/orders/{code}/payment', [PaymentController::class, 'show']);
    Route::post('customer/orders/{code}/pay', [PaymentController::class, 'pay'])->middleware('throttle:10,1');
    Route::post('customer/orders/{code}/qris', [PaymentController::class, 'qris'])->middleware('throttle:20,1');
    Route::get('customer/orders/{code}/qris/status', [PaymentController::class, 'qrisStatus'])->middleware('throttle:60,1');
    Route::get('customer/orders/{code}/review', [ReviewController::class, 'show']);
    Route::post('customer/orders/{code}/review', [ReviewController::class, 'store']);

    // Pesan (chat teknisi & CS)
    Route::get('customer/conversations', [ChatController::class, 'index']);
    Route::post('customer/conversations/support', [ChatController::class, 'openSupport']);
    Route::get('customer/conversations/{id}', [ChatController::class, 'show'])->whereNumber('id');
    Route::post('customer/conversations/{id}/messages', [ChatController::class, 'send'])
        ->whereNumber('id')->middleware('throttle:60,1');
    Route::post('customer/orders/{code}/chat', [ChatController::class, 'openForOrder']);

    // Akun
    Route::prefix('account')->group(function () {
        Route::get('/', [AccountController::class, 'show']);
        Route::put('profile', [AccountController::class, 'updateProfile']);
        Route::post('password', [AccountController::class, 'changePassword'])->middleware('throttle:5,1');
        Route::get('addresses', [AccountController::class, 'addresses']);
        Route::post('addresses', [AccountController::class, 'storeAddress']);
        Route::put('addresses/{id}', [AccountController::class, 'updateAddress'])->whereNumber('id');
        Route::post('addresses/{id}/default', [AccountController::class, 'makeDefault'])->whereNumber('id');
        Route::delete('addresses/{id}', [AccountController::class, 'destroyAddress'])->whereNumber('id');
        Route::get('notifications', [AccountController::class, 'notifications']);
    });
});
