<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Un support imprimable prédéfini par TicketLab.
 *
 * Les colonnes `qr_zone_json` et `fields_json` sont exposées à l'API sous
 * les noms `qr_zone` et `fields` (voir $appends) : le frontend et le
 * générateur manipulent donc toujours la même structure, quelle que soit
 * la façon dont elle est stockée.
 *
 * @property int    $id
 * @property string $type
 * @property string $sector
 * @property string $name
 * @property string|null $description
 * @property string $image_path
 * @property int    $width
 * @property int    $height
 * @property array  $qr_zone_json
 * @property array  $fields_json
 * @property bool   $is_global
 */
class Template extends Model
{
    protected $fillable = [
        'type',
        'sector',
        'name',
        'description',
        'image_path',
        'width',
        'height',
        'qr_zone_json',
        'fields_json',
        'is_global',
    ];

    protected $casts = [
        'qr_zone_json' => 'array',
        'fields_json'  => 'array',
        'is_global'    => 'boolean',
    ];

    /**
     * Champs de la zone QR ajoutés à la sérialisation.
     *
     * @var list<string>
     */
    protected $appends = ['qr_zone', 'fields', 'image_url'];

    /**
     * Zone QR en pixels dans la résolution native du template.
     *
     * @return array{x:int,y:int,width:int,height:int}
     */
    public function getQrZoneAttribute(): array
    {
        $z = $this->qr_zone_json ?: [];

        return [
            'x'      => (int) ($z['x'] ?? 0),
            'y'      => (int) ($z['y'] ?? 0),
            'width'  => (int) ($z['width'] ?? 0),
            'height' => (int) ($z['height'] ?? 0),
        ];
    }

    /**
     * Champs texte éditables, normalisés pour que le frontend et le
     * générateur puissent compter sur la même forme.
     *
     * @return list<array<string,mixed>>
     */
    public function getFieldsAttribute(): array
    {
        return array_map(
            static fn (array $f): array => [
                'key'         => (string) ($f['key'] ?? ''),
                'label'       => (string) ($f['label'] ?? $f['key'] ?? ''),
                'type'        => in_array($f['type'] ?? 'text', ['text', 'textarea'], true) ? $f['type'] : 'text',
                'x'           => (int) ($f['x'] ?? 0),
                'y'           => (int) ($f['y'] ?? 0),
                'maxWidth'    => (int) ($f['maxWidth'] ?? 600),
                'fontSize'    => (int) ($f['fontSize'] ?? 24),
                'fontFamily'  => (string) ($f['fontFamily'] ?? 'Poppins'),
                'fontWeight'  => (int) ($f['fontWeight'] ?? 600),
                // 'auto' => le générateur choisit blanc ou foncé d'après le fond
                'color'       => (string) ($f['color'] ?? 'auto'),
                'align'       => in_array($f['align'] ?? 'left', ['left', 'center', 'right'], true) ? $f['align'] : 'left',
                'required'    => (bool) ($f['required'] ?? false),
                'placeholder' => (string) ($f['placeholder'] ?? ''),
                'lineHeight'  => (float) ($f['lineHeight'] ?? 1.25),
            ],
            $this->fields_json ?: []
        );
    }

    /**
     * URL publique de l'image de fond, servie par la route /api/templates/{id}/image.
     */
    public function getImageUrlAttribute(): string
    {
        return route('templates.image', ['template' => $this->id]);
    }

    /**
     * Chemin absolu sur le disque de l'image de fond.
     */
    public function absoluteImagePath(): string
    {
        return config('ticketlab.templates_path').DIRECTORY_SEPARATOR.$this->image_path;
    }

    /**
     * L'image de fond existe-t-elle bien sur le disque ?
     */
    public function imageExists(): bool
    {
        return is_file($this->absoluteImagePath());
    }

    /**
     * Les champs réellement obligatoires de ce template.
     *
     * @return list<string>
     */
    public function requiredFieldKeys(): array
    {
        return array_values(array_map(
            static fn (array $f): string => $f['key'],
            array_filter($this->fields, static fn (array $f): bool => $f['required'])
        ));
    }

    /**
     * Scopes utilitaires pour le filtrage de la galerie.
     */
    public function scopeGlobal($query)
    {
        return $query->where('is_global', true);
    }

    public function scopeOfType($query, ?string $type)
    {
        return $type ? $query->where('type', $type) : $query;
    }

    public function scopeOfSector($query, ?string $sector)
    {
        return $sector ? $query->where('sector', $sector) : $query;
    }
}
