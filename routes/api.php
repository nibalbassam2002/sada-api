<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PresentationController;
use App\Http\Controllers\Api\SessionController;

// Auth Routes
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login',    [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password',  [AuthController::class, 'resetPassword']);
Route::get('/auth/google',          [AuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);

Route::get('/reset-password/{token}', function ($token) {
    return redirect("http://localhost:5173/reset-password?token=$token");
})->name('password.reset');

// Public Session Routes (no auth — participants)
Route::get( 'sessions/{code}/info',              [SessionController::class, 'info']);
Route::post('sessions/join',                     [SessionController::class, 'join']);
Route::get( 'sessions/{id}/status',              [SessionController::class, 'status']);
Route::get( 'sessions/{id}/current-slide',       [SessionController::class, 'currentSlide']);
Route::post('sessions/{id}/answer',              [SessionController::class, 'submitAnswer']);
Route::get( 'sessions/{id}/results/{slideId}',   [SessionController::class, 'slideResults']);
Route::get( 'sessions/{id}/slide-results/{slideId}', [SessionController::class, 'slideResults']);
Route::get('sessions/{id}/question-report/{slideId}', [SessionController::class, 'questionReport']);
Route::get('sessions/{id}/user-remaining-time', [SessionController::class, 'getUserRemainingTime']);
// Protected Routes (auth required — presenter)
Route::middleware('auth:sanctum')->group(function () {

    // User 
    Route::get('/user', fn(Request $request) => $request->user());

    // Presentations 
    Route::post('/presentations/import-pptx',        [PresentationController::class, 'importPptx']);
    Route::get( '/presentations',                    [PresentationController::class, 'index']);
    Route::post('/presentations',                    [PresentationController::class, 'store']);
    Route::get( '/presentations/{id}',               [PresentationController::class, 'show']);
    Route::post('/presentations/{id}/duplicate',     [PresentationController::class, 'duplicate']);
    Route::patch('/presentations/{id}/archive',      [PresentationController::class, 'toggleArchive']);
    Route::get( '/presentations/{id}/report',        [PresentationController::class, 'getReport']);
    Route::delete('/presentations/{id}',             [PresentationController::class, 'destroy']);
    Route::patch('/presentations/{id}/title',        [PresentationController::class, 'updateTitle']);
    Route::post('/presentations/{id}/sync',          [PresentationController::class, 'syncSlides']);

    // Sessions (presenter) 
    Route::post('presentations/{id}/sessions/start', [SessionController::class, 'start']);
    Route::get( 'presentations/{id}/sessions/current',[SessionController::class, 'current']);
    Route::get('presentations/{id}/sessions', [SessionController::class, 'listSessions']);

    Route::post('sessions/{id}/launch',         [SessionController::class, 'launch']);
    Route::post('sessions/{id}/slide',          [SessionController::class, 'changeSlide']);
    Route::post('sessions/{id}/end',            [SessionController::class, 'end']);
    Route::post('sessions/{id}/expire-timer',   [SessionController::class, 'expireTimer']);
    Route::post('sessions/{id}/close-question', [SessionController::class, 'closeQuestion']);
    Route::post('sessions/{id}/reveal-results', [SessionController::class, 'revealResults']);
    Route::post('sessions/{id}/hide-results',   [SessionController::class, 'hideResults']);

    Route::get('sessions/{id}/participants',        [SessionController::class, 'participants']);
    Route::get('sessions/{id}/report',              [SessionController::class, 'generateReport']);
    Route::put('/user/profile',  [AuthController::class, 'updateProfile']);
    Route::put('/user/password', [AuthController::class, 'updatePassword']);
    Route::post('/user/set-password', [AuthController::class, 'setPassword']);

});

// Dev Only — Reset DB
Route::get('/update-db', function () {
    \Illuminate\Support\Facades\Artisan::call('migrate:fresh --seed --force');
    return 'Database Wiped & Re-Created Successfully with Full Details!';
});