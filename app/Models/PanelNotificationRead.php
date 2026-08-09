<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PanelNotificationRead extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'panel_notification_id', 'user_id', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }
}
