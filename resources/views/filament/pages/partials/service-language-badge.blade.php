{{-- Shared by the description and title badge rows in service-translation-queue.blade.php -
     $stateKey picks which of $language's two independent states ('state' for description,
     'titleState' for title) this particular badge renders, so the exact same markup/icons stay
     visually identical between the two rows. --}}
@php
    $state = $language[$stateKey];
    $retranslatedKey = $stateKey === 'titleState' ? 'titleRetranslated' : 'retranslated';
    $retranslated = $language[$retranslatedKey] ?? false;
    $tooltip = $language['name'].': '.match ($state) {
        'default' => 'Default language',
        'pending' => 'Translating…',
        'error' => 'Last translation attempt failed',
        'missing' => 'Not translated',
        'needsUpdate' => 'Translated - not uploaded to the site yet',
        'confirmed' => 'Uploaded - confirmed live on the site',
    };
    if ($retranslated) {
        $tooltip .= ' · Automatically re-translated recently - the source content changed';
    }
@endphp
<span
    @class([
        'inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset dark:text-gray-300',
        'ring-gray-950/10 dark:ring-white/10' => ! $retranslated,
    ])
    @style($retranslated ? ['box-shadow' => 'inset 0 0 0 1px rgb(var(--primary-500))'] : [])
    title="{{ $tooltip }}"
>
    {{ strtoupper($language['code']) }}
    @if ($retranslated)
        <x-filament::icon icon="heroicon-m-arrow-path" class="h-3 w-3" style="color: rgb(var(--primary-600))" />
    @endif
    @switch($state)
        @case('default')
            <x-filament::icon icon="heroicon-m-star" class="h-3 w-3" style="color: rgb(var(--warning-500))" />
            @break
        @case('pending')
            <x-filament::loading-indicator class="h-3 w-3" />
            @break
        @case('error')
            <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-3 w-3" style="color: rgb(var(--danger-600))" />
            @break
        @case('needsUpdate')
            <x-filament::icon icon="heroicon-m-arrow-up-tray" class="h-3 w-3" style="color: rgb(var(--warning-600))" />
            @break
        @case('confirmed')
            {{-- Two ticks side by side (like a messaging app's "delivered" mark) - distinct at a
                 glance from the single-tick "needsUpdate" state right above it. --}}
            <span class="inline-flex" style="color: rgb(var(--success-600))">
                <x-filament::icon icon="heroicon-m-check" class="h-3 w-3" />
                <x-filament::icon icon="heroicon-m-check" class="h-3 w-3" style="margin-left: -6px" />
            </span>
            @break
        @default
            <x-filament::icon icon="heroicon-m-x-circle" class="h-3 w-3 opacity-50" />
    @endswitch
</span>
