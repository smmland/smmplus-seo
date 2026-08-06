<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\GiveawayClaim;
use App\Services\GiveawaySettingsService;
use App\Services\TelegramBotService;
use App\Services\TelegramLoginVerifier;
use App\Services\YoutubeDataApiService;
use App\Services\YoutubeOAuthService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/**
 * Backend for the Giveaway page (giveaway.twig, in the smmplus-website repo). Verification only -
 * reward delivery is manual, the admin credits the real smm.plus wallet by hand and marks a claim
 * "rewarded" from the GiveawayClaims Filament page. See the plan doc for the full write-up of why
 * (no API access to that panel's wallet system).
 *
 * Claims are keyed on the panel account's email, not a numeric id - nothing in the smmplus-website
 * Twig templates anywhere references a raw user id (only user['email']/user['username'] ever show
 * up), and email is also what an admin actually looks an account up by when crediting a wallet.
 */
class GiveawayController extends Controller
{
    /**
     * Site-specific config the giveaway page's JS needs but can't get from its own Twig context
     * (a third-party system this app doesn't control) - which tasks are currently live, and the
     * Telegram bot's public @username to render the Login Widget with.
     */
    public function config(GiveawaySettingsService $settings): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'telegram' => [
                'enabled' => $settings->isTelegramEnabled(),
                'botUsername' => $settings->getTelegramBotUsername(),
            ],
            'youtube' => [
                'subscribeEnabled' => $settings->isYoutubeEnabled(),
                'featuredEnabled' => $settings->isYoutubeFeaturedEnabled(),
                'videoEnabled' => $settings->isYoutubeVideoEnabled(),
                'videoRequiredKeyword' => $settings->getYoutubeVideoRequiredKeyword(),
            ],
            'trustpilot' => [
                'enabled' => $settings->isTrustpilotEnabled(),
                'reviewUrl' => $settings->getTrustpilotReviewUrl(),
            ],
        ]);
    }

    public function verifyTelegram(
        Request $request,
        TelegramLoginVerifier $verifier,
        TelegramBotService $bot,
        GiveawaySettingsService $settings,
    ): JsonResponse {
        if (! $settings->isTelegramEnabled()) {
            return response()->json(['ok' => false, 'status' => 'disabled', 'message' => 'Telegram giveaway is currently disabled.'], 200);
        }

        $panelUserEmail = $request->input('panel_user_email');
        $panelUsername = $request->input('panel_username');

        if (! is_string($panelUserEmail) || ! filter_var($panelUserEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'status' => 'invalid', 'message' => 'Missing or invalid panel user.'], 400);
        }

        $login = $verifier->verify($request->except(['panel_user_email', 'panel_username']));

        if (! $login['ok']) {
            return response()->json(['ok' => false, 'status' => 'invalid', 'message' => $login['message']], 200);
        }

        $telegramUserId = $login['telegramUserId'];

        $existing = GiveawayClaim::query()
            ->where('platform', GiveawayClaim::PLATFORM_TELEGRAM)
            ->where(function ($q) use ($panelUserEmail, $telegramUserId) {
                $q->where('panel_user_email', $panelUserEmail)
                    ->orWhere('platform_account_id', $telegramUserId);
            })
            ->first();

        if ($existing) {
            return response()->json([
                'ok' => true,
                'status' => 'already_claimed',
                'message' => 'This has already been claimed.',
                'claimStatus' => $existing->status,
            ]);
        }

        $membership = $bot->getChatMember($telegramUserId);

        if (! $membership['ok']) {
            return response()->json(['ok' => false, 'status' => 'error', 'message' => $membership['message']], 200);
        }

        if (! $membership['isMember']) {
            return response()->json(['ok' => true, 'status' => 'not_a_member', 'message' => 'Join the channel first, then try again.']);
        }

        try {
            GiveawayClaim::create([
                'platform' => GiveawayClaim::PLATFORM_TELEGRAM,
                'panel_user_email' => $panelUserEmail,
                'panel_username' => $panelUsername,
                'platform_account_id' => $telegramUserId,
                'verified_at' => now(),
                'status' => GiveawayClaim::STATUS_VERIFIED,
            ]);
        } catch (QueryException $e) {
            // Unique constraint race (double-submit) - treat the same as "already claimed".
            return response()->json(['ok' => true, 'status' => 'already_claimed', 'message' => 'This has already been claimed.']);
        }

        return response()->json(['ok' => true, 'status' => 'verified', 'message' => 'Verified - your reward request is now with our team.']);
    }

    /**
     * Trustpilot has no public API to confirm a review is real (unlike Telegram/YouTube, which
     * self-verify), so this just records what the user submitted as proof and leaves it
     * STATUS_PENDING_REVIEW for an admin to actually go check on Trustpilot before rewarding it -
     * see GiveawayClaims (the admin queue) for that side of it.
     */
    public function submitTrustpilot(Request $request, GiveawaySettingsService $settings): JsonResponse
    {
        if (! $settings->isTrustpilotEnabled()) {
            return response()->json(['ok' => false, 'status' => 'disabled', 'message' => 'Trustpilot giveaway is currently disabled.'], 200);
        }

        $panelUserEmail = $request->input('panel_user_email');
        $panelUsername = $request->input('panel_username');
        $proofUrl = trim((string) $request->input('proof_url'));

        if (! is_string($panelUserEmail) || ! filter_var($panelUserEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'status' => 'invalid', 'message' => 'Missing or invalid panel user.'], 400);
        }

        if ($proofUrl === '' || mb_strlen($proofUrl) > 2000) {
            return response()->json(['ok' => false, 'status' => 'invalid', 'message' => 'Please paste a link to your review.'], 200);
        }

        // A hash of the submitted link, not the account itself - Trustpilot review URLs aren't
        // guaranteed to carry a stable account identifier, but this still stops the same exact
        // submission being reused across multiple panel accounts.
        $proofKey = md5($proofUrl);

        $existing = GiveawayClaim::query()
            ->where('platform', GiveawayClaim::PLATFORM_TRUSTPILOT)
            ->where(function ($q) use ($panelUserEmail, $proofKey) {
                $q->where('panel_user_email', $panelUserEmail)
                    ->orWhere('platform_account_id', $proofKey);
            })
            ->first();

        if ($existing) {
            return response()->json([
                'ok' => true,
                'status' => 'already_claimed',
                'message' => 'This has already been submitted.',
                'claimStatus' => $existing->status,
            ]);
        }

        try {
            GiveawayClaim::create([
                'platform' => GiveawayClaim::PLATFORM_TRUSTPILOT,
                'panel_user_email' => $panelUserEmail,
                'panel_username' => $panelUsername,
                'platform_account_id' => $proofKey,
                'proof_url' => $proofUrl,
                'verified_at' => now(),
                'status' => GiveawayClaim::STATUS_PENDING_REVIEW,
            ]);
        } catch (QueryException) {
            return response()->json(['ok' => true, 'status' => 'already_claimed', 'message' => 'This has already been submitted.']);
        }

        return response()->json(['ok' => true, 'status' => 'pending_review', 'message' => "Thanks! Our team will check it and add your reward once confirmed."]);
    }

    public function youtubeOauthStart(Request $request, YoutubeOAuthService $youtube, GiveawaySettingsService $settings): RedirectResponse
    {
        $panelUserEmail = $request->query('panel_user_email');
        $panelUsername = $request->query('panel_username');

        if (! is_string($panelUserEmail) || ! filter_var($panelUserEmail, FILTER_VALIDATE_EMAIL)) {
            abort(400, 'Missing or invalid panel user.');
        }

        $state = Crypt::encryptString(json_encode([
            'panel_user_email' => $panelUserEmail,
            'panel_username' => $panelUsername,
            'nonce' => Str::random(16),
            'ts' => now()->timestamp,
        ]));

        $consentUrl = $youtube->buildConsentUrl($this->youtubeRedirectUri($settings), $state);

        if (! $consentUrl) {
            abort(500, 'YouTube giveaway is not configured yet.');
        }

        return redirect()->away($consentUrl);
    }

    public function youtubeOauthCallback(
        Request $request,
        YoutubeOAuthService $youtube,
        GiveawaySettingsService $settings,
    ): RedirectResponse {
        $returnBase = $settings->getFrontendReturnUrl();

        if ($request->query('error')) {
            return redirect()->away($returnBase.'?youtube=error');
        }

        $code = $request->query('code');
        $state = $request->query('state');

        if (! $code || ! $state) {
            return redirect()->away($returnBase.'?youtube=error');
        }

        try {
            $decoded = json_decode(Crypt::decryptString($state), true);
        } catch (\Throwable) {
            return redirect()->away($returnBase.'?youtube=error');
        }

        $panelUserEmail = $decoded['panel_user_email'] ?? null;
        $panelUsername = $decoded['panel_username'] ?? null;

        // A stale state (link opened long after it was generated) is rejected the same as an
        // expired Telegram login payload - see TelegramLoginVerifier::MAX_AUTH_AGE_SECONDS.
        if (! $panelUserEmail || now()->timestamp - (int) ($decoded['ts'] ?? 0) > 600) {
            return redirect()->away($returnBase.'?youtube=error');
        }

        $result = $youtube->verifySubscription($code, $this->youtubeRedirectUri($settings));

        if (! $result['ok']) {
            return redirect()->away($returnBase.'?youtube=error');
        }

        if (! $result['isSubscribed']) {
            return redirect()->away($returnBase.'?youtube=not_subscribed');
        }

        $googleAccountId = $result['googleAccountId'] ?? ('unknown_'.md5($panelUserEmail));

        $existing = GiveawayClaim::query()
            ->where('platform', GiveawayClaim::PLATFORM_YOUTUBE_SUBSCRIBE)
            ->where(function ($q) use ($panelUserEmail, $googleAccountId) {
                $q->where('panel_user_email', $panelUserEmail)
                    ->orWhere('platform_account_id', $googleAccountId);
            })
            ->exists();

        if ($existing) {
            return redirect()->away($returnBase.'?youtube=already_claimed');
        }

        try {
            GiveawayClaim::create([
                'platform' => GiveawayClaim::PLATFORM_YOUTUBE_SUBSCRIBE,
                'panel_user_email' => $panelUserEmail,
                'panel_username' => $panelUsername,
                'platform_account_id' => $googleAccountId,
                'verified_at' => now(),
                'status' => GiveawayClaim::STATUS_VERIFIED,
            ]);
        } catch (QueryException) {
            return redirect()->away($returnBase.'?youtube=already_claimed');
        }

        return redirect()->away($returnBase.'?youtube=verified');
    }

    /**
     * Unlike subscribing, this is checked with a public API key, not OAuth - a channel's
     * featured-channels list is visible to anyone. See YoutubeDataApiService::checkFeaturedChannel().
     */
    public function submitYoutubeFeatured(Request $request, YoutubeDataApiService $youtubeData, GiveawaySettingsService $settings): JsonResponse
    {
        if (! $settings->isYoutubeFeaturedEnabled()) {
            return response()->json(['ok' => false, 'status' => 'disabled', 'message' => 'This task is currently disabled.'], 200);
        }

        $panelUserEmail = $request->input('panel_user_email');
        $panelUsername = $request->input('panel_username');
        $channelInput = trim((string) $request->input('channel'));

        if (! is_string($panelUserEmail) || ! filter_var($panelUserEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'status' => 'invalid', 'message' => 'Missing or invalid panel user.'], 400);
        }

        if ($channelInput === '') {
            return response()->json(['ok' => false, 'status' => 'invalid', 'message' => 'Please enter your channel handle or URL.'], 200);
        }

        $check = $youtubeData->checkFeaturedChannel($channelInput);

        if (! $check['ok']) {
            return response()->json(['ok' => false, 'status' => 'error', 'message' => $check['message']], 200);
        }

        $existing = GiveawayClaim::query()
            ->where('platform', GiveawayClaim::PLATFORM_YOUTUBE_FEATURED)
            ->where(function ($q) use ($panelUserEmail, $check) {
                $q->where('panel_user_email', $panelUserEmail)
                    ->orWhere('platform_account_id', $check['channelId']);
            })
            ->first();

        if ($existing) {
            return response()->json(['ok' => true, 'status' => 'already_claimed', 'message' => 'This has already been claimed.', 'claimStatus' => $existing->status]);
        }

        if (! $check['isFeatured']) {
            return response()->json(['ok' => true, 'status' => 'not_found', 'message' => "We couldn't find our channel in your featured channels yet. Add it, then try again."]);
        }

        try {
            GiveawayClaim::create([
                'platform' => GiveawayClaim::PLATFORM_YOUTUBE_FEATURED,
                'panel_user_email' => $panelUserEmail,
                'panel_username' => $panelUsername,
                'platform_account_id' => $check['channelId'],
                'proof_url' => 'https://www.youtube.com/channel/'.$check['channelId'],
                'verified_at' => now(),
                'status' => GiveawayClaim::STATUS_VERIFIED,
            ]);
        } catch (QueryException) {
            return response()->json(['ok' => true, 'status' => 'already_claimed', 'message' => 'This has already been claimed.']);
        }

        return response()->json(['ok' => true, 'status' => 'verified', 'message' => 'Verified - your reward request is now with our team.']);
    }

    /**
     * Also checked with a public API key - a video's own title/description/visibility are
     * public. See YoutubeDataApiService::checkVideoProof().
     */
    public function submitYoutubeVideo(Request $request, YoutubeDataApiService $youtubeData, GiveawaySettingsService $settings): JsonResponse
    {
        if (! $settings->isYoutubeVideoEnabled()) {
            return response()->json(['ok' => false, 'status' => 'disabled', 'message' => 'This task is currently disabled.'], 200);
        }

        $panelUserEmail = $request->input('panel_user_email');
        $panelUsername = $request->input('panel_username');
        $videoUrl = trim((string) $request->input('video_url'));

        if (! is_string($panelUserEmail) || ! filter_var($panelUserEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'status' => 'invalid', 'message' => 'Missing or invalid panel user.'], 400);
        }

        if ($videoUrl === '') {
            return response()->json(['ok' => false, 'status' => 'invalid', 'message' => 'Please paste a link to your video.'], 200);
        }

        $check = $youtubeData->checkVideoProof($videoUrl, $settings->getYoutubeVideoRequiredKeyword() ?? '');

        if (! $check['ok']) {
            return response()->json(['ok' => false, 'status' => 'error', 'message' => $check['message']], 200);
        }

        $videoId = $check['videoId'] ?? md5($videoUrl);

        $existing = GiveawayClaim::query()
            ->where('platform', GiveawayClaim::PLATFORM_YOUTUBE_VIDEO)
            ->where(function ($q) use ($panelUserEmail, $videoId) {
                $q->where('panel_user_email', $panelUserEmail)
                    ->orWhere('platform_account_id', $videoId);
            })
            ->first();

        if ($existing) {
            return response()->json(['ok' => true, 'status' => 'already_claimed', 'message' => 'This has already been claimed.', 'claimStatus' => $existing->status]);
        }

        if (! $check['isValid']) {
            return response()->json(['ok' => true, 'status' => 'not_found', 'message' => "We couldn't verify that video yet - make sure it's public and mentions us, then try again."]);
        }

        try {
            GiveawayClaim::create([
                'platform' => GiveawayClaim::PLATFORM_YOUTUBE_VIDEO,
                'panel_user_email' => $panelUserEmail,
                'panel_username' => $panelUsername,
                'platform_account_id' => $videoId,
                'proof_url' => $videoUrl,
                'verified_at' => now(),
                'status' => GiveawayClaim::STATUS_VERIFIED,
            ]);
        } catch (QueryException) {
            return response()->json(['ok' => true, 'status' => 'already_claimed', 'message' => 'This has already been claimed.']);
        }

        return response()->json(['ok' => true, 'status' => 'verified', 'message' => 'Verified - your reward request is now with our team.']);
    }

    public function status(Request $request): JsonResponse
    {
        $panelUserEmail = $request->query('panel_user_email');

        if (! is_string($panelUserEmail) || ! filter_var($panelUserEmail, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'message' => 'Missing or invalid panel user.'], 400);
        }

        $claims = GiveawayClaim::query()->where('panel_user_email', $panelUserEmail)->get(['platform', 'status']);

        return response()->json([
            'ok' => true,
            'claims' => $claims->mapWithKeys(fn ($claim) => [$claim->platform => $claim->status]),
        ]);
    }

    private function youtubeRedirectUri(GiveawaySettingsService $settings): string
    {
        return $settings->getApiBaseUrl().'/api/giveaway/youtube/oauth/callback';
    }
}
