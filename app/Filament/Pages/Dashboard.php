<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\DashboardStatsWidget;
use App\Filament\Widgets\NewBlogTopicsWidget;
use App\Filament\Widgets\ServiceTranslationWidget;
use App\Filament\Widgets\SitemapSyncWidget;
use App\Filament\Widgets\TelegramQueueWidget;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces Filament's stock Dashboard page (App\Providers\Filament\AdminPanelProvider registers
 * this one instead) to let each admin reorder, hide, and re-add their own dashboard cards - a
 * single-column stacked list rather than the previous 2-column layout, since drag-reordering a
 * flat list is far simpler (and far more robust) than reordering within a CSS grid that mixes
 * full-width and half-width cards.
 *
 * Every card still gates itself exactly as before via its own canView() (the same PanelSection
 * checks already in place) - this page only decides order/visibility among whatever a user is
 * already allowed to see, never grants access to a card they couldn't otherwise view.
 */
class Dashboard extends BaseDashboard
{
    protected static string $view = 'filament.pages.dashboard';

    // Short, stable keys - not the class names directly - so a stored layout never breaks if a
    // widget class is ever renamed; only this map needs updating in that case.
    public const WIDGET_REGISTRY = [
        'sitemap_sync' => SitemapSyncWidget::class,
        'new_blog_topics' => NewBlogTopicsWidget::class,
        'dashboard_stats' => DashboardStatsWidget::class,
        'service_translation' => ServiceTranslationWidget::class,
        'telegram_queue' => TelegramQueueWidget::class,
    ];

    public bool $customizing = false;

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return array_map(fn (string $key) => self::WIDGET_REGISTRY[$key], $this->visibleKeys());
    }

    public function getColumns(): int|string|array
    {
        return 1;
    }

    public function toggleCustomizing(): void
    {
        $this->customizing = ! $this->customizing;
    }

    /**
     * Every card this user currently has permission to see, in the registry's default order -
     * the full universe visibleKeys()/hiddenKeys() are drawn from.
     *
     * @return list<string>
     */
    public function availableKeys(): array
    {
        return collect(self::WIDGET_REGISTRY)
            ->filter(fn (string $class) => $class::canView())
            ->keys()
            ->values()
            ->all();
    }

    /**
     * This user's chosen order, filtered down to whatever they're both allowed to see and still
     * exists - never trust a stored layout blindly, since permissions or the registry itself can
     * change after it was saved. Falls back to "everything available, default order" the first
     * time (stored value is null) rather than starting someone off with an empty dashboard.
     *
     * @return list<string>
     */
    public function visibleKeys(): array
    {
        $stored = $this->storedOrder();
        $available = $this->availableKeys();

        if ($stored === null) {
            return $available;
        }

        return collect($stored)
            ->filter(fn ($key) => in_array($key, $available, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function hiddenKeys(): array
    {
        return array_values(array_diff($this->availableKeys(), $this->visibleKeys()));
    }

    public function removeWidget(string $key): void
    {
        $this->persist(array_values(array_diff($this->visibleKeys(), [$key])));
    }

    public function addWidget(string $key): void
    {
        $current = $this->visibleKeys();

        if (! in_array($key, $this->availableKeys(), true) || in_array($key, $current, true)) {
            return;
        }

        $this->persist([...$current, $key]);
    }

    /**
     * @param  list<string>  $orderedKeys
     */
    public function reorderWidgets(array $orderedKeys): void
    {
        $available = $this->availableKeys();

        $this->persist(
            collect($orderedKeys)->filter(fn ($key) => in_array($key, $available, true))->unique()->values()->all()
        );
    }

    /**
     * @return ?list<string>
     */
    private function storedOrder(): ?array
    {
        if (! Schema::hasColumn('users', 'dashboard_widgets')) {
            return null;
        }

        return auth()->user()?->dashboard_widgets;
    }

    /**
     * @param  list<string>  $keys
     */
    private function persist(array $keys): void
    {
        if (! Schema::hasColumn('users', 'dashboard_widgets')) {
            return;
        }

        auth()->user()?->update(['dashboard_widgets' => $keys]);
    }
}
