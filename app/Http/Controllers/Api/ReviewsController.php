<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewsController extends Controller
{
    private const DEFAULT_LIMIT = 20;

    private const MAX_LIMIT = 50;

    // Read-only, non-sensitive marketing content - unlike the free-service gateway this has
    // nothing worth abusing (no upstream calls, no per-caller state to exhaust), so it skips
    // that endpoint's CORS-origin-allowlist/rate-limiting stack entirely and is just open to any
    // origin.
    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', self::DEFAULT_LIMIT);
        $limit = max(1, min(self::MAX_LIMIT, $limit ?: self::DEFAULT_LIMIT));

        $reviews = Review::query()
            ->where('is_approved', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (Review $review) => [
                'author_name' => $review->author_name,
                'rating' => $review->rating,
                'body' => $review->body,
                'avatar_url' => $review->avatarUrl(),
                'related_service' => $review->related_service,
                'country_name' => $review->country_name,
                'country_flag' => $review->countryFlag(),
            ]);

        return response()->json(['ok' => true, 'reviews' => $reviews])
            ->header('Access-Control-Allow-Origin', '*');
    }
}
