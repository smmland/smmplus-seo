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
    </x-filament::section>
</x-filament-widgets::widget>
