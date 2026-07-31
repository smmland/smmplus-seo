<?php

namespace App\Filament\Pages;

use App\Services\AdminAutomationClient;
use App\Services\AdminAutomationSettingsService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use RuntimeException;

class AdminLogin extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-arrow-right-on-rectangle';

    protected static ?string $navigationGroup = 'Translation';

    protected static ?string $navigationLabel = 'Admin Login';

    // Sits right after Blog Translation/Settings (which default to -1) but before Settings' 100.
    protected static ?int $navigationSort = 50;

    protected static string $view = 'filament.pages.admin-login';

    private const TERMINAL_STATUSES = ['idle', 'blog_page_ready', 'login_failed', 'two_factor_required', 'error'];

    public ?string $sessionId = null;

    public string $status = 'idle';

    public ?string $message = null;

    public ?string $errorText = null;

    public ?string $frameDataUri = null;

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function isConfigured(AdminAutomationSettingsService $settings): bool
    {
        return $settings->getUsername() && $settings->hasPassword() && $settings->getServiceUrl() && $settings->hasServiceToken();
    }

    public function start(AdminAutomationClient $client): void
    {
        $this->reset(['sessionId', 'message', 'errorText', 'frameDataUri']);

        try {
            $session = $client->startLogin();
        } catch (RuntimeException $e) {
            Notification::make()
                ->title('Cannot start login')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Could not reach the automation service')
                ->body($e->getMessage())
                ->danger()
                ->send();

            return;
        }

        $this->sessionId = $session['id'];
        $this->status = $session['status'];
        $this->message = $session['message'] ?? null;
    }

    public function poll(AdminAutomationClient $client): void
    {
        if (! $this->sessionId || $this->isTerminal()) {
            return;
        }

        try {
            $session = $client->getStatus($this->sessionId);
        } catch (\Throwable $e) {
            $this->status = 'error';
            $this->errorText = $e->getMessage();

            return;
        }

        $this->status = $session['status'];
        $this->message = $session['message'] ?? null;
        $this->errorText = $session['error'] ?? null;

        if ($this->status === 'awaiting_captcha') {
            $frame = $client->getFrame($this->sessionId);
            $this->frameDataUri = $frame ? 'data:image/jpeg;base64,'.base64_encode($frame) : null;
        } else {
            $this->frameDataUri = null;
        }
    }

    public function forwardClick(float $xPct, float $yPct, AdminAutomationClient $client): void
    {
        if (! $this->sessionId || $this->status !== 'awaiting_captcha') {
            return;
        }

        $client->forwardInput($this->sessionId, [
            'type' => 'click',
            'xPct' => $xPct,
            'yPct' => $yPct,
        ]);
    }

    public function cancel(AdminAutomationClient $client): void
    {
        if ($this->sessionId) {
            $client->cancel($this->sessionId);
        }

        $this->reset(['sessionId', 'message', 'errorText', 'frameDataUri']);
        $this->status = 'idle';
    }
}
