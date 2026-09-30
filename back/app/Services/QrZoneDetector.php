<?php

namespace App\Services;

use RuntimeException;

/**
 * Détecteur automatique de zone QR.
 *
 * Les templates TicketLab (et la plupart des designs imprimés) réservent au
 * QR un conteneur clair : un bloc carré blanc posé sur le fond sarcelle.
 * Ce service retrouve ce bloc automatiquement, ce qui évite à l'utilisateur
 * de devoir placer la zone à la main sur les templates prédéfinis — et lui
 * propose un point de départ fiable sur son propre template (option A).
 *
 * Principe de la séparation :
 *   - le FOND TicketLab est saturé  (sarcelle #015F69, jaune #FFB400, ...)
 *   - le CONTENEUR du QR est achromatique (blanc, noir, ou gris de compression)
 *
 * On bascule donc sur un simple test de saturation, beaucoup plus robuste
 * qu'un seuil de blancheur : l'anti-aliasing JPEG entre modules noirs et
 * blancs produit des gris que la saturation classe correctement.
 */
class QrZoneDetector
{
    /**
     * Seuil de saturation au-delà duquel un pixel est considéré comme
     * appartenant au fond coloré (et non au conteneur).
     */
    private const SATURATION_BG = 62;

    /**
     * Facteur de sous-échantillonnage utilisé pour l'analyse des composantes.
     * 2 = précision de 2 px, analyse ~4x plus rapide sur un 2362x2362.
     */
    private const STEP = 2;

    /**
     * Détecte la zone QR la plus plausible dans une image.
     *
     * @return array{x:int,y:int,width:int,height:int,confidence:float}|null
     *         null si aucun conteneur carré n'est trouvé
     */
    public function detect(string $imagePath): ?array
    {
        $image = $this->loadImage($imagePath);
        $width = imagesx($image);
        $height = imagesy($image);

        $candidates = $this->squareComponents($image, $width, $height);

        if ($candidates === []) {
            imagedestroy($image);

            return null;
        }

        $candidates = $this->rankCandidates($candidates, $width, $height);
        $best = $this->refine($image, $candidates[0], $width, $height);
        imagedestroy($image);

        if ($best === null) {
            return null;
        }

        return $best + ['confidence' => $candidates[0]['score']];
    }

    /**
     * Charge une image GD depuis un chemin, quel que soit le format.
     *
     * @return \GdImage
     */
    private function loadImage(string $path)
    {
        $info = @getimagesize($path);
        if ($info === false) {
            throw new RuntimeException("Image illisible : {$path}");
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            IMAGETYPE_GIF  => @imagecreatefromgif($path),
            default        => false,
        };

        if ($image === false) {
            throw new RuntimeException("Format d'image non pris en charge : {$path}");
        }

        return $image;
    }

    /**
     * Un pixel appartient-il au fond coloré TicketLab ?
     * (saturé) => fond ; (gris / blanc / noir) => conteneur possible
     */
    private function isBackgroundPixel(int $r, int $g, int $b): bool
    {
        return (max($r, $g, $b) - min($r, $g, $b)) >= self::SATURATION_BG;
    }

    /**
     * Composantes connexes carrées de pixels "non fond", les plus grandes d'abord.
     *
     * @return list<array{x:int,y:int,w:int,h:int,score:float}>
     */
    private function squareComponents($image, int $width, int $height): array
    {
        $step = self::STEP;
        $sw = intdiv($width, $step);
        $sh = intdiv($height, $step);

        $small = imagecreatetruecolor($sw, $sh);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $sw, $sh, $width, $height);

        // Masque binaire : 1 = candidat conteneur
        $mask = [];
        for ($y = 0; $y < $sh; $y++) {
            $row = [];
            for ($x = 0; $x < $sw; $x++) {
                $c = imagecolorat($small, $x, $y);
                $row[$x] = $this->isBackgroundPixel(($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF) ? 0 : 1;
            }
            $mask[$y] = $row;
        }
        imagedestroy($small);

        // Union-find : regroupe les pixels adjacents en composantes
        $parent = [];
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                if ($mask[$y][$x]) {
                    $parent[$y * $sw + $x] = $y * $sw + $x;
                }
            }
        }
        $find = static function (array &$parent, int $p): int {
            while ($parent[$p] !== $p) {
                $parent[$p] = $parent[$parent[$p]];
                $p = $parent[$p];
            }

            return $p;
        };
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                if (!$mask[$y][$x]) {
                    continue;
                }
                $p = $y * $sw + $x;
                if ($x > 0 && $mask[$y][$x - 1]) {
                    $a = $find($parent, $p);
                    $b = $find($parent, $p - 1);
                    if ($a !== $b) {
                        $parent[max($a, $b)] = min($a, $b);
                    }
                }
                if ($y > 0 && $mask[$y - 1][$x]) {
                    $a = $find($parent, $p);
                    $b = $find($parent, $p - $sw);
                    if ($a !== $b) {
                        $parent[max($a, $b)] = min($a, $b);
                    }
                }
            }
        }

        // Bornes + surface de chaque composante
        $stats = [];
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                if (!$mask[$y][$x]) {
                    continue;
                }
                $root = $find($parent, $y * $sw + $x);
                if (!isset($stats[$root])) {
                    $stats[$root] = ['n' => 0, 'minx' => $x, 'maxx' => $x, 'miny' => $y, 'maxy' => $y];
                }
                $stats[$root]['n']++;
                $stats[$root]['minx'] = min($stats[$root]['minx'], $x);
                $stats[$root]['maxx'] = max($stats[$root]['maxx'], $x);
                $stats[$root]['miny'] = min($stats[$root]['miny'], $y);
                $stats[$root]['maxy'] = max($stats[$root]['maxy'], $y);
            }
        }

        $found = [];
        $imageArea = $width * $height;

        foreach ($stats as $s) {
            $x = $s['minx'] * $step;
            $y = $s['miny'] * $step;
            $w = ($s['maxx'] - $s['minx'] + 1) * $step;
            $h = ($s['maxy'] - $s['miny'] + 1) * $step;

            if ($w < 60 || $h < 60) {
                continue; // trop petit pour être un QR
            }

            $ratio = $w / $h;
            if ($ratio < 0.85 || $ratio > 1.18) {
                continue; // un QR est (quasi) carré
            }

            $cells = ($s['maxx'] - $s['minx'] + 1) * ($s['maxy'] - $s['miny'] + 1);
            $fill = $s['n'] / max(1, $cells);   // densité de la composante

            $found[] = [
                'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
                'fill' => $fill,
                'score' => $this->score($w, $h, $ratio, $fill, $w * $h / $imageArea),
            ];
        }

        return $found;
    }

    /**
     * Note un candidat : on privilégie les grands carrés denses et bien remplis.
     *
     * - `fill` proche de 1 = bloc plein (conteneur blanc avec QR à l'intérieur)
     *   une valeur beaucoup plus basse traduirait du texte clair en/run.
     */
    private function score(int $w, int $h, float $ratio, float $fill, float $share): float
    {
        $squareness = 1 - min(1, abs(1 - $ratio));         // 1 = parfaitement carré
        $density    = 1 - min(1, abs(0.95 - $fill));        // ~0.95 = bloc plein
        $size       = min(1.0, sqrt(($w * $h) / 1200 / 1200)); // ~1 à partir de 1200px

        return ($squareness * 0.35) + ($density * 0.35) + ($size * 0.20) + ($share * 0.10);
    }

    /**
     * Réordonne les candidats du plus au moins probable.
     *
     * @param  list<array<string,mixed>>  $candidates
     * @return list<array<string,mixed>>
     */
    private function rankCandidates(array $candidates, int $width, int $height): array
    {
        // Pénalise les composants qui collent aux bords de l'image : un vrai
        // conteneur de QR est encadré, jamais coupé par le bord du fichier.
        foreach ($candidates as $i => $c) {
            $touch = 0;
            if ($c['x'] <= self::STEP) {
                $touch++;
            }
            if ($c['y'] <= self::STEP) {
                $touch++;
            }
            if ($c['x'] + $c['w'] >= $width - self::STEP) {
                $touch++;
            }
            if ($c['y'] + $c['h'] >= $height - self::STEP) {
                $touch++;
            }
            $candidates[$i]['score'] -= $touch * 0.12;
        }

        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $candidates;
    }

    /**
     * Convertit la boîte approximative (précision 2 px) en zone carrée exacte
     * au pixel, en balayant l'image en pleine résolution.
     *
     * @param  array{x:int,y:int,w:int,h:int,score:float}  $box
     * @return array{x:int,y:int,width:int,height:int}|null
     */
    private function refine($image, array $box, int $width, int $height): ?array
    {
        $cx = $box['x'] + intdiv($box['w'], 2);
        $cy = $box['y'] + intdiv($box['h'], 2);

        $isForeground = function (int $x, int $y) use ($image, $width, $height): bool {
            if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) {
                return false;
            }
            $c = imagecolorat($image, $x, $y);

            return !$this->isBackgroundPixel(($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF);
        };

        // Enveloppe commune : on balaie plusieurs lignes/colonnes centrales et on
        // ne conserve que l'intersection, ce qui ignore les dents de scie dues
        // aux modules noirs du QR.
        $left = $cx;
        $right = $cx;
        $top = $cy;
        $bottom = $cy;

        for ($dy = -intdiv($box['h'], 4); $dy <= intdiv($box['h'], 4); $dy++) {
            $yy = $cy + $dy;
            if ($yy < 0 || $yy >= $height || !$isForeground($cx, $yy)) {
                continue;
            }
            $a = $cx;
            while ($a > 0 && $isForeground($a - 1, $yy)) {
                $a--;
            }
            $z = $cx;
            while ($z < $width - 1 && $isForeground($z + 1, $yy)) {
                $z++;
            }
            $left = max($left, $a);
            $right = min($right, $z);
        }

        for ($dx = -intdiv($box['w'], 4); $dx <= intdiv($box['w'], 4); $dx++) {
            $xx = $cx + $dx;
            if ($xx < 0 || $xx >= $width || !$isForeground($xx, $cy)) {
                continue;
            }
            $a = $cy;
            while ($a > 0 && $isForeground($xx, $a - 1)) {
                $a--;
            }
            $z = $cy;
            while ($z < $height - 1 && $isForeground($xx, $z + 1)) {
                $z++;
            }
            $top = max($top, $a);
            $bottom = min($bottom, $z);
        }

        $w = $right - $left + 1;
        $h = $bottom - $top + 1;

        if ($w < 40 || $h < 40) {
            return null;
        }

        // Un QR est toujours carré : on prend le plus grand carré centré.
        $size = min($w, $h);
        $x = intdiv($left + $right, 2) - intdiv($size, 2);
        $y = intdiv($top + $bottom, 2) - intdiv($size, 2);

        return [
            'x'      => max(0, $x),
            'y'      => max(0, $y),
            'width'  => $size,
            'height' => $size,
        ];
    }
}
