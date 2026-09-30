<?php

namespace App\Services;

use GdImage;
use RuntimeException;

/**
 * Moteur de composition des supports TicketLab.
 *
 * Responsabilités :
 *   1. charger le fond (image de template) ;
 *   2. y dessiner les champs texte (police Poppins, position/style du JSON) ;
 *   3. y poser un QR code par-dessus, dans la zone QR ;
 *   4. produire l'archive ZIP finale.
 *
 * Optimisation clé : le fond et les textes sont identiques pour tous les
 * tickets d'une même génération. On les rend donc UNE seule fois, puis on ne
 * duplique que cette image de base pour y coller chaque QR. Le coût par
 * ticket se limite ainsi à une copie mémoire + un collage.
 *
 * Les coordonnées sont toujours exprimées dans la résolution native de
 * l'image de fond : l'aperçu côté front n'a qu'à appliquer une échelle.
 */
class TicketImageGenerator
{
    /** Marge intérieure du rectangle blanc dessiné sous le QR (px). */
    private const QR_PADDING = 6;

    /**
     * Génère l'archive complète et la retourne en mémoire.
     *
     * @param  string  $backgroundPath  Image de fond
     * @param  array{x:int,y:int,width:int,height:int}  $qrZone
     * @param  list<string>  $qrPaths  QR codes, déjà triés
     * @param  list<array<string,mixed>>  $fields  Champs du template
     * @param  array<string,string>  $values  Valeurs saisies par l'utilisateur
     * @param  callable|null  $onProgress  fn(int $done, int $total): void
     * @return string Contenu binaire du ZIP
     */
    public function generateZip(
        string $backgroundPath,
        array $qrZone,
        array $qrPaths,
        array $fields = [],
        array $values = [],
        ?callable $onProgress = null
    ): string {
        $base = $this->renderBase($backgroundPath, $fields, $values);
        $baseWidth = imagesx($base);
        $baseHeight = imagesy($base);

        [$zoneX, $zoneY, $zoneW, $zoneH] = $this->normalizeZone($qrZone, $baseWidth, $baseHeight);

        $total = count($qrPaths);
        $output = $this->openMemoryStream();
        $zip = new \ZipArchive;
        if ($zip->open($output, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            imagedestroy($base);
            throw new RuntimeException("Impossible de créer l'archive ZIP de sortie.");
        }

        // Le format de sortie suit celui du fond : un fond PNG reste sans
        // perte, un fond JPEG reste léger (qualité 92,modules QR nets).
        $extension = strtolower(pathinfo($backgroundPath, PATHINFO_EXTENSION)) === 'png' ? 'png' : 'jpg';
        $imageType = $extension === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;

        foreach ($qrPaths as $index => $qrPath) {
            $canvas = $this->newCanvas($base, $baseWidth, $baseHeight);
            $this->pasteQr($canvas, $qrPath, $zoneX, $zoneY, $zoneW, $zoneH);

            $contents = $this->encode($canvas, $imageType, $extension);
            imagedestroy($canvas);

            $zip->addFromString(sprintf('ticket_%03d.%s', $index + 1, $extension), $contents);
            unset($contents);

            if ($onProgress !== null) {
                $onProgress($index + 1, $total);
            }

            // Laisse respirer le garbage collector sur les grosses séries
            if (($index + 1) % 25 === 0) {
                gc_collect_cycles();
            }
        }

        $zip->close();
        imagedestroy($base);

        rewind($output);
        $zipContent = stream_get_contents($output);
        fclose($output);

        return $zipContent !== false ? $zipContent : '';
    }

    /**
     * Construit l'image de base : fond + tous les textes.
     * Esta image est identique pour toute la série, on la rend une fois.
     *
     * @param  list<array<string,mixed>>  $fields
     * @param  array<string,string>  $values
     * @return GdImage
     */
    public function renderBase(string $backgroundPath, array $fields = [], array $values = []): GdImage
    {
        $source = $this->loadImage($backgroundPath);
        $width = imagesx($source);
        $height = imagesy($source);

        $canvas = imagecreatetruecolor($width, $height);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);
        imagedestroy($source);

        // Les JPEG n'ont pas de couche alpha : on force un fond opaque blanc
        // pour éviter que la transparence n'apparaisse en noir à l'enregistrement.
        imagealphablending($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));

        foreach ($fields as $field) {
            $key = $field['key'] ?? null;
            if ($key === null) {
                continue;
            }

            $text = $this->fieldValue($values, (string) $key, $field);
            if ($text === '') {
                continue;
            }

            $this->drawField($canvas, $field, $text, $width, $height);
        }

        return $canvas;
    }

    /**
     * Dessine un champ texte sur le canvas.
     *
     * @param  array<string,mixed>  $field
     */
    private function drawField(GdImage $canvas, array $field, string $text, int $imgW, int $imgH): void
    {
        $fontSize = max(6, (int) ($field['fontSize'] ?? 24));
        $maxWidth = (int) ($field['maxWidth'] ?? 600);
        if ($maxWidth <= 0) {
            $maxWidth = $imgW;
        }

        $fontPath = $this->fontPath((int) ($field['fontWeight'] ?? 600), (string) ($field['fontFamily'] ?? 'Poppins'));
        $color = $this->resolveColor($field['color'] ?? 'auto', $canvas, $field, $imgW, $imgH);
        $rgb = $this->hexToRgb($color);

        $lineHeight = (float) ($field['lineHeight'] ?? 1.25);
        $lineStep = (int) round($fontSize * $lineHeight);

        // Retour à la ligne automatique si la ligne dépasse maxWidth.
        $lines = $field['type'] === 'textarea'
            ? $this->wrapParagraph($text, $fontPath, $fontSize, $maxWidth)
            : $this->wrapSingle($text, $fontPath, $fontSize, $maxWidth);

        if ($lines === []) {
            return;
        }

        $x = (int) ($field['x'] ?? 0);
        $y = (int) ($field['y'] ?? 0);
        $align = (string) ($field['align'] ?? 'left');

        // imagettftext prend une LIGNE DE BASE : on convertit depuis le haut du texte
        $metrics = $this->fontMetrics($fontPath, $fontSize);
        $firstBaseline = $y + $metrics['ascent'];

        foreach ($lines as $index => $line) {
            $baseline = $firstBaseline + ($index * $lineStep);
            if ($baseline < -$fontSize || $baseline > $imgH + $fontSize) {
                continue; // champ hors image
            }

            $lineWidth = $this->textWidth($line, $fontPath, $fontSize);
            $drawX = match ($align) {
                'center' => $x + intdiv($maxWidth - $lineWidth, 2),
                'right'  => $x + $maxWidth - $lineWidth,
                default  => $x,
            };

            // Le texte peut déborder : on le borne à l'image
            $drawX = max(0, min($drawX, $imgW - 1));
            $baseline = max(0, min($baseline, $imgH));

            $this->drawText($canvas, $line, $fontPath, $fontSize, $drawX, $baseline, $rgb);
        }
    }

    /**
     * Écrit une ligne de texte avec un contour fin optionnel pour améliorer
     * la lisibilité sur fond chargé.
     *
     * @param  array{0:int,1:int,2:int}  $rgb
     */
    private function drawText(GdImage $canvas, string $text, string $font, int $size, int $x, int $y, array $rgb): void
    {
        $color = imagecolorallocate($canvas, $rgb[0], $rgb[1], $rgb[2]);
        if ($color === false) {
            return;
        }

        imagettftext($canvas, $size, 0.0, $x, $y, $color, $font, $text);
    }

    /**
     * Colle un QR code dans la zone, après avoir repeint un fond blanc
     * (la spec exige un fond blanc et pas de déformation).
     *
     * Contraintes respectées :
     *  - le QR est redimensionné en contenant (letterbox) pour ne jamais
     *    être déformé, même si la zone n'est pas parfaitement carrée ;
     *  - un cadre blanc légèrement plus grand que le QR est peint avant le
     *    collage, ce qui efface proprement un éventuel QR déjà présent dans
     *    le template.
     */
    private function pasteQr(GdImage $canvas, string $qrPath, int $zx, int $zy, int $zw, int $zh): void
    {
        $qr = $this->loadImage($qrPath);
        $qrW = imagesx($qr);
        $qrH = imagesy($qr);

        // Fond blanc de la zone entière : supprime tout résidu du design
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, $zx, $zy, $zx + $zw - 1, $zy + $zh - 1, $white);

        // On garde le QR carré : côté = min(largeur, hauteur) de la zone
        $side = (int) min($zw, $zh);
        $offsetX = intdiv($zw - $side, 2);
        $offsetY = intdiv($zh - $side, 2);

        // Zone de silence interne : elle améliore nettement la lecture du code
        $quiet = (int) round($side * (float) config('ticketlab.qr_quiet_zone_ratio', 0.04));
        $inner = max(1, $side - (2 * $quiet));

        $resized = imagecreatetruecolor($inner, $inner);
        imagecopyresampled($resized, $qr, 0, 0, 0, 0, $inner, $inner, $qrW, $qrH);
        imagedestroy($qr);

        imagecopy($canvas, $resized, $zx + $offsetX + $quiet, $zy + $offsetY + $quiet, 0, 0, $inner, $inner);
        imagedestroy($resized);
    }

    /**
     * Normalise et borne la zone QR à l'intérieur de l'image.
     *
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function normalizeZone(array $zone, int $imgW, int $imgH): array
    {
        $x = max(0, (int) ($zone['x'] ?? 0));
        $y = max(0, (int) ($zone['y'] ?? 0));
        $w = (int) ($zone['width'] ?? 0);
        $h = (int) ($zone['height'] ?? 0);

        if ($w <= 0) {
            $w = (int) config('ticketlab.qr_sizes')[1];
        }
        if ($h <= 0) {
            $h = $w;
        }

        // La zone ne doit jamais sortir de l'image
        $w = min($w, $imgW - $x);
        $h = min($h, $imgH - $y);

        return [$x, $y, max(1, $w), max(1, $h)];
    }

    /**
     * Valeur d'un champ, avec repli sur le placeholder si vide.
     *
     * @param  array<string,mixed>  $field
     */
    private function fieldValue(array $values, string $key, array $field): string
    {
        $raw = $values[$key] ?? null;
        $text = is_scalar($raw) ? trim((string) $raw) : '';

        if ($text === '') {
            $text = (string) ($field['placeholder'] ?? '');
        }

        return $this->sanitize($text);
    }

    /**
     * Nettoie un texte utilisateur avant de le dessiner.
     * On retire les caractères de contrôle et on normalise les espaces.
     */
    private function sanitize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * Résout la couleur d'un champ.
     * - '#RRGGBB' => telle quelle
     * - 'auto'    => échantillonne le fond sous le champ et choisit un
     *              contraste lisible (spec §5 : texte foncé #156660 sur jaune)
     *
     * @param  array<string,mixed>  $field
     */
    private function resolveColor(string|array|null $colorSpec, GdImage $canvas, array $field, int $imgW, int $imgH): string
    {
        if (is_string($colorSpec) && $colorSpec !== '' && strtolower($colorSpec) !== 'auto') {
            if (preg_match('/^#?[0-9a-fA-F]{3,8}$/', $colorSpec)) {
                return str_starts_with($colorSpec, '#') ? $colorSpec : '#'.$colorSpec;
            }
        }

        return $this->autoContrastColor($canvas, (int) ($field['x'] ?? 0), (int) ($field['y'] ?? 0), (int) ($field['fontSize'] ?? 24), (int) ($field['maxWidth'] ?? 600), $imgW, $imgH);
    }

    /**
     * Échantillonne le fond sous un champ et renvoie la couleur de texte
     * la plus lisible.
     *
     * Règle : sur fond clair -> texte foncé (#015F69) ; sur fond sombre ->
     * texte blanc (#FFFFFF).
     */
    private function autoContrastColor(GdImage $canvas, int $x, int $y, int $fontSize, int $maxWidth, int $imgW, int $imgH): string
    {
        $x = max(0, min($x, $imgW - 1));
        $y = max(0, min($y, $imgH - 1));
        $w = max(1, min($maxWidth, $imgW - $x));
        $h = max(1, min($fontSize, $imgH - $y));

        $sum = 0.0;
        $n = 0;
        for ($yy = $y; $yy < $y + $h; $yy += max(1, intdiv($h, 6))) {
            for ($xx = $x; $xx < $x + $w; $xx += max(1, intdiv($w, 12))) {
                $c = imagecolorat($canvas, $xx, $yy);
                $r = ($c >> 16) & 0xFF;
                $g = ($c >> 8) & 0xFF;
                $b = $c & 0xFF;
                $sum += (0.299 * $r) + (0.587 * $g) + (0.114 * $b);
                $n++;
            }
        }

        $luminance = $n > 0 ? $sum / $n / 255 : 0.0;

        // Palette TicketLab : texte blanc sur fond sarcelle, texte foncé sur jaune
        return $luminance > 0.55
            ? (string) config('ticketlab.palette.teal_dark', '#015F69')
            : (string) config('ticketlab.palette.white', '#FFFFFF');
    }

    /**
     * Résout le chemin du fichier de police pour un poids donné.
     */
    private function fontPath(int $weight, string $family): string
    {
        $fonts = config('ticketlab.fonts');
        $file = $fonts[$weight] ?? null;

        if ($file === null) {
            // Rapproche le poids disponible le plus proche
            $keys = array_map('intval', array_keys($fonts));
            $closest = $keys[0];
            $delta = PHP_INT_MAX;
            foreach ($keys as $k) {
                if (abs($k - $weight) < $delta) {
                    $delta = abs($k - $weight);
                    $closest = $k;
                }
            }
            $file = $fonts[$closest];
        }

        $path = config('ticketlab.fonts_path').DIRECTORY_SEPARATOR.$file;

        if (! is_file($path)) {
            $fallback = config('ticketlab.fonts_path').DIRECTORY_SEPARATOR.config('ticketlab.font_fallback');
            if (is_file($fallback)) {
                return $fallback;
            }
            throw new RuntimeException("Police introuvable : {$path}");
        }

        return $path;
    }

    /**
     * Charge une image GD (PNG/JPG) depuis un chemin.
     */
    private function loadImage(string $path): GdImage
    {
        $info = @getimagesize($path);
        if ($info === false) {
            throw new RuntimeException('Image illisible.');
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default        => false,
        };

        if ($image === false) {
            throw new RuntimeException('Format d\'image non pris en charge.');
        }

        return $image;
    }

    /**
     * Crée un canvas truecolor et y recopie le contenu de la base.
     */
    private function newCanvas(GdImage $base, int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor($width, $height);
        imagealphablending($canvas, true);
        imagecopy($canvas, $base, 0, 0, 0, 0, $width, $height);

        return $canvas;
    }

    /**
     * Encode le canvas en PNG ou JPEG et retourne les octets.
     */
    private function encode(GdImage $canvas, int $imageType, string $extension): string
    {
        ob_start();
        if ($imageType === IMAGETYPE_PNG) {
            imagepng($canvas, null, 6);
        } else {
            imagejpeg($canvas, null, (int) config('ticketlab.jpeg_quality', 92));
        }

        return (string) ob_get_clean();
    }

    /**
     * @return resource
     */
    private function openMemoryStream()
    {
        $stream = fopen('php://temp/maxmemory:'.(512 * 1024 * 1024), 'w+b');
        if ($stream === false) {
            throw new RuntimeException("Impossible d'ouvrir le flux de sortie.");
        }

        return $stream;
    }

    /**
     * Convertit #RRGGBB en tableau RVB.
     *
     * @return array{0:int,1:int,2:int}
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Retour à la ligne automatique pour un champ monoligne.
     *
     * @return list<string>
     */
    private function wrapSingle(string $text, string $font, int $size, int $maxWidth): array
    {
        $lines = [];
        foreach (explode("\n", $text) as $paragraph) {
            foreach ($this->wrapParagraph($paragraph, $font, $size, $maxWidth) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Découpe un paragraphe en lignes tenant dans $maxWidth.
     *
     * @return list<string>
     */
    private function wrapParagraph(string $text, string $font, int $size, int $maxWidth): array
    {
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        if ($words === []) {
            return [];
        }

        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($this->textWidth($candidate, $font, $size) <= $maxWidth || $current === '') {
                $current = $candidate;
            } else {
                $lines[] = $current;
                $current = $word;
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * Largeur en pixels d'une chaîne pour une police et une taille données.
     */
    private function textWidth(string $text, string $font, int $size): int
    {
        if ($text === '') {
            return 0;
        }

        $box = imagettfbbox($size, 0.0, $font, $text);
        if ($box === false) {
            return 0;
        }

        $widths = [];
        foreach ([0, 2, 4, 6] as $i) {
            $widths[] = $box[$i];
        }

        return (int) round(max($widths) - min($widths));
    }

    /**
     * Métriques verticales d'une police : ascent / descent.
     *
     * @return array{ascent:int,descent:int}
     */
    private function fontMetrics(string $font, int $size): array
    {
        $box = imagettfbbox($size, 0.0, $font, 'Hg');
        if ($box === false) {
            return ['ascent' => (int) round($size * 0.8), 'descent' => (int) round($size * 0.2)];
        }

        // imagettfbbox renvoie [x0,y0,x1,y1,...] avec y vers le BAS
        $ascent = -min(0, $box[1], $box[3], $box[5], $box[7]);
        $descent = max(0, $box[1], $box[3], $box[5], $box[7]);

        return ['ascent' => max(1, (int) $ascent), 'descent' => max(0, (int) $descent)];
    }
}
