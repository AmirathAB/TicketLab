<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use GdImage;
use RuntimeException;

/**
 * Rasterise un QR code au format SVG en image GD, sans Imagick ni binaire externe.
 *
 * Pourquoi : GD ne lit pas le SVG, or Ticketche fournit ses QR codes en SVG.
 * Ce convertisseur couvre ce que produisent les générateurs de QR usuels
 * (node-qrcode, qrcode.react / react-qr-code, segno, php-qrcode, ...) :
 *
 *  - formes : <rect>, <path>, <polygon>, <polyline>, <line>, <circle>, <ellipse>
 *  - chemins : M L H V C S Q T A Z (absolus et relatifs)
 *  - remplissage (fill, fill-rule nonzero/evenodd) ET traits (stroke, stroke-width,
 *    stroke-linecap) : node-qrcode dessine ses modules avec des traits
 *  - <g>, transform (matrix, translate, scale, rotate, skewX, skewY), viewBox
 *  - couleurs #rgb, #rrggbb, rgb(), noms usuels, opacités
 *
 * Non supportés (ignorés) : dégradés (rendus en noir), <image>, <text>, <use>,
 * feuilles <style>. Si aucune forme n'est dessinée, une erreur claire est levée.
 *
 * Rendu : anti-aliasing par sur-échantillonnage. Quand le SVG est exprimé en
 * "modules" (viewBox entier, ex. 0 0 29 29) et que la zone le permet, le QR est
 * rendu avec un nombre ENTIER de pixels par module : bords parfaitement nets,
 * lecture optimale (désactivable : ticketlab.qr_svg_crisp_modules).
 *
 * Sécurité : entités XML interdites, pas d'accès réseau, profondeur et nombre
 * de noeuds bornés.
 */
class SvgRasterizer
{
    private const MAX_FILE_BYTES = 8 * 1024 * 1024;
    private const MAX_NODES = 200000;
    private const MAX_DEPTH = 32;
    private const MAX_PIXELS_SIDE = 3000;
    private const CURVE_STEPS = 16;
    private const SUBSAMPLES = 4; // sous-échantillons verticaux par pixel

    /** Éléments sans rendu direct, à ne pas parcourir. */
    private const SKIPPED = [
        'defs', 'title', 'desc', 'metadata', 'style', 'clippath', 'mask',
        'lineargradient', 'radialgradient', 'pattern', 'symbol', 'marker',
        'filter', 'script', 'image', 'text', 'use', 'foreignobject',
    ];

    private const NAMED_COLORS = [
        'black' => [0, 0, 0], 'white' => [255, 255, 255], 'red' => [255, 0, 0],
        'green' => [0, 128, 0], 'blue' => [0, 0, 255], 'gray' => [128, 128, 128],
        'grey' => [128, 128, 128], 'yellow' => [255, 255, 0], 'navy' => [0, 0, 128],
        'orange' => [255, 165, 0], 'purple' => [128, 0, 128], 'teal' => [0, 128, 128],
    ];

    private GdImage $canvas;
    private int $cw = 0;
    private int $ch = 0;
    private int $nodeCount = 0;
    private int $shapeCount = 0;

    /**
     * @param  int  $maxWidth   largeur maximale disponible (pixels)
     * @param  int  $maxHeight  hauteur maximale disponible (pixels)
     * @return GdImage image carrée/proportionnelle au SVG, tenant dans maxWidth x maxHeight
     *
     * @throws RuntimeException message français affichable tel quel
     */
    public function render(string $path, int $maxWidth, int $maxHeight, bool $crispModules = true): GdImage
    {
        $size = @filesize($path);
        if ($size === false || $size === 0) {
            throw new RuntimeException('fichier SVG vide ou illisible.');
        }
        if ($size > self::MAX_FILE_BYTES) {
            throw new RuntimeException('fichier SVG trop volumineux (8 Mo maximum).');
        }

        $xml = (string) file_get_contents($path);
        $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml) ?? $xml;

        // Bloque les entités (XXE, "billion laughs")
        if (stripos($xml, '<!ENTITY') !== false) {
            throw new RuntimeException('SVG refusé (entités XML non autorisées).');
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $loaded ? $dom->documentElement : null;
        if (! $root instanceof DOMElement || strtolower($root->localName) !== 'svg') {
            throw new RuntimeException("ce fichier n'est pas un SVG valide.");
        }

        [$minX, $minY, $vbW, $vbH] = $this->viewBox($root);

        $maxWidth = max(1, min($maxWidth, self::MAX_PIXELS_SIDE));
        $maxHeight = max(1, min($maxHeight, self::MAX_PIXELS_SIDE));

        $fit = min($maxWidth / $vbW, $maxHeight / $vbH);
        $scale = $fit;

        // SVG exprimé en modules (dimensions entières raisonnables) : pixels entiers par module
        if ($crispModules && $this->isWhole($vbW) && $this->isWhole($vbH) && $vbW <= 200 && $vbH <= 200) {
            $module = (int) floor($fit);
            if ($module >= 3) {
                $scale = (float) $module;
            }
        }

        $this->cw = max(1, (int) round($vbW * $scale));
        $this->ch = max(1, (int) round($vbH * $scale));
        $this->nodeCount = 0;
        $this->shapeCount = 0;

        $canvas = imagecreatetruecolor($this->cw, $this->ch);
        imagealphablending($canvas, false);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        $this->canvas = $canvas;

        $sx = $this->cw / $vbW;
        $sy = $this->ch / $vbH;
        $matrix = [$sx, 0.0, 0.0, $sy, -$minX * $sx, -$minY * $sy];

        $style = [
            'fill' => '#000000', 'stroke' => 'none', 'stroke-width' => '1',
            'fill-rule' => 'nonzero', 'fill-opacity' => '1', 'stroke-opacity' => '1',
            'opacity' => 1.0, 'stroke-linecap' => 'butt', 'visibility' => 'visible',
        ];

        try {
            $matrix = $this->applyTransform($matrix, $root->getAttribute('transform'));
            $this->walk($root, $matrix, $this->computeStyle($root, $style), 0);
        } catch (RuntimeException $e) {
            imagedestroy($canvas);
            throw $e;
        }

        if ($this->shapeCount === 0) {
            imagedestroy($canvas);
            throw new RuntimeException('le SVG ne contient aucune forme exploitable (images intégrées ou textes non supportés).');
        }

        return $canvas;
    }

    // ---------------------------------------------------------------------
    // Structure du document
    // ---------------------------------------------------------------------

    /** @return array{0:float,1:float,2:float,3:float} minX, minY, largeur, hauteur */
    private function viewBox(DOMElement $root): array
    {
        $vb = trim($root->getAttribute('viewBox'));
        if ($vb !== '') {
            $parts = preg_split('/[\s,]+/', $vb) ?: [];
            if (count($parts) === 4 && array_reduce($parts, static fn ($ok, $p) => $ok && is_numeric($p), true)) {
                [$x, $y, $w, $h] = array_map('floatval', $parts);
                if ($w > 0 && $h > 0) {
                    return [$x, $y, $w, $h];
                }
            }
        }

        $w = $this->length($root->getAttribute('width'));
        $h = $this->length($root->getAttribute('height'));
        if ($w > 0 && $h > 0) {
            return [0.0, 0.0, $w, $h];
        }

        throw new RuntimeException('dimensions du SVG introuvables (viewBox ou width/height manquants).');
    }

    private function walk(DOMElement $node, array $matrix, array $style, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new RuntimeException('SVG trop imbriqué.');
        }

        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            if (++$this->nodeCount > self::MAX_NODES) {
                throw new RuntimeException('SVG trop complexe (trop d\'éléments).');
            }

            $name = strtolower($child->localName);
            if (in_array($name, self::SKIPPED, true)) {
                continue;
            }

            $childStyle = $this->computeStyle($child, $style);
            if ($childStyle['display'] ?? false) {
                continue; // display:none
            }
            $childMatrix = $this->applyTransform($matrix, $child->getAttribute('transform'));

            if (in_array($name, ['g', 'svg', 'a', 'switch'], true)) {
                $this->walk($child, $childMatrix, $childStyle, $depth + 1);
                continue;
            }

            if (in_array($name, ['rect', 'circle', 'ellipse', 'polygon', 'polyline', 'line', 'path'], true)) {
                $this->drawShape($child, $name, $childMatrix, $childStyle);
            }
        }
    }

    // ---------------------------------------------------------------------
    // Styles
    // ---------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function computeStyle(DOMElement $el, array $parent): array
    {
        $style = $parent;
        unset($style['display']);

        $props = [
            'fill', 'stroke', 'stroke-width', 'fill-rule', 'fill-opacity',
            'stroke-opacity', 'stroke-linecap', 'visibility',
        ];

        $declared = [];
        foreach ($props as $prop) {
            if ($el->hasAttribute($prop)) {
                $declared[$prop] = trim($el->getAttribute($prop));
            }
        }
        foreach (['opacity', 'display'] as $prop) {
            if ($el->hasAttribute($prop)) {
                $declared[$prop] = trim($el->getAttribute($prop));
            }
        }

        // L'attribut style="" prime sur les attributs de présentation
        if ($el->hasAttribute('style')) {
            foreach (explode(';', $el->getAttribute('style')) as $decl) {
                $pair = explode(':', $decl, 2);
                if (count($pair) === 2) {
                    $declared[strtolower(trim($pair[0]))] = trim($pair[1]);
                }
            }
        }

        foreach ($declared as $prop => $value) {
            if ($value === '' || $value === 'inherit') {
                continue;
            }
            if ($prop === 'opacity') {
                $style['opacity'] = $parent['opacity'] * max(0.0, min(1.0, (float) $value));
            } elseif ($prop === 'display') {
                $style['display'] = strtolower($value) === 'none';
            } elseif (in_array($prop, $props, true)) {
                $style[$prop] = $value;
            }
        }

        return $style;
    }

    /** @return array{0:int,1:int,2:int}|null null = aucun remplissage */
    private function parseColor(string $value): ?array
    {
        $v = strtolower(trim($value));

        if ($v === '' || $v === 'none' || $v === 'transparent') {
            return null;
        }
        if (str_starts_with($v, 'url(') || $v === 'currentcolor') {
            return [0, 0, 0]; // dégradé / couleur courante : noir (lisible pour un QR)
        }
        if (isset(self::NAMED_COLORS[$v])) {
            return self::NAMED_COLORS[$v];
        }
        if (preg_match('/^#([0-9a-f]{3,8})$/', $v, $m)) {
            $hex = $m[1];
            if (strlen($hex) === 3 || strlen($hex) === 4) {
                $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
            }
            if (strlen($hex) >= 6) {
                return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
            }
        }
        if (preg_match('/^rgba?\(([^)]*)\)/', $v, $m)) {
            $parts = preg_split('/[\s,\/]+/', trim($m[1])) ?: [];
            if (count($parts) >= 3) {
                $rgb = [];
                foreach (array_slice($parts, 0, 3) as $p) {
                    $rgb[] = str_ends_with($p, '%')
                        ? (int) round(((float) $p) * 2.55)
                        : (int) round((float) $p);
                }

                return array_map(static fn (int $c): int => max(0, min(255, $c)), $rgb);
            }
        }

        return [0, 0, 0];
    }

    private function opacity(mixed $value): float
    {
        $v = trim((string) $value);
        $f = str_ends_with($v, '%') ? ((float) $v) / 100 : (float) $v;

        return max(0.0, min(1.0, $f));
    }

    // ---------------------------------------------------------------------
    // Transformations (matrice [a, b, c, d, e, f] : x' = ax + cy + e ; y' = bx + dy + f)
    // ---------------------------------------------------------------------

    private function applyTransform(array $matrix, string $transform): array
    {
        if (trim($transform) === '') {
            return $matrix;
        }

        preg_match_all('/(matrix|translate|scale|rotate|skewX|skewY)\s*\(([^)]*)\)/i', $transform, $found, PREG_SET_ORDER);

        foreach ($found as $item) {
            $args = array_map('floatval', preg_split('/[\s,]+/', trim($item[2])) ?: []);
            $t = match (strtolower($item[1])) {
                'matrix' => count($args) >= 6 ? array_slice($args, 0, 6) : null,
                'translate' => [1.0, 0.0, 0.0, 1.0, $args[0] ?? 0.0, $args[1] ?? 0.0],
                'scale' => [$args[0] ?? 1.0, 0.0, 0.0, $args[1] ?? ($args[0] ?? 1.0), 0.0, 0.0],
                'rotate' => $this->rotation($args),
                'skewx' => [1.0, 0.0, tan(deg2rad($args[0] ?? 0.0)), 1.0, 0.0, 0.0],
                'skewy' => [1.0, tan(deg2rad($args[0] ?? 0.0)), 0.0, 1.0, 0.0, 0.0],
                default => null,
            };

            if ($t !== null) {
                $matrix = $this->multiply($matrix, $t);
            }
        }

        return $matrix;
    }

    /** @return array<int,float> */
    private function rotation(array $args): array
    {
        $a = deg2rad($args[0] ?? 0.0);
        $cos = cos($a);
        $sin = sin($a);
        $rot = [$cos, $sin, -$sin, $cos, 0.0, 0.0];

        if (isset($args[1], $args[2])) {
            $rot = $this->multiply([1.0, 0.0, 0.0, 1.0, $args[1], $args[2]], $rot);
            $rot = $this->multiply($rot, [1.0, 0.0, 0.0, 1.0, -$args[1], -$args[2]]);
        }

        return $rot;
    }

    private function multiply(array $m, array $t): array
    {
        return [
            $m[0] * $t[0] + $m[2] * $t[1],
            $m[1] * $t[0] + $m[3] * $t[1],
            $m[0] * $t[2] + $m[2] * $t[3],
            $m[1] * $t[2] + $m[3] * $t[3],
            $m[0] * $t[4] + $m[2] * $t[5] + $m[4],
            $m[1] * $t[4] + $m[3] * $t[5] + $m[5],
        ];
    }

    /** @return array{0:float,1:float} */
    private function point(array $m, float $x, float $y): array
    {
        return [$m[0] * $x + $m[2] * $y + $m[4], $m[1] * $x + $m[3] * $y + $m[5]];
    }

    // ---------------------------------------------------------------------
    // Formes
    // ---------------------------------------------------------------------

    private function drawShape(DOMElement $el, string $name, array $matrix, array $style): void
    {
        if (($style['visibility'] ?? 'visible') === 'hidden') {
            return;
        }

        $subpaths = $this->buildSubpaths($el, $name);
        if ($subpaths === []) {
            return;
        }

        // Passage en coordonnées écran
        foreach ($subpaths as &$sub) {
            foreach ($sub['pts'] as &$p) {
                $p = $this->point($matrix, $p[0], $p[1]);
            }
            unset($p);
        }
        unset($sub);

        $opacity = (float) $style['opacity'];

        // Remplissage (les tracés ouverts sont fermés implicitement ; <line> n'a pas d'aire)
        $fill = $this->parseColor((string) $style['fill']);
        if ($fill !== null && $name !== 'line') {
            $polys = array_map(static fn (array $s): array => $s['pts'], $subpaths);
            $this->fillPolygons(
                $polys,
                $fill,
                strtolower((string) $style['fill-rule']) === 'evenodd',
                $opacity * $this->opacity($style['fill-opacity'])
            );
            $this->shapeCount++;
        }

        // Trait
        $stroke = $this->parseColor((string) $style['stroke']);
        $strokeWidth = $this->length((string) $style['stroke-width'], 1.0);
        if ($stroke !== null && $strokeWidth > 0) {
            $deviceWidth = $strokeWidth * sqrt(abs($matrix[0] * $matrix[3] - $matrix[1] * $matrix[2]));
            $quads = $this->strokeToPolygons($subpaths, $deviceWidth, strtolower((string) $style['stroke-linecap']));
            if ($quads !== []) {
                $this->fillPolygons($quads, $stroke, false, $opacity * $this->opacity($style['stroke-opacity']));
                $this->shapeCount++;
            }
        }
    }

    /** @return list<array{pts:list<array{0:float,1:float}>,closed:bool}> */
    private function buildSubpaths(DOMElement $el, string $name): array
    {
        switch ($name) {
            case 'rect':
                $x = $this->length($el->getAttribute('x'));
                $y = $this->length($el->getAttribute('y'));
                $w = $this->length($el->getAttribute('width'));
                $h = $this->length($el->getAttribute('height'));
                if ($w <= 0 || $h <= 0) {
                    return [];
                }
                $rx = $this->length($el->getAttribute('rx'));
                $ry = $this->length($el->getAttribute('ry'));
                if ($rx <= 0 && $ry > 0) {
                    $rx = $ry;
                }
                if ($ry <= 0 && $rx > 0) {
                    $ry = $rx;
                }
                $rx = min($rx, $w / 2);
                $ry = min($ry, $h / 2);

                if ($rx <= 0 || $ry <= 0) {
                    return [['pts' => [[$x, $y], [$x + $w, $y], [$x + $w, $y + $h], [$x, $y + $h]], 'closed' => true]];
                }

                $pts = [];
                $corners = [
                    [$x + $w - $rx, $y + $ry, -90],
                    [$x + $w - $rx, $y + $h - $ry, 0],
                    [$x + $rx, $y + $h - $ry, 90],
                    [$x + $rx, $y + $ry, 180],
                ];
                foreach ($corners as [$cx, $cy, $start]) {
                    for ($i = 0; $i <= 6; $i++) {
                        $a = deg2rad($start + 15 * $i);
                        $pts[] = [$cx + $rx * cos($a), $cy + $ry * sin($a)];
                    }
                }

                return [['pts' => $pts, 'closed' => true]];

            case 'circle':
            case 'ellipse':
                $cx = $this->length($el->getAttribute('cx'));
                $cy = $this->length($el->getAttribute('cy'));
                if ($name === 'circle') {
                    $rx = $ry = $this->length($el->getAttribute('r'));
                } else {
                    $rx = $this->length($el->getAttribute('rx'));
                    $ry = $this->length($el->getAttribute('ry'));
                }
                if ($rx <= 0 || $ry <= 0) {
                    return [];
                }
                $pts = [];
                for ($i = 0; $i < 48; $i++) {
                    $a = 2 * M_PI * $i / 48;
                    $pts[] = [$cx + $rx * cos($a), $cy + $ry * sin($a)];
                }

                return [['pts' => $pts, 'closed' => true]];

            case 'polygon':
            case 'polyline':
                preg_match_all('/[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/', $el->getAttribute('points'), $m);
                $nums = array_map('floatval', $m[0]);
                $pts = [];
                for ($i = 0; $i + 1 < count($nums); $i += 2) {
                    $pts[] = [$nums[$i], $nums[$i + 1]];
                }

                return count($pts) >= 2 ? [['pts' => $pts, 'closed' => $name === 'polygon']] : [];

            case 'line':
                return [[
                    'pts' => [
                        [$this->length($el->getAttribute('x1')), $this->length($el->getAttribute('y1'))],
                        [$this->length($el->getAttribute('x2')), $this->length($el->getAttribute('y2'))],
                    ],
                    'closed' => false,
                ]];

            case 'path':
                return $this->parsePath($el->getAttribute('d'));
        }

        return [];
    }

    // ---------------------------------------------------------------------
    // Attribut "d" des chemins
    // ---------------------------------------------------------------------

    /** @return list<array{pts:list<array{0:float,1:float}>,closed:bool}> */
    private function parsePath(string $d): array
    {
        $len = strlen($d);
        $i = 0;
        $subs = [];
        $cur = null;
        $closed = false;
        $cx = $cy = $sx = $sy = 0.0;
        $prevCmd = '';
        $ctrl = null; // dernier point de contrôle (pour S et T)

        $flush = static function () use (&$subs, &$cur, &$closed): void {
            if ($cur !== null && count($cur) >= 1) {
                $subs[] = ['pts' => $cur, 'closed' => $closed];
            }
            $cur = null;
            $closed = false;
        };

        $skip = static function () use ($d, $len, &$i): void {
            while ($i < $len && (ctype_space($d[$i]) || $d[$i] === ',')) {
                $i++;
            }
        };

        $number = function () use ($d, &$i, $skip): float {
            $skip();
            if (! preg_match('/\G[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/', $d, $m, 0, $i)) {
                throw new RuntimeException('chemin SVG invalide (nombre attendu).');
            }
            $i += strlen($m[0]);

            return (float) $m[0];
        };

        $flag = function () use ($d, $len, &$i, $skip): int {
            $skip();
            if ($i >= $len || ($d[$i] !== '0' && $d[$i] !== '1')) {
                throw new RuntimeException('chemin SVG invalide (indicateur d\'arc attendu).');
            }

            return (int) $d[$i++];
        };

        $cmd = '';
        $guard = 0;

        while (true) {
            $skip();
            if ($i >= $len) {
                break;
            }
            if (++$guard > 2000000) {
                throw new RuntimeException('chemin SVG trop long.');
            }

            if (ctype_alpha($d[$i])) {
                $cmd = $d[$i++];
            } elseif ($cmd === '' || $cmd === 'Z' || $cmd === 'z') {
                throw new RuntimeException('chemin SVG invalide.');
            }

            $rel = ctype_lower($cmd);
            $up = strtoupper($cmd);

            if ($up === 'Z') {
                if ($cur !== null) {
                    $closed = true;
                    $flush();
                }
                $cx = $sx;
                $cy = $sy;
                $prevCmd = 'Z';
                $ctrl = null;
                continue;
            }

            if (! in_array($up, ['M', 'L', 'H', 'V', 'C', 'S', 'Q', 'T', 'A'], true)) {
                throw new RuntimeException("commande de chemin SVG inconnue « {$cmd} ».");
            }

            // Un tracé commence toujours par un point de départ
            if ($up === 'M') {
                $flush();
                $x = $number();
                $y = $number();
                $cx = $rel ? $cx + $x : $x;
                $cy = $rel ? $cy + $y : $y;
                $sx = $cx;
                $sy = $cy;
                $cur = [[$cx, $cy]];
                $prevCmd = 'M';
                $ctrl = null;
                $cmd = $rel ? 'l' : 'L'; // paires suivantes = lignes implicites
                continue;
            }

            if ($cur === null) {
                $cur = [[$cx, $cy]];
            }

            switch ($up) {
                case 'L':
                    $x = $number();
                    $y = $number();
                    $cx = $rel ? $cx + $x : $x;
                    $cy = $rel ? $cy + $y : $y;
                    $cur[] = [$cx, $cy];
                    $ctrl = null;
                    break;

                case 'H':
                    $x = $number();
                    $cx = $rel ? $cx + $x : $x;
                    $cur[] = [$cx, $cy];
                    $ctrl = null;
                    break;

                case 'V':
                    $y = $number();
                    $cy = $rel ? $cy + $y : $y;
                    $cur[] = [$cx, $cy];
                    $ctrl = null;
                    break;

                case 'C':
                case 'S':
                    if ($up === 'C') {
                        $x1 = $number();
                        $y1 = $number();
                        if ($rel) {
                            $x1 += $cx;
                            $y1 += $cy;
                        }
                    } else {
                        $reflect = in_array($prevCmd, ['C', 'S'], true) && $ctrl !== null;
                        $x1 = $reflect ? 2 * $cx - $ctrl[0] : $cx;
                        $y1 = $reflect ? 2 * $cy - $ctrl[1] : $cy;
                    }
                    $x2 = $number();
                    $y2 = $number();
                    $x = $number();
                    $y = $number();
                    if ($rel) {
                        $x2 += $cx;
                        $y2 += $cy;
                        $x += $cx;
                        $y += $cy;
                    }
                    for ($s = 1; $s <= self::CURVE_STEPS; $s++) {
                        $t = $s / self::CURVE_STEPS;
                        $u = 1 - $t;
                        $cur[] = [
                            $u * $u * $u * $cx + 3 * $u * $u * $t * $x1 + 3 * $u * $t * $t * $x2 + $t * $t * $t * $x,
                            $u * $u * $u * $cy + 3 * $u * $u * $t * $y1 + 3 * $u * $t * $t * $y2 + $t * $t * $t * $y,
                        ];
                    }
                    $ctrl = [$x2, $y2];
                    $cx = $x;
                    $cy = $y;
                    break;

                case 'Q':
                case 'T':
                    if ($up === 'Q') {
                        $x1 = $number();
                        $y1 = $number();
                        if ($rel) {
                            $x1 += $cx;
                            $y1 += $cy;
                        }
                    } else {
                        $reflect = in_array($prevCmd, ['Q', 'T'], true) && $ctrl !== null;
                        $x1 = $reflect ? 2 * $cx - $ctrl[0] : $cx;
                        $y1 = $reflect ? 2 * $cy - $ctrl[1] : $cy;
                    }
                    $x = $number();
                    $y = $number();
                    if ($rel) {
                        $x += $cx;
                        $y += $cy;
                    }
                    for ($s = 1; $s <= self::CURVE_STEPS; $s++) {
                        $t = $s / self::CURVE_STEPS;
                        $u = 1 - $t;
                        $cur[] = [
                            $u * $u * $cx + 2 * $u * $t * $x1 + $t * $t * $x,
                            $u * $u * $cy + 2 * $u * $t * $y1 + $t * $t * $y,
                        ];
                    }
                    $ctrl = [$x1, $y1];
                    $cx = $x;
                    $cy = $y;
                    break;

                case 'A':
                    $rx = $number();
                    $ry = $number();
                    $rot = $number();
                    $large = $flag();
                    $sweep = $flag();
                    $x = $number();
                    $y = $number();
                    if ($rel) {
                        $x += $cx;
                        $y += $cy;
                    }
                    foreach ($this->arcPoints($cx, $cy, $rx, $ry, $rot, $large, $sweep, $x, $y) as $p) {
                        $cur[] = $p;
                    }
                    $cx = $x;
                    $cy = $y;
                    $ctrl = null;
                    break;
            }

            $prevCmd = $up;
        }

        $flush();

        return $subs;
    }

    /**
     * Arc elliptique SVG (conversion "endpoint" -> "center", spec SVG F.6.5), aplati en segments.
     *
     * @return list<array{0:float,1:float}>
     */
    private function arcPoints(float $x1, float $y1, float $rx, float $ry, float $rotation, int $large, int $sweep, float $x2, float $y2): array
    {
        if ($x1 === $x2 && $y1 === $y2) {
            return [];
        }
        $rx = abs($rx);
        $ry = abs($ry);
        if ($rx == 0.0 || $ry == 0.0) {
            return [[$x2, $y2]];
        }

        $phi = deg2rad($rotation);
        $cosP = cos($phi);
        $sinP = sin($phi);

        $dx = ($x1 - $x2) / 2;
        $dy = ($y1 - $y2) / 2;
        $x1p = $cosP * $dx + $sinP * $dy;
        $y1p = -$sinP * $dx + $cosP * $dy;

        $lambda = ($x1p * $x1p) / ($rx * $rx) + ($y1p * $y1p) / ($ry * $ry);
        if ($lambda > 1) {
            $rx *= sqrt($lambda);
            $ry *= sqrt($lambda);
        }

        $num = $rx * $rx * $ry * $ry - $rx * $rx * $y1p * $y1p - $ry * $ry * $x1p * $x1p;
        $den = $rx * $rx * $y1p * $y1p + $ry * $ry * $x1p * $x1p;
        $coef = $den == 0.0 ? 0.0 : sqrt(max(0.0, $num / $den));
        if ($large === $sweep) {
            $coef = -$coef;
        }

        $cxp = $coef * ($rx * $y1p / $ry);
        $cyp = $coef * (-$ry * $x1p / $rx);
        $cx = $cosP * $cxp - $sinP * $cyp + ($x1 + $x2) / 2;
        $cy = $sinP * $cxp + $cosP * $cyp + ($y1 + $y2) / 2;

        $theta1 = atan2(($y1p - $cyp) / $ry, ($x1p - $cxp) / $rx);
        $theta2 = atan2((-$y1p - $cyp) / $ry, (-$x1p - $cxp) / $rx);
        $delta = $theta2 - $theta1;
        if ($sweep === 0 && $delta > 0) {
            $delta -= 2 * M_PI;
        } elseif ($sweep === 1 && $delta < 0) {
            $delta += 2 * M_PI;
        }

        $steps = max(4, (int) ceil(abs($delta) / (M_PI / 2) * 8));
        $points = [];
        for ($s = 1; $s <= $steps; $s++) {
            $a = $theta1 + $delta * $s / $steps;
            $points[] = [
                $cosP * $rx * cos($a) - $sinP * $ry * sin($a) + $cx,
                $sinP * $rx * cos($a) + $cosP * $ry * sin($a) + $cy,
            ];
        }
        $points[count($points) - 1] = [$x2, $y2];

        return $points;
    }

    // ---------------------------------------------------------------------
    // Traits -> polygones
    // ---------------------------------------------------------------------

    /**
     * Transforme chaque segment en rectangle orienté (extrémités plates ou carrées)
     * et chaque sommet intérieur en petit disque : l'union est remplie en "nonzero".
     *
     * @param  list<array{pts:list<array{0:float,1:float}>,closed:bool}>  $subpaths  coordonnées écran
     * @return list<list<array{0:float,1:float}>>
     */
    private function strokeToPolygons(array $subpaths, float $width, string $cap): array
    {
        $half = $width / 2;
        if ($half <= 0) {
            return [];
        }

        $polys = [];

        foreach ($subpaths as $sub) {
            $pts = $sub['pts'];
            $count = count($pts);
            if ($count < 2) {
                continue;
            }

            $segments = $count - 1 + ($sub['closed'] ? 1 : 0);

            for ($s = 0; $s < $segments; $s++) {
                $a = $pts[$s];
                $b = $pts[($s + 1) % $count];
                $dx = $b[0] - $a[0];
                $dy = $b[1] - $a[1];
                $len = sqrt($dx * $dx + $dy * $dy);
                if ($len < 1e-9) {
                    continue;
                }
                $ux = $dx / $len;
                $uy = $dy / $len;
                $nx = -$uy * $half;
                $ny = $ux * $half;

                $extStart = (! $sub['closed'] && $s === 0 && $cap !== 'butt') ? $half : 0.0;
                $extEnd = (! $sub['closed'] && $s === $segments - 1 && $cap !== 'butt') ? $half : 0.0;

                $ax = $a[0] - $ux * $extStart;
                $ay = $a[1] - $uy * $extStart;
                $bx = $b[0] + $ux * $extEnd;
                $by = $b[1] + $uy * $extEnd;

                $polys[] = [
                    [$ax + $nx, $ay + $ny],
                    [$bx + $nx, $by + $ny],
                    [$bx - $nx, $by - $ny],
                    [$ax - $nx, $ay - $ny],
                ];
            }

            // Joints arrondis aux sommets intérieurs
            $from = $sub['closed'] ? 0 : 1;
            $to = $sub['closed'] ? $count : $count - 1;
            for ($v = $from; $v < $to; $v++) {
                $disc = [];
                for ($k = 0; $k < 10; $k++) {
                    $ang = 2 * M_PI * $k / 10;
                    $disc[] = [$pts[$v][0] + $half * cos($ang), $pts[$v][1] + $half * sin($ang)];
                }
                $polys[] = $disc;
            }
        }

        // Orientation uniforme : évite que deux formes superposées s'annulent en "nonzero"
        foreach ($polys as $k => $poly) {
            if ($this->signedArea($poly) < 0) {
                $polys[$k] = array_reverse($poly);
            }
        }

        return $polys;
    }

    private function signedArea(array $poly): float
    {
        $area = 0.0;
        $n = count($poly);
        for ($i = 0; $i < $n; $i++) {
            $j = ($i + 1) % $n;
            $area += $poly[$i][0] * $poly[$j][1] - $poly[$j][0] * $poly[$i][1];
        }

        return $area / 2;
    }

    // ---------------------------------------------------------------------
    // Remplissage par balayage avec anti-aliasing
    // ---------------------------------------------------------------------

    /**
     * @param  list<list<array{0:float,1:float}>>  $polygons  coordonnées écran (pixels)
     * @param  array{0:int,1:int,2:int}  $rgb
     */
    private function fillPolygons(array $polygons, array $rgb, bool $evenOdd, float $alpha): void
    {
        if ($alpha <= 0.0) {
            return;
        }

        $ss = self::SUBSAMPLES;
        $maxK = $this->ch * $ss - 1;

        // Intersections de chaque arête avec les lignes de balayage (centre de chaque sous-ligne)
        $crossings = [];
        foreach ($polygons as $poly) {
            $n = count($poly);
            if ($n < 3) {
                continue;
            }
            for ($i = 0; $i < $n; $i++) {
                [$x0, $y0] = $poly[$i];
                [$x1, $y1] = $poly[($i + 1) % $n];
                if ($y0 === $y1) {
                    continue;
                }
                $dir = 1;
                if ($y1 < $y0) {
                    [$x0, $y0, $x1, $y1] = [$x1, $y1, $x0, $y0];
                    $dir = -1;
                }
                $k0 = max(0, (int) ceil($y0 * $ss - 0.5));
                $k1 = min($maxK, (int) ceil($y1 * $ss - 0.5) - 1);
                if ($k1 < $k0) {
                    continue;
                }
                $slope = ($x1 - $x0) / ($y1 - $y0);
                for ($k = $k0; $k <= $k1; $k++) {
                    $crossings[$k][] = [$x0 + (($k + 0.5) / $ss - $y0) * $slope, $dir];
                }
            }
        }

        if ($crossings === []) {
            return;
        }

        ksort($crossings);

        $row = null;
        $cov = [];

        foreach ($crossings as $k => $list) {
            $y = intdiv($k, $ss);

            if ($row !== $y) {
                if ($row !== null) {
                    $this->flushRow($row, $cov, $rgb, $alpha);
                }
                $row = $y;
                $cov = [];
            }

            sort($list);
            $winding = 0;
            $count = count($list);

            for ($i = 0; $i < $count - 1; $i++) {
                $winding += $evenOdd ? 1 : $list[$i][1];
                $inside = $evenOdd ? ($winding % 2 !== 0) : ($winding !== 0);
                if (! $inside) {
                    continue;
                }

                $xa = max(0.0, $list[$i][0]);
                $xb = min((float) $this->cw, $list[$i + 1][0]);
                if ($xb <= $xa) {
                    continue;
                }

                $p0 = (int) floor($xa);
                $p1 = min($this->cw - 1, (int) floor($xb));

                if ($p0 === $p1) {
                    $cov[$p0] = ($cov[$p0] ?? 0.0) + ($xb - $xa) / $ss;
                    continue;
                }

                $cov[$p0] = ($cov[$p0] ?? 0.0) + ($p0 + 1 - $xa) / $ss;
                for ($p = $p0 + 1; $p < $p1; $p++) {
                    $cov[$p] = ($cov[$p] ?? 0.0) + 1.0 / $ss;
                }
                $tail = $xb - $p1;
                if ($tail > 0) {
                    $cov[$p1] = ($cov[$p1] ?? 0.0) + $tail / $ss;
                }
            }
        }

        if ($row !== null) {
            $this->flushRow($row, $cov, $rgb, $alpha);
        }
    }

    /** @param array<int,float> $cov couverture (0..1) par colonne */
    private function flushRow(int $y, array $cov, array $rgb, float $alpha): void
    {
        $solid = ($rgb[0] << 16) | ($rgb[1] << 8) | $rgb[2];

        foreach ($cov as $x => $c) {
            $c = min(1.0, $c) * $alpha;
            if ($c <= 0.004) {
                continue;
            }
            if ($c >= 0.996) {
                imagesetpixel($this->canvas, $x, $y, $solid);
                continue;
            }

            $under = imagecolorat($this->canvas, $x, $y);
            $r = (int) round((($under >> 16) & 255) * (1 - $c) + $rgb[0] * $c);
            $g = (int) round((($under >> 8) & 255) * (1 - $c) + $rgb[1] * $c);
            $b = (int) round(($under & 255) * (1 - $c) + $rgb[2] * $c);
            imagesetpixel($this->canvas, $x, $y, ($r << 16) | ($g << 8) | $b);
        }
    }

    // ---------------------------------------------------------------------
    // Utilitaires
    // ---------------------------------------------------------------------

    /** Longueur SVG : nombre, éventuellement suivi d'une unité (ignorée). Les % valent le défaut. */
    private function length(string $value, float $default = 0.0): float
    {
        $value = trim($value);
        if ($value === '' || str_ends_with($value, '%')) {
            return $default;
        }

        return preg_match('/^[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/', $value, $m) ? (float) $m[0] : $default;
    }

    private function isWhole(float $v): bool
    {
        return abs($v - round($v)) < 1e-6;
    }
}
