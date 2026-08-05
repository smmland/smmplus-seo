<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-medium text-gray-950 dark:text-white">Telegram channel queue</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    What's waiting for review, what's broken, and how active the channel's been
                </p>
                @if ($ready && ! $enabled)
                    <p class="mt-1 text-xs" style="color: rgb(var(--warning-600))">
                        Telegram posting is currently disabled - enable it in Telegram Settings.
                    </p>
                @endif
            </div>

            <x-filament::button tag="a" href="{{ $queueUrl }}" color="gray" size="sm">
                Open Telegram Queue
            </x-filament::button>
        </div>

        @if (! $ready)
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                This feature needs a database update first - go to General Settings and click "Update database".
            </p>
        @else
            <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="flex items-center gap-2">
                        <x-filament::icon
                            icon="heroicon-o-inbox-arrow-down"
                            class="h-5 w-5 {{ $pendingCount > 0 ? 'text-primary-500' : 'text-gray-400' }}"
                        />
                        <span class="text-2xl font-bold text-gray-950 dark:text-white">{{ $pendingCount }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Pending review</p>
                </div>

                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="flex items-center gap-2">
                        <x-filament::icon
                            icon="heroicon-o-exclamation-triangle"
                            class="h-5 w-5 {{ $failedCount > 0 ? 'text-danger-600' : 'text-gray-400' }}"
                        />
                        <span class="text-2xl font-bold text-gray-950 dark:text-white">{{ $failedCount }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Failed - needs retry</p>
                </div>

                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="flex items-center gap-2">
                        <x-filament::icon
                            icon="heroicon-o-paper-airplane"
                            class="h-5 w-5 {{ $sentTodayCount > 0 ? 'text-success-600' : 'text-gray-400' }}"
                        />
                        <span class="text-2xl font-bold text-gray-950 dark:text-white">{{ $sentTodayCount }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Sent (24h)</p>
                </div>

                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="flex items-center gap-2">
                        <x-filament::icon
                            icon="heroicon-o-clock"
                            class="h-5 w-5 {{ $dueNowCount > 0 ? 'text-primary-500' : 'text-gray-400' }}"
                        />
                        <span class="text-2xl font-bold text-gray-950 dark:text-white">{{ $dueNowCount }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Due now (next tick)</p>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
