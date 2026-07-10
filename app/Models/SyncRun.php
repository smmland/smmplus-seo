<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['status', 'started_at', 'finished_at', 'total_fetched', 'added', 'updated', 'removed', 'error_message'])]
class SyncRun extends Model
{
    public const RUNNING = 'RUNNING';
    public const SUCCESS = 'SUCCESS';
    public const FAILED = 'FAILED';

    // started_at/finished_at already track this; the table has no created_at/updated_at columns.
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
