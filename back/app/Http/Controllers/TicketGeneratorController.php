<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Services\TicketImageGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Génération en série.
 *
 *  POST /api/generate/custom  mode A : template personnel + zone QR
 *  POST /api/generate/preset  mode B : template TicketLab + textes
 *
 * La liste des templates est servie par TemplateController.
 *
 * Les erreurs "attendues" (zone hors image, ZIP vide...) remontent en 422 avec
 * un message français ; tout le reste est journalisé et renvoyé en 500 sans
 * détails techniques. Le dossier temporaire est supprimé dans tous les cas
 * (après l'envoi du ZIP en cas de succès).
 */
class TicketGeneratorController extends Controller
{
    public function __construct(private readonly TicketImageGenerator $generator)
    {
    }

    public function generateFromCustom(Request $request): BinaryFileResponse|JsonResponse
    {
        $limits = config('ticketlab.limits');

        $validator = Validator::make($request->all(), [
            'background_image' => ['required', 'file', 'mimes:png,jpg,jpeg', 'max:' . ($limits['template_max_mb'] * 1024)],
            'qr_zone' => ['required', 'json'],
            'qr_zip' => ['required', 'file', 'mimes:zip', 'max:' . ($limits['qr_zip_max_mb'] * 1024)],
        ], $this->messages($limits));

        if ($validator->fails()) {
            return $this->invalid($validator->errors()->first(), $validator->errors()->toArray());
        }

        $zone = json_decode((string) $request->input('qr_zone'), true);
        if (! is_array($zone)) {
            return $this->invalid('La zone du QR code est invalide.');
        }

        return $this->run(fn () => $this->generator->generateFromCustom(
            $request->file('background_image')->getRealPath(),
            $zone,
            $request->file('qr_zip')->getRealPath()
        ));
    }

    public function generateFromPreset(Request $request): BinaryFileResponse|JsonResponse
    {
        $limits = config('ticketlab.limits');

        $validator = Validator::make($request->all(), [
            'template_id' => ['required', 'integer', 'exists:templates,id'],
            'fields' => ['required', 'json'],
            'qr_zip' => ['required', 'file', 'mimes:zip', 'max:' . ($limits['qr_zip_max_mb'] * 1024)],
        ], $this->messages($limits));

        if ($validator->fails()) {
            return $this->invalid($validator->errors()->first(), $validator->errors()->toArray());
        }

        $values = json_decode((string) $request->input('fields'), true);
        if (! is_array($values)) {
            return $this->invalid('Les champs du template sont invalides.');
        }

        $template = Template::global()->find($request->integer('template_id'));
        if (! $template) {
            return $this->invalid('Template introuvable.');
        }

        return $this->run(fn () => $this->generator->generateFromPreset(
            $template,
            $values,
            $request->file('qr_zip')->getRealPath()
        ));
    }

    /**
     * Exécute la génération et transforme le résultat en réponse HTTP.
     *
     * @param  callable():string  $generate  renvoie le chemin du ZIP final
     */
    private function run(callable $generate): BinaryFileResponse|JsonResponse
    {
        try {
            $zipPath = $generate();
        } catch (RuntimeException $e) {
            $this->generator->cleanup();

            return $this->invalid($e->getMessage());
        } catch (Throwable $e) {
            $this->generator->cleanup();
            report($e);

            return response()->json([
                'message' => 'Une erreur inattendue est survenue pendant la génération. Réessayez ou contactez l\'administrateur.',
            ], 500);
        }

        // Nettoyage complet une fois le ZIP envoyé au client
        app()->terminating(fn () => $this->generator->cleanup());

        return response()->download(
            $zipPath,
            'tickets_' . date('Y-m-d_H-i-s') . '.zip',
            ['Content-Type' => 'application/zip']
        );
    }

    private function invalid(string $message, array $errors = []): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => (object) $errors], 422);
    }

    /** @param array<string,int> $limits */
    private function messages(array $limits): array
    {
        return [
            'background_image.required' => 'Ajoutez l\'image de votre template.',
            'background_image.mimes' => 'Le template doit être une image PNG ou JPG.',
            'background_image.max' => "Le template dépasse {$limits['template_max_mb']} Mo.",
            'background_image.uploaded' => "Le template n'a pas pu être envoyé (taille supérieure à la limite du serveur ?).",
            'qr_zone.required' => 'Définissez la zone du QR code.',
            'qr_zone.json' => 'La zone du QR code est invalide.',
            'qr_zip.required' => 'Ajoutez le ZIP des QR codes.',
            'qr_zip.mimes' => 'Les QR codes doivent être envoyés dans un fichier ZIP.',
            'qr_zip.max' => "Le ZIP des QR codes dépasse {$limits['qr_zip_max_mb']} Mo.",
            'qr_zip.uploaded' => "Le ZIP n'a pas pu être envoyé (taille supérieure à la limite du serveur ?).",
            'template_id.required' => 'Choisissez un template.',
            'template_id.exists' => 'Template introuvable.',
            'fields.required' => 'Les champs du template sont manquants.',
            'fields.json' => 'Les champs du template sont invalides.',
        ];
    }
}
