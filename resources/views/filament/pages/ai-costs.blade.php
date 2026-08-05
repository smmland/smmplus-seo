<x-filament-panels::page>
    @php $aiCosts = $this->getAiCostStats(); @endphp

    <x-filament::section heading="AI Costs" description="Estimated spend across every AI feature this panel runs - blog/service translation, and Telegram post text + image generation - based on approximate published per-model pricing. Actual provider invoices may differ slightly.">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <p class="text-xs font-medium text-gray-400 dark:text-gray-500">Total estimated spend</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">${{ number_format($aiCosts['totalCost'], 2) }}</p>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <p class="text-xs font-medium text-gray-400 dark:text-gray-500">Translation attempts</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ $aiCosts['totalJobs'] }}</p>
            </div>
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <p class="text-xs font-medium text-gray-400 dark:text-gray-500">Tokens used</p>
                <p class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($aiCosts['totalInputTokens'] + $aiCosts['totalOutputTokens']) }}</p>
            </div>
        </div>

        @if ($aiCosts['unknownPricingCount'] > 0)
            <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                {{ $aiCosts['unknownPricingCount'] }} translation(s) used a custom model with no known pricing - not included in the total above.
            </p>
        @endif
    </x-filament::section>

    <x-filament::section heading="Blog translations" class="mt-4">
        @if (! $aiCosts['blog']['available'])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                This needs a database update first - go to General Settings and click "Update database".
            </p>
        @elseif ($aiCosts['blog']['byTopic']->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No AI blog translations have run yet.</p>
        @else
            <div class="overflow-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                <table class="w-full text-start text-xs">
                    <thead>
                        <tr class="bg-gray-50 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            <th class="p-2 text-start font-medium">Blog article</th>
                            <th class="p-2 text-end font-medium">Translations</th>
                            <th class="p-2 text-end font-medium">Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($aiCosts['blog']['byTopic'] as $topic)
                            <tr class="border-t border-gray-950/5 dark:border-white/10">
                                <td class="max-w-sm truncate p-2">
                                    @if ($topic['sourceUrl'])
                                        <a href="{{ $topic['sourceUrl'] }}" target="_blank" rel="noopener" class="font-medium text-primary-600 dark:text-primary-400">
                                            {{ $topic['title'] }}
                                        </a>
                                    @else
                                        <span class="font-medium text-gray-700 dark:text-gray-200">{{ $topic['title'] }}</span>
                                    @endif
                                </td>
                                <td class="p-2 text-end text-gray-500 dark:text-gray-400">{{ $topic['translations'] }}</td>
                                <td class="p-2 text-end font-medium text-gray-950 dark:text-white">${{ number_format($topic['cost'], 4) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($aiCosts['blog']['lastPage'] > 1)
                <div class="mt-3 flex items-center justify-between gap-3">
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        Page {{ $aiCosts['blog']['page'] }} of {{ $aiCosts['blog']['lastPage'] }} ({{ $aiCosts['blog']['total'] }} topic{{ $aiCosts['blog']['total'] === 1 ? '' : 's' }})
                    </p>
                    <div class="flex items-center gap-2">
                        <x-filament::button
                            size="sm"
                            color="gray"
                            icon="heroicon-o-chevron-left"
                            :disabled="$aiCosts['blog']['page'] <= 1"
                            wire:click="previousBlogCostsPage"
                        >
                            Previous
                        </x-filament::button>
                        <x-filament::button
                            size="sm"
                            color="gray"
                            icon="heroicon-o-chevron-right"
                            icon-position="after"
                            :disabled="$aiCosts['blog']['page'] >= $aiCosts['blog']['lastPage']"
                            wire:click="nextBlogCostsPage"
                        >
                            Next
                        </x-filament::button>
                    </div>
                </div>
            @endif
        @endif
    </x-filament::section>

    <x-filament::section heading="Service translations" class="mt-4">
        @if (! $aiCosts['service']['available'])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                This needs a database update first - go to General Settings and click "Update database".
            </p>
        @elseif ($aiCosts['service']['byService']->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No AI service translations have run yet.</p>
        @else
            <div class="overflow-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                <table class="w-full text-start text-xs">
                    <thead>
                        <tr class="bg-gray-50 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            <th class="p-2 text-start font-medium">Service</th>
                            <th class="p-2 text-end font-medium">Translations</th>
                            <th class="p-2 text-end font-medium">Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($aiCosts['service']['byService'] as $service)
                            <tr class="border-t border-gray-950/5 dark:border-white/10">
                                <td class="max-w-sm truncate p-2">
                                    <span class="font-medium text-gray-700 dark:text-gray-200">{{ $service['title'] }}</span>
                                    @if ($service['categoryTitle'])
                                        <span class="text-gray-400 dark:text-gray-500">· {{ $service['categoryTitle'] }}</span>
                                    @endif
                                </td>
                                <td class="p-2 text-end text-gray-500 dark:text-gray-400">{{ $service['translations'] }}</td>
                                <td class="p-2 text-end font-medium text-gray-950 dark:text-white">${{ number_format($service['cost'], 4) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($aiCosts['service']['lastPage'] > 1)
                <div class="mt-3 flex items-center justify-between gap-3">
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        Page {{ $aiCosts['service']['page'] }} of {{ $aiCosts['service']['lastPage'] }} ({{ $aiCosts['service']['total'] }} service{{ $aiCosts['service']['total'] === 1 ? '' : 's' }})
                    </p>
                    <div class="flex items-center gap-2">
                        <x-filament::button
                            size="sm"
                            color="gray"
                            icon="heroicon-o-chevron-left"
                            :disabled="$aiCosts['service']['page'] <= 1"
                            wire:click="previousServiceCostsPage"
                        >
                            Previous
                        </x-filament::button>
                        <x-filament::button
                            size="sm"
                            color="gray"
                            icon="heroicon-o-chevron-right"
                            icon-position="after"
                            :disabled="$aiCosts['service']['page'] >= $aiCosts['service']['lastPage']"
                            wire:click="nextServiceCostsPage"
                        >
                            Next
                        </x-filament::button>
                    </div>
                </div>
            @endif
        @endif
    </x-filament::section>

    <x-filament::section heading="Telegram posts" description="Text generation (AI Settings' provider) and image generation (always OpenAI - see Telegram Settings) kept as two separate numbers, since only images have a toggle to turn them off." class="mt-4">
        @if (! $aiCosts['telegram']['available'])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                This needs a database update first - go to General Settings and click "Update database".
            </p>
        @elseif ($aiCosts['telegram']['byType']->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">No Telegram posts have been generated yet.</p>
        @else
            <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <p class="text-xs font-medium text-gray-400 dark:text-gray-500">Text generation</p>
                    <p class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">${{ number_format($aiCosts['telegram']['textCost'], 4) }}</p>
                </div>
                <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <p class="text-xs font-medium text-gray-400 dark:text-gray-500">Image generation ({{ $aiCosts['telegram']['imageCount'] }} image{{ $aiCosts['telegram']['imageCount'] === 1 ? '' : 's' }})</p>
                    <p class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">${{ number_format($aiCosts['telegram']['imageCost'], 4) }}</p>
                </div>
                <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <p class="text-xs font-medium text-gray-400 dark:text-gray-500">Combined</p>
                    <p class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">${{ number_format($aiCosts['telegram']['textCost'] + $aiCosts['telegram']['imageCost'], 4) }}</p>
                </div>
            </div>

            <div class="overflow-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                <table class="w-full text-start text-xs">
                    <thead>
                        <tr class="bg-gray-50 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                            <th class="p-2 text-start font-medium">Post type</th>
                            <th class="p-2 text-end font-medium">Posts</th>
                            <th class="p-2 text-end font-medium">Text cost</th>
                            <th class="p-2 text-end font-medium">Image cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($aiCosts['telegram']['byType'] as $row)
                            <tr class="border-t border-gray-950/5 dark:border-white/10">
                                <td class="p-2 font-medium text-gray-700 dark:text-gray-200">{{ \App\Models\TelegramPost::TYPE_LABELS[$row['type']] ?? $row['type'] }}</td>
                                <td class="p-2 text-end text-gray-500 dark:text-gray-400">{{ $row['posts'] }}</td>
                                <td class="p-2 text-end font-medium text-gray-950 dark:text-white">${{ number_format($row['textCost'], 4) }}</td>
                                <td class="p-2 text-end font-medium text-gray-950 dark:text-white">${{ number_format($row['imageCost'], 4) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
