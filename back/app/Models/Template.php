<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Template prédéfini (ticket, flyer, affiche).
 *
 * Un template = une image de fond + une zone QR + des champs texte éditables.
 * Toutes les coordonnées sont en pixels, dans la résolution native de l'image
 * (colonnes width / height). Voir database/seeders/TemplateSeeder.php pour le
 * schéma complet d'un champ.
 *
 * @property int $id
 * @property string $type
 * @property string $sector
 * @property string $name
 * @property string|null $description
 * @property string $image_path  relatif à config('ticketlab.templates_path')
 * @property int $width
 * @property int $height
 * @property array $qr_zone
 * @property array $fields
 */
class Template extends Model
{
    use HasFactory;

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
        'fields_json' => 'array',
        'is_global' => 'boolean',
    ];

    /** Les colonnes *_json restent internes : l'API expose qr_zone, fields et image_url. */
    protected $hidden = ['qr_zone_json', 'fields_json', 'image_path'];

    protected $appends = ['qr_zone', 'fields', 'image_url'];

    /** Format : ['x' => int, 'y' => int, 'width' => int, 'height' => int] */
    public function getQrZoneAttribute(): array
    {
        return $this->qr_zone_json ?? [];
    }

    /** Format : [{key, label, type, x, y, fontSize, fontFamily, color, align, maxWidth, required, ...}] */
    public function getFieldsAttribute(): array
    {
        return $this->fields_json ?? [];
    }

    /**
     * URL publique de l'image (route non protégée : une balise <img> ne peut
     * pas envoyer le header Authorization).
     */
    public function getImageUrlAttribute(): string
    {
        return route('templates.image', ['template' => $this->getKey()]);
    }

    /** Chemin absolu de l'image, ou null si le chemin sort du dossier des templates. */
    public function absoluteImagePath(): ?string
    {
        $base = realpath((string) config('ticketlab.templates_path'));
        $file = $base ? realpath($base . DIRECTORY_SEPARATOR . ltrim($this->image_path, '/\\')) : false;

        if ($base === false || $file === false || ! str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $file;
    }

    public function imageExists(): bool
    {
        $path = $this->absoluteImagePath();

        return $path !== null && is_file($path);
    }

    public function scopeOfType($query, ?string $type)
    {
        return $type ? $query->where('type', $type) : $query;
    }

    public function scopeOfSector($query, ?string $sector)
    {
        return $sector ? $query->where('sector', $sector) : $query;
    }

    public function scopeGlobal($query)
    {
        return $query->where('is_global', true);
    }
}
