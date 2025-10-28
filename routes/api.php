<?php

use Illuminate\Support\Facades\Route;

// AUTHENTICATION
Route::prefix('auth')->as('auth:')->group(
    base_path('routes/api/v1/auth.php'),
);

// CONTENT GENERATION
Route::prefix('content')->as('content:')->group(
    base_path('routes/api/v1/content.php'),
);
