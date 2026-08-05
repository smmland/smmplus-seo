<x-filament-panels::page>
    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <x-filament::button type="submit">
                Save
            </x-filament::button>

            <x-filament::button
                type="button"
                color="gray"
                wire:click="testConnection"
            >
                Test connection
            </x-filament::button>

            <x-filament::button
                type="button"
                color="gray"
                wire:click="resetBlogSummaryPromptToDefault"
            >
                Reset blog prompt to default
            </x-filament::button>

            <x-filament::button
                type="button"
                color="gray"
                wire:click="resetServiceAnnouncementPromptToDefault"
            >
                Reset service announcement prompt to default
            </x-filament::button>
        </div>

        <div class="mt-4 rounded-lg border border-gray-200 p-3 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
            <p class="mb-1 font-medium text-gray-600 dark:text-gray-300">Placeholders supported in the blog summary prompt above:</p>
            <div class="flex flex-wrap gap-x-4 gap-y-1">
                @foreach (\App\Services\TelegramContentAiService::BLOG_SUMMARY_PLACEHOLDERS as $token => $description)
                    <span><code class="rounded bg-gray-100 px-1 dark:bg-white/10">{{ $token }}</code> {{ $description }}</span>
                @endforeach
            </div>
        </div>

        <div class="mt-3 rounded-lg border border-gray-200 p-3 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
            <p class="mb-1 font-medium text-gray-600 dark:text-gray-300">Placeholders supported in the service announcement prompt above:</p>
            <div class="flex flex-wrap gap-x-4 gap-y-1">
                @foreach (\App\Services\TelegramContentAiService::SERVICE_ANNOUNCEMENT_PLACEHOLDERS as $token => $description)
                    <span><code class="rounded bg-gray-100 px-1 dark:bg-white/10">{{ $token }}</code> {{ $description }}</span>
                @endforeach
            </div>
        </div>
    </form>
</x-filament-panels::page>
