<x-filament-panels::page>
    <div wire:poll.30s>
        @php $cronStatus = $this->getCronStatus(app(\App\Services\SettingsService::class)); @endphp
        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-950 dark:text-white">Server cron</p>
                    <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                        @if ($cronStatus['active'])
                            <x-filament::badge color="success">Active</x-filament::badge>
                            <span>last checked in {{ $cronStatus['heartbeat']->diffForHumans() }}</span>
                        @elseif ($cronStatus['heartbeat'])
                            <x-filament::badge color="danger">Not detected</x-filament::badge>
                            <span>last seen {{ $cronStatus['heartbeat']->diffForHumans() }} - the server's system crontab has stopped reaching this app.</span>
                        @else
                            <x-filament::badge color="danger">Not detected</x-filament::badge>
                            <span>never seen - the required system cron entry (see README) isn't reaching this app.</span>
                        @endif
                    </div>
                </div>
            </div>
        </x-filament::section>
    </div>

    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="text-sm font-medium text-gray-950 dark:text-white">Last sync</p>
                @if ($latestRun)
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                        <span>
                            {{ $latestRun->started_at->diffForHumans() }}
                            <span title="{{ $latestRun->started_at }}">({{ $latestRun->started_at }})</span>
                        </span>
                        <x-filament::badge :color="match ($latestRun->status) {
                            \App\Models\SyncRun::SUCCESS => 'success',
                            \App\Models\SyncRun::FAILED => 'danger',
                            default => 'warning',
                        }">
                            {{ $latestRun->status }}
                        </x-filament::badge>
                    </div>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        <span class="font-medium text-gray-950 dark:text-white">{{ $latestRun->added }}</span> new URLs added,
                        {{ $latestRun->updated }} updated, {{ $latestRun->removed }} removed
                        (of {{ $latestRun->total_fetched }} fetched).
                    </p>
                @else
                    <p class="text-sm text-gray-500 dark:text-gray-400">No sync has run yet.</p>
                @endif
            </div>

            <x-filament::button tag="a" :href="$this->getSyncHistoryUrl()" color="gray" icon="heroicon-o-arrow-path">
                View sync history
            </x-filament::button>
        </div>
    </x-filament::section>

    <form wire:submit="save">
        {{ $this->form }}

        <x-filament::button type="submit" class="mt-4">
            Save
        </x-filament::button>
    </form>
</x-filament-panels::page>
