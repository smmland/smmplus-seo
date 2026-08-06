<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\AiCosts;
use App\Filament\Pages\GatewayStats;
use App\Filament\Pages\HiddenTranslations;
use App\Models\BlogTranslationJob;
use App\Models\GatewayRequestLog;
use App\Models\ServiceTranslationJob;
use App\Models\TelegramPost;
use App\Services\HiddenTranslationService;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Schema;

class DashboardStatsWidget extends StatsOverviewWidget
{
    // Placed after NewBlogTopicsWidget (sort 2) and SitemapSyncWidget (sort 1).
    protected static ?int $sort = 3;

    // Each card links to (and is gated the same as) a specific page - GatewayStats, Hidden
    // Translations, AiCosts - so a user without access to that page never sees a dashboard card
    // teasing numbers from it either. Hides the whole widget once none of the three apply.
    public static function canView(): bool
    {
        return GatewayStats::canAccess() || HiddenTranslations::canAccess() || AiCosts::canAccess();
    }

    protected function getStats(): array
    {
        $stats = [];

        if (GatewayStats::canAccess()) {
            $stats[] = $this->gatewayRequestsStat();
        }

        if (HiddenTranslations::canAccess()) {
            $stats[] = $this->recoveryStat();
        }

        if (AiCosts::canAccess()) {
            $stats[] = $this->aiSpendStat();
        }

        return $stats;
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

    // Combines every AI pipeline this panel runs (blog articles, service descriptions, and
    // Telegram post text + image generation - see AiCosts, which this links to and which already
    // covers all three) into one figure, each guarded the same way its own page is: the cost
    // columns can lag behind this code until "Update database" is clicked.
    private function aiSpendStat(): Stat
    {
        $blogAvailable = Schema::hasTable('blog_translation_jobs') && Schema::hasColumn('blog_translation_jobs', 'estimated_cost_usd');
        $serviceAvailable = Schema::hasTable('service_translation_jobs') && Schema::hasColumn('service_translation_jobs', 'estimated_cost_usd');
        $telegramAvailable = Schema::hasTable('telegram_posts');

        if (! $blogAvailable && ! $serviceAvailable && ! $telegramAvailable) {
            return Stat::make('AI translation spend', 'n/a')
                ->description('Needs a database update')
                ->color('gray');
        }

        $totalCost = 0.0;
        $totalJobs = 0;

        if ($blogAvailable) {
            $totalCost += (float) BlogTranslationJob::query()->whereNotNull('provider')->sum('estimated_cost_usd');
            $totalJobs += BlogTranslationJob::query()->whereNotNull('provider')->count();
        }

        if ($serviceAvailable) {
            $totalCost += (float) ServiceTranslationJob::query()->whereNotNull('provider')->sum('estimated_cost_usd');
            $totalJobs += ServiceTranslationJob::query()->whereNotNull('provider')->count();
        }

        if ($telegramAvailable) {
            $totalCost += (float) TelegramPost::query()->sum('estimated_cost_usd') + (float) TelegramPost::query()->sum('image_cost_usd');
            $totalJobs += TelegramPost::query()->whereNotNull('ai_provider')->count();
        }

        return Stat::make('AI translation spend', '$'.number_format($totalCost, 2))
            ->description("{$totalJobs} translation attempt(s) total")
            ->descriptionIcon('heroicon-m-sparkles')
            ->color('primary')
            ->url(AiCosts::getUrl());
    }
}
