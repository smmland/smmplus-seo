<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        wire:ignore
        x-data='{
            widgetId: null,
            renderAttempts: 0,
            renderCaptcha() {
                if (this.widgetId !== null) return;

                if (!window.grecaptcha || !window.grecaptcha.render) {
                    if (this.renderAttempts++ < 80) setTimeout(() => this.renderCaptcha(), 250);
                    return;
                }

                this.widgetId = window.grecaptcha.render(this.$refs.widget, {
                    sitekey: @js($siteKey),
                    callback: (token) => $wire.set("data.recaptcha_token", token),
                    "expired-callback": () => $wire.set("data.recaptcha_token", null),
                    "error-callback": () => $wire.set("data.recaptcha_token", null),
                });
            },
            resetCaptcha() {
                $wire.set("data.recaptcha_token", null);
                if (this.widgetId !== null && window.grecaptcha) window.grecaptcha.reset(this.widgetId);
            },
        }'
        x-init="renderCaptcha()"
        x-on:reset-login-recaptcha.window="resetCaptcha()"
    >
        <div x-ref="widget"></div>
    </div>

    @once
        <script src="https://www.google.com/recaptcha/api.js?render=explicit" async defer></script>
    @endonce
</x-dynamic-component>
