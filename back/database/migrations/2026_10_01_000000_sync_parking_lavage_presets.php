<?php

use Database\Seeders\TemplateSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Synchronise les templates prédéfinis (Parking + Lavage partagent désormais le
 * visuel ticket_parking_v1.jpg).
 *
 * Pourquoi une migration : un simple `php artisan migrate` suffit ainsi à mettre
 * la base à jour (galerie « Aucun template disponible » pour Parking, ancien
 * « Ticket Lavage V1 » encore affiché). TemplateSeeder est idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new TemplateSeeder())->run();
    }

    public function down(): void
    {
        // Rien à défaire : les presets sont de simples lignes de configuration.
    }
};
