<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex flex-wrap gap-2">
                @foreach (['today' => 'Today', '7days' => '7 days', '30days' => '30 days', '90days' => '90 days', 'all' => 'All time'] as $value => $label)
                    <x-filament::button
                        :color="$period === $value ? 'primary' : 'gray'"
                        wire:click="$set('period', '{{ $value }}')"
                        size="sm"
                    >
                        {{ $label }}
                    </x-filament::button>
                @endforeach
            </div>

            <div class="grid w-full gap-3 sm:grid-cols-2 lg:w-auto xl:grid-cols-5">
                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                    <span class="mb-1 block">Language</span>
                    <select wire:model.live="language" class="fi-input block w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        @foreach ($this->languageOptions as $value => $label)
                            <option value="{{ $value }}">{{ strtoupper($label) }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                    <span class="mb-1 block">Device</span>
                    <select wire:model.live="device" class="fi-input block w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="all">All devices</option>
                        <option value="desktop">Desktop</option>
                        <option value="mobile">Mobile</option>
                        <option value="tablet">Tablet</option>
                    </select>
                </label>

                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                    <span class="mb-1 block">Country</span>
                    <select wire:model.live="country" class="fi-input block w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        @foreach ($this->countryOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                    <span class="mb-1 block">Audience</span>
                    <select wire:model.live="userState" class="fi-input block w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="all">All visitors</option>
                        <option value="guest">Guests</option>
                        <option value="authenticated">Logged in</option>
                        <option value="internal">Internal team</option>
                        <option value="unknown">Legacy / unknown</option>
                    </select>
                </label>

                <label class="text-sm font-medium text-gray-700 dark:text-gray-200">
                    <span class="mb-1 block">Currency</span>
                    <select wire:model.live="currency" class="fi-input block w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        @foreach ($this->currencyOptions as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
        </div>
    </x-filament::section>

    <div class="grid grid-cols-2 gap-4 md:grid-cols-4 xl:grid-cols-7">
        @foreach ([
            ['Page views', number_format($this->summary['page_views']), null],
            ['Visitors', number_format($this->summary['visitors']), null],
            ['Sessions', number_format($this->summary['sessions']), null],
            ['Engagement rate', $this->summary['engagement_rate'].'%', 'Sessions with 10s engagement, 2+ pages, or a conversion'],
            ['Bounce rate', $this->summary['bounce_rate'].'%', 'The inverse of engaged sessions'],
            ['Avg. engagement', $this->summary['average_engagement_seconds'].'s', 'Active foreground time'],
            ['Conversions', number_format($this->summary['conversions']), null],
        ] as [$label, $value, $hint])
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                <div class="mt-1 text-2xl font-bold">{{ $value }}</div>
                @if ($hint)
                    <div class="mt-1 text-xs leading-4 text-gray-400">{{ $hint }}</div>
                @endif
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section
        heading="Purchases and revenue"
        description="Verified server-to-server order data. Revenue is never accepted from tracker.js; refunds reduce net revenue and repeated order updates are idempotent."
    >
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
            @foreach ([
                ['Purchases', number_format($this->purchaseSummary['purchases']), 'Paid orders, including later refunds'],
                ['Gross revenue', $this->purchaseSummary['gross'], 'Before refunds'],
                ['Refunds', $this->purchaseSummary['refunds'], 'Full and partial refunds'],
                ['Net revenue', $this->purchaseSummary['net'], 'Gross minus refunds'],
                ['Avg. order value', $this->purchaseSummary['average'], 'Net revenue per purchase'],
                ['Purchase rate', $this->purchaseSummary['conversion_rate'].'%', 'Purchases divided by tracked sessions'],
            ] as [$label, $value, $hint])
                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div class="text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                    <div class="mt-1 break-words text-xl font-bold">{{ $value }}</div>
                    <div class="mt-1 text-xs leading-4 text-gray-400">{{ $hint }}</div>
                </div>
            @endforeach
        </div>

        <div class="mt-6 grid gap-6 xl:grid-cols-2">
            <div>
                <h3 class="mb-2 text-sm font-semibold text-gray-800 dark:text-gray-100">Revenue by acquisition source</h3>
                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full text-start">
                        <thead><tr>
                            <th class="p-2 text-start text-sm font-semibold">Source / medium</th>
                            <th class="p-2 text-end text-sm font-semibold">Purchases</th>
                            <th class="p-2 text-end text-sm font-semibold">Net revenue</th>
                        </tr></thead>
                        <tbody>
                            @forelse ($this->purchaseSources as $row)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="p-2">{{ $row->source ?: 'Unattributed' }} / {{ $row->medium ?: '—' }}</td>
                                    <td class="p-2 text-end">{{ number_format($row->purchases) }}</td>
                                    <td class="p-2 text-end">{{ number_format($row->net, 2) }} {{ $row->currency }}</td>
                                </tr>
                            @empty
                                <tr><td class="p-4 text-center text-gray-500" colspan="3">No verified purchases for this period yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>

            <div>
                <h3 class="mb-2 text-sm font-semibold text-gray-800 dark:text-gray-100">Revenue by landing page</h3>
                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full text-start">
                        <thead><tr>
                            <th class="p-2 text-start text-sm font-semibold">Landing page</th>
                            <th class="p-2 text-end text-sm font-semibold">Purchases</th>
                            <th class="p-2 text-end text-sm font-semibold">Net revenue</th>
                        </tr></thead>
                        <tbody>
                            @forelse ($this->purchaseLandingPages as $row)
                                <tr class="border-t border-gray-100 dark:border-white/5">
                                    <td class="max-w-sm truncate p-2" title="{{ $row->landing_page }}">{{ $row->landing_page ?: 'Unattributed' }}</td>
                                    <td class="p-2 text-end">{{ number_format($row->purchases) }}</td>
                                    <td class="p-2 text-end">{{ number_format($row->net, 2) }} {{ $row->currency }}</td>
                                </tr>
                            @empty
                                <tr><td class="p-4 text-center text-gray-500" colspan="3">Landing-page revenue appears after the order backend sends analytics session IDs.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mt-6">
            <h3 class="mb-2 text-sm font-semibold text-gray-800 dark:text-gray-100">Recent purchases</h3>
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead><tr>
                        <th class="p-2 text-start text-sm font-semibold">Order</th>
                        <th class="p-2 text-start text-sm font-semibold">Status</th>
                        <th class="p-2 text-end text-sm font-semibold">Gross</th>
                        <th class="p-2 text-end text-sm font-semibold">Refunded</th>
                        <th class="p-2 text-end text-sm font-semibold">Net</th>
                        <th class="p-2 text-start text-sm font-semibold">Source</th>
                        <th class="p-2 text-start text-sm font-semibold">Paid at</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($this->recentPurchases as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="p-2 font-medium">{{ $row->external_order_id }}</td>
                                <td class="p-2"><x-filament::badge :color="$row->status === 'paid' ? 'success' : ($row->status === 'refunded' ? 'danger' : 'warning')">{{ str($row->status)->replace('_', ' ')->title() }}</x-filament::badge></td>
                                <td class="p-2 text-end">{{ number_format($row->gross_amount, 2) }} {{ $row->currency }}</td>
                                <td class="p-2 text-end">{{ number_format($row->refunded_amount, 2) }} {{ $row->currency }}</td>
                                <td class="p-2 text-end font-semibold">{{ number_format($row->gross_amount - $row->refunded_amount, 2) }} {{ $row->currency }}</td>
                                <td class="p-2">{{ $row->source ?: 'Unattributed' }}</td>
                                <td class="whitespace-nowrap p-2">{{ $row->paid_at->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr><td class="p-4 text-center text-gray-500" colspan="7">No verified purchases for this period yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section heading="Audience by login status" description="Identity-free comparison of guests, signed-in customers, and internal traffic. Historical events recorded before this feature remain Unknown.">
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach (['guest' => 'Guests', 'authenticated' => 'Logged in', 'internal' => 'Internal team', 'unknown' => 'Legacy / unknown'] as $state => $label)
                @php($row = $this->audienceBreakdown->get($state))
                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div class="text-sm font-semibold text-gray-700 dark:text-gray-200">{{ $label }}</div>
                    <div class="mt-2 text-2xl font-bold">{{ number_format($row?->views ?? 0) }}</div>
                    <div class="mt-1 text-xs text-gray-500">
                        {{ number_format($row?->visitors ?? 0) }} visitors · {{ number_format($row?->sessions ?? 0) }} sessions
                    </div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Analytics API security" description="Active safeguards for the public browser telemetry endpoint." collapsible collapsed>
        <div class="grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-3">
            @foreach ([
                ['Origin and proxy trust', 'Only configured smm.plus origins receive CORS access; forwarding headers are trusted only from official Cloudflare networks.'],
                ['Abuse limits', '32 KB payload, 25 events per batch, 120 requests/minute and 10,000 events/hour per resolved IP.'],
                ['Strict schema', 'Unknown fields, event types, audience states and nested metadata are rejected.'],
                ['Privacy redaction', 'Raw IPs, authenticated page titles, click text, tokens, email-like campaign values and phone-like campaign values are not stored.'],
                ['Replay resistance', 'Event UUIDs are unique, so repeated event submissions are ignored.'],
                ['Script integrity', 'The website pins the tracker with SHA-384 Subresource Integrity and anonymous CORS.'],
                ['Financial integrity', 'Purchase amounts and refunds use a separate HMAC-signed server webhook; browser-submitted revenue is never trusted.'],
            ] as [$label, $description])
                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div class="flex items-center gap-2 font-semibold text-gray-800 dark:text-gray-100">
                        <span class="h-2.5 w-2.5 rounded-full bg-success-500"></span>{{ $label }}
                    </div>
                    <div class="mt-2 leading-5 text-gray-500 dark:text-gray-400">{{ $description }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section heading="Page performance" description="Use weak engagement or scroll depth to find pages whose content, internal links, or search intent need work.">
        <div class="overflow-x-auto">
            <table class="fi-ta-table w-full text-start">
                <thead>
                    <tr>
                        <th class="p-2 text-start text-sm font-semibold">Page</th>
                        <th class="p-2 text-end text-sm font-semibold">Views</th>
                        <th class="p-2 text-end text-sm font-semibold">Visitors</th>
                        <th class="p-2 text-end text-sm font-semibold">Entrances</th>
                        <th class="p-2 text-end text-sm font-semibold">Exits</th>
                        <th class="p-2 text-end text-sm font-semibold">Engagement</th>
                        <th class="p-2 text-end text-sm font-semibold">Scroll</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->topPages as $row)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="max-w-md truncate p-2 font-medium" title="{{ $row->page_path }}">{{ $row->page_path }}</td>
                            <td class="p-2 text-end">{{ number_format($row->views) }}</td>
                            <td class="p-2 text-end">{{ number_format($row->visitors) }}</td>
                            <td class="p-2 text-end">{{ number_format($row->entrances) }}</td>
                            <td class="p-2 text-end">{{ number_format($row->exits) }}</td>
                            <td class="p-2 text-end">{{ number_format(($row->avg_engagement_ms ?? 0) / 1000, 1) }}s</td>
                            <td class="p-2 text-end">{{ number_format($row->avg_scroll ?? 0, 0) }}%</td>
                        </tr>
                    @empty
                        <tr><td class="p-4 text-center text-gray-500" colspan="7">No analytics data for this period yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <div class="grid gap-6 xl:grid-cols-3">
        <x-filament::section heading="Landing traffic sources" description="Original attribution is kept for the whole visit, including internal navigation.">
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead><tr>
                        <th class="p-2 text-start text-sm font-semibold">Source</th>
                        <th class="p-2 text-start text-sm font-semibold">Medium</th>
                        <th class="p-2 text-end text-sm font-semibold">Sessions</th>
                        <th class="p-2 text-end text-sm font-semibold">Visitors</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($this->topSources as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="p-2">{{ $row->source ?: 'unknown' }}</td>
                                <td class="p-2">{{ $row->medium ?: '—' }}</td>
                                <td class="p-2 text-end">{{ number_format($row->sessions) }}</td>
                                <td class="p-2 text-end">{{ number_format($row->visitors) }}</td>
                            </tr>
                        @empty
                            <tr><td class="p-4 text-center text-gray-500" colspan="4">No landing sessions yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Languages" description="Compare translated versions to identify high-traffic languages with weak content coverage.">
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead><tr>
                        <th class="p-2 text-start text-sm font-semibold">Language</th>
                        <th class="p-2 text-end text-sm font-semibold">Views</th>
                        <th class="p-2 text-end text-sm font-semibold">Visitors</th>
                        <th class="p-2 text-end text-sm font-semibold">Sessions</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($this->languageBreakdown as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="p-2 font-semibold uppercase">{{ $row->language ?: '—' }}</td>
                                <td class="p-2 text-end">{{ number_format($row->views) }}</td>
                                <td class="p-2 text-end">{{ number_format($row->visitors) }}</td>
                                <td class="p-2 text-end">{{ number_format($row->sessions) }}</td>
                            </tr>
                        @empty
                            <tr><td class="p-4 text-center text-gray-500" colspan="4">No language data yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section heading="Countries" description="Country comes from Cloudflare's edge header; the visitor's raw IP is never retained.">
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead><tr>
                        <th class="p-2 text-start text-sm font-semibold">Country</th>
                        <th class="p-2 text-end text-sm font-semibold">Views</th>
                        <th class="p-2 text-end text-sm font-semibold">Visitors</th>
                        <th class="p-2 text-end text-sm font-semibold">Sessions</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($this->countryBreakdown as $row)
                            <tr class="border-t border-gray-100 dark:border-white/5">
                                <td class="p-2 font-semibold uppercase">{{ $row->country_code ?: 'Unknown' }}</td>
                                <td class="p-2 text-end">{{ number_format($row->views) }}</td>
                                <td class="p-2 text-end">{{ number_format($row->visitors) }}</td>
                                <td class="p-2 text-end">{{ number_format($row->sessions) }}</td>
                            </tr>
                        @empty
                            <tr><td class="p-4 text-center text-gray-500" colspan="4">No country data yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    </div>

    <div class="grid gap-6 xl:grid-cols-2">
        <x-filament::section heading="SEO conversions" description="Key actions such as sign-up, free service, login and order starts.">
            <table class="fi-ta-table w-full text-start">
                <thead><tr>
                    <th class="p-2 text-start text-sm font-semibold">Action</th>
                    <th class="p-2 text-end text-sm font-semibold">Events</th>
                    <th class="p-2 text-end text-sm font-semibold">Sessions</th>
                </tr></thead>
                <tbody>
                    @forelse ($this->conversions as $row)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="p-2">{{ str($row->target ?: 'unknown')->replace('_', ' ')->title() }}</td>
                            <td class="p-2 text-end">{{ number_format($row->total) }}</td>
                            <td class="p-2 text-end">{{ number_format($row->sessions) }}</td>
                        </tr>
                    @empty
                        <tr><td class="p-4 text-center text-gray-500" colspan="3">No conversion events yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section heading="Core Web Vitals" description="Field measurements from real visitors. LCP, INP, FCP and TTFB are milliseconds; CLS has no unit.">
            <table class="fi-ta-table w-full text-start">
                <thead><tr>
                    <th class="p-2 text-start text-sm font-semibold">Metric</th>
                    <th class="p-2 text-end text-sm font-semibold">Average</th>
                    <th class="p-2 text-end text-sm font-semibold">Samples</th>
                    <th class="p-2 text-end text-sm font-semibold">Status</th>
                </tr></thead>
                <tbody>
                    @forelse ($this->webVitals as $row)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="p-2 font-semibold">{{ $row->target }}</td>
                            <td class="p-2 text-end">{{ $row->target === 'CLS' ? number_format($row->average, 3) : number_format($row->average, 0).' ms' }}</td>
                            <td class="p-2 text-end">{{ number_format($row->samples) }}</td>
                            <td class="p-2 text-end">
                                <x-filament::badge :color="$row->status === 'Good' ? 'success' : 'warning'">{{ $row->status }}</x-filament::badge>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="p-4 text-center text-gray-500" colspan="4">Web Vital samples arrive after real page visits.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>
    </div>

    <x-filament::section heading="404 pages" description="Fix these URLs with a redirect or an internal-link correction. Query strings are intentionally excluded.">
        <div class="overflow-x-auto">
            <table class="fi-ta-table w-full text-start">
                <thead><tr>
                    <th class="p-2 text-start text-sm font-semibold">Missing path</th>
                    <th class="p-2 text-end text-sm font-semibold">Views</th>
                    <th class="p-2 text-start text-sm font-semibold">Last seen</th>
                </tr></thead>
                <tbody>
                    @forelse ($this->notFoundPages as $row)
                        <tr class="border-t border-gray-100 dark:border-white/5">
                            <td class="p-2 font-medium">{{ $row->page_path }}</td>
                            <td class="p-2 text-end">{{ number_format($row->views) }}</td>
                            <td class="p-2">{{ \Illuminate\Support\Carbon::parse($row->last_seen)->diffForHumans() }}</td>
                        </tr>
                    @empty
                        <tr><td class="p-4 text-center text-gray-500" colspan="3">No 404 visits recorded in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
