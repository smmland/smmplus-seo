<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}
