<?php

use Illuminate\Support\Facades\Route;

// // Public routes
// Route::post('/register', [AuthController::class, 'register']);
// Route::post('/login', [AuthController::class, 'login']);

// // Protected routes
// Route::middleware('auth:sanctum')->group(function () {
//     Route::post('/logout', [AuthController::class, 'logout']);
//     Route::get('/user', [AuthController::class, 'user']);
// });



// AUTHENTICATION
Route::prefix('auth')->as('auth:')->group(
    base_path('routes/api/v1/auth.php'),
);
