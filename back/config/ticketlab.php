<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Templates
    |---------------------------------------------------------------------------
    | Les templates prédéfinis vivent dans resources/templates/images.
    | `image_path` en base est toujours relatif à ce dossier.
    */
    'templates_path' => resource_path('templates/images'),

    /*
    |---------------------------------------------------------------------------
    | Polices
    |---------------------------------------------------------------------------
    | Poppins est la police officielle Ticketche. Elle est partagée entre le
    | backend (rendu GD) et le frontend (aperçu) pour que l'aperçu corresponde
    | exactement au rendu final. Ajoutez simplement le .ttf dans resources/fonts
    | puis référencez-le dans la map ci-dessous ET dans src/styles/fonts.css.
    */
    'fonts_path' => resource_path('fonts'),

    'fonts' => [
        400 => 'Poppins-Regular.ttf',
        500 => 'Poppins-Medium.ttf',
        600 => 'Poppins-SemiBold.ttf',
        700 => 'Poppins-Bold.ttf',
        800 => 'Poppins-ExtraBold.ttf',
    ],

    // Police de repli si un template en demande une absente
    'font_fallback' => 'Poppins-Regular.ttf',

    /*
    |---------------------------------------------------------------------------
    | Charte graphique TicketLab
    |---------------------------------------------------------------------------
    | Palette extraite des templates officiels. Sert notamment au mode
    | `color: "auto"` : le générateur échantillonne le fond sous le champ
    | et choisit automatiquement une couleur de texte lisible.
    */
    'palette' => [
        'gold'            => '#FFB400',
        'teal_dark'       => '#015F69',
        'teal_medium'     => '#037580',
        'teal_light'      => '#087D86',
        'green_teal'      => '#226E61',
        'green_teal_alt'  => '#267060',
        'white'           => '#FFFFFF',
        'black'           => '#000000',
        'text_on_gold'    => '#156660',
    ],

    /*
    |---------------------------------------------------------------------------
    | Tailles de QR prédéfinies (option A : template personnel)
    |---------------------------------------------------------------------------
    | Ces valeurs sont celles des QR générés par Ticketche. Elles restent
    | proposées à l'utilisateur comme raccourcis, mais la zone reste
    | librement redimensionnable à la souris.
    */
    'qr_sizes' => [150, 200, 250, 300, 400],

    /*
    |---------------------------------------------------------------------------
    | Limites techniques
    |---------------------------------------------------------------------------
    | AUCUNE limite métier sur le nombre de tickets (cf. spec §12) : le
    | générateur en accepte 1, 30, 100, 500... Ces valeurs sont uniquement
    | les garde-fous techniques du serveur (upload PHP / temps d'exécution).
    */
    'limits' => [
        'template_max_mb'    => 10,   // spec §6.1 : template perso 10 Mo
        'qr_zip_max_mb'      => 50,   // spec §6.3 : ZIP de QR 50 Mo
        'max_tickets'        => 2000, // garde-fou technique (pas une limite métier)
        'max_uncompressed_mb' => 2048, // anti "zip bomb"
    ],

    /*
    |---------------------------------------------------------------------------
    | Rendu
    |---------------------------------------------------------------------------
    | `output_format` : 'source' (PNG si le fond est un PNG, sinon JPEG)
    | `jpeg_quality`  : qualité JPEG des sorties. 92 conserve la netteté des
    |                   modules QR tout en divisant le poids par ~5.
    */
    'output_format' => 'source',
    'jpeg_quality'  => 92,

    /*
    |---------------------------------------------------------------------------
    | Zone QR
    |---------------------------------------------------------------------------
    | `quiet_zone_ratio` : marge blanche ajoutée autour du QR. La spec demande
    | un fond blanc et une zone de silence pour la lisibilité du code.
    */
    'qr_quiet_zone_ratio' => 0.04,

];
