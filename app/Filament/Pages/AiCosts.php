<?php

namespace App\Filament\Pages;

use App\Models\BlogTranslationJob;
use App\Models\Language;
use App\Models\ServiceTranslation;
use App\Models\ServiceTranslationJob;
use App\Models\Url;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Schema;

/**
 * Moved out of General Settings into its own page, reached from the account menu (top-right
 * avatar, next to Settings and Sign out - see AdminPanelProvider::panel()'s userMenuItems())
 * instead of the sidebar, the same way HiddenTranslations is. Combines spend from both AI
 * translation pipelines this panel runs - blog articles (BlogTranslationJob) and service
 * descriptions (ServiceTranslationJob) - into one total, with a separate breakdown table per
 * pipeline since a blog "topic" and a "service" aren't the same kind of thing to list rows for.
 */
class AiCosts extends Page
{
    protected static ?string $title = 'AI Costs';

    protected static string $view = 'filament.pages.ai-costs';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    // How many rows each breakdown table shows per page - a busy site could have hundreds of
    // translated topics/services, so both are paginated rather than capped at a fixed top-N.
    private const PER_PAGE = 15;

    public int $blogCostsPage = 1;

    public int $serviceCostsPage = 1;

    public function previousBlogCostsPage(): void
    {
        $this->blogCostsPage = max(1, $this->blogCostsPage - 1);
    }

    public function nextBlogCostsPage(): void
    {
        $this->blogCostsPage++;
    }

    public function previousServiceCostsPage(): void
    {
        $this->serviceCostsPage = max(1, $this->serviceCostsPage - 1);
    }

    public function nextServiceCostsPage(): void
    {
        $this->serviceCostsPage++;
    }

    /**
     * @return array{
     *     totalCost: float, totalInputTokens: int, totalOutputTokens: int, totalJobs: int, unknownPricingCount: int,
     *     blog: array{available: bool, byTopic: \Illuminate\Support\Collection, page: int, lastPage: int, total: int},
     *     service: array{available: bool, byService: \Illuminate\Support\Collection, page: int, lastPage: int, total: int},
     * }
     */
    public function getAiCostStats(): array
    {
        $blog = $this->blogCostStats();
        $service = $this->serviceCostStats();

        return [
            'totalCost' => $blog['totalCost'] + $service['totalCost'],
            'totalInputTokens' => $blog['totalInputTokens'] + $service['totalInputTokens'],
            'totalOutputTokens' => $blog['totalOutputTokens'] + $service['totalOutputTokens'],
            'totalJobs' => $blog['totalJobs'] + $service['totalJobs'],
            'unknownPricingCount' => $blog['unknownPricingCount'] + $service['unknownPricingCount'],
            'blog' => [
                'available' => $blog['available'],
                'byTopic' => $blog['byTopic'],
                'page' => $blog['page'],
                'lastPage' => $blog['lastPage'],
                'total' => $blog['total'],
            ],
            'service' => [
                'available' => $service['available'],
                'byService' => $service['byService'],
                'page' => $service['page'],
                'lastPage' => $service['lastPage'],
                'total' => $service['total'],
            ],
        ];
    }

    /**
     * Aggregates every completed blog translation's estimated cost (BlogAiTranslationService
     * writes one per attempt, success or fail - a failed call can still have burned real tokens)
     * into an overall total plus a per-topic breakdown. Guarded the same way every
     * blog_translation_jobs feature is: the cost columns can lag behind this code until "Update
     * database" is clicked, since this host has no terminal access to run migrations any other
     * way.
     */
    private function blogCostStats(): array
    {
        $empty = [
            'available' => false, 'totalCost' => 0.0, 'totalInputTokens' => 0, 'totalOutputTokens' => 0,
            'totalJobs' => 0, 'unknownPricingCount' => 0, 'byTopic' => collect(), 'page' => 1, 'lastPage' => 1, 'total' => 0,
        ];

        if (! Schema::hasTable('blog_translation_jobs') || ! Schema::hasColumn('blog_translation_jobs', 'estimated_cost_usd')) {
            return $empty;
        }

        $attempted = BlogTranslationJob::query()->whereNotNull('provider');

        $totals = (clone $attempted)
            ->selectRaw('COALESCE(SUM(estimated_cost_usd), 0) as total_cost, COALESCE(SUM(input_tokens), 0) as total_input, COALESCE(SUM(output_tokens), 0) as total_output, COUNT(*) as total_jobs')
            ->first();

        // A custom/unlisted model has no known rate (AiSettingsService::estimateCost() returns
        // null rather than guessing) - called out separately so the total doesn't silently look
        // complete when part of it couldn't actually be priced.
        $unknownPricingCount = (clone $attempted)->whereNull('estimated_cost_usd')->count();

        $totalTopics = (clone $attempted)->distinct('group_key')->count('group_key');
        $lastPage = max(1, (int) ceil($totalTopics / self::PER_PAGE));
        $page = max(1, min($this->blogCostsPage, $lastPage));

        $byGroup = (clone $attempted)
            ->selectRaw('group_key, COALESCE(SUM(estimated_cost_usd), 0) as cost, COALESCE(SUM(input_tokens), 0) as input_tokens, COALESCE(SUM(output_tokens), 0) as output_tokens, COUNT(*) as translations')
            ->groupBy('group_key')
            ->orderByDesc('cost')
            ->forPage($page, self::PER_PAGE)
            ->get();

        $defaultLangCode = Language::query()->where('is_default', true)->value('code') ?? 'en';

        $topics = Url::query()
            ->where('lang', $defaultLangCode)
            ->whereIn('group_key', $byGroup->pluck('group_key'))
            ->get(['group_key', 'article_title', 'source_url'])
            ->keyBy('group_key');

        $byTopic = $byGroup->map(function ($row) use ($topics) {
            $topic = $topics->get($row->group_key);

            return [
                'title' => $topic?->article_title ?: ($topic?->source_url ?: $row->group_key),
                'sourceUrl' => $topic?->source_url,
                'cost' => (float) $row->cost,
                'translations' => (int) $row->translations,
            ];
        });

        return [
            'available' => true,
            'totalCost' => (float) $totals->total_cost,
            'totalInputTokens' => (int) $totals->total_input,
            'totalOutputTokens' => (int) $totals->total_output,
            'totalJobs' => (int) $totals->total_jobs,
            'unknownPricingCount' => $unknownPricingCount,
            'byTopic' => $byTopic,
            'page' => $page,
            'lastPage' => $lastPage,
            'total' => $totalTopics,
        ];
    }

    /**
     * Same shape as blogCostStats(), grouped by service_key instead of group_key - the service
     * catalog's title/category come from the default-language service_translations row, the
     * services equivalent of the default-language Url row blogCostStats() reads titles from.
     */
    private function serviceCostStats(): array
    {
        $empty = [
            'available' => false, 'totalCost' => 0.0, 'totalInputTokens' => 0, 'totalOutputTokens' => 0,
            'totalJobs' => 0, 'unknownPricingCount' => 0, 'byService' => collect(), 'page' => 1, 'lastPage' => 1, 'total' => 0,
        ];

        if (! Schema::hasTable('service_translation_jobs') || ! Schema::hasColumn('service_translation_jobs', 'estimated_cost_usd')) {
            return $empty;
        }

        $attempted = ServiceTranslationJob::query()->whereNotNull('provider');

        $totals = (clone $attempted)
            ->selectRaw('COALESCE(SUM(estimated_cost_usd), 0) as total_cost, COALESCE(SUM(input_tokens), 0) as total_input, COALESCE(SUM(output_tokens), 0) as total_output, COUNT(*) as total_jobs')
            ->first();

        $unknownPricingCount = (clone $attempted)->whereNull('estimated_cost_usd')->count();

        $totalServices = (clone $attempted)->distinct('service_key')->count('service_key');
        $lastPage = max(1, (int) ceil($totalServices / self::PER_PAGE));
        $page = max(1, min($this->serviceCostsPage, $lastPage));

        $byServiceKey = (clone $attempted)
            ->selectRaw('service_key, COALESCE(SUM(estimated_cost_usd), 0) as cost, COALESCE(SUM(input_tokens), 0) as input_tokens, COALESCE(SUM(output_tokens), 0) as output_tokens, COUNT(*) as translations')
            ->groupBy('service_key')
            ->orderByDesc('cost')
            ->forPage($page, self::PER_PAGE)
            ->get();

        $defaultLangCode = Language::query()->where('is_default', true)->value('code') ?? 'en';

        $services = ServiceTranslation::query()
            ->where('lang', $defaultLangCode)
            ->whereIn('service_key', $byServiceKey->pluck('service_key'))
            ->get(['service_key', 'title', 'category_title'])
            ->keyBy('service_key');

        $byService = $byServiceKey->map(function ($row) use ($services) {
            $service = $services->get($row->service_key);

            return [
                'title' => $service?->title ?: "Service #{$row->service_key}",
                'categoryTitle' => $service?->category_title,
                'cost' => (float) $row->cost,
                'translations' => (int) $row->translations,
            ];
        });

        return [
            'available' => true,
            'totalCost' => (float) $totals->total_cost,
            'totalInputTokens' => (int) $totals->total_input,
            'totalOutputTokens' => (int) $totals->total_output,
            'totalJobs' => (int) $totals->total_jobs,
            'unknownPricingCount' => $unknownPricingCount,
            'byService' => $byService,
            'page' => $page,
            'lastPage' => $lastPage,
            'total' => $totalServices,
        ];
    }
}
