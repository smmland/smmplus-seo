<div
    wire:poll.30s
    class="hidden items-center gap-x-1 text-xs lg:flex"
    style="white-space: nowrap;"
>
    @if ($total !== null)
        <span
            title="Gateway requests in the last minute ({{ $blocked }} blocked)"
            @style([
                'color: rgb(var(--danger-600))' => $underAttack,
                'color: rgb(var(--warning-600))' => ! $underAttack && $blocked > 0,
            ])
            class="{{ ! $underAttack && $blocked === 0 ? 'text-gray-500 dark:text-gray-400' : '' }}"
        >
            Gateway {{ $total }}@if ($blocked > 0) ({{ $blocked }} blocked)@endif
        </span>
    @endif
</div>
