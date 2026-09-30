<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TicketGeneratorController;
use Illuminate\Support\Facades\Route;

// Routes publiques
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Templates (public pour test, à protéger plus tard)
Route::get('/templates', [TicketGeneratorController::class, 'listTemplates']);

// Routes protégées
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/generate/custom', [TicketGeneratorController::class, 'generateFromCustom']);
    Route::post('/generate/preset', [TicketGeneratorController::class, 'generateFromPreset']);
});