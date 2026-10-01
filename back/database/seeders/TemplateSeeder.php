<?php

namespace Database\Seeders;

use App\Models\Template;
use Illuminate\Database\Seeder;

/**
 * Templates prédéfinis TicketLab.
 *
 * Toutes les coordonnées (qr_zone_json, fields_json) sont exprimées en pixels
 * dans la résolution NATIVE de l'image (width x height). Le générateur les met
 * à l'échelle si l'image réelle devait avoir une autre taille.
 *
 * Les images (resources/templates/images) sont des "fonds nettoyés" : les
 * textes d'origine et le QR d'exemple en sont retirés
 * (cf. tools/make_clean_plate.py).
 *
 * Schéma d'un champ :
 *   key          identifiant (clé envoyée par le front dans `fields`)
 *   label        libellé du formulaire
 *   type         text | textarea | counter (numéro auto-incrémenté par ticket)
 *   x, y         coin supérieur gauche de la boîte de texte
 *   maxWidth     largeur de la boîte (retour à la ligne automatique) ; sert
 *                aussi de référence à l'alignement center/right
 *   fontSize     taille en pixels de l'image
 *   fontWeight   400 | 500 | 600 | 700 | 800 (Poppins)
 *   lineHeight   interligne (multiplicateur de fontSize)
 *   color        #RRGGBB
 *   align        left | center | right
 *   required     champ obligatoire
 *   placeholder  texte par défaut affiché
 *
 * AJOUTER UN TEMPLATE : déposer l'image dans resources/templates/images puis
 * ajouter un bloc updateOrCreate ci-dessous (ou l'insérer en base), puis
 * relancer `php artisan db:seed --class=TemplateSeeder`.
 */
class TemplateSeeder extends Seeder
{
    public function run(): void
    {
        Template::updateOrCreate(
            ['type' => 'ticket', 'sector' => 'stand', 'name' => 'Ticket Stand V1'],
            [
                'description' => 'Ticket de stand avec valeur, description et QR code.',
                'image_path' => 'ticket_stand_v1.jpg',
                'width' => 1004,
                'height' => 650,
                'qr_zone_json' => [
                'x' => 609,
                'y' => 250,
                'width' => 300,
                'height' => 300,
            ],
                'fields_json' => [
                [
                    'key' => 'title',
                    'label' => 'Titre',
                    'type' => 'text',
                    'x' => 85,
                    'y' => 109,
                    'maxWidth' => 319,
                    'fontSize' => 47,
                    'fontFamily' => 'Poppins',
                    'fontWeight' => 700,
                    'color' => '#FFB400',
                    'align' => 'left',
                    'lineHeight' => 1.4,
                    'required' => true,
                    'placeholder' => 'Ticket Stand',
                ],
                [
                    'key' => 'ticket_number',
                    'label' => 'Numéro du ticket (automatique)',
                    'type' => 'counter',
                    'x' => 822,
                    'y' => 107,
                    'maxWidth' => 98,
                    'fontSize' => 48,
                    'fontFamily' => 'Poppins',
                    'fontWeight' => 700,
                    'color' => '#FFB400',
                    'align' => 'center',
                    'lineHeight' => 1.4,
                    'required' => false,
                    'placeholder' => '1',
                ],
                [
                    'key' => 'amount',
                    'label' => 'Montant',
                    'type' => 'text',
                    'x' => 217,
                    'y' => 268,
                    'maxWidth' => 248,
                    'fontSize' => 43,
                    'fontFamily' => 'Poppins',
                    'fontWeight' => 800,
                    'color' => '#156660',
                    'align' => 'left',
                    'lineHeight' => 1.4,
                    'required' => true,
                    'placeholder' => '5000 FCFA',
                ],
                [
                    'key' => 'description',
                    'label' => 'Description',
                    'type' => 'textarea',
                    'x' => 116,
                    'y' => 340,
                    'maxWidth' => 373,
                    'fontSize' => 25,
                    'fontFamily' => 'Poppins',
                    'fontWeight' => 500,
                    'color' => '#FFFFFF',
                    'align' => 'left',
                    'lineHeight' => 1.36,
                    'required' => true,
                    'placeholder' => 'Valable pour vos achats sur' . "\n" . 'ce stand',
                ],
                [
                    'key' => 'phone',
                    'label' => 'Téléphone',
                    'type' => 'text',
                    'x' => 177,
                    'y' => 427,
                    'maxWidth' => 282,
                    'fontSize' => 29,
                    'fontFamily' => 'Poppins',
                    'fontWeight' => 500,
                    'color' => '#FFFFFF',
                    'align' => 'left',
                    'lineHeight' => 1.4,
                    'required' => false,
                    'placeholder' => '+229 01 40 51 21 33',
                ],
                [
                    'key' => 'note',
                    'label' => 'Note',
                    'type' => 'textarea',
                    'x' => 147,
                    'y' => 494,
                    'maxWidth' => 325,
                    'fontSize' => 18,
                    'fontFamily' => 'Poppins',
                    'fontWeight' => 400,
                    'color' => '#FFFFFF',
                    'align' => 'left',
                    'lineHeight' => 1.33,
                    'required' => false,
                    'placeholder' => 'Présentez ce ticket au moment du' . "\n" . 'paiement.',
                ],
            ],
                'is_global' => true,
            ]
        );
        // ------------------------------------------------------------------
        // Visuel Ticketche partagé par les parcours PARKING et LAVAGE
        // (image : ticket_parking_v1.jpg, fond nettoyé par
        // tools/make_clean_plate.py). Deux lignes en base (une par secteur) pour
        // que la galerie les filtre normalement ; seul le slogan change.
        // Coordonnées en pixels natifs de l'image (2048 x 1326), calibrées sur
        // le visuel d'origine : ne les modifiez pas sans régénérer le fond.
        // ------------------------------------------------------------------
        $shared = [
            'image_path' => 'ticket_parking_v1.jpg',
            'width' => 2048,
            'height' => 1326,
            // Intérieur du cadre blanc arrondi : la zone de silence (4 %) ajoutée par le
            // générateur reste DANS le cadre, donc ses coins arrondis restent visibles
            'qr_zone_json' => ['x' => 240, 'y' => 448, 'width' => 660, 'height' => 660],
        ];

        $fields = static fn (string $tagline): array => [
            [
                // "N°" est déjà imprimé sur le fond : on ne dessine que le chiffre
                'key' => 'ticket_number', 'label' => 'Numéro du ticket', 'type' => 'counter',
                'x' => 1606, 'y' => 228, 'maxWidth' => 360, 'fontSize' => 163,
                'fontFamily' => 'Poppins', 'fontWeight' => 800, 'color' => '#F7A326',
                'align' => 'left', 'lineHeight' => 1.2, 'required' => false, 'placeholder' => '1',
            ],
            [
                'key' => 'service_name', 'label' => 'Nom du service', 'type' => 'text',
                'x' => 1087, 'y' => 570, 'maxWidth' => 930, 'fontSize' => 68,
                'fontFamily' => 'Poppins', 'fontWeight' => 700, 'color' => '#105163',
                'align' => 'left', 'lineHeight' => 1.2, 'required' => true, 'placeholder' => 'SERVICE TICKETCHE',
            ],
            [
                'key' => 'tagline', 'label' => 'Slogan', 'type' => 'text',
                'x' => 1024, 'y' => 712, 'maxWidth' => 480, 'fontSize' => 35,
                'fontFamily' => 'Poppins', 'fontWeight' => 400, 'color' => '#FFFFFF',
                'align' => 'left', 'lineHeight' => 1.2, 'required' => false, 'placeholder' => $tagline,
            ],
            [
                'key' => 'opening_hours', 'label' => 'Horaires', 'type' => 'text',
                'x' => 1512, 'y' => 714, 'maxWidth' => 248, 'fontSize' => 34,
                'fontFamily' => 'Poppins', 'fontWeight' => 600, 'color' => '#FFFFFF',
                'align' => 'center', 'lineHeight' => 1.2, 'required' => false, 'placeholder' => '7j/7 - 24h/24',
            ],
            [
                'key' => 'phone', 'label' => 'Téléphone', 'type' => 'text',
                'x' => 1157, 'y' => 875, 'maxWidth' => 800, 'fontSize' => 42,
                'fontFamily' => 'Poppins', 'fontWeight' => 400, 'color' => '#FFFFFF',
                'align' => 'left', 'lineHeight' => 1.2, 'required' => false, 'placeholder' => '+229 01 40 51 21 33',
            ],
            [
                'key' => 'location', 'label' => 'Adresse', 'type' => 'textarea',
                'x' => 1160, 'y' => 935, 'maxWidth' => 800, 'fontSize' => 40,
                'fontFamily' => 'Poppins', 'fontWeight' => 400, 'color' => '#FFFFFF',
                'align' => 'left', 'lineHeight' => 1.34, 'required' => false,
                'placeholder' => "Abomey-Calavi, Bidossessi\nVon en face de la pharmacie\nFleuve de vie.",
            ],
        ];

        // Anciens presets remplacés par ce visuel (évite les doublons dans la galerie)
        Template::where('type', 'ticket')->where('sector', 'lavage')
            ->where('name', 'Ticket Lavage V1')->delete();
        Template::where('type', 'ticket')->where('sector', 'parking')
            ->where('name', 'Ticket Parking V1')->delete();

        Template::updateOrCreate(
            ['type' => 'ticket', 'sector' => 'parking', 'name' => 'Ticket Parking-Lavage V1'],
            $shared + [
                'description' => 'Ticket Ticketche pour le stationnement : numéro, nom du service, horaires, téléphone et adresse.',
                'fields_json' => $fields('Solution de stationnement'),
                'is_global' => true,
            ]
        );

        Template::updateOrCreate(
            ['type' => 'ticket', 'sector' => 'lavage', 'name' => 'Ticket Parking-Lavage V1'],
            $shared + [
                'description' => 'Même visuel Ticketche, utilisé pour le lavage : slogan, horaires, téléphone et adresse modifiables.',
                'fields_json' => $fields('Solution de lavage auto'),
                'is_global' => true,
            ]
        );
    }
}
