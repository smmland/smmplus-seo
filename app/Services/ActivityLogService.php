<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * The single write path into the activity_logs table - every recorded action, whether from an
 * Eloquent model event (see App\Models\Concerns\LogsActivity) or an explicit call at a specific
 * button/action handler, goes through record() here.
 */
class ActivityLogService
{
    // Never written to the log even if present in a model's changed attributes - a credential
    // showing up in a plaintext audit trail would turn the trail itself into a secret that needs
    // protecting, defeating the point of it being freely viewable by every super admin.
    private const REDACTED_KEYS = [
        'password', 'api_key', 'api_token', 'access_token', 'refresh_token',
        'client_secret', 'bot_token', 'remember_token',
    ];

    /**
     * @param  array<string, mixed>|null  $changes
     */
    public function record(
        string $action,
        ?Model $subject = null,
        ?array $changes = null,
        ?string $section = null,
        ?string $subjectLabel = null,
    ): void {
        // Guarded exactly like every other lag-tolerant check in this app (is_ai_guessed,
        // missed_syncs, is_super_admin): this table can lag behind this code until "Update
        // database" is clicked, and logging must never be the thing that breaks a real action -
        // silently skipping the log entry for that window is far safer than throwing.
        if (! Schema::hasTable('activity_logs')) {
            return;
        }

        $user = auth()->user();

        ActivityLog::query()->create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'action' => $action,
            'subject_type' => $subject ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subjectLabel ?? $this->labelFor($subject),
            'changes' => $changes ? $this->redact($changes) : null,
            'section' => $section,
            'ip_address' => request()?->ip(),
            'created_at' => now(),
        ]);
    }

    private function labelFor(?Model $subject): ?string
    {
        if (! $subject) {
            return null;
        }

        if (method_exists($subject, 'activityLabel')) {
            return $subject->activityLabel();
        }

        return (string) $subject->getKey();
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function redact(array $changes): array
    {
        foreach (self::REDACTED_KEYS as $key) {
            if (array_key_exists($key, $changes)) {
                $changes[$key] = '(redacted)';
            }
        }

        unset($changes['updated_at']);

        return $changes;
    }
}
