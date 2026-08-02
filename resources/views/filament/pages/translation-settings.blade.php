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
                wire:click="resetPromptToDefault"
            >
                Reset prompt to default
            </x-filament::button>
        </div>

        <div class="mt-4 rounded-lg border border-gray-200 p-3 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
            <p class="mb-1 font-medium text-gray-600 dark:text-gray-300">Placeholders supported in the prompt above:</p>
            <div class="flex flex-wrap gap-x-4 gap-y-1">
                @foreach (\App\Services\AiSettingsService::BLOG_TRANSLATION_PLACEHOLDERS as $token => $description)
                    <span><code class="rounded bg-gray-100 px-1 dark:bg-white/10">{{ $token }}</code> {{ $description }}</span>
                @endforeach
            </div>
        </div>
    </form>

    <x-filament::section heading="Manual check">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Runs the detector immediately for up to 40 of the most overdue blog URLs, instead of waiting for the hourly background check.
        </p>

        <p class="mt-2 text-sm {{ $this->pendingCount > 0 ? 'text-warning-600 dark:text-warning-400' : 'text-success-600 dark:text-success-400' }}">
            @if ($this->pendingCount > 0)
                {{ $this->pendingCount }} blog URL{{ $this->pendingCount === 1 ? '' : 's' }} still due for a check
                @if ($this->pendingCount > 40)
                    &mdash; one run only covers 40, click "Run check now" {{ (int) ceil($this->pendingCount / 40) }} times (or wait for the hourly background check) to cover all of them.
                @endif
            @else
                All blog URLs are up to date - nothing is currently due for a check.
            @endif
        </p>

        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
            To check one specific link right away instead of waiting your turn in the batch, use the "Recheck" button next to it on the URLs page.
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
