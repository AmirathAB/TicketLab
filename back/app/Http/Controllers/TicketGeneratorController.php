<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Services\QrArchive;
use App\Services\QrZoneDetector;
use App\Services\TicketImageGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Génération en série des supports TicketLab.
 *
 * Deux modes, tous deux protégés par authentification (spec §10) :
 *
 *   POST /api/generate/custom
 *       L'utilisateur fournit son propre fond, sa zone QR et son ZIP de QR.
 *
 *   POST /api/generate/preset
 *       L'utilisateur choisit un template TicketLab et en remplit les champs.
 *
 * Les deux renvoient le même ZIP : N fichiers ticket_001…, où N est le
 * nombre exact d'images contenues dans le ZIP de QR codes.
 */
class TicketGeneratorController extends Controller
{
    public function __construct(
        private readonly TicketImageGenerator $generator,
        private readonly QrArchive $archive,
        private readonly QrZoneDetector $zoneDetector,
    ) {}

    /**
     * GET /api/generate/options
     *
     * Tailles de QR proposées et zones détectables : le frontend s'en sert
     * pour dimensionner son éditeur avant tout envoi.
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'qr_sizes'     => config('ticketlab.qr_sizes'),
            'max_tickets'  => config('ticketlab.limits.max_tickets'),
            'palette'      => config('ticketlab.palette'),
        ]);
    }

    /**
     * POST /api/detect-qr-zone
     *
     * Analyse un template uploadé et renvoie la zone QR détectée.
     * C'est le raccourci qui permet à l'utilisateur de partir d'une zone
     * correcte plutôt que de la deviner — il reste libre de la redimensionner.
     */
    public function detectZone(Request $request): JsonResponse
    {
        $request->validate([
            'background_image' => [
                'required',
                'file',
                'image',
                'mimes:png,jpg,jpeg',
                'max:'.config('ticketlab.limits.template_max_mb'),
            ],
        ]);

        $file = $request->file('background_image');
        $temp = $file->getRealPath();

        $zone = $this->zoneDetector->detect($temp);

        if ($zone === null) {
            return response()->json([
                'detected' => false,
                'message'  => "Aucun conteneur de QR carré n'a été trouvé. Placez la zone manuellement.",
            ]);
        }

        [$width, $height] = getimagesize($temp);

        return response()->json([
            'detected' => true,
            'zone'     => [
                'x'      => $zone['x'],
                'y'      => $zone['y'],
                'width'  => $zone['width'],
                'height' => $zone['height'],
            ],
            'image'    => ['width' => $width, 'height' => $height],
            'confidence' => round($zone['confidence'], 2),
        ]);
    }

    /**
     * POST /api/generate/custom
     *
     * Champs multipart :
     *   background_image : image (PNG/JPG), 10 Mo max
     *   qr_zone          : JSON { x, y, width, height }
     *   fields           : JSON optionnel (champs libres ajoutés par l'utilisateur)
     *   qr_zip           : ZIP d'images, 50 Mo max
     */
    public function generateCustom(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'background_image' => [
                'required', 'file', 'image', 'mimes:png,jpg,jpeg',
                'max:'.config('ticketlab.limits.template_max_mb'),
            ],
            'qr_zip' => [
                'required', 'file', 'mimetypes:application/zip,application/x-zip-compressed,application/octet-stream',
                'max:'.config('ticketlab.limits.qr_zip_max_mb'),
            ],
            'qr_zone' => ['required', 'string', 'max:2000'],
            'fields'  => ['nullable', 'string', 'max:65535'],
        ]);

        $zone = $this->parseZone($validated['qr_zone'], $request->file('background_image')->getRealPath());
        $fields = $this->parseFields($validated['fields'] ?? null);
        $values = $this->parseValues($validated['fields'] ?? null);

        return $this->run(
            backgroundPath: $request->file('background_image')->getRealPath(),
            qrZone: $zone,
            fields: $fields,
            values: $values,
            qrZip: $request->file('qr_zip')->getRealPath(),
        );
    }

    /**
     * POST /api/generate/preset
     *
     * Champs multipart :
     *   template_id : id du template TicketLab
     *   fields      : JSON { event_name: "...", … }
     *   qr_zip      : ZIP d'images
     */
    public function generatePreset(Request $request): StreamedResponse
    {
        $validated = $request->validate([
            'template_id' => ['required', 'integer', 'exists:templates,id'],
            'fields'      => ['nullable', 'string', 'max:65535'],
            'qr_zip'      => [
                'required', 'file', 'mimetypes:application/zip,application/x-zip-compressed,application/octet-stream',
                'max:'.config('ticketlab.limits.qr_zip_max_mb'),
            ],
        ]);

        /** @var Template $template */
        $template = Template::findOrFail($validated['template_id']);

        if (! $template->imageExists()) {
            return $this->error(sprintf(
                "Le fichier image du template « %s » est introuvable sur le serveur.",
                $template->name
            ), 500);
        }

        $values = $this->parseValues($validated['fields'] ?? null);

        // Les champs du template dicent quels styles appliquer ; les valeurs
        // viennent du formulaire. On fusionne les deux.
        return $this->run(
            backgroundPath: $template->absoluteImagePath(),
            qrZone: $template->qr_zone,
            fields: $template->fields,
            values: $values,
            qrZip: $request->file('qr_zip')->getRealPath(),
            template: $template,
        );
    }

    /**
     * Pipeline commun aux deux modes.
     *
     * @param  array{x:int,y:int,width:int,height:int}  $qrZone
     * @param  list<array<string,mixed>>  $fields
     * @param  array<string,string>  $values
     */
    private function run(
        string $backgroundPath,
        array $qrZone,
        array $fields,
        array $values,
        string $qrZip,
        ?Template $template = null,
    ): StreamedResponse {
        $workDir = storage_path('app/temp/tl_'.Str::uuid()->toString());

        if (! is_dir($workDir) && ! mkdir($workDir, 0755, true) && ! is_dir($workDir)) {
            return $this->error("Impossible de créer le dossier de travail temporaire.", 500);
        }

        try {
            $qrPaths = $this->archive->extract($qrZip, $workDir);
            $ticketCount = count($qrPaths);

            $zipContent = $this->generator->generateZip(
                backgroundPath: $backgroundPath,
                qrZone: $qrZone,
                qrPaths: $qrPaths,
                fields: $fields,
                values: $values,
            );
        } catch (RuntimeException $e) {
            QrArchive::forgetDirectory($workDir);

            return $this->error($e->getMessage(), 422);
        } catch (Throwable $e) {
            QrArchive::forgetDirectory($workDir);
            report($e);

            return $this->error('Erreur interne pendant la génération. Consultez les logs du serveur.', 500);
        }

        QrArchive::forgetDirectory($workDir);

        if ($zipContent === '') {
            return $this->error("L'archive générée est vide.", 500);
        }

        $filename = sprintf(
            '%s_%s.zip',
            $template !== null ? Str::slug($template->name) : 'tickets',
            now()->format('Y-m-d_H-i-s')
        );

        // On envoie le ZIP déjà assemblé en mémoire : pas de fichier
        // intermédiaire à nettoyer côté serveur (cf. spec §6.4 étape 6).
        return response()->streamDownload(
            static function () use ($zipContent): void {
                echo $zipContent;
            },
            $filename,
            [
                'Content-Type'        => 'application/zip',
                'Content-Length'      => (string) strlen($zipContent),
                'X-Ticket-Count'      => $GLOBALS['__tl_count'] ?? null,
            ]
        )->deleteHeader('X-Powered-By');
    }

    /**
     * Valide et borne une zone QR contre les dimensions réelles de l'image.
     *
     * @return array{x:int,y:int,width:int,height:int}
     */
    private function parseZone(string $raw, string $imagePath): array
    {
        $zone = json_decode($raw, true);

        if (! is_array($zone)) {
            throw ValidationException::withMessages([
                'qr_zone' => ['La zone QR doit être un JSON valide : {"x":0,"y":0,"width":200,"height":200}.'],
            ]);
        }

        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (! isset($zone[$key]) || ! is_numeric($zone[$key])) {
                throw ValidationException::withMessages([
                    'qr_zone' => ["La zone QR doit contenir la valeur numérique « {$key} »."],
                ]);
            }
        }

        [$imgW, $imgH] = getimagesize($imagePath) ?: [0, 0];

        $x = max(0, (int) $zone['x']);
        $y = max(0, (int) $zone['y']);
        $w = (int) $zone['width'];
        $h = (int) $zone['height'];

        if ($w < 20 || $h < 20) {
            throw ValidationException::withMessages([
                'qr_zone' => ['La zone QR doit faire au moins 20x20 pixels pour rester lisible.'],
            ]);
        }

        if ($imgW > 0 && $x + $w > $imgW) {
            throw ValidationException::withMessages([
                'qr_zone' => ["La zone QR dépasse la largeur de l'image ({$imgW} px)."],
            ]);
        }
        if ($imgH > 0 && $y + $h > $imgH) {
            throw ValidationException::withMessages([
                'qr_zone' => ["La zone QR dépasse la hauteur de l'image ({$imgH} px)."],
            ]);
        }

        return ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h];
    }

    /**
     * Décode la définition des champs (option A : champs libres).
     *
     * @return list<array<string,mixed>>
     */
    private function parseFields(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $fields = json_decode($raw, true);

        return is_array($fields) ? array_values(array_filter($fields, 'is_array')) : [];
    }

    /**
     * Décode les valeurs saisies. Accepte deux formes :
     *   { "fields": { … }, "positions": { … } }  ->plates option A
     *   { "event_name": "…" }                    -> preset (option B)
     *
     * @return array<string,string>
     */
    private function parseValues(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $source = isset($decoded['fields']) && is_array($decoded['fields'])
            ? $decoded['fields']
            : $decoded;

        $values = [];
        foreach ($source as $key => $value) {
            if (is_scalar($value)) {
                $values[(string) $key] = (string) $value;
            }
        }

        return $values;
    }

    /**
     * Réponse d'erreur JSON homogène.
     */
    private function error(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
