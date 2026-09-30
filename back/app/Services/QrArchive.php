<?php

namespace App\Services;

use RuntimeException;
use SplFileInfo;
use ZipArchive;

/**
 * Extraction et tri des QR codes contenus dans le ZIP fourni par l'utilisateur.
 *
 * Le nombre d'images trouvées détermine exactement le nombre de supports
 * générés (cf. spec §3 : « N est exactement le nombre de fichiers générés »).
 * Aucune limite métier n'est appliquée, seulement les garde-fous techniques
 * définis dans config/ticketlab.php.
 */
class QrArchive
{
    /**
     * Extensions acceptées pour les QR codes. Le SVG (format fourni par Ticketche)
     * est rasterisé par SvgRasterizer au moment de la composition.
     */
    public const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'svg'];

    /**
     * Extrait les images du ZIP dans un dossier temporaire.
     *
     * @return list<string> Chemins absolus des images extraites, triées
     * @throws RuntimeException si le ZIP est illisible, vide ou sans image
     */
    public function extract(string $zipPath, string $destinationDir): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException("L'extension PHP « zip » est requise sur le serveur.");
        }

        $zip = new ZipArchive;
        $opened = $zip->open($zipPath, ZipArchive::RDONLY);

        if ($opened !== true) {
            throw new RuntimeException("Le fichier ZIP est invalide ou corrompu (code {$opened}).");
        }

        $maxBytes = (int) config('ticketlab.limits.max_uncompressed_mb') * 1024 * 1024;
        $totalUncompressed = 0;

        // Nom lisible -> chemin source, le temps d'extraire
        $entries = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                continue;
            }

            $name = $stat['name'];

            // Ignore les dossiers et les entrées parasites des OS (__MACOSX, .DS_Store)
            if (str_ends_with($name, '/') || basename($name) === '.DS_Store' || str_contains($name, '__MACOSX/')) {
                continue;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                continue;
            }

            $totalUncompressed += (int) $stat['size'];
            if ($totalUncompressed > $maxBytes) {
                $zip->close();
                throw new RuntimeException(sprintf(
                    'Le contenu décompressé du ZIP dépasse la limite technique de %d Mo.',
                    (int) config('ticketlab.limits.max_uncompressed_mb')
                ));
            }

            $entries[$name] = true;
        }

        if ($entries === []) {
            $zip->close();
            throw new RuntimeException('Le ZIP ne contient aucun QR code exploitable (PNG, JPG ou SVG attendus).');
        }

        $maxTickets = (int) config('ticketlab.limits.max_tickets');
        if (count($entries) > $maxTickets) {
            $zip->close();
            throw new RuntimeException(sprintf(
                'Le ZIP contient %d QR codes, au-delà de la limite technique de %d par génération.',
                count($entries),
                $maxTickets
            ));
        }

        // Tri déterministe : "naturel" pour que ticket_2 précède ticket_10.
        $names = array_keys($entries);
        natcasesort($names);
        $names = array_values($names);

        $paths = [];
        foreach ($names as $index => $name) {
            $stream = $zip->getStream($name);
            if ($stream === false) {
                continue;
            }

            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $target = sprintf('%s/qr_%05d.%s', $destinationDir, $index + 1, $extension);

            $out = fopen($target, 'wb');
            if ($out === false) {
                fclose($stream);
                continue;
            }
            stream_copy_to_stream($stream, $out);
            fclose($stream);
            fclose($out);

            $paths[] = $target;
        }

        $zip->close();

        if ($paths === []) {
            throw new RuntimeException("Aucun QR code n'a pu être extrait du ZIP.");
        }

        return $paths;
    }

    /**
     * Supprime un fichier en ignorant silencieusement les erreurs.
     */
    public static function forget(?string $path): void
    {
        if ($path !== null && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Supprime récursivement le contenu d'un dossier temporaire.
     */
    public static function forgetDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        /** @var SplFileInfo $item */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
