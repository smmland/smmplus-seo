<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <x-filament::button type="submit" class="mt-4">
            Save
        </x-filament::button>
    </form>

    <x-filament::section heading="Manual check">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Runs the detector immediately for up to 40 of the most overdue blog URLs, instead of waiting for the hourly background check.
        </p>

        <x-filament::button color="gray" wire:click="runNow" class="mt-4">
            Run check now
        </x-filament::button>

        @if ($lastRunResult)
            <p class="mt-4 text-sm text-gray-700 dark:text-gray-200">
                Checked {{ $lastRunResult['checked'] }}, hid {{ $lastRunResult['hidden'] }}, unhid {{ $lastRunResult['unhidden'] }}, errors {{ $lastRunResult['errors'] }}.
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
