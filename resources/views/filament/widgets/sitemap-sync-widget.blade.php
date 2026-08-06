<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-medium text-gray-950 dark:text-white">Sitemap sync</p>
                <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                    @if ($hasRun)
                        Last run {{ $lastRunAt->diffForHumans() }} - {{ number_format($linksAnalyzed) }} link{{ $linksAnalyzed === 1 ? '' : 's' }} analyzed
                    @else
                        Waiting for the first sync to run
                    @endif
                </p>
            </div>

            @if ($hasRun)
                <span class="text-xs text-gray-500 dark:text-gray-400">
                    @if ($remainingMinutes > 0)
                        Next sync in {{ $remainingMinutes }} min
                    @else
                        Next sync due any moment
                    @endif
                </span>
            @endif
        </div>

        {{-- h-2/h-full aren't in Filament's pre-built CSS bundle (never compiled - see the note
             on blog-translation-queue.blade.php's own progress bar for the fuller story), so
             height is set inline instead of trusting the class. --}}
        <div class="mt-3 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" style="height: 8px">
            <div
                class="rounded-full bg-primary-500 transition-all duration-500"
                style="width: {{ $percent }}%; height: 8px"
            ></div>
        </div>

        {{-- Inline grid-template-columns, not a grid-cols-4 class - the plain unprefixed
             grid-cols-N classes (2, 3, 4...) are never actually compiled into this app's Filament
             CSS bundle, only grid-cols-1 and responsive-prefixed variants are (confirmed directly
             against public/css/filament/filament/app.css) - see ServiceTranslationWidget's own
             blade for the fuller story of this same mistake being made and then actually fixed. --}}
        <div class="mt-4 gap-3" style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr))">
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="flex items-center gap-2">
                    <x-filament::icon
                        icon="heroicon-o-plus-circle"
                        class="h-5 w-5 {{ $addedLastRun > 0 ? 'text-success-600' : 'text-gray-400' }}"
                    />
                    <span class="text-2xl font-bold text-gray-950 dark:text-white">{{ number_format($addedLastRun) }}</span>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">New links (last sync)</p>
            </div>

            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="flex items-center gap-2">
                    <x-filament::icon
                        icon="heroicon-o-globe-alt"
                        class="h-5 w-5 text-primary-500"
                    />
                    <span class="text-2xl font-bold text-gray-950 dark:text-white">{{ number_format($totalInSitemap) }}</span>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Total links in sitemap</p>
            </div>

            @if ($hasRun && ($updatedLastRun > 0 || $removedLastRun > 0))
                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="flex items-center gap-2">
                        <x-filament::icon
                            icon="heroicon-o-arrow-path"
                            class="h-5 w-5 {{ $updatedLastRun > 0 ? 'text-primary-500' : 'text-gray-400' }}"
                        />
                        <span class="text-2xl font-bold text-gray-950 dark:text-white">{{ number_format($updatedLastRun) }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Updated (last sync)</p>
                </div>

                <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <div class="flex items-center gap-2">
                        <x-filament::icon
                            icon="heroicon-o-minus-circle"
                            class="h-5 w-5 {{ $removedLastRun > 0 ? 'text-danger-600' : 'text-gray-400' }}"
                        />
                        <span class="text-2xl font-bold text-gray-950 dark:text-white">{{ number_format($removedLastRun) }}</span>
                    </div>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Removed (last sync)</p>
                </div>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
