<?php

namespace App\Filament\Widgets;

use App\Models\SyncRun;
use App\Services\SettingsService;
use Filament\Widgets\Widget;

/**
 * How far along we are toward the next sitemap sync, plus what the last one found - mirrors the
 * same "elapsed / interval" progress bar BlogTranslationQueue's cronProgress() already shows for
 * the translation-detection cron, just for SyncSitemapCommand's own admin-configurable interval
 * (SettingsService::getSyncIntervalHours()) instead.
 */
class SitemapSyncWidget extends Widget
{
    protected static string $view = 'filament.widgets.sitemap-sync-widget';

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $lastRun = SyncRun::query()
            ->where('status', SyncRun::SUCCESS)
            ->orderByDesc('started_at')
            ->first();

        if (! $lastRun) {
            return [
                'hasRun' => false,
                'percent' => 0,
                'remainingMinutes' => null,
                'lastRunAt' => null,
                'linksAnalyzed' => 0,
            ];
        }

        $intervalMinutes = max(1, app(SettingsService::class)->getSyncIntervalHours() * 60);

        // abs() because Carbon's diffInMinutes() sign convention isn't reliable across versions
        // for "which side is later" - only the magnitude matters here (mirrors
        // BlogTranslationQueue::cronProgress()'s own comment on this exact same pattern).
        $elapsedMinutes = min($intervalMinutes, max(0, abs(now()->diffInMinutes($lastRun->started_at))));

        return [
            'hasRun' => true,
            'percent' => (int) round(($elapsedMinutes / $intervalMinutes) * 100),
            'remainingMinutes' => (int) ceil(max(0, $intervalMinutes - $elapsedMinutes)),
            'lastRunAt' => $lastRun->started_at,
            'linksAnalyzed' => $lastRun->total_fetched,
        ];
    }
}
