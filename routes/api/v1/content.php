<?php

declare(strict_types=1);

use App\Http\Controllers\v1\ContentGenerationController;
use Illuminate\Support\Facades\Route;

// PUBLIC ROUTE - Limited to 50 requests per IP
Route::middleware('rate.limit.ip:50')->group(function () {
    Route::post('generate', [ContentGenerationController::class, 'generate'])->name('public.generate');
});

// PRIVATE ROUTE - Unlimited requests (requires authentication)
Route::middleware('auth:api')->group(function () {
    Route::post('generate-unlimited', [ContentGenerationController::class, 'generate'])->name('private.generate');
    Route::post('generate-with-conversation', [ContentGenerationController::class, 'generateWithConversation'])->name('private.generate-conversation');
});
