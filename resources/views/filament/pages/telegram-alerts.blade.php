<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-3">
            <x-filament::button type="submit">
                Save
            </x-filament::button>
        </div>
    </form>

    <x-filament::section
        heading="Recipients"
        description="Whoever's added here gets DMed by the bot, once they've opened their own link below and pressed Start in Telegram - the bot can't message someone who hasn't messaged it first."
        class="mt-4"
    >
        @if (! $this->recipientsTableReady)
            <div class="rounded-lg p-3 text-sm" style="background-color: rgba(var(--danger-500), .1); color: rgb(var(--danger-700))">
                This feature needs a database update first - go to General Settings and click "Update database", then reload this page.
            </div>
        @else
            <div class="mb-3 flex flex-wrap items-end gap-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Name</label>
                    <input
                        type="text"
                        wire:model="newRecipientLabel"
                        wire:keydown.enter="addRecipient"
                        placeholder="e.g. Ali"
                        class="fi-input block rounded-lg border-0 py-1.5 text-sm text-gray-950 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10"
                    >
                </div>

                <x-filament::button icon="heroicon-o-plus" wire:click="addRecipient">
                    Add recipient
                </x-filament::button>
            </div>

            @if (! $this->botUsername())
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Set up a bot token on Telegram Channel &gt; Settings first - the link below needs it to know which bot to connect to.
                </p>
            @endif

            @if ($this->recipients->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">No recipients yet - add one above.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full text-start">
                        <thead>
                            <tr>
                                <th class="p-2 text-start text-sm font-semibold">Name</th>
                                <th class="p-2 text-start text-sm font-semibold">Status</th>
                                <th class="p-2 text-start text-sm font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->recipients as $recipient)
                                <tr wire:key="recipient-{{ $recipient->id }}" class="border-t border-gray-100 dark:border-white/5 align-top">
                                    <td class="p-2 text-sm font-medium text-gray-950 dark:text-white">
                                        {{ $recipient->label }}
                                    </td>
                                    <td class="p-2 text-sm">
                                        @if ($recipient->isLinked())
                                            <x-filament::badge color="success" size="xs">
                                                Connected{{ $recipient->telegram_username ? ' as @'.$recipient->telegram_username : '' }}
                                            </x-filament::badge>
                                        @elseif ($this->botUsername())
                                            <x-filament::badge color="warning" size="xs">Waiting to connect</x-filament::badge>
                                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                <a href="{{ $this->linkUrlFor($recipient) }}" target="_blank" class="text-primary-600 dark:text-primary-400" style="text-decoration: underline;">
                                                    {{ $this->linkUrlFor($recipient) }}
                                                </a>
                                            </div>
                                        @else
                                            <x-filament::badge color="gray" size="xs">Waiting to connect</x-filament::badge>
                                        @endif
                                    </td>
                                    <td class="p-2">
                                        <x-filament::icon-button
                                            icon="heroicon-o-trash"
                                            color="danger"
                                            size="sm"
                                            label="Remove"
                                            tooltip="Remove"
                                            wire:click="removeRecipient({{ $recipient->id }})"
                                            wire:confirm="Remove {{ $recipient->label }} from alerts?"
                                        />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
