<x-filament-panels::page>
    @if (! $this->isConfigured(app(\App\Services\AdminAutomationSettingsService::class)))
        <x-filament::section>
            <p class="text-sm text-warning-600 dark:text-warning-400">
                Admin username/password and the automation service URL/token aren't fully configured yet.
                Set them on the <a href="{{ \App\Filament\Pages\TranslationSettings::getUrl() }}" class="underline">Translation &rarr; Settings</a> page first.
            </p>
        </x-filament::section>
    @endif

    <x-filament::section heading="Panel login">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            Logs in to the configured admin panel, auto-solving the hCaptcha checkbox when one is shown, then opens
            the Blog page under Appearance to confirm access. If hCaptcha pops an interactive challenge, a live view
            appears below so you can solve it yourself.
        </p>

        <div class="mt-4 flex items-center gap-3">
            <x-filament::button
                wire:click="start"
                wire:loading.attr="disabled"
                :disabled="! $this->isTerminal()"
            >
                Login
            </x-filament::button>

            @if ($sessionId && ! $this->isTerminal())
                <x-filament::button color="gray" wire:click="cancel">
                    Cancel
                </x-filament::button>
            @endif
        </div>

        @if (! $this->isTerminal())
            <div wire:poll.1000ms="poll"></div>
        @endif

        @if ($sessionId)
            <div class="mt-6 space-y-4">
                <div class="flex items-center gap-2">
                    <span class="inline-flex h-2.5 w-2.5 rounded-full
                        {{ match(true) {
                            $status === 'blog_page_ready' => 'bg-success-500',
                            in_array($status, ['login_failed', 'error']) => 'bg-danger-500',
                            $status === 'awaiting_captcha' => 'bg-warning-500',
                            default => 'bg-gray-400 animate-pulse',
                        } }}">
                    </span>
                    <span class="text-sm font-medium">
                        {{ match($status) {
                            'starting' => 'Opening the login page...',
                            'checking_captcha' => 'Checking whether a captcha is required...',
                            'awaiting_captcha' => 'Captcha challenge - please solve it below',
                            'submitting' => 'Signing in...',
                            'navigating' => 'Opening the Blog page...',
                            'blog_page_ready' => 'Success',
                            'login_failed' => 'Login failed',
                            'two_factor_required' => 'Two-factor authentication required',
                            'error' => 'Error',
                            default => 'Idle',
                        } }}
                    </span>
                </div>

                @if ($status === 'blog_page_ready' && $message)
                    <p class="text-lg font-semibold text-success-600 dark:text-success-400">
                        {{ $message }}
                    </p>
                @elseif ($message)
                    <p class="text-sm text-gray-700 dark:text-gray-200">{{ $message }}</p>
                @endif

                @if ($errorText)
                    <p class="text-sm text-danger-600 dark:text-danger-400">{{ $errorText }}</p>
                @endif

                @if ($status === 'awaiting_captcha')
                    <div>
                        <p class="mb-2 text-xs text-gray-500 dark:text-gray-400">
                            Click directly on the image below exactly as you would on the real captcha - clicks are
                            forwarded live to the browser running the login.
                        </p>

                        @if ($frameDataUri)
                            <img
                                src="{{ $frameDataUri }}"
                                style="cursor: crosshair;"
                                class="max-w-full rounded border border-gray-300 dark:border-gray-600"
                                @click="
                                    const rect = $el.getBoundingClientRect();
                                    const xPct = ($event.clientX - rect.left) / rect.width;
                                    const yPct = ($event.clientY - rect.top) / rect.height;
                                    $wire.forwardClick(xPct, yPct);
                                "
                            />
                        @else
                            <p class="text-sm text-gray-400">Waiting for the first frame...</p>
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
