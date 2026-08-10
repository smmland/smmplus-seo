<div
    wire:poll.30s
    class="hidden items-center gap-x-3 text-xs text-gray-500 dark:text-gray-400 lg:flex"
    style="white-space: nowrap;"
>
    @if ($load !== null)
        <span title="1-minute server load average">CPU {{ $load }}</span>
    @endif

    @if ($memoryPercent !== null)
        <span title="Server RAM in use">RAM {{ $memoryPercent }}%</span>
    @endif
</div>
