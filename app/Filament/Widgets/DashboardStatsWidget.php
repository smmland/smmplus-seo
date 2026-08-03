<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\BlogTranslationQueue;
use App\Filament\Pages\GatewayStats;
use App\Filament\Pages\GeneralSettings;
use App\Filament\Pages\HiddenTranslations;
use App\Models\BlogTranslationJob;
use App\Models\GatewayRequestLog;
use App\Models\Language;
use App\Models\Url;
use App\Services\HiddenTranslationService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Schema;

class DashboardStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 2;

    protected function getStats(): array
    {
        return [
            $this->newBlogTopicsStat(),
            $this->gatewayRequestsStat(),
            $this->recoveryStat(),
            $this->aiSpendStat(),
        ];
    }

    // New blog topics discovered by the sitemap sync in the last 24h - the trigger for this is
    // always SyncSitemapCommand adding a fresh default-language BLOG row, so "new since
    // first_seen_at" is exactly "new since it first appeared in the source sitemap".
    private function newBlogTopicsStat(): Stat
    {
        $defaultLang = Language::query()->where('is_default', true)->value('code') ?? 'en';

        $count = Url::query()
            ->where('pattern_type', 'BLOG')
            ->where('lang', $defaultLang)
            ->where('first_seen_at', '>=', now()->subDay())
            ->count();

        return Stat::make('New blog topics (24h)', (string) $count)
            ->description($count > 0 ? 'Discovered by the sitemap sync - ready to translate' : 'Nothing new since yesterday')
            ->descriptionIcon($count > 0 ? 'heroicon-m-document-plus' : 'heroicon-m-check-circle')
            ->color($count > 0 ? 'primary' : 'gray')
            ->url(BlogTranslationQueue::getUrl());
    }

    // Success vs everything-else (blocked IPs, rate limits, invalid requests, upstream errors -
    // GatewayStats has the full breakdown) over the same 24h window as the other cards here.
    private function gatewayRequestsStat(): Stat
    {
        $since = now()->subDay();
        $successful = GatewayRequestLog::query()
            ->where('created_at', '>=', $since)
            ->where('status', GatewayRequestLog::STATUS_SUCCESS)
            ->count();
        $total = GatewayRequestLog::query()->where('created_at', '>=', $since)->count();
        $failed = $total - $successful;

        return Stat::make('Free service requests (24h)', "{$successful} successful")
            ->description("{$failed} failed / rejected")
            ->descriptionIcon($failed > 0 ? 'heroicon-m-x-circle' : 'heroicon-m-check-circle')
            ->color($failed > 0 ? 'warning' : 'success')
            ->url(GatewayStats::getUrl());
    }

    // Surfaces exactly the recovery tools built into the Hidden Translations page - a translation
    // sitting hidden or orphaned is otherwise invisible unless someone thinks to go check that
    // page, so this puts the count where it can't be missed.
    private function recoveryStat(): Stat
    {
        $hidden = app(HiddenTranslationService::class);
        $count = $hidden->count() + $hidden->orphanedCount();

        return Stat::make('Hidden/orphaned translations', (string) $count)
            ->description($count > 0 ? 'Translated content not showing in the normal list' : 'Nothing needs recovery')
            ->descriptionIcon($count > 0 ? 'heroicon-m-eye-slash' : 'heroicon-m-check-circle')
            ->color($count > 0 ? 'danger' : 'success')
            ->url(HiddenTranslations::getUrl());
    }

    // Guarded the same way GeneralSettings::getAiCostStats() is - the cost columns can lag behind
    // this code until "Update database" is clicked.
    private function aiSpendStat(): Stat
    {
        if (! Schema::hasTable('blog_translation_jobs') || ! Schema::hasColumn('blog_translation_jobs', 'estimated_cost_usd')) {
            return Stat::make('AI translation spend', 'n/a')
                ->description('Needs a database update')
                ->color('gray');
        }

        $totalCost = BlogTranslationJob::query()->whereNotNull('provider')->sum('estimated_cost_usd');
        $totalJobs = BlogTranslationJob::query()->whereNotNull('provider')->count();

        return Stat::make('AI translation spend', '$'.number_format((float) $totalCost, 2))
            ->description("{$totalJobs} translation attempt(s) total")
            ->descriptionIcon('heroicon-m-sparkles')
            ->color('primary')
            ->url(GeneralSettings::getUrl());
    }
}
