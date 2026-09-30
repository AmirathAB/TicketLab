<?php

namespace App\Services;

use App\Models\Template;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Service de génération d'images
 * 
 * Ce service compose les images finales en combinant :
 * - Le template (image de fond)
 * - Les textes (champs éditables)
 * - Le QR code (image PNG)
 * 
 * Utilise la bibliothèque GD ou Imagick selon disponibilité.
 */
class TicketImageGenerator
{
    protected string $tempDir;

    public function __construct()
    {
        $this->tempDir = storage_path('app/temp/' . Str::random(10));
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }
    }

    public function generateFromCustom(
        string $backgroundPath,
        array $qrZone,
        string $qrZipPath
    ): string {
        $qrImages = $this->extractQrZip($qrZipPath);

        if (empty($qrImages)) {
            throw new \Exception('Le ZIP ne contient aucune image QR');
        }

        sort($qrImages);

        $outputDir = $this->tempDir . '/output';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        foreach ($qrImages as $index => $qrPath) {
            $ticketNumber = str_pad($index + 1, 3, '0', STR_PAD_LEFT);
            $outputPath = $outputDir . '/ticket_' . $ticketNumber . '.png';

            $this->composeImage(
                $backgroundPath,
                $qrPath,
                $qrZone,
                $outputPath
            );
        }

        return $this->createZip($outputDir);
    }

    public function generateFromPreset(
        Template $template,
        array $fields,
        string $qrZipPath
    ): string {
        $qrImages = $this->extractQrZip($qrZipPath);

        if (empty($qrImages)) {
            throw new \Exception('Le ZIP ne contient aucune image QR');
        }

        sort($qrImages);

        $outputDir = $this->tempDir . '/output';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $backgroundPath = storage_path('app/' . $template->image_path);

        if (!file_exists($backgroundPath)) {
            throw new \Exception('Template introuvable : ' . $backgroundPath);
        }

        foreach ($qrImages as $index => $qrPath) {
            $ticketNumber = str_pad($index + 1, 3, '0', STR_PAD_LEFT);
            $outputPath = $outputDir . '/ticket_' . $ticketNumber . '.png';

            $this->composeImageWithText(
                $backgroundPath,
                $qrPath,
                $template->qr_zone,
                $template->fields,
                $fields,
                $outputPath
            );
        }

        return $this->createZip($outputDir);
    }

    protected function extractQrZip(string $zipPath): array
    {
        $zip = new \ZipArchive();
        
        if ($zip->open($zipPath) !== true) {
            throw new \Exception('Impossible d\'ouvrir le ZIP');
        }

        $qrImages = [];
        $extractDir = $this->tempDir . '/qrs';
        
        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $file = $zip->statIndex($i);
            $fileName = $file['name'];

            if (str_ends_with($fileName, '/')) {
                continue;
            }

            $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            if (!in_array($extension, ['png', 'jpg', 'jpeg'])) {
                continue;
            }

            $extractPath = $extractDir . '/' . basename($fileName);
            $zip->extractTo($extractDir, $fileName);
            
            $newPath = $extractDir . '/qr_' . $i . '.' . $extension;
            rename($extractPath, $newPath);
            
            $qrImages[] = $newPath;
        }

        $zip->close();
        return $qrImages;
    }

    protected function composeImage(
        string $backgroundPath,
        string $qrPath,
        array $qrZone,
        string $outputPath
    ): void {
        $background = $this->loadImage($backgroundPath);
        $qr = $this->loadImage($qrPath);
        
        $qrResized = imagecreatetruecolor($qrZone['width'], $qrZone['height']);
        imagealphablending($qrResized, false);
        imagesavealpha($qrResized, true);
        imagecopyresampled(
            $qrResized,
            $qr,
            0, 0, 0, 0,
            $qrZone['width'],
            $qrZone['height'],
            imagesx($qr),
            imagesy($qr)
        );

        imagecopy(
            $background,
            $qrResized,
            $qrZone['x'],
            $qrZone['y'],
            0, 0,
            $qrZone['width'],
            $qrZone['height']
        );

        imagepng($background, $outputPath, 9);

        imagedestroy($background);
        imagedestroy($qr);
        imagedestroy($qrResized);
    }

    protected function composeImageWithText(
        string $backgroundPath,
        string $qrPath,
        array $qrZone,
        array $fields,
        array $values,
        string $outputPath
    ): void {
        $background = $this->loadImage($backgroundPath);
        $qr = $this->loadImage($qrPath);
        
        $qrResized = imagecreatetruecolor($qrZone['width'], $qrZone['height']);
        imagealphablending($qrResized, false);
        imagesavealpha($qrResized, true);
        imagecopyresampled(
            $qrResized,
            $qr,
            0, 0, 0, 0,
            $qrZone['width'],
            $qrZone['height'],
            imagesx($qr),
            imagesy($qr)
        );

        imagecopy(
            $background,
            $qrResized,
            $qrZone['x'],
            $qrZone['y'],
            0, 0,
            $qrZone['width'],
            $qrZone['height']
        );

        foreach ($fields as $field) {
            $value = $values[$field['key']] ?? '';
            
            if (empty($value)) {
                continue;
            }

            $this->drawText(
                $background,
                $value,
                $field
            );
        }

        imagepng($background, $outputPath, 9);

        imagedestroy($background);
        imagedestroy($qr);
        imagedestroy($qrResized);
    }

    protected function drawText(&$image, string $text, array $field): void
    {
        $fontSize = $field['fontSize'] ?? 16;
        $color = $this->hexToRgb($field['color'] ?? '#000000');
        $x = $field['x'] ?? 0;
        $y = $field['y'] ?? 0;

        $font = 5;

        $textColor = imagecolorallocate(
            $image,
            $color['r'],
            $color['g'],
            $color['b']
        );

        imagestring(
            $image,
            $font,
            $x,
            $y,
            $text,
            $textColor
        );

        imagecolordeallocate($image, $textColor);
    }

    protected function loadImage(string $path)
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'png') {
            $image = imagecreatefrompng($path);
        } elseif (in_array($extension, ['jpg', 'jpeg'])) {
            $image = imagecreatefromjpeg($path);
        } else {
            throw new \Exception('Format d\'image non supporté : ' . $extension);
        }

        if (!$image) {
            throw new \Exception('Impossible de charger l\'image : ' . $path);
        }

        return $image;
    }

    protected function hexToRgb(string $hex): array
    {
        $hex = str_replace('#', '', $hex);
        
        if (strlen($hex) === 3) {
            $r = hexdec(str_repeat(substr($hex, 0, 1), 2));
            $g = hexdec(str_repeat(substr($hex, 1, 1), 2));
            $b = hexdec(str_repeat(substr($hex, 2, 1), 2));
        } else {
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
        }

        return ['r' => $r, 'g' => $g, 'b' => $b];
    }

    protected function createZip(string $inputDir): string
    {
        $zipPath = $this->tempDir . '/tickets_' . date('Y-m-d_H-i-s') . '.zip';
        $zip = new \ZipArchive();

        if ($zip->open($zipPath, \ZipArchive::CREATE) !== true) {
            throw new \Exception('Impossible de créer le ZIP');
        }

        $files = glob($inputDir . '/*.png');
        
        foreach ($files as $file) {
            $zip->addFile($file, basename($file));
        }

        $zip->close();

        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($inputDir);

        return $zipPath;
    }

    public function cleanup(): void
    {
        if (is_dir($this->tempDir)) {
            $this->deleteDirectory($this->tempDir);
        }
    }

    protected function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}