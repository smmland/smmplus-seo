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
use Illuminate\Support\Facades\Schema;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'is_super_admin', 'granted_sections'];

    protected $hidden = ['password', 'remember_token', 'api_token'];

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * Guarded: right after this feature's own migration ships but before "Update database" is
     * clicked, this column doesn't exist yet - on a host with no terminal access, "Update
     * database" is itself reached through a page this same check would otherwise gate, which
     * would permanently lock every admin out with no way back in. Degrading to "yes" for that
     * window instead just matches this app's behavior before per-section access existed at all
     * (every account was effectively a full admin), which is exactly right until the migration
     * that actually defines "super admin" has run.
     */
    public function isSuperAdmin(): bool
    {
        if (! Schema::hasColumn('users', 'is_super_admin')) {
            return true;
        }

        return (bool) $this->is_super_admin;
    }

    /**
     * A super admin bypasses every per-section check - this app has no lesser access level than
     * "full admin" until PanelSection existed, so every account that predates it stays one (see
     * the is_super_admin migration), and it's the only way to reach user management or the
     * self-update installer, neither of which is itself a grantable PanelSection.
     */
    public function hasAccess(string $section): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        // Same lag-tolerant guard as isSuperAdmin() above, for the second half of this
        // feature's migration.
        if (! Schema::hasColumn('users', 'granted_sections')) {
            return true;
        }

        return in_array($section, $this->granted_sections ?? [], true);
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
            'granted_sections' => 'array',
        ];
    }
}
