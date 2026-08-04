<x-filament-panels::page>
    <x-filament::section heading="Panel updates" description="Install a new version of the panel and apply any database changes it needs - no server terminal required for either.">
        @php $panelVersion = $this->panelVersion(); @endphp
        <div class="mb-1 flex items-center gap-2 text-sm">
            <span class="text-gray-500 dark:text-gray-400">Current version:</span>
            @if ($panelVersion)
                <x-filament::badge color="gray">{{ $panelVersion }}</x-filament::badge>
            @else
                <span class="text-xs text-gray-400 dark:text-gray-500">unknown - not set until the first update carrying a version is installed</span>
            @endif
        </div>

        @php $panelNotes = $this->panelNotes(); @endphp
        @if ($panelNotes)
            <div class="mb-4 mt-3 rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <p class="text-xs font-medium text-gray-400 dark:text-gray-500">What changed in this version</p>
                <p class="mt-1 whitespace-pre-line text-sm text-gray-700 dark:text-gray-200">{{ $panelNotes }}</p>
            </div>
        @else
            <div class="mb-4"></div>
        @endif

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
                    {{-- h-2/h-full aren't in Filament's pre-built CSS bundle (never compiled -
                         see the note on blog-translation-queue.blade.php's progress bar for the
                         fuller story on this class of bug) - height is set inline instead. --}}
                    <div class="w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" style="height: 8px">
                        <div class="rounded-full bg-primary-500 transition-all duration-150" :style="`width: ${progress}%; height: 8px`"></div>
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
</x-filament-panels::page>
