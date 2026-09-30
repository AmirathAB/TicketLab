<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Table 'templates' : stocke les templates prédéfinis (ticket, flyer, affiche)
     * pour chaque secteur (stand, événement, parking, garage, lavage).
     * Chaque template contient :
     * - Une image de fond
     * - Une zone QR prédéfinie (x, y, width, height)
     * - Des champs texte éditables (nom, date, lieu, prix, etc.)
     */
    public function up(): void
    {
        Schema::create('templates', function (Blueprint $table) {
            $table->id();
            
            // Type de support : ticket, flyer ou affiche
            $table->enum('type', ['ticket', 'flyer', 'affiche']);
            
            // Secteur d'usage : stand, événement, parking, garage, lavage
            $table->enum('sector', ['stand', 'evenement', 'parking', 'garage', 'lavage']);
            
            // Nom du template (ex: "Ticket Stand V1")
            $table->string('name');
            
            // Description optionnelle
            $table->text('description')->nullable();
            
            // Chemin vers l'image du template (dans resources/templates/images/)
            $table->string('image_path');
            
            // Dimensions originales du template en pixels
            $table->integer('width');
            $table->integer('height');
            
            // Zone QR : {"x": 900, "y": 80, "width": 200, "height": 200}
            $table->json('qr_zone_json');
            
            // Champs texte éditables : [{"key": "event_name", "label": "Nom", "x": 80, "y": 80, ...}]
            $table->json('fields_json');
            
            // Template global (disponible pour tous) ou spécifique à un utilisateur
            $table->boolean('is_global')->default(true);
            
            $table->timestamps();
            
            // Index pour filtrer rapidement par type et secteur
            $table->index(['type', 'sector']);
            $table->index('is_global');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('templates');
    }
};