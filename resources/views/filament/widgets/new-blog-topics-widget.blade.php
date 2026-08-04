<x-filament-widgets::widget>
    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-medium text-gray-950 dark:text-white">Blog translation pipeline</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    New topics found by the sitemap sync, and where existing translations stand
                </p>
            </div>

            <x-filament::button tag="a" href="{{ $queueUrl }}" color="gray" size="sm">
                Open Blog Translation queue
            </x-filament::button>
        </div>

        <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="flex items-center gap-2">
                    <x-filament::icon
                        icon="heroicon-o-document-plus"
                        class="h-5 w-5 {{ $newCount > 0 ? 'text-primary-500' : 'text-gray-400' }}"
                    />
                    <span class="text-2xl font-bold text-gray-950 dark:text-white">{{ $newCount }}</span>
                </div>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">New topics (24h)</p>
            </div>

            <div class="rounded-lg border border-gray-200 p-3 dark:border-white/10">
                <div class="flex items-center gap-2">
                    <x-filament::icon
                        icon="heroicon-o-arrow-up-tray"
                        class="h-5 w-5 {{ $notUploadedCount > 0 ? 'text-danger-600' : 'text-gray-400' }}"
                    />
                    <span class="text-2xl font-bold text-gray-950 dark:text-white">{{ $notUploadedCount }}</span>
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
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
