<?php

declare(strict_types=1);

use App\Http\Controllers\v1\ConversationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->group(function () {
    Route::get('/', [ConversationController::class, 'index'])->name('private.index');
    Route::get('/{id}', [ConversationController::class, 'show'])->name('private.show');
    Route::post('/send', [ConversationController::class, 'sendMessage'])->name('private.send');
    Route::put('/{id}', [ConversationController::class, 'update'])->name('private.update');
    Route::delete('/{id}', [ConversationController::class, 'destroy'])->name('private.delete');
});
