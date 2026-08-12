<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'lang', 'author_name', 'rating', 'body', 'avatar_path', 'related_service',
        'country_name', 'country_code', 'is_approved', 'sort_order',
        'submitted_username', 'submitted_ip',
        'frontend_user_id', 'frontend_order_id', 'frontend_ticket_id',
        'frontend_csrf_token', 'reported_ip', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'is_approved' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? url('/review-avatars/'.$this->avatar_path) : null;
    }

    // Converts a 2-letter ISO country code (e.g. "IR") into its flag emoji by shifting each
    // letter into the Unicode "regional indicator symbol" range - no lookup table needed, works
    // for any real country code.
    public function countryFlag(): ?string
    {
        if (! $this->country_code || strlen($this->country_code) !== 2) {
            return null;
        }

        $code = strtoupper($this->country_code);

        return mb_chr(127397 + ord($code[0])).mb_chr(127397 + ord($code[1]));
    }
}
