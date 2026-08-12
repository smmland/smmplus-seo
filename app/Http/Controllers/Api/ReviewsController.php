<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Language;
use App\Models\Review;
use App\Services\GatewayRateLimiter;
use App\Services\ReviewsSettingsService;
use App\Services\ReviewSubmissionService;
use App\Support\GatewayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReviewsController extends Controller
{
    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 50;

    // How often the visible selection changes on its own - a request during the same window
    // always gets the same picks/order (so a page that fetches once and renders is stable, and
    // repeat visitors within a few hours see a consistent set), but the next window reshuffles
    // which approved reviews surface without needing a scheduled command or any stored state.
    private const ROTATION_HOURS = 6;

    // Separate from HandleGatewayCors's shared 3-req/minute flood limit (which still applies on
    // top of this, since /reviews POST goes through gateway.cors too) - this one specifically
    // caps genuine submissions, not just abuse bursts.
    private const SUBMIT_LIMIT_SECONDS = 7200;

    public function __construct(
        private readonly ReviewsSettingsService $settings,
        private readonly GatewayRateLimiter $limiter,
    ) {}

    // Read-only, non-sensitive marketing content - unlike the free-service gateway this has
    // nothing worth abusing (no upstream calls, no per-caller state to exhaust), so it skips
    // that endpoint's CORS-origin-allowlist/rate-limiting stack entirely and is just open to any
    // origin.
    public function index(Request $request): JsonResponse
    {
        if (! $this->settings->isEnabled()) {
            return response()->json(['ok' => true, 'enabled' => false, 'reviews' => []])
                ->header('Access-Control-Allow-Origin', '*');
        }

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
            'enabled' => true,
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

    // Public submission endpoint - lang/country are never taken from the caller, only
    // auto-detected server-side from their IP (ReviewSubmissionService), so there's nothing here
    // for a client to spoof. Lands as unapproved (Review::is_approved defaults to false) so it
    // only reaches index() above once an admin approves it in the panel. Routed behind
    // 'gateway.cors' (CORS origin allowlist, IP/Tor blocking, shared flood limit) same as every
    // other public mutating endpoint - the one-per-IP-per-2-hours check below is on top of that.
    public function store(Request $request, ReviewSubmissionService $submissions): JsonResponse
    {
        if (! $this->settings->isEnabled()) {
            return response()->json(['ok' => false, 'error' => 'Reviews are currently disabled.'], 403)
                ->header('Access-Control-Allow-Origin', '*');
        }

        $validator = Validator::make($request->all(), [
            'author_name' => ['required', 'string', 'max:255'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['required', 'string', 'max:2000'],
            'related_service' => ['nullable', 'string', 'max:255'],
            // The site account submitting this - required for moderation/accountability (see
            // Review::submitted_username), not shown publicly.
            'username' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => false, 'error' => $validator->errors()->first()], 422)
                ->header('Access-Control-Allow-Origin', '*');
        }

        $ip = GatewayClient::resolveIp($request);
        $key = 'reviews:submit:ip:'.md5($ip);

        if ($this->limiter->get($key) >= 1) {
            return response()->json([
                'ok' => false,
                'error' => 'Only one review is allowed per 2 hours.',
                'retry_after_seconds' => $this->limiter->secondsRemaining($key),
            ], 429)->header('Access-Control-Allow-Origin', '*');
        }

        $review = $submissions->submit($validator->validated(), $ip);

        $this->limiter->incrementWithTtl($key, 1, self::SUBMIT_LIMIT_SECONDS);

        return response()->json([
            'ok' => true,
            'message' => 'Thanks! Your review will be shown once approved.',
            'lang' => $review->lang,
            'country_name' => $review->country_name,
        ])->header('Access-Control-Allow-Origin', '*');
    }

    // Whether the "leave a review" prompt should show on one specific page of the live site -
    // the server has no way to know what page a caller is on by itself, so the frontend passes
    // it explicitly via ?page=. Combines the global on/off switch with that page's own toggle
    // (Reviews > Settings) into the single answer a frontend actually needs to act on.
    public function status(Request $request): JsonResponse
    {
        $page = (string) $request->query('page', '');

        if (! array_key_exists($page, ReviewsSettingsService::PROMPT_PAGES)) {
            return response()->json([
                'ok' => false,
                'error' => 'Unknown or missing "page" parameter. Valid values: '.implode(', ', array_keys(ReviewsSettingsService::PROMPT_PAGES)).'.',
            ], 400)->header('Access-Control-Allow-Origin', '*');
        }

        return response()->json([
            'ok' => true,
            'page' => $page,
            'show_prompt' => $this->settings->isEnabled() && $this->settings->isPromptEnabledFor($page),
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
