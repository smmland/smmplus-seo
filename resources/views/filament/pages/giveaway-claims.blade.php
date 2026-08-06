<x-filament-panels::page>
    <div wire:poll.30s>
        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-gray-950 dark:text-white">Giveaway claims</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Telegram/YouTube claims are checked automatically. Trustpilot claims are self-reported (no
                        API can confirm a review is real) - check the submitted link before rewarding those. Nothing
                        here sends a reward on its own - go credit the user's wallet in the real smm.plus admin
                        panel, then mark the claim as rewarded here so it isn't paid twice.
                    </p>
                </div>

                @if (! empty($this->pendingCounts))
                    <div class="flex flex-wrap gap-2">
                        @foreach ($this->pendingCounts as $platform => $count)
                            <x-filament::badge color="warning">
                                {{ \App\Models\GiveawayClaim::PLATFORM_LABELS[$platform] ?? $platform }}: {{ $count }} pending
                            </x-filament::badge>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section class="mt-4">
            <div class="mb-3 flex flex-wrap items-center gap-3">
                <select
                    wire:model.live="platformFilter"
                    class="fi-input rounded-lg border-0 py-1.5 text-sm text-gray-950 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10"
                >
                    @foreach ($this::PLATFORM_FILTERS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>

                <select
                    wire:model.live="statusFilter"
                    class="fi-input rounded-lg border-0 py-1.5 text-sm text-gray-950 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10"
                >
                    @foreach ($this::STATUS_FILTERS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            @if (! $this->tableReady)
                <div class="rounded-lg p-3 text-sm" style="background-color: rgba(var(--danger-500), .1); color: rgb(var(--danger-700))">
                    This feature needs a database update first - go to General Settings and click "Update database", then reload this page.
                </div>
            @elseif ($this->claims->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No claims yet - they'll show up here as soon as someone completes a task on the giveaway page.
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full text-start">
                        <thead>
                            <tr>
                                <th class="p-2 text-start text-sm font-semibold">Platform</th>
                                <th class="p-2 text-start text-sm font-semibold">Panel user</th>
                                <th class="p-2 text-start text-sm font-semibold">Proof</th>
                                <th class="p-2 text-start text-sm font-semibold">Submitted</th>
                                <th class="p-2 text-start text-sm font-semibold">Status</th>
                                <th class="p-2 text-start text-sm font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->claims as $claim)
                                <tr wire:key="claim-{{ $claim->id }}" class="border-t border-gray-100 dark:border-white/5 align-top">
                                    <td class="p-2">
                                        <x-filament::badge color="gray" size="xs">
                                            {{ \App\Models\GiveawayClaim::PLATFORM_LABELS[$claim->platform] ?? $claim->platform }}
                                        </x-filament::badge>
                                    </td>
                                    <td class="p-2 text-sm">
                                        <div class="font-medium text-gray-950 dark:text-white">{{ $claim->panel_user_email }}</div>
                                        <div class="text-xs text-gray-400 dark:text-gray-500">{{ $claim->panel_username ?? '—' }}</div>
                                    </td>
                                    <td class="p-2 text-sm">
                                        @if ($claim->proof_url)
                                            <a href="{{ $claim->proof_url }}" target="_blank" rel="noopener" class="text-primary-600 underline">
                                                {{ \Illuminate\Support\Str::limit($claim->proof_url, 40) }}
                                            </a>
                                        @else
                                            <span class="text-gray-400 dark:text-gray-500">— (auto-verified)</span>
                                        @endif
                                    </td>
                                    <td class="p-2 text-sm text-gray-600 dark:text-gray-300">
                                        {{ $claim->verified_at?->diffForHumans() }}
                                    </td>
                                    <td class="p-2">
                                        @switch($claim->status)
                                            @case('verified')
                                                <x-filament::badge color="warning" size="xs">Awaiting reward</x-filament::badge>
                                                @break
                                            @case('pending_review')
                                                <x-filament::badge color="warning" size="xs">Needs manual check</x-filament::badge>
                                                @break
                                            @case('rewarded')
                                                <x-filament::badge color="success" size="xs">Rewarded</x-filament::badge>
                                                @break
                                            @case('rejected')
                                                <x-filament::badge color="danger" size="xs">Rejected</x-filament::badge>
                                                @break
                                        @endswitch

                                        @if ($claim->status === 'rewarded')
                                            <div class="mt-1 max-w-xs text-xs text-gray-400 dark:text-gray-500">
                                                {{ $claim->reward_note }}
                                                @if ($claim->rewardedBy)
                                                    — {{ $claim->rewardedBy->name }}
                                                @endif
                                            </div>
                                        @endif
                                    </td>
                                    <td class="p-2">
                                        <div class="flex flex-wrap items-center gap-2">
                                            @if (in_array($claim->status, ['verified', 'pending_review']))
                                                <x-filament::button
                                                    size="sm"
                                                    color="success"
                                                    icon="heroicon-o-check-badge"
                                                    wire:click="mountAction('markRewarded', {{ Illuminate\Support\Js::from(['claimId' => $claim->id]) }})"
                                                >
                                                    Mark as rewarded
                                                </x-filament::button>

                                                <x-filament::icon-button
                                                    icon="heroicon-o-x-mark"
                                                    color="danger"
                                                    size="sm"
                                                    label="Reject"
                                                    tooltip="Reject this claim"
                                                    wire:click="rejectClaim({{ $claim->id }})"
                                                    wire:confirm="Reject this claim?"
                                                />
                                            @endif

                                            <x-filament::icon-button
                                                icon="heroicon-o-trash"
                                                color="danger"
                                                size="sm"
                                                label="Delete"
                                                tooltip="Delete"
                                                wire:click="deleteClaim({{ $claim->id }})"
                                                wire:confirm="Delete this claim permanently?"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
