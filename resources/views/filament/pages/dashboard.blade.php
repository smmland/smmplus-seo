<x-filament-panels::page>
    <x-filament::section>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-medium text-gray-950 dark:text-white">
                    {{ __('Welcome') }}, {{ filament()->getUserName(auth()->user()) }}
                </p>
            </div>

            <x-filament::button
                size="sm"
                :color="$customizing ? 'primary' : 'gray'"
                icon="heroicon-o-squares-2x2"
                wire:click="toggleCustomizing"
            >
                {{ $customizing ? 'Done' : 'Customize' }}
            </x-filament::button>
        </div>
    </x-filament::section>

    @php
        $keys = $this->visibleKeys();
        $hidden = $this->hiddenKeys();
    @endphp

    @if ($customizing && ! empty($hidden))
        <x-filament::section class="mt-4">
            <p class="mb-2 text-xs font-medium text-gray-600 dark:text-gray-300">Add a card</p>
            <div class="flex flex-wrap gap-2">
                @foreach ($hidden as $hiddenKey)
                    <x-filament::button
                        size="sm"
                        color="gray"
                        icon="heroicon-o-plus"
                        wire:click="addWidget('{{ $hiddenKey }}')"
                    >
                        {{ \Illuminate\Support\Str::of($hiddenKey)->replace('_', ' ')->ucfirst() }}
                    </x-filament::button>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    <div
        x-data="dashboardCardList($wire)"
        class="mt-4"
        style="display: flex; flex-direction: column; gap: 1rem;"
    >
        @foreach ($keys as $key)
            <div
                data-key="{{ $key }}"
                @if ($customizing)
                    draggable="true"
                    x-on:dragstart="onDragStart($event)"
                    x-on:dragover="onDragOver($event)"
                    x-on:drop="onDrop($event)"
                    x-on:dragend="onDragEnd($event)"
                @endif
                style="{{ $customizing ? 'border: 1px dashed rgba(148,163,184,.5); border-radius: .75rem; padding: 6px;' : '' }}"
            >
                @if ($customizing)
                    <div class="mb-2 flex items-center justify-between px-1">
                        <span class="cursor-move text-xs text-gray-400 dark:text-gray-500" style="user-select: none;">
                            ⠿ {{ \Illuminate\Support\Str::of($key)->replace('_', ' ')->ucfirst() }}
                        </span>

                        <x-filament::icon-button
                            icon="heroicon-o-x-mark"
                            color="danger"
                            size="sm"
                            label="Remove"
                            tooltip="Remove this card"
                            wire:click="removeWidget('{{ $key }}')"
                        />
                    </div>
                @endif

                @livewire(\App\Filament\Pages\Dashboard::WIDGET_REGISTRY[$key], key("dashboard-widget-{$key}"))
            </div>
        @endforeach
    </div>

    <script>
        function dashboardCardList(wire) {
            return {
                dragged: null,
                onDragStart(event) {
                    this.dragged = event.currentTarget;
                    event.dataTransfer.effectAllowed = 'move';
                },
                onDragOver(event) {
                    event.preventDefault();

                    const target = event.currentTarget;

                    if (!this.dragged || target === this.dragged) {
                        return;
                    }

                    const container = target.parentElement;
                    const children = Array.from(container.children);
                    const draggedIndex = children.indexOf(this.dragged);
                    const targetIndex = children.indexOf(target);

                    if (draggedIndex < targetIndex) {
                        target.after(this.dragged);
                    } else {
                        target.before(this.dragged);
                    }
                },
                onDrop(event) {
                    event.preventDefault();
                },
                onDragEnd(event) {
                    if (!this.dragged) {
                        return;
                    }

                    const order = Array.from(this.dragged.parentElement.children).map((el) => el.dataset.key);
                    this.dragged = null;
                    wire.reorderWidgets(order);
                },
            };
        }
    </script>
</x-filament-panels::page>
