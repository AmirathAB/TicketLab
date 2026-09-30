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

        Template::updateOrCreate(
            ['type' => 'ticket', 'sector' => 'lavage', 'name' => 'Ticket Lavage V1'],
            [
                'description' => 'Affiche de lavage auto avec offre, code de parrainage et QR code.',
                'image_path' => 'ticket_lavage_v1.jpg',
                'width' => 2362,
                'height' => 2362,
                'qr_zone_json' => [
                'x' => 264,
                'y' => 1483,
                'width' => 356,
                'height' => 356,
            ],
                'fields_json' => [
                [
                    'key' => 'title',
                    'label' => 'Titre',
                    'type' => 'text',
                    'x' => 357,
                    'y' => 250,
                    'maxWidth' => 1650,
                    'fontSize' => 110,
                    'fontFamily' => 'Poppins',
                    'fontWeight' => 800,
                    'color' => '#156660',
                    'align' => 'center',
                    'lineHeight' => 1.4,
                    'required' => true,
                    'placeholder' => 'LAVAGE SHILO SERVICE',
                ],
                [
                    'key' => 'tagline',
                    'label' => 'Accroche',
                    'type' => 'textarea',
                    'x' => 231,
                    'y' => 447,
                    'maxWidth' => 1900,
                    'fontSize' => 66,
                    'fontFamily' => 'Poppins',
                    'fontWeight' => 500,
                    'color' => '#FFFFFF',
                    'align' => 'center',
                    'lineHeight' => 1.3,
                    'required' => true,
                    'placeholder' => 'Votre meilleur compagnon pour la propreté' . "\n" . 'de votre véhicule.',
                ],
                [
                    'key' => 'heading',
                    'label' => 'Titre du panneau',
                    'type' => 'textarea',
                    'x' => 275,
                    'y' => 877,
                    'maxWidth' => 752,
                    'fontSize' => 65,
                    'fontFamily' => 'Poppins',
                    'fontWeight' => 500,
                    'color' => '#FFFFFF',
                    'align' => 'left',
                    'lineHeight' => 1.34,
                    'required' => false,
                    'placeholder' => 'Désormais disponible' . "\n" . 'sur Ticketché !',
                ],
                [
                    'key' => 'promo',
                    'label' => 'Offre',
                    'type' => 'textarea',
                    'x' => 286,
                    'y' => 1140,
                    'maxWidth' => 861,
                    'fontSize' => 50,
                    'fontFamily' => 'Poppins',
                    'fontWeight' => 500,
                    'color' => '#015F69',
                    'align' => 'left',
                    'lineHeight' => 1.4,
                    'required' => true,
                    'placeholder' => 'Profitez de 100% de réduction' . "\n" . 'sur votre première prestation en' . "\n" . 'réservant via l’application' . "\n" . 'Ticketché.',
                ],
                [
                    'key' => 'download',
                    'label' => 'Téléchargement',
                    'type' => 'textarea',
                    'x' => 692,
                    'y' => 1500,
                    'maxWidth' => 332,
                    'fontSize' => 51,
                    'fontFamily' => 'Poppins',
                    'fontWeight' => 500,
                    'color' => '#FFFFFF',
                    'align' => 'left',
                    'lineHeight' => 1.39,
                    'required' => false,
                    'placeholder' => 'Téléchargez' . "\n" . 'Ticketché !',
                ],
                [
                    'key' => 'bullets',
                    'label' => 'Puces',
                    'type' => 'textarea',
                    'x' => 757,
                    'y' => 1698,
                    'maxWidth' => 289,
                    'fontSize' => 52,
                    'fontFamily' => 'Poppins',
                    'fontWeight' => 600,
                    'color' => '#015F69',
                    'align' => 'left',
                    'lineHeight' => 1.37,
                    'required' => false,
                    'placeholder' => '• Réservez' . "\n" . '• Profitez',
                ],
                [
                    'key' => 'referral_label',
                    'label' => 'Libellé du code',
                    'type' => 'text',
                    'x' => 292,
                    'y' => 2001,
                    'maxWidth' => 819,
                    'fontSize' => 57,
                    'fontFamily' => 'Poppins',
                    'fontWeight' => 600,
                    'color' => '#FFB400',
                    'align' => 'left',
                    'lineHeight' => 1.4,
                    'required' => false,
                    'placeholder' => 'Votre code de parrainage :',
                ],
                [
                    'key' => 'referral_code',
                    'label' => 'Code de parrainage',
                    'type' => 'text',
                    'x' => 190,
                    'y' => 2120,
                    'maxWidth' => 1057,
                    'fontSize' => 76,
                    'fontFamily' => 'Poppins',
                    'fontWeight' => 700,
                    'color' => '#015F69',
                    'align' => 'left',
                    'lineHeight' => 1.4,
                    'required' => true,
                    'placeholder' => 'TKT - SHILOSERVICES3458',
                ],
                [
                    'key' => 'help_label',
                    'label' => 'Libellé aide',
                    'type' => 'text',
                    'x' => 1501,
                    'y' => 2083,
                    'maxWidth' => 437,
                    'fontSize' => 51,
                    'fontFamily' => 'Poppins',
                    'fontWeight' => 500,
                    'color' => '#015F69',
                    'align' => 'left',
                    'lineHeight' => 1.4,
                    'required' => false,
                    'placeholder' => 'Besoins d’aide ?',
                ],
                [
                    'key' => 'help_phone',
                    'label' => 'Téléphone d’aide',
                    'type' => 'text',
                    'x' => 1492,
                    'y' => 2148,
                    'maxWidth' => 710,
                    'fontSize' => 70,
                    'fontFamily' => 'Poppins',
                    'fontWeight' => 700,
                    'color' => '#015F69',
                    'align' => 'left',
                    'lineHeight' => 1.4,
                    'required' => false,
                    'placeholder' => '+229 01 99 98 43 45',
                ],
            ],
                'is_global' => true,
            ]
        );
    }
}
