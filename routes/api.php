<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);


Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
Route::get('/update-db', function() {
    try {
        // 1. مسح وإنشاء الجداول من الصفر (بدون تشغيل السيدر التلقائي لتجنب خطأ fake)
        \Illuminate\Support\Facades\Artisan::call('migrate:fresh --force');

        // 2. تشغيل صفحة السوبر أدمن التي أنشأتها أنت خصيصاً بالاسم
        \Illuminate\Support\Facades\Artisan::call('db:seed', [
            '--class' => 'SuperAdminSeeder',
            '--force' => true
        ]);

        return 'Success: Database wiped, migrated, and SuperAdminSeeder executed!';
    } catch (\Exception $e) {
        // في حال حدوث أي خطأ، سيخبرك به هنا بدلاً من تعليق الموقع
        return 'Error: ' . $e->getMessage();
    }
});