<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <x-filament::button type="submit">
                Save
            </x-filament::button>

            <x-filament::button
                type="button"
                color="gray"
                wire:click="refreshTorList"
            >
                Refresh Tor list now
            </x-filament::button>

            <x-filament::button
                type="button"
                color="danger"
                outlined
                wire:click="blockAllTorExitNodes"
                wire:confirm="Block every known Tor exit node IP now? This queues the whole list (not just ones that have made a request) for blocking - registering them with cPanel happens in the background, 5 at a time."
            >
                Block all Tor exit nodes now
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
