<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

/**
 * Seed des templates initiaux
 * 
 * Templates disponibles :
 * - ticket_stand_v1 : Ticket pour stand (1200x600px)
 * - ticket_lavage_v1 : Ticket pour lavage (1200x600px)
 * 
 * Les autres templates (flyer, affiche, parking, garage, événement) 
 * seront ajoutés ultérieurement.
 */
class TemplateSeeder extends Seeder
{
    public function run(): void
    {
        // Template 1 : Ticket Stand V1
        Template::create([
            'type' => 'ticket',
            'sector' => 'stand',
            'name' => 'Ticket Stand V1',
            'description' => 'Template de ticket pour stand avec zone QR prédéfinie',
            'image_path' => 'templates/Stand.jpg',
            'width' => 1200,
            'height' => 600,
            'qr_zone_json' => [
                'x' => 900,
                'y' => 80,
                'width' => 200,
                'height' => 200,
            ],
            'fields_json' => [
                [
                    'key' => 'event_name',
                    'label' => 'Nom de l\'événement',
                    'type' => 'text',
                    'x' => 80,
                    'y' => 80,
                    'maxWidth' => 600,
                    'fontSize' => 36,
                    'fontFamily' => 'Inter',
                    'color' => '#ffffff',
                    'align' => 'left',
                    'fontWeight' => 700,
                    'required' => true,
                    'placeholder' => 'Nom de l\'événement',
                ],
                [
                    'key' => 'event_date',
                    'label' => 'Date et heure',
                    'type' => 'text',
                    'x' => 80,
                    'y' => 130,
                    'maxWidth' => 400,
                    'fontSize' => 20,
                    'fontFamily' => 'Inter',
                    'color' => '#cbd5e1',
                    'align' => 'left',
                    'fontWeight' => 400,
                    'required' => true,
                    'placeholder' => 'Date et heure',
                ],
                [
                    'key' => 'location',
                    'label' => 'Lieu',
                    'type' => 'text',
                    'x' => 80,
                    'y' => 160,
                    'maxWidth' => 400,
                    'fontSize' => 18,
                    'fontFamily' => 'Inter',
                    'color' => '#94a3b8',
                    'align' => 'left',
                    'fontWeight' => 400,
                    'required' => false,
                    'placeholder' => 'Lieu (optionnel)',
                ],
                [
                    'key' => 'price',
                    'label' => 'Prix',
                    'type' => 'text',
                    'x' => 80,
                    'y' => 190,
                    'maxWidth' => 200,
                    'fontSize' => 24,
                    'fontFamily' => 'Inter',
                    'color' => '#FFB400',
                    'align' => 'left',
                    'fontWeight' => 600,
                    'required' => false,
                    'placeholder' => 'Prix (optionnel)',
                ],
            ],
            'is_global' => true,
        ]);

        // Template 2 : Ticket Lavage V1
        Template::create([
            'type' => 'ticket',
            'sector' => 'lavage',
            'name' => 'Ticket Lavage V1',
            'description' => 'Template de ticket pour lavage auto avec zone QR prédéfinie',
            'image_path' => 'templates/Lavage.jpg',
            'width' => 1200,
            'height' => 600,
            'qr_zone_json' => [
                'x' => 900,
                'y' => 80,
                'width' => 200,
                'height' => 200,
            ],
            'fields_json' => [
                [
                    'key' => 'service_name',
                    'label' => 'Nom du service',
                    'type' => 'text',
                    'x' => 80,
                    'y' => 80,
                    'maxWidth' => 600,
                    'fontSize' => 36,
                    'fontFamily' => 'Inter',
                    'color' => '#ffffff',
                    'align' => 'left',
                    'fontWeight' => 700,
                    'required' => true,
                    'placeholder' => 'Nom du service',
                ],
                [
                    'key' => 'valid_until',
                    'label' => 'Valable jusqu\'au',
                    'type' => 'text',
                    'x' => 80,
                    'y' => 130,
                    'maxWidth' => 400,
                    'fontSize' => 20,
                    'fontFamily' => 'Inter',
                    'color' => '#cbd5e1',
                    'align' => 'left',
                    'fontWeight' => 400,
                    'required' => true,
                    'placeholder' => 'Valable jusqu\'au',
                ],
                [
                    'key' => 'car_plate',
                    'label' => 'Immatriculation',
                    'type' => 'text',
                    'x' => 80,
                    'y' => 160,
                    'maxWidth' => 300,
                    'fontSize' => 18,
                    'fontFamily' => 'Inter',
                    'color' => '#94a3b8',
                    'align' => 'left',
                    'fontWeight' => 400,
                    'required' => false,
                    'placeholder' => 'Immatriculation (optionnel)',
                ],
            ],
            'is_global' => true,
        ]);
    }
}