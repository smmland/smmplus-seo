<?php

namespace App\Filament\Pages\Auth;

use App\Services\LoginSecuritySettingsService;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Forms\Components\ViewField;
use Filament\Forms\Form;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Models\Contracts\FilamentUser;
use Filament\Pages\Auth\Login as BaseLogin;
use Illuminate\Validation\ValidationException;

class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        try {
            $this->rateLimit(5);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $data = $this->form->getState();
        $email = (string) ($data['email'] ?? '');
        $ip = request()->ip() ?? 'unknown';
        $security = app(LoginSecuritySettingsService::class);

        if ($security->requiresCaptcha($email, $ip)) {
            if (! $security->verifyRecaptcha($data['recaptcha_token'] ?? null, $ip)) {
                $this->dispatch('reset-login-recaptcha');

                throw ValidationException::withMessages([
                    'data.recaptcha_token' => 'Please complete the reCAPTCHA verification.',
                ]);
            }
        }

        if (! Filament::auth()->attempt($this->getCredentialsFromFormData($data), $data['remember'] ?? false)) {
            $security->recordFailure($email, $ip);
            $this->dispatch('reset-login-recaptcha');
            $this->throwFailureValidationException();
        }

        $user = Filament::auth()->user();

        if (($user instanceof FilamentUser) && (! $user->canAccessPanel(Filament::getCurrentPanel()))) {
            Filament::auth()->logout();
            $security->recordFailure($email, $ip);
            $this->dispatch('reset-login-recaptcha');
            $this->throwFailureValidationException();
        }

        $security->clearFailures($email, $ip);
        session()->regenerate();

        return app(LoginResponse::class);
    }

    /**
     * @return array<int | string, string | Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getRememberFormComponent(),
                        ViewField::make('recaptcha_token')
                            ->label('Security verification')
                            ->view('filament.forms.components.login-recaptcha')
                            ->viewData(fn (): array => [
                                'siteKey' => app(LoginSecuritySettingsService::class)->getRecaptchaSiteKey(),
                            ])
                            ->visible(fn (): bool => app(LoginSecuritySettingsService::class)->requiresCaptcha(
                                $this->data['email'] ?? null,
                                request()->ip() ?? 'unknown',
                            )),
                    ])
                    ->statePath('data'),
            ),
        ];
    }
}
