<x-filament-panels::page>
    <div wire:poll.60s>
        <x-filament::section>
            <div class="flex items-center justify-between text-sm text-gray-600 dark:text-gray-300">
                <span>Next automatic check (up to 40 URLs)</span>
                <span>
                    @if ($this->cronProgress['hasRun'])
                        @if ($this->cronProgress['remainingMinutes'] > 0)
                            in {{ $this->cronProgress['remainingMinutes'] }} min
                        @else
                            due any moment
                        @endif
                    @else
                        waiting for the first automatic run
                    @endif
                </span>
            </div>

            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                <div
                    class="h-full rounded-full bg-primary-500 transition-all duration-500"
                    style="width: {{ $this->cronProgress['percent'] }}%"
                ></div>
            </div>

            @if ($this->cronProgress['hasRun'])
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                    Last automatic check: {{ $this->cronProgress['lastRunAt']->diffForHumans() }}
                </p>
            @endif
        </x-filament::section>
    </div>

    <x-filament::section>
        @if ($this->queue->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No blog topics have missing translations right now.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead>
                        <tr>
                            <th class="p-2 text-start text-sm font-semibold">Topic (default language)</th>
                            <th class="p-2 text-start text-sm font-semibold">Missing languages</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->queue as $topic)
                            <tr class="border-t border-gray-100 dark:border-white/5 align-top">
                                <td class="p-2">
                                    <a href="{{ $topic['url']->source_url }}" target="_blank" rel="noopener" class="text-primary-600 hover:underline dark:text-primary-400">
                                        {{ $topic['url']->slug }}
                                    </a>
                                </td>
                                <td class="p-2">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($topic['missing'] as $language)
                                            <x-filament::badge color="warning">
                                                {{ $language->code }}
                                            </x-filament::badge>
                                        @endforeach
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
