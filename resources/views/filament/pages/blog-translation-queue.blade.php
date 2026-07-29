<x-filament-panels::page>
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
