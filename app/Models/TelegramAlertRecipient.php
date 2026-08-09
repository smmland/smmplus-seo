<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class TelegramAlertRecipient extends Model
{
    protected $fillable = [
        'label', 'link_token', 'chat_id', 'telegram_username', 'linked_at',
    ];

    protected function casts(): array
    {
        return [
            'linked_at' => 'datetime',
        ];
    }

    public function isLinked(): bool
    {
        return $this->chat_id !== null;
    }

    public static function generateLinkToken(): string
    {
        return Str::random(24);
    }
}
