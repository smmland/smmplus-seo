<x-filament-panels::page>
    <x-filament::section heading="Appearance" description="Pick the panel's accent color - used for the active nav item, primary buttons, links and switches throughout.">
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            @foreach ($this->getAccentColorPresets() as $key => $preset)
                <button
                    type="button"
                    wire:click="setAccentColor('{{ $key }}')"
                    @class([
                        'flex items-center gap-2 rounded-lg p-2.5 text-start text-sm font-medium ring-1 ring-inset transition',
                        'ring-gray-950/10 hover:bg-gray-50 dark:ring-white/10 dark:hover:bg-white/5' => $accentColor !== $key,
                    ])
                    style="{{ $accentColor === $key ? 'background-color:color-mix(in srgb, '.$preset['hex'].' 12%, transparent); box-shadow: inset 0 0 0 1.5px '.$preset['hex'].';' : '' }}"
                >
                    <span class="h-4 w-4 shrink-0 rounded-full" style="background-color: {{ $preset['hex'] }}"></span>
                    <span class="text-gray-700 dark:text-gray-200">{{ $preset['label'] }}</span>
                    @if ($accentColor === $key)
                        <x-filament::icon icon="heroicon-o-check" class="ms-auto h-4 w-4" style="color: {{ $preset['hex'] }}" />
                    @endif
                </button>
            @endforeach
        </div>
    </x-filament::section>

    <form wire:submit="save" class="mt-6">
        {{ $this->form }}

        <x-filament::button type="submit" class="mt-4">
            Save
        </x-filament::button>
    </form>
</x-filament-panels::page>
