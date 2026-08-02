<x-filament-panels::page>
    <div wire:poll.30s>
        @php $cronStatus = $this->getCronStatus(app(\App\Services\SettingsService::class)); @endphp
        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-950 dark:text-white">Server cron</p>
                    <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-gray-500 dark:text-gray-400">
                        @if ($cronStatus['active'])
                            <x-filament::badge color="success">Active</x-filament::badge>
                            <span>last checked in {{ $cronStatus['heartbeat']->diffForHumans() }}</span>
                        @elseif ($cronStatus['heartbeat'])
                            <x-filament::badge color="danger">Not detected</x-filament::badge>
                            <span>last seen {{ $cronStatus['heartbeat']->diffForHumans() }} - the server's system crontab has stopped reaching this app.</span>
                        @else
                            <x-filament::badge color="danger">Not detected</x-filament::badge>
                            <span>never seen - the required system cron entry (see README) isn't reaching this app.</span>
                        @endif
                    </div>
                </div>
            </div>
        </x-filament::section>
    </div>

    <x-filament::section heading="Panel updates" description="Install a new version of the panel and apply any database changes it needs - no server terminal required for either.">
        <div class="space-y-5">
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <p class="text-sm font-medium text-gray-950 dark:text-white">Install from zip</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Upload the update zip file exactly as sent - it's installed over the panel's own files. Existing files are overwritten; nothing outside the zip is deleted, and <code class="rounded bg-gray-100 px-1 dark:bg-white/10">storage/</code> and <code class="rounded bg-gray-100 px-1 dark:bg-white/10">.env</code> are never touched. Refused if a background translation is currently queued or running, to keep the file swap from interrupting it mid-job.
                </p>

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <input
                        type="file"
                        accept=".zip"
                        wire:model="updateZip"
                        class="fi-input block flex-1 rounded-lg border-0 py-1.5 text-sm text-gray-950 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10"
                    >

                    <x-filament::button
                        color="danger"
                        icon="heroicon-o-arrow-up-tray"
                        :disabled="! $updateZip"
                        wire:click="installUpdate"
                        wire:loading.attr="disabled"
                        wire:target="installUpdate,updateZip"
                        wire:confirm="Install this update? It overwrites the panel's own files with what's in the zip - make sure it's the file you meant to upload."
                    >
                        Install update
                    </x-filament::button>
                </div>

                <div wire:loading wire:target="updateZip" class="mt-2 text-xs text-gray-400 dark:text-gray-500">
                    Uploading…
                </div>

                @error('updateZip')
                    <p class="mt-2 text-xs text-danger-600 dark:text-danger-400">{{ $message }}</p>
                @enderror
            </div>

            @php $pendingMigrations = $this->pendingMigrationsCount(); @endphp
            <div class="flex flex-wrap items-center justify-between gap-4 rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div>
                    <p class="text-sm font-medium text-gray-950 dark:text-white">Database updates</p>
                    @if ($pendingMigrations > 0)
                        <p class="text-sm text-warning-600 dark:text-warning-400">
                            {{ $pendingMigrations }} update{{ $pendingMigrations === 1 ? '' : 's' }} waiting to be applied - click "Update database" after installing new files above.
                        </p>
                    @else
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Up to date - nothing waiting to be applied.
                        </p>
                    @endif
                </div>

                <x-filament::button
                    color="gray"
                    icon="heroicon-o-circle-stack"
                    wire:click="runMigrations"
                    wire:loading.attr="disabled"
                    wire:target="runMigrations"
                >
                    Update database
                </x-filament::button>
            </div>
        </div>
    </x-filament::section>

    <form wire:submit="saveAiSettings">
        {{ $this->aiForm }}

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <x-filament::button type="submit">
                Save AI settings
            </x-filament::button>

            <x-filament::button
                type="button"
                color="gray"
                wire:click="testAiConnection"
                wire:loading.attr="disabled"
                wire:target="testAiConnection"
            >
                Test AI connection
            </x-filament::button>

            @if ($aiTestResult)
                <span class="text-sm {{ $aiTestResult['ok'] ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400' }}">
                    {{ $aiTestResult['message'] }}
                </span>
            @endif
        </div>
    </form>

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
