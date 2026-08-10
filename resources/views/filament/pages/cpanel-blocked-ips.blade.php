<x-filament-panels::page>
    <x-filament::section
        heading="cPanel's own blocked IP list"
        description="Read directly from your cPanel account's .htaccess (Security Settings), since cPanel's own API has no function to list what its IP Blocker has blocked - only add and remove. Can include IPs blocked directly in cPanel outside this panel too."
    >
        <div class="mb-3">
            <x-filament::button icon="heroicon-o-arrow-path" color="gray" wire:click="refresh">
                Refresh
            </x-filament::button>
        </div>

        @php($result = $this->result)

        @if (! $result['ok'])
            <div class="rounded-lg p-3 text-sm" style="background-color: rgba(var(--danger-500), .1); color: rgb(var(--danger-700))">
                Couldn't read the list from cPanel: {{ $result['error'] }}
            </div>
        @elseif (empty($result['ips']))
            <p class="text-sm text-gray-500 dark:text-gray-400">cPanel isn't currently blocking any IPs.</p>
        @else
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead>
                        <tr>
                            <th class="p-2 text-start text-sm font-semibold">IP</th>
                            <th class="p-2 text-start text-sm font-semibold">Note (this panel)</th>
                            <th class="p-2 text-start text-sm font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($result['ips'] as $ip)
                            <tr wire:key="cpanel-blocked-{{ $ip }}" class="border-t border-gray-100 dark:border-white/5 align-top">
                                <td class="p-2 text-sm font-medium text-gray-950 dark:text-white">
                                    {{ $ip }}
                                </td>
                                <td class="p-2 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $this->noteFor($ip) ?? '-' }}
                                </td>
                                <td class="p-2">
                                    <x-filament::button
                                        size="xs"
                                        color="danger"
                                        outlined
                                        icon="heroicon-o-lock-open"
                                        wire:click="unblock('{{ $ip }}')"
                                        wire:confirm="Unblock {{ $ip }} at cPanel?"
                                    >
                                        Unblock
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
