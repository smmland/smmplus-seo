<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewsController extends Controller
{
    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 50;

    // How often the visible selection changes on its own - a request during the same window
    // always gets the same picks/order (so a page that fetches once and renders is stable, and
    // repeat visitors within a few hours see a consistent set), but the next window reshuffles
    // which approved reviews surface without needing a scheduled command or any stored state.
    private const ROTATION_HOURS = 6;

    // Read-only, non-sensitive marketing content - unlike the free-service gateway this has
    // nothing worth abusing (no upstream calls, no per-caller state to exhaust), so it skips
    // that endpoint's CORS-origin-allowlist/rate-limiting stack entirely and is just open to any
    // origin.
    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit ?: self::DEFAULT_LIMIT));

        // No requested language, or one nobody's written reviews for yet, just falls through to
        // whatever's approved for the site's default language rather than showing an empty
        // section - the caller doesn't need to know in advance which languages already have
        // reviews.
        $lang = trim((string) $request->query('lang', '')) ?: $this->defaultLang();

        $reviews = $this->rotatedSelection($lang, $limit);

        if ($reviews->isEmpty() && $lang !== $this->defaultLang()) {
            $lang = $this->defaultLang();
            $reviews = $this->rotatedSelection($lang, $limit);
        }

        return response()->json([
            'ok' => true,
            'lang' => $reviews->first()->lang ?? $lang,
            'rotates_at' => $this->windowEnd()->toIso8601String(),
            'reviews' => $reviews->map(fn (Review $review) => [
                'author_name' => $review->author_name,
                'rating' => $review->rating,
                'body' => $review->body,
                'avatar_url' => $review->avatarUrl(),
                'related_service' => $review->related_service,
                'country_name' => $review->country_name,
                'country_flag' => $review->countryFlag(),
            ]),
        ])->header('Access-Control-Allow-Origin', '*');
    }

    /**
     * @return \Illuminate\Support\Collection<int, Review>
     */
    private function rotatedSelection(string $lang, int $limit)
    {
        // A deterministic hash-sort keyed by the current rotation window instead of true
        // randomness: same lang + same window always produces the same order (so it's stable
        // for the whole window without caching anything), and it's a different order once the
        // window rolls over - all without a scheduled job.
        $seed = $lang.'|'.$this->windowStart()->timestamp;

        return Review::query()
            ->where('lang', $lang)
            ->where('is_approved', true)
            ->get()
            ->sortBy(fn (Review $review) => md5($review->id.$seed))
            ->take($limit)
            ->values();
    }

    private function windowStart(): \Illuminate\Support\Carbon
    {
        $seconds = self::ROTATION_HOURS * 3600;

        return \Illuminate\Support\Carbon::createFromTimestamp(intdiv(now()->timestamp, $seconds) * $seconds);
    }

    private function windowEnd(): \Illuminate\Support\Carbon
    {
        return $this->windowStart()->addHours(self::ROTATION_HOURS);
    }

    private function defaultLang(): string
    {
        return Language::query()->where('is_default', true)->value('code') ?? 'fa';
    }
}
