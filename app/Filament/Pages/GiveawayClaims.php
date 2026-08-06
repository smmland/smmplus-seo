<?php

namespace App\Filament\Pages;

use App\Models\GiveawayClaim;
use App\Services\ActivityLogService;
use App\Services\GiveawaySettingsService;
use App\Support\PanelSection;
use App\Filament\Concerns\GuardsSectionEdits;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;

/**
 * The admin review queue for Giveaway: every verified "user did the growth action" event
 * (Telegram channel join, YouTube subscription - both checked automatically, see
 * GiveawayController). Nothing here sends any reward automatically - the panel this admin
 * actually pays wallet credits from (smm.plus) isn't one we have API access to, so this page
 * exists purely so the admin can see who's owed what and mark it done once they've handled it
 * by hand, without paying the same claim twice.
 */
class GiveawayClaims extends Page implements HasActions
{
    use InteractsWithActions;
    use GuardsSectionEdits;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'Giveaway';

    protected static ?string $navigationLabel = 'Claims';

    protected static string $view = 'filament.pages.giveaway-claims';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyAccess(PanelSection::viewOrEditKeys(PanelSection::GIVEAWAY)) ?? false;
    }

    public string $platformFilter = 'all';

    public string $statusFilter = 'all';

    public const PLATFORM_FILTERS = ['all' => 'All platforms', ...GiveawayClaim::PLATFORM_LABELS];

    public const STATUS_FILTERS = ['all' => 'All statuses', ...GiveawayClaim::STATUS_LABELS];

    public function updatedPlatformFilter(): void
    {
        unset($this->claims);
    }

    public function updatedStatusFilter(): void
    {
        unset($this->claims);
    }

    #[Computed]
    public function tableReady(): bool
    {
        return Schema::hasTable('giveaway_claims');
    }

    #[Computed]
    public function pendingCounts(): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        return GiveawayClaim::query()
            ->whereIn('status', [GiveawayClaim::STATUS_VERIFIED, GiveawayClaim::STATUS_PENDING_REVIEW])
            ->selectRaw('platform, count(*) as total')
            ->groupBy('platform')
            ->pluck('total', 'platform')
            ->all();
    }

    #[Computed]
    public function claims()
    {
        if (! $this->tableReady()) {
            return collect();
        }

        $query = GiveawayClaim::query()->with('rewardedBy')->orderByDesc('verified_at');

        if ($this->platformFilter !== 'all') {
            $query->where('platform', $this->platformFilter);
        }

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        return $query->limit(100)->get();
    }

    public function rejectClaim(int $claimId): void
    {
        if (! $this->assertCanEdit(PanelSection::GIVEAWAY)) {
            return;
        }

        $claim = GiveawayClaim::query()->find($claimId);

        GiveawayClaim::query()
            ->where('id', $claimId)
            ->whereIn('status', [GiveawayClaim::STATUS_VERIFIED, GiveawayClaim::STATUS_PENDING_REVIEW])
            ->update(['status' => GiveawayClaim::STATUS_REJECTED]);

        if ($claim) {
            app(ActivityLogService::class)->record('giveaway.claim_rejected', $claim, section: PanelSection::GIVEAWAY, subjectLabel: "{$claim->platform}: {$claim->panel_user_email}");
        }

        unset($this->claims);
        unset($this->pendingCounts);

        Notification::make()->title('Claim rejected')->success()->send();
    }

    public function deleteClaim(int $claimId): void
    {
        if (! $this->assertCanEdit(PanelSection::GIVEAWAY)) {
            return;
        }

        $claim = GiveawayClaim::query()->find($claimId);

        GiveawayClaim::query()->where('id', $claimId)->delete();

        if ($claim) {
            app(ActivityLogService::class)->record('giveaway.claim_deleted', $claim, section: PanelSection::GIVEAWAY, subjectLabel: "{$claim->platform}: {$claim->panel_user_email}");
        }

        unset($this->claims);
        unset($this->pendingCounts);
    }

    public function markRewardedAction(): Action
    {
        return Action::make('markRewarded')
            ->label('Mark as rewarded')
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (): bool => auth()->user()?->hasAccess(PanelSection::key(PanelSection::GIVEAWAY, PanelSection::TIER_EDIT)) ?? false)
            ->fillForm(function (array $arguments) {
                $claim = GiveawayClaim::query()->find($arguments['claimId']);
                $amount = $claim ? app(GiveawaySettingsService::class)->getRewardAmountFor($claim->platform) : null;

                return ['rewardNote' => $amount !== null ? "credited \${$amount} wallet balance" : null];
            })
            ->form([
                Textarea::make('rewardNote')
                    ->label('What was given (for your own records)')
                    ->placeholder('e.g. credited $2 wallet balance')
                    ->rows(3),
            ])
            ->action(function (array $data, array $arguments) {
                $claim = GiveawayClaim::query()->find($arguments['claimId']);

                GiveawayClaim::query()->where('id', $arguments['claimId'])->update([
                    'status' => GiveawayClaim::STATUS_REWARDED,
                    'reward_note' => $data['rewardNote'] ?: null,
                    'rewarded_at' => now(),
                    'rewarded_by_user_id' => auth()->id(),
                ]);

                if ($claim) {
                    app(ActivityLogService::class)->record(
                        'giveaway.claim_rewarded',
                        $claim,
                        ['reward_note' => $data['rewardNote'] ?: null],
                        PanelSection::GIVEAWAY,
                        "{$claim->platform}: {$claim->panel_user_email}",
                    );
                }

                unset($this->claims);
                unset($this->pendingCounts);

                Notification::make()->title('Marked as rewarded')->success()->send();
            });
    }
}
