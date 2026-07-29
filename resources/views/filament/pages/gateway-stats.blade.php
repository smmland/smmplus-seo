<x-filament-panels::page>
    <div class="flex gap-x-2">
        @foreach (['today' => 'Today', '7days' => 'Last 7 days', '30days' => 'Last 30 days', 'all' => 'All time'] as $value => $label)
            <x-filament::button
                :color="$period === $value ? 'primary' : 'gray'"
                wire:click="$set('period', '{{ $value }}')"
                size="sm"
            >
                {{ $label }}
            </x-filament::button>
        @endforeach
    </div>

    <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-7">
        @foreach ([
            'Total requests' => $this->summary['total'],
            'Successful' => $this->summary['success'],
            'Blocked' => $this->summary['blocked'],
            'Rate limited' => $this->summary['rate_limited'],
            'Upstream errors' => $this->summary['errors'],
            'Unique IPs' => $this->summary['unique_ips'],
            'Unique targets' => $this->summary['unique_targets'],
        ] as $label => $value)
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="text-2xl font-bold">{{ number_format($value) }}</div>
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Requests per service">
        <table class="fi-ta-table w-full text-start">
            <thead>
                <tr>
                    <th class="p-2 text-start text-sm font-semibold">Service</th>
                    <th class="p-2 text-end text-sm font-semibold">Total</th>
                    <th class="p-2 text-end text-sm font-semibold">Successful</th>
                    <th class="p-2 text-end text-sm font-semibold">Quantity fulfilled</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->perService as $row)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="p-2">{{ $row->service_slug }}</td>
                        <td class="p-2 text-end">{{ number_format($row->total) }}</td>
                        <td class="p-2 text-end">{{ number_format($row->successful) }}</td>
                        <td class="p-2 text-end">{{ number_format($row->quantity_fulfilled ?? 0) }}</td>
                    </tr>
                @empty
                    <tr><td class="p-2 text-gray-500" colspan="4">No requests in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Requests per API server">
        <table class="fi-ta-table w-full text-start">
            <thead>
                <tr>
                    <th class="p-2 text-start text-sm font-semibold">API server</th>
                    <th class="p-2 text-end text-sm font-semibold">Total</th>
                    <th class="p-2 text-end text-sm font-semibold">Successful</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->perUpstream as $row)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="p-2">{{ $row->name }}</td>
                        <td class="p-2 text-end">{{ number_format($row->total) }}</td>
                        <td class="p-2 text-end">{{ number_format($row->successful) }}</td>
                    </tr>
                @empty
                    <tr><td class="p-2 text-gray-500" colspan="3">No requests in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="Top IPs" description="Highest request counts in this period, capped at 50.">
        <table class="fi-ta-table w-full text-start">
            <thead>
                <tr>
                    <th class="p-2 text-start text-sm font-semibold">IP</th>
                    <th class="p-2 text-end text-sm font-semibold">Total</th>
                    <th class="p-2 text-end text-sm font-semibold">Successful</th>
                    <th class="p-2 text-end text-sm font-semibold">Services hit</th>
                    <th class="p-2 text-start text-sm font-semibold">Last seen</th>
                    <th class="p-2 text-end text-sm font-semibold">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->topIps as $row)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="p-2">{{ $row->ip }}</td>
                        <td class="p-2 text-end">{{ number_format($row->total) }}</td>
                        <td class="p-2 text-end">{{ number_format($row->successful) }}</td>
                        <td class="p-2 text-end">{{ number_format($row->services_hit) }}</td>
                        <td class="p-2">{{ \Illuminate\Support\Carbon::parse($row->last_seen)->diffForHumans() }}</td>
                        <td class="p-2 text-end">
                            @if ($row->is_blocked)
                                <x-filament::badge color="danger">Blocked</x-filament::badge>
                            @else
                                <x-filament::button
                                    color="danger"
                                    size="xs"
                                    wire:click="blockIp('{{ $row->ip }}')"
                                    wire:confirm="Block {{ $row->ip }} from using the free service gateway?"
                                >
                                    Block
                                </x-filament::button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="p-2 text-gray-500" colspan="6">No requests in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section heading="IP × Service breakdown" description="How many times each IP hit each service, capped at the top 100 pairs.">
        <table class="fi-ta-table w-full text-start">
            <thead>
                <tr>
                    <th class="p-2 text-start text-sm font-semibold">IP</th>
                    <th class="p-2 text-start text-sm font-semibold">Service</th>
                    <th class="p-2 text-end text-sm font-semibold">Hits</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->ipServiceBreakdown as $row)
                    <tr class="border-t border-gray-100 dark:border-white/5">
                        <td class="p-2">{{ $row->ip }}</td>
                        <td class="p-2">{{ $row->service_slug }}</td>
                        <td class="p-2 text-end">{{ number_format($row->hits) }}</td>
                    </tr>
                @empty
                    <tr><td class="p-2 text-gray-500" colspan="3">No requests in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-panels::page>
