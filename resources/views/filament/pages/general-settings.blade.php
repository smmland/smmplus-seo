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
        @php $panelVersion = $this->panelVersion(); @endphp
        <div class="mb-4 flex items-center gap-2 text-sm">
            <span class="text-gray-500 dark:text-gray-400">Current version:</span>
            @if ($panelVersion)
                <x-filament::badge color="gray">{{ $panelVersion }}</x-filament::badge>
            @else
                <span class="text-xs text-gray-400 dark:text-gray-500">unknown - not set until the first update carrying a version is installed</span>
            @endif
        </div>

        <div class="space-y-5">
            <div
                x-data="{
                    uploading: false,
                    progress: 0,
                    fileName: '',
                    fileSizeBytes: 0,
                    uploadError: null,
                    formatBytes(bytes) {
                        if (!bytes) return '0 B';
                        const units = ['B', 'KB', 'MB', 'GB'];
                        let n = bytes, i = 0;
                        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
                        return n.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
                    },
                }"
                x-on:livewire-upload-start="uploading = true; progress = 0; uploadError = null"
                x-on:livewire-upload-finish="uploading = false; progress = 100"
                x-on:livewire-upload-cancel="uploading = false"
                x-on:livewire-upload-error="uploading = false; uploadError = 'Upload failed - the connection may have been interrupted, or this server\'s PHP upload limits (upload_max_filesize / post_max_size) may be smaller than this file. Try again; if it keeps failing, that server setting is the most likely cause.'"
                x-on:livewire-upload-progress="progress = $event.detail.progress"
                class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10"
            >
                <p class="text-sm font-medium text-gray-950 dark:text-white">Install from zip</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    Upload the update zip file exactly as sent - it's installed over the panel's own files. Existing files are overwritten; nothing outside the zip is deleted, and <code class="rounded bg-gray-100 px-1 dark:bg-white/10">storage/</code> and <code class="rounded bg-gray-100 px-1 dark:bg-white/10">.env</code> are never touched. Refused if a background translation is currently queued or running, to keep the file swap from interrupting it mid-job. Also refused if the zip doesn't carry this panel's own update signature - so accidentally uploading the wrong file (a backup, an old download) is rejected outright instead of being extracted.
                </p>

                <div class="mt-3 flex flex-wrap items-center gap-3">
                    <input
                        type="file"
                        accept=".zip"
                        wire:model="updateZip"
                        @change="fileName = $event.target.files[0]?.name || ''; fileSizeBytes = $event.target.files[0]?.size || 0"
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

                {{-- Real upload progress from Livewire's own JS upload events (livewire-upload-*),
                     not a fake/indeterminate spinner - progress carries a 0-100 percentage, which
                     combined with the file's own byte size (read client-side the moment it's
                     picked) gives an actual "X of Y uploaded" readout. --}}
                <div x-show="uploading" x-cloak class="mt-3">
                    <div class="mb-1 flex items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                        <span class="truncate" x-text="fileName"></span>
                        <span class="shrink-0" x-text="formatBytes(fileSizeBytes * progress / 100) + ' / ' + formatBytes(fileSizeBytes) + ' (' + progress + '%)'"></span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                        <div class="h-full rounded-full bg-primary-500 transition-all duration-150" :style="`width: ${progress}%`"></div>
                    </div>
                </div>

                <p x-show="uploadError" x-cloak x-text="uploadError" class="mt-2 text-xs text-danger-600 dark:text-danger-400"></p>

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

    <x-filament::section heading="AI Costs" description="Estimated spend on AI translation calls, based on approximate published per-model pricing - actual provider invoices may differ slightly.">
        @php $aiCosts = $this->getAiCostStats(); @endphp

        @if (! $aiCosts['available'])
            <p class="text-sm text-gray-500 dark:text-gray-400">
                This needs a database update first - go to "Panel updates" above and click "Update database".
            </p>
        @else
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

            @if ($aiCosts['byTopic']->isNotEmpty())
                <div class="mt-3 overflow-auto rounded-xl ring-1 ring-gray-950/5 dark:ring-white/10">
                    <table class="w-full text-start text-xs">
                        <thead>
                            <tr class="bg-gray-50 text-gray-500 dark:bg-white/5 dark:text-gray-400">
                                <th class="p-2 text-start font-medium">Blog article</th>
                                <th class="p-2 text-end font-medium">Translations</th>
                                <th class="p-2 text-end font-medium">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($aiCosts['byTopic'] as $topic)
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

                @if ($aiCosts['lastPage'] > 1)
                    <div class="mt-3 flex items-center justify-between gap-3">
                        <p class="text-xs text-gray-400 dark:text-gray-500">
                            Page {{ $aiCosts['page'] }} of {{ $aiCosts['lastPage'] }} ({{ $aiCosts['totalTopics'] }} topic{{ $aiCosts['totalTopics'] === 1 ? '' : 's' }})
                        </p>
                        <div class="flex items-center gap-2">
                            <x-filament::button
                                size="sm"
                                color="gray"
                                icon="heroicon-o-chevron-left"
                                :disabled="$aiCosts['page'] <= 1"
                                wire:click="previousAiCostsPage"
                            >
                                Previous
                            </x-filament::button>
                            <x-filament::button
                                size="sm"
                                color="gray"
                                icon="heroicon-o-chevron-right"
                                icon-position="after"
                                :disabled="$aiCosts['page'] >= $aiCosts['lastPage']"
                                wire:click="nextAiCostsPage"
                            >
                                Next
                            </x-filament::button>
                        </div>
                    </div>
                @endif
            @else
                <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">No AI translations have run yet.</p>
            @endif
        @endif
    </x-filament::section>

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
