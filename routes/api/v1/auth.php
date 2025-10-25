<?php

declare(strict_types=1);

use App\Http\Controllers\v1\AuthController;
use Illuminate\Support\Facades\Route;

// PUBLIC ROUTE
Route::post("login", [AuthController::class, 'login'])->name('login');
Route::post("register", [AuthController::class, 'register'])->name('register');

// PRIVATE ROUTE
Route::middleware('auth:api')->group(function () {
    Route::get('me', [AuthController::class, 'user'])->name('me');
});
