<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Table des templates TicketLab.
 *
 * Un template décrit entièrement un support imprimable :
 *  - l'image de fond (image_path, relatif à config('ticketlab.templates_path'))
 *  - ses dimensions natives (width/height) : toutes les coordonnées stockées
 *    dans qr_zone_json et fields_json sont exprimées dans cette résolution.
 *  - la zone du QR code (qr_zone_json)
 *  - les champs texte éditables (fields_json)
 *
 * Le format est volontairement générique : ajouter un template = ajouter une
 * ligne + une image, sans toucher au code de génération.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['ticket', 'flyer', 'affiche'])->index();
            $table->enum('sector', ['stand', 'evenement', 'parking', 'garage', 'lavage'])->index();
            $table->string('name');
            $table->text('description')->nullable();

            // Chemin de l'image, relatif à config('ticketlab.templates_path')
            $table->string('image_path');

            // Résolution native : référence de toutes les coordonnées
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');

            // { x, y, width, height }
            $table->json('qr_zone_json');

            // [ { key, label, type, x, y, fontSize, fontFamily, color, ... } ]
            $table->json('fields_json');

            $table->boolean('is_global')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};
