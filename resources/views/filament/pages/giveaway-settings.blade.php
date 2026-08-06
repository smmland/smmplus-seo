<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <x-filament::button type="submit">
                Save
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
