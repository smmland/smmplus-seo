<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CatalogService extends Model
{
    protected $fillable = [
        'service_id', 'name', 'type', 'category', 'rate', 'min', 'max',
        'refill', 'cancel', 'available', 'source_label', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'min' => 'integer',
            'max' => 'integer',
            'refill' => 'boolean',
            'cancel' => 'boolean',
            'available' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    // service_key isn't a real foreign key (ServiceTranslation predates this table and is keyed
    // on the site's own service id string, matched here by value) - lets CatalogServiceResource's
    // language picker eager-load every language's translation for a page of services in one
    // query instead of one per row.
    public function translations(): HasMany
    {
        return $this->hasMany(ServiceTranslation::class, 'service_key', 'service_id');
    }
}
