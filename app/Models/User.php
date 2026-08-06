<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\PanelSection;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    protected $fillable = ['name', 'email', 'password', 'is_super_admin'];

    protected $hidden = ['password', 'remember_token', 'api_token'];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * A super admin bypasses every per-section permission check - this app has no lesser access
     * level than "full admin" until PanelSection existed, so every account that predates it stays
     * one (see the is_super_admin migration), and it's the only way to reach user management or
     * the self-update installer, neither of which is itself a grantable PanelSection.
     */
    public function hasAccess(string $section): bool
    {
        return $this->is_super_admin || $this->hasPermissionTo(PanelSection::permissionKey($section));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }
}
