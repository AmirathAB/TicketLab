<?php

namespace App\Services;

use App\Models\Template;
use GdImage;
use RuntimeException;
use ZipArchive;

/**
 * Composition des tickets / flyers / affiches (GD + FreeType).
 *
 * Pour chaque QR code du ZIP fourni, le service produit UNE image :
 *   fond  +  textes (mode préréglé)  +  QR code
 * puis assemble toutes les images dans un ZIP final.
 *
 * Choix de conception :
 *  - Les textes sont dessinés avec imagettftext() et les polices Poppins
 *    (resources/fonts) : accents, graisses et tailles fonctionnent, et le
 *    rendu est le même que l'aperçu du front (qui utilise les mêmes .ttf).
 *  - GD mesure les tailles en POINTS : px * 0.75 (voir PX_TO_PT).
 *  - Les textes identiques pour tous les tickets sont dessinés UNE seule fois
 *    sur une image "de base" ; seul le numéro (champ `counter`) et le QR sont
 *    redessinés par ticket. C'est ce qui garde 100+ tickets rapides.
 *  - Tous les fichiers temporaires vivent dans un dossier unique supprimé par
 *    cleanup() (appelé par le contrôleur après l'envoi du ZIP).
 *
 * Toutes les erreurs "utilisateur" (zone hors image, ZIP vide...) sont des
 * RuntimeException au message français, affichable tel quel.
 */
class TicketImageGenerator
{
    /** GD attend des points, les coordonnées du template sont en pixels. */
    private const PX_TO_PT = 0.75;

    /** Métriques verticales de Poppins (hhea) : ascendante + descendante = 1.4 em. */
    private const ASCENT = 1.05;
    private const NATURAL_LINE_HEIGHT = 1.4;

    private ?string $workDir = null;

    public function __construct(private readonly QrArchive $archive)
    {
    }

    /**
     * Mode A : template personnel (aucun texte, seulement le QR).
     *
     * @param  array{x:int,y:int,width:int,height:int}  $qrZone  en pixels natifs
     * @return string chemin du ZIP final
     */
    public function generateFromCustom(string $backgroundPath, array $qrZone, string $qrZipPath): string
    {
        $this->prepareRuntime();

        [$width, $height, $format] = $this->inspectImage($backgroundPath);
        $zone = $this->validateZone($qrZone, $width, $height);

        $qrPaths = $this->archive->extract($qrZipPath, $this->dir('qr'));

        $background = $this->loadImage($backgroundPath);

        try {
            return $this->render($background, $qrPaths, $zone, [], $format);
        } finally {
            imagedestroy($background);
        }
    }

    /**
     * Mode B : template TicketLab (textes + QR).
     *
     * @param  array<string,mixed>  $values  valeurs saisies, indexées par field.key
     * @return string chemin du ZIP final
     */
    public function generateFromPreset(Template $template, array $values, string $qrZipPath): string
    {
        $this->prepareRuntime();

        if (! $template->imageExists()) {
            throw new RuntimeException("L'image du template « {$template->name} » est introuvable sur le serveur.");
        }

        $path = $template->absoluteImagePath();
        [$width, $height, $format] = $this->inspectImage($path);

        // Les coordonnées du template sont exprimées dans width x height ;
        // si l'image réelle diffère, tout est mis à l'échelle.
        $scale = $template->width > 0 ? $width / $template->width : 1.0;

        $zone = $this->validateZone($this->scaleZone($template->qr_zone, $scale), $width, $height);
        $fields = $this->prepareFields($template->fields, $values, $scale);

        $qrPaths = $this->archive->extract($qrZipPath, $this->dir('qr'));

        $background = $this->loadImage($path);

        try {
            return $this->render($background, $qrPaths, $zone, $fields, $format);
        } finally {
            imagedestroy($background);
        }
    }

    /** Supprime le dossier temporaire (idempotent). */
    public function cleanup(): void
    {
        if ($this->workDir !== null) {
            QrArchive::forgetDirectory($this->workDir);
            $this->workDir = null;
        }
    }

    // ---------------------------------------------------------------------
    // Boucle de génération
    // ---------------------------------------------------------------------

    /**
     * @param  list<string>  $qrPaths
     * @param  list<array<string,mixed>>  $fields  champs déjà préparés
     */
    private function render(GdImage $background, array $qrPaths, array $zone, array $fields, string $format): string
    {
        $width = imagesx($background);
        $height = imagesy($background);

        // Image de base : fond + textes communs à tous les tickets
        $base = $this->cloneImage($background);
        foreach ($fields as $field) {
            if ($field['type'] !== 'counter' && $field['text'] !== '') {
                $this->drawField($base, $field, $field['text']);
            }
        }

        $counters = array_values(array_filter($fields, static fn (array $f): bool => $f['type'] === 'counter'));

        $outDir = $this->dir('out');
        $extension = $format === 'png' ? 'png' : 'jpg';
        $digits = max(3, strlen((string) count($qrPaths)));

        try {
            foreach ($qrPaths as $index => $qrPath) {
                $canvas = $this->cloneImage($base);

                try {
                    foreach ($counters as $field) {
                        $number = (int) ($field['start'] ?? 1) + $index;
                        $this->drawField($canvas, $field, (string) $number);
                    }

                    $this->placeQr($canvas, $qrPath, $zone, $index + 1);

                    $target = sprintf('%s/ticket_%s.%s', $outDir, str_pad((string) ($index + 1), $digits, '0', STR_PAD_LEFT), $extension);
                    $this->saveImage($canvas, $target, $format);
                } finally {
                    imagedestroy($canvas);
                }

                // Le QR source n'est plus utile : on libère l'espace disque au fil de l'eau
                QrArchive::forget($qrPath);
            }
        } finally {
            imagedestroy($base);
        }

        return $this->buildZip($outDir);
    }

    // ---------------------------------------------------------------------
    // QR code
    // ---------------------------------------------------------------------

    /**
     * Place le QR dans la zone : fond blanc (zone de silence), proportions
     * conservées (jamais de déformation), agrandissement net (plus proche
     * voisin) pour garder des modules aux bords francs.
     */
    private function placeQr(GdImage $canvas, string $qrPath, array $zone, int $position): void
    {
        try {
            $qr = $this->loadImage($qrPath);
        } catch (RuntimeException) {
            throw new RuntimeException("Le QR code n°{$position} du ZIP est illisible (image corrompue ou format non supporté).");
        }

        $qw = imagesx($qr);
        $qh = imagesy($qr);

        // Aplatit l'éventuelle transparence sur du blanc
        $flat = imagecreatetruecolor($qw, $qh);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopy($flat, $qr, 0, 0, 0, 0, $qw, $qh);
        imagedestroy($qr);

        // Zone de silence blanche autour du QR
        $margin = (int) round(min($zone['width'], $zone['height']) * (float) config('ticketlab.qr_quiet_zone_ratio', 0.04));
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle(
            $canvas,
            $zone['x'] - $margin,
            $zone['y'] - $margin,
            $zone['x'] + $zone['width'] - 1 + $margin,
            $zone['y'] + $zone['height'] - 1 + $margin,
            $white
        );

        // Ajustement "contain" dans la zone, centré
        $ratio = min($zone['width'] / $qw, $zone['height'] / $qh);
        $dw = max(1, (int) round($qw * $ratio));
        $dh = max(1, (int) round($qh * $ratio));
        $dx = $zone['x'] + intdiv($zone['width'] - $dw, 2);
        $dy = $zone['y'] + intdiv($zone['height'] - $dh, 2);

        if ($ratio >= 1) {
            imagecopyresized($canvas, $flat, $dx, $dy, 0, 0, $dw, $dh, $qw, $qh); // plus proche voisin
        } else {
            imagecopyresampled($canvas, $flat, $dx, $dy, 0, 0, $dw, $dh, $qw, $qh);
        }

        imagedestroy($flat);
    }

    // ---------------------------------------------------------------------
    // Texte
    // ---------------------------------------------------------------------

    /**
     * Normalise les champs du template + valeurs saisies.
     *
     * @param  list<array<string,mixed>>  $templateFields
     * @return list<array<string,mixed>>
     */
    private function prepareFields(array $templateFields, array $values, float $scale): array
    {
        $prepared = [];

        foreach ($templateFields as $field) {
            $type = $field['type'] ?? 'text';
            $key = (string) ($field['key'] ?? '');

            $raw = $type === 'counter' ? '' : ($values[$key] ?? ($field['placeholder'] ?? ''));
            $text = $this->sanitizeText((string) $raw, $type === 'textarea' ? 600 : 200, $type === 'textarea');

            if ($type !== 'counter' && ! empty($field['required']) && $text === '') {
                throw new RuntimeException('Le champ « ' . ($field['label'] ?? $key) . ' » est obligatoire.');
            }

            $prepared[] = [
                'type' => $type,
                'text' => $text,
                'start' => $field['start'] ?? 1,
                'x' => (float) ($field['x'] ?? 0) * $scale,
                'y' => (float) ($field['y'] ?? 0) * $scale,
                'maxWidth' => isset($field['maxWidth']) ? (float) $field['maxWidth'] * $scale : null,
                'fontSize' => (float) ($field['fontSize'] ?? 24) * $scale,
                'fontWeight' => (int) ($field['fontWeight'] ?? 400),
                'lineHeight' => (float) ($field['lineHeight'] ?? self::NATURAL_LINE_HEIGHT),
                'color' => (string) ($field['color'] ?? '#000000'),
                'align' => (string) ($field['align'] ?? 'left'),
            ];
        }

        return $prepared;
    }

    /**
     * Les textes sont dessinés (jamais interprétés), mais on retire quand même
     * les caractères de contrôle et on borne la longueur.
     */
    private function sanitizeText(string $text, int $maxLength, bool $multiline): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = (string) preg_replace('/[^\P{C}\n]+/u', '', $text);

        if (! $multiline) {
            $text = str_replace("\n", ' ', $text);
        }

        return mb_substr(trim($text), 0, $maxLength);
    }

    /**
     * Dessine un champ. (x, y) = coin supérieur gauche de la boîte de texte ;
     * la ligne de base est calculée avec les métriques de Poppins, exactement
     * comme le fait le CSS de l'aperçu (line-height explicite).
     */
    private function drawField(GdImage $image, array $field, string $text): void
    {
        $font = $this->fontFile($field['fontWeight']);
        $pixelSize = $field['fontSize'];
        $points = $pixelSize * self::PX_TO_PT;
        $lineHeight = $field['lineHeight'];
        $color = $this->allocateColor($image, $field['color']);

        $lines = $field['maxWidth'] !== null
            ? $this->wrap($text, $font, $points, $field['maxWidth'])
            : explode("\n", $text);

        $firstBaseline = $field['y'] + (($lineHeight - self::NATURAL_LINE_HEIGHT) / 2 + self::ASCENT) * $pixelSize;

        foreach ($lines as $i => $line) {
            if ($line === '') {
                continue;
            }

            $x = $field['x'];
            if ($field['maxWidth'] !== null && $field['align'] !== 'left') {
                $free = $field['maxWidth'] - $this->textWidth($line, $font, $points);
                $x += $field['align'] === 'center' ? $free / 2 : $free;
            }

            imagettftext(
                $image,
                $points,
                0,
                (int) round($x),
                (int) round($firstBaseline + $i * $lineHeight * $pixelSize),
                $color,
                $font,
                $line
            );
        }
    }

    /**
     * Retour à la ligne glouton, en respectant les "\n" explicites.
     *
     * @return list<string>
     */
    private function wrap(string $text, string $font, float $points, float $maxWidth): array
    {
        $lines = [];

        foreach (explode("\n", $text) as $paragraph) {
            $current = '';

            foreach (preg_split('/\s+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
                $candidate = $current === '' ? $word : $current . ' ' . $word;

                if ($current !== '' && $this->textWidth($candidate, $font, $points) > $maxWidth) {
                    $lines[] = $current;
                    $current = $word;
                } else {
                    $current = $candidate;
                }
            }

            $lines[] = $current;
        }

        return $lines;
    }

    private function textWidth(string $text, string $font, float $points): float
    {
        $box = imagettfbbox($points, 0, $font, $text);

        return $box === false ? 0.0 : (float) ($box[2] - $box[0]);
    }

    /** Police Poppins dont la graisse est la plus proche de celle demandée. */
    private function fontFile(int $weight): string
    {
        $fonts = (array) config('ticketlab.fonts');
        $dir = rtrim((string) config('ticketlab.fonts_path'), '/\\');

        $best = null;
        foreach (array_keys($fonts) as $candidate) {
            if ($best === null || abs($candidate - $weight) < abs($best - $weight)) {
                $best = $candidate;
            }
        }

        $file = $dir . '/' . ($best !== null ? $fonts[$best] : config('ticketlab.font_fallback'));

        if (! is_file($file)) {
            $file = $dir . '/' . config('ticketlab.font_fallback');
        }
        if (! is_file($file)) {
            throw new RuntimeException('Police introuvable sur le serveur (resources/fonts).');
        }

        return $file;
    }

    private function allocateColor(GdImage $image, string $hex): int
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '000000';
        }

        return imagecolorallocate($image, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    }

    // ---------------------------------------------------------------------
    // Images, zone, ZIP
    // ---------------------------------------------------------------------

    /** @return array{0:int,1:int,2:string} largeur, hauteur, format de sortie (png|jpg) */
    private function inspectImage(string $path): array
    {
        $info = @getimagesize($path);

        if ($info === false || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw new RuntimeException('Le template doit être une image PNG ou JPG valide.');
        }

        $format = match ((string) config('ticketlab.output_format', 'source')) {
            'png' => 'png',
            'jpg' => 'jpg',
            default => $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg',
        };

        return [$info[0], $info[1], $format];
    }

    private function loadImage(string $path): GdImage
    {
        $info = @getimagesize($path);

        $image = match ($info[2] ?? null) {
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            default => false,
        };

        if ($image === false) {
            throw new RuntimeException('Image illisible ou format non supporté (PNG ou JPG attendus).');
        }

        imagealphablending($image, true);
        imagesavealpha($image, true);

        return $image;
    }

    private function cloneImage(GdImage $source): GdImage
    {
        $copy = imagecreatetruecolor(imagesx($source), imagesy($source));
        imagealphablending($copy, false);
        imagesavealpha($copy, true);
        imagecopy($copy, $source, 0, 0, 0, 0, imagesx($source), imagesy($source));
        imagealphablending($copy, true);

        return $copy;
    }

    private function saveImage(GdImage $image, string $target, string $format): void
    {
        $ok = $format === 'png'
            ? imagepng($image, $target, 6)
            : imagejpeg($image, $target, (int) config('ticketlab.jpeg_quality', 92));

        if (! $ok) {
            throw new RuntimeException("Impossible d'écrire l'image générée (disque plein ?).");
        }
    }

    /**
     * @param  array<string,mixed>  $zone
     * @return array{x:int,y:int,width:int,height:int}
     */
    private function validateZone(array $zone, int $imageWidth, int $imageHeight): array
    {
        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (! isset($zone[$key]) || ! is_numeric($zone[$key])) {
                throw new RuntimeException("La zone du QR code est incomplète (« {$key} » manquant).");
            }
        }

        $zone = [
            'x' => (int) round((float) $zone['x']),
            'y' => (int) round((float) $zone['y']),
            'width' => (int) round((float) $zone['width']),
            'height' => (int) round((float) $zone['height']),
        ];

        if ($zone['width'] < 20 || $zone['height'] < 20) {
            throw new RuntimeException('La zone du QR code est trop petite (minimum 20 × 20 px).');
        }

        if ($zone['x'] < 0 || $zone['y'] < 0
            || $zone['x'] + $zone['width'] > $imageWidth
            || $zone['y'] + $zone['height'] > $imageHeight) {
            throw new RuntimeException(sprintf(
                'La zone du QR code dépasse de l\'image (image : %d × %d px, zone : x=%d y=%d, %d × %d px).',
                $imageWidth, $imageHeight, $zone['x'], $zone['y'], $zone['width'], $zone['height']
            ));
        }

        return $zone;
    }

    /** @param array<string,mixed> $zone */
    private function scaleZone(array $zone, float $scale): array
    {
        return array_map(static fn ($v) => is_numeric($v) ? (float) $v * $scale : $v, $zone);
    }

    private function buildZip(string $imagesDir): string
    {
        $zipPath = $this->dir('zip') . '/tickets_' . date('Y-m-d_H-i-s') . '.zip';
        $files = glob($imagesDir . '/ticket_*.*') ?: [];
        natsort($files);

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Impossible de créer le ZIP final.');
        }

        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
            // Les images sont déjà compressées : stocker évite de perdre du temps CPU
            $zip->setCompressionName(basename($file), ZipArchive::CM_STORE);
        }

        if (! $zip->close()) {
            throw new RuntimeException('Impossible de finaliser le ZIP (disque plein ?).');
        }

        // Les images sont désormais dans le ZIP : on les supprime avant l'envoi
        foreach ($files as $file) {
            QrArchive::forget($file);
        }

        return $zipPath;
    }

    // ---------------------------------------------------------------------
    // Environnement
    // ---------------------------------------------------------------------

    private function prepareRuntime(): void
    {
        if (! extension_loaded('gd') || ! function_exists('imagettftext')) {
            throw new RuntimeException('Le serveur doit disposer de l\'extension PHP GD avec le support FreeType.');
        }

        // Génération longue et images volumineuses : garde-fous relevés localement
        @set_time_limit(0);
        if ((int) ini_get('memory_limit') !== -1 && $this->memoryLimitBytes() < 1024 * 1024 * 1024) {
            @ini_set('memory_limit', '1024M');
        }
    }

    private function memoryLimitBytes(): int
    {
        $value = trim((string) ini_get('memory_limit'));
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }

    private function dir(string $name): string
    {
        if ($this->workDir === null) {
            $this->workDir = storage_path('app/temp/' . bin2hex(random_bytes(8)));
        }

        $path = $this->workDir . '/' . $name;
        if (! is_dir($path) && ! @mkdir($path, 0775, true) && ! is_dir($path)) {
            throw new RuntimeException("Impossible de créer le dossier temporaire ({$path}).");
        }

        return $path;
    }
}
