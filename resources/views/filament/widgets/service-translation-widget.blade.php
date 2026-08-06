<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-medium text-gray-950 dark:text-white">Service translation pipeline</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    New services found by the catalog sync, and where existing description/title translations stand
                </p>
            </div>

            <x-filament::button tag="a" href="{{ $queueUrl }}" color="gray" size="sm">
                Open Service Translation queue
            </x-filament::button>
        </div>

        @if (! $ready)
            <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
                This feature needs a database update first - go to General Settings and click "Update database".
            </p>
        @else
            {{-- Inline grid-template-columns, not a grid-cols-N class - re-verified this update that
                 even the plain unprefixed grid-cols-2/3/4 classes were NEVER actually compiled into
                 this app's Filament CSS bundle either (only grid-cols-1 and responsive-prefixed
                 variants like sm:grid-cols-2 are in there - checked public/css/filament/filament/app.css
                 directly), so the "fix" that switched this to grid-cols-2 in the previous update
                 didn't actually fix anything - it was still silently stacking in one column. Inline
                 styles side-step this bundle entirely since they don't depend on any class existing. --}}
            <div class="mt-4 gap-3" style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr))">
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="flex items-center gap-2">
                        <x-filament::icon
                            icon="heroicon-o-tag"
                            class="h-5 w-5 {{ $newCount > 0 ? 'text-primary-500' : 'text-gray-400' }}"
                        />
                        <span class="text-2xl font-bold text-gray-950 dark:text-white">{{ $newCount }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">New services (24h)</p>
                </div>

                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="flex items-center gap-2">
                        <x-filament::icon
                            icon="heroicon-o-arrow-up-tray"
                            class="h-5 w-5 {{ $needsUploadCount > 0 ? 'text-danger-600' : 'text-gray-400' }}"
                        />
                        <span class="text-2xl font-bold text-gray-950 dark:text-white">{{ $needsUploadCount }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Translated, not uploaded yet</p>
                </div>

                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="flex items-center gap-2">
                        <x-filament::icon
                            icon="heroicon-o-sparkles"
                            class="h-5 w-5 {{ $inProgressCount > 0 ? 'text-primary-500' : 'text-gray-400' }}"
                        />
                        <span class="text-2xl font-bold text-gray-950 dark:text-white">{{ $inProgressCount }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Translating now</p>
                </div>

                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="flex items-center gap-2">
                        <x-filament::icon
                            icon="heroicon-o-arrow-path"
                            class="h-5 w-5 {{ $recentlyRetranslatedCount > 0 ? 'text-primary-500' : 'text-gray-400' }}"
                        />
                        <span class="text-2xl font-bold text-gray-950 dark:text-white">{{ $recentlyRetranslatedCount }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Auto re-translated (7d)</p>
                </div>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
