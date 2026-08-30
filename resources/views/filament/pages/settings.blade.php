<x-filament-panels::page>
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

    <x-filament::section heading="smm.plus API (catalog pricing)" class="mt-6">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Credentials smm.plus's own customer API (https://smm.plus/api) uses to keep GET /api/services's cached price/min/max fresh for landing pages.
        </p>

        <form wire:submit="saveCatalog" class="mt-4">
            {{ $this->catalogForm }}

            <x-filament::button type="submit" class="mt-4">
                Save catalog API settings
            </x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>
