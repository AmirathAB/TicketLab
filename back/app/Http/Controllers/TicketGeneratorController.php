<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Services\TicketImageGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Contrôleur de génération de tickets
 * 
 * Endpoints :
 * - GET /api/templates : Liste des templates
 * - POST /api/generate/custom : Génération avec template personnalisé
 * - POST /api/generate/preset : Génération avec template prédéfini
 */
class TicketGeneratorController extends Controller
{
    protected TicketImageGenerator $generator;

    public function __construct(TicketImageGenerator $generator)
    {
        $this->generator = $generator;
    }

    /**
     * Liste des templates
     * 
     * @param Request $request
     * @return JsonResponse
     */
    public function listTemplates(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $sector = $request->query('sector');

        $templates = Template::query()
            ->byTypeAndSector($type, $sector)
            ->global()
            ->get()
            ->map(function (Template $template) {
                return [
                    'id' => $template->id,
                    'type' => $template->type,
                    'sector' => $template->sector,
                    'name' => $template->name,
                    'description' => $template->description,
                    'image_url' => Storage::url($template->image_path),
                    'width' => $template->width,
                    'height' => $template->height,
                    'qr_zone' => $template->qr_zone,
                    'fields' => $template->fields,
                ];
            });

        return response()->json($templates);
    }

    /**
     * Génération avec template personnalisé
     * 
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function generateFromCustom(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'background_image' => ['required', 'image', 'max:10240'], // 10MB
            'qr_zone' => ['required', 'json'],
            'qr_zip' => ['required', 'file', 'mimes:zip', 'max:51200'], // 50MB
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        $backgroundImage = $request->file('background_image');
        $qrZip = $request->file('qr_zip');
        $qrZone = json_decode($request->input('qr_zone'), true);

        // Sauvegarder temporairement
        $backgroundPath = $backgroundImage->store('temp');
        $qrZipPath = $qrZip->store('temp');

        try {
            $zipFile = $this->generator->generateFromCustom(
                storage_path('app/' . $backgroundPath),
                $qrZone,
                storage_path('app/' . $qrZipPath)
            );

            return response()->download($zipFile)->deleteFileAfterSend();
        } catch (\Exception $e) {
            // Nettoyage en cas d'erreur
            Storage::delete($backgroundPath);
            Storage::delete($qrZipPath);

            return response()->json([
                'message' => 'Erreur lors de la génération',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Génération avec template prédéfini
     * 
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function generateFromPreset(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'template_id' => ['required', 'integer', 'exists:templates,id'],
            'fields' => ['required', 'json'],
            'qr_zip' => ['required', 'file', 'mimes:zip', 'max:51200'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        $template = Template::findOrFail($request->input('template_id'));
        $fields = json_decode($request->input('fields'), true);
        $qrZip = $request->file('qr_zip');

        $qrZipPath = $qrZip->store('temp');

        try {
            $zipFile = $this->generator->generateFromPreset(
                $template,
                $fields,
                storage_path('app/' . $qrZipPath)
            );

            return response()->download($zipFile)->deleteFileAfterSend();
        } catch (\Exception $e) {
            Storage::delete($qrZipPath);

            return response()->json([
                'message' => 'Erreur lors de la génération',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}