<?php

namespace App\Http\Controllers;

use App\Models\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Galerie des templates TicketLab (option B).
 */
class TemplateController extends Controller
{
    /**
     * GET /api/templates?type=&sector=
     *
     * Filtres optionnels : `type` (ticket|flyer|affiche) et
     * `sector` (stand|evenement|parking|garage|lavage).
     *
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        // Routes protégées (Sanctum) : le front envoie le Bearer token
        $validated = $request->validate([
            'type'   => ['nullable', 'in:ticket,flyer,affiche'],
            'sector' => ['nullable', 'in:stand,evenement,parking,garage,lavage'],
        ]);

        $templates = Template::query()
            ->global()
            ->ofType($validated['type'] ?? null)
            ->ofSector($validated['sector'] ?? null)
            ->orderBy('type')
            ->orderBy('sector')
            ->orderBy('name')
            ->get();

        return response()->json(
            // $appends fournit déjà qr_zone, fields et image_url
            $templates->toArray()
        );
    }

    /**
     * GET /api/templates/{template}
     */
    public function show(Template $template): JsonResponse
    {
        return response()->json($template->toArray());
    }

    /**
     * GET /api/templates/{template}/image
     *
     * Sert l'image de fond du template. Passe par PHP (et non un fichier
     * public) pour que les templates restent hors de la racine web.
     */
    public function image(Template $template): BinaryFileResponse|JsonResponse
    {
        if (! $template->imageExists()) {
            return response()->json([
                'message' => "Image du template introuvable sur le serveur ({$template->image_path}).",
            ], 404);
        }

        return response()->file($template->absoluteImagePath(), [
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * GET /api/templates/meta
     *
     * Liste les combinaisons type/sector réellement disponibles, pour que le
     * frontend puisse n'afficher que des choix qui mènent quelque part.
     */
    public function meta(): JsonResponse
    {
        $combinations = Template::query()
            ->global()
            ->select('type', 'sector')
            ->distinct()
            ->get()
            ->map(static fn (Template $t): array => ['type' => $t->type, 'sector' => $t->sector])
            ->values();

        return response()->json([
            'types'    => ['ticket', 'flyer', 'affiche'],
            'sectors'  => ['stand', 'evenement', 'parking', 'garage', 'lavage'],
            'qr_sizes' => config('ticketlab.qr_sizes'),
            'available' => $combinations,
        ]);
    }
}
