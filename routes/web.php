<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect(auth()->check() ? '/home' : '/login'));
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'page'])->name('login');
    Route::get('/register', [AuthController::class, 'page'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    if (app()->environment('local')) Route::post('/demo', [AuthController::class, 'demo'])->middleware('throttle:10,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    foreach (['home', 'today', 'calendar', 'projects', 'goals', 'ideas', 'notes', 'habits', 'finance', 'content', 'reviews', 'settings', 'assistant'] as $page) Route::get('/'.$page, WorkspaceController::class);
    Route::get('/projects/{id}', WorkspaceController::class);
    Route::get('/goals/{id}', WorkspaceController::class);
    Route::get('/search', SearchController::class)->middleware('throttle:90,1');
    Route::get('/review-summary', [RecordController::class, 'reviewSummary']);
    Route::patch('/settings', SettingsController::class);
    Route::get('/daily-checkins', [\App\Http\Controllers\DailyCheckinController::class, 'index']);
    Route::put('/daily-checkins', [\App\Http\Controllers\DailyCheckinController::class, 'save']);
    Route::post('/data/tasks/{id}/complete', [RecordController::class, 'complete']);
    Route::post('/data/projects/{id}/focus', [RecordController::class, 'focus']);
    Route::post('/data/habits/{id}/toggle', [RecordController::class, 'toggle']);
    Route::post('/data/ideas/{id}/convert', [RecordController::class, 'convert']);
    Route::post('/data/{entity}/{id}/move', [RecordController::class, 'move']);
    Route::get('/data/{entity}', [RecordController::class, 'index']);
    Route::get('/data/{entity}/{id}', [RecordController::class, 'show']);
    Route::post('/data/{entity}', [RecordController::class, 'store']);
    Route::patch('/data/{entity}/{id}', [RecordController::class, 'update']);
    Route::delete('/data/{entity}/{id}', [RecordController::class, 'destroy']);
    Route::post('/quick-capture/{type}', [RecordController::class, 'capture']);
});
