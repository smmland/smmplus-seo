<div x-data="{ langSelected: {{ Illuminate\Support\Js::from($languages->mapWithKeys(fn ($l) => [$l['code'] => true])->all()) }} }">
    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
        {{ $selectedCount }} service{{ $selectedCount === 1 ? '' : 's' }} selected. The zip groups files into one folder per service id (e.g. <code class="rounded bg-gray-100 px-1 dark:bg-white/10">{{ $fileExample }}</code>), each containing just that language's {{ $fieldLabel }} text.
    </p>

    <div class="space-y-2">
        @foreach ($languages as $language)
            <label class="flex cursor-pointer items-center gap-2 text-sm text-gray-700 dark:text-gray-200">
                <input
                    type="checkbox"
                    x-model="langSelected['{{ $language['code'] }}']"
                    class="rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5"
                >
                <span class="font-medium">{{ strtoupper($language['code']) }}</span>
                <span class="text-gray-400 dark:text-gray-500">{{ $language['name'] }}</span>
            </label>
        @endforeach
    </div>

    <div class="mt-4 flex justify-end">
        <x-filament::button
            size="sm"
            icon="heroicon-o-arrow-down-tray"
            @click="$wire.{{ $wireMethod }}(Object.keys(langSelected).filter(code => langSelected[code]))"
        >
            Download zip
        </x-filament::button>
    </div>
</div>
