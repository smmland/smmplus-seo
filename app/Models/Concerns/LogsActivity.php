<?php

namespace App\Models\Concerns;

use App\Services\ActivityLogService;

/**
 * Auto-logs create/update/delete for models edited through a standard Filament Resource
 * (Create/Edit/Delete pages, which call real Eloquent methods on a model instance) - only
 * covers that path. This deliberately does NOT cover this app's many `Model::query()->where(...)
 * ->update(...)` mass-update actions elsewhere (BlogTranslationQueue, TelegramQueue,
 * GiveawayClaims, ...), since Eloquent model events never fire for query-builder mass
 * operations; those log explicitly at their own call site instead - see each page's use of
 * ActivityLogService directly.
 *
 * Only logs while an admin is actually authenticated in this request (auth()->check()) - a
 * scheduled command or queued job touching the same model runs with no authenticated user, so
 * this naturally excludes routine system activity (a sync, a queued translation) from an audit
 * trail meant to answer "which admin changed what", not "what did the app do on its own".
 */
trait LogsActivity
{
    public static function bootLogsActivity(): void
    {
        static::created(function ($model) {
            if (! auth()->check()) {
                return;
            }

            app(ActivityLogService::class)->record('created', $model, section: $model->activitySection());
        });

        static::updated(function ($model) {
            if (! auth()->check()) {
                return;
            }

            $changes = $model->getChanges();
            // remember_token churns on every login (Laravel's own "remember me" cookie
            // mechanism) - not an admin edit, so it shouldn't itself trigger a log entry (only
            // User has this column; harmless to strip on every model).
            unset($changes['updated_at'], $changes['remember_token']);

            if (empty($changes)) {
                return;
            }

            app(ActivityLogService::class)->record('updated', $model, $changes, $model->activitySection());
        });

        static::deleted(function ($model) {
            if (! auth()->check()) {
                return;
            }

            app(ActivityLogService::class)->record('deleted', $model, section: $model->activitySection());
        });
    }

    /**
     * A human-readable identifier for this record in the activity log - override per model
     * (e.g. a URL's source_url, a user's email) since a bare numeric id means nothing on its own
     * once the record itself may no longer exist to look up.
     */
    public function activityLabel(): string
    {
        return (string) $this->getKey();
    }

    /**
     * Which PanelSection this model's changes belong to, for filtering the activity log -
     * override per model. Null is fine (e.g. for models with no section, like User itself).
     */
    public function activitySection(): ?string
    {
        return null;
    }
}
