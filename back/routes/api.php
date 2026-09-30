<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TemplateController;
use App\Http\Controllers\TicketGeneratorController;
use Illuminate\Support\Facades\Route;

// ---------------------------------------------------------------------------
// Routes publiques
// ---------------------------------------------------------------------------
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// Inscription fermée par défaut : activez-la avec ALLOW_REGISTRATION=true
// (un compte permet de lancer des générations sur votre serveur).
Route::post('/register', [AuthController::class, 'register']);

// Image de fond d'un template : publique car chargée par une balise <img>
// (qui ne peut pas envoyer de token). Ce sont des visuels de démonstration.
Route::get('/templates/{template}/image', [TemplateController::class, 'image'])
    ->whereNumber('template')
    ->name('templates.image');

// ---------------------------------------------------------------------------
// Routes protégées (Bearer token Sanctum)
// ---------------------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // "meta" doit rester AVANT "{template}"
    Route::get('/templates', [TemplateController::class, 'index']);
    Route::get('/templates/meta', [TemplateController::class, 'meta']);
    Route::get('/templates/{template}', [TemplateController::class, 'show'])->whereNumber('template');

    Route::post('/generate/custom', [TicketGeneratorController::class, 'generateFromCustom']);
    Route::post('/generate/preset', [TicketGeneratorController::class, 'generateFromPreset']);
});
