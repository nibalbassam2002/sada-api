<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);
Route::get('/auth/google', [AuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/presentations', [PresentationController::class, 'index']); // جلب القائمة
    Route::post('/presentations', [PresentationController::class, 'store']);
    Route::post('/presentations/{id}/duplicate', [PresentationController::class, 'duplicate']); // نسخ
    Route::patch('/presentations/{id}/archive', [PresentationController::class, 'toggleArchive']); // أرشفة
    Route::get('/presentations/{id}/report', [PresentationController::class, 'getReport']); // تقرير
});
Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

Route::get('/update-db', function() {
    
    \Illuminate\Support\Facades\Artisan::call('migrate:fresh --seed --force');
    return 'Database Wiped & Re-Created Successfully with Full Details!';
});

Route::get('/reset-password/{token}', function ($token) {
    return redirect("http://localhost:5173/reset-password?token=$token");
})->name('password.reset');