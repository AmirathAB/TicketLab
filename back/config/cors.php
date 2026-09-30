<?php

/*
| CORS : seul le front TicketLab (FRONTEND_URL) peut appeler l'API depuis un
| navigateur. Content-Disposition est exposé pour que le front puisse lire le
| nom du ZIP téléchargé.
*/
return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => array_filter(array_map('trim', explode(',', (string) env('FRONTEND_URL', 'http://localhost:5173')))),
    // En local uniquement : accepte localhost / 127.0.0.1 sur n'importe quel port
    // (Vite bascule sur 5174, 5175... si 5173 est déjà pris).
    'allowed_origins_patterns' => env('APP_ENV') === 'local'
        ? ['#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#']
        : [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['Content-Disposition'],
    'max_age' => 0,
    'supports_credentials' => false,
];
