<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Modèle Template
 * 
 * Représente un template prédéfini (ticket, flyer, affiche) avec :
 * - Une image de fond
 * - Une zone QR prédéfinie
 * - Des champs texte éditables
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

    /**
     * Obtenir la zone QR sous forme d'objet
     * Format : ['x' => int, 'y' => int, 'width' => int, 'height' => int]
     */
    public function getQrZoneAttribute(): array
    {
        return $this->qr_zone_json;
    }

    /**
     * Obtenir les champs éditables
     * Format : [{key, label, type, x, y, fontSize, fontFamily, color, align, maxWidth, required}]
     */
    public function getFieldsAttribute(): array
    {
        return $this->fields_json;
    }

    /**
     * Scope pour filtrer par type et secteur
     */
    public function scopeByTypeAndSector($query, ?string $type, ?string $sector)
    {
        if ($type) {
            $query->where('type', $type);
        }
        
        if ($sector) {
            $query->where('sector', $sector);
        }
        
        return $query;
    }

    /**
     * Scope pour les templates globaux
     */
    public function scopeGlobal($query)
    {
        return $query->where('is_global', true);
    }
}