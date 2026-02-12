<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);


Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
Route::get('/update-db', function() {
    // استخدمنا fresh بدلاً من refresh
    // fresh: يحذف الجداول فوراً دون النظر لدالة down
    \Illuminate\Support\Facades\Artisan::call('migrate:fresh --seed --force');
    return 'Database Wiped & Re-Created Successfully with Full Details!';
});