<?php

namespace App\Filament\Pages;

use App\Models\BlogTranslationJob;
use App\Models\Language;
use App\Models\Url;
use App\Services\AiSettingsService;
use App\Services\PanelUpdateService;
use App\Services\SettingsService;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Livewire\WithFileUploads;

class GeneralSettings extends Page implements HasForms
{
    use InteractsWithForms;
    use WithFileUploads;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'General Settings';

    protected static ?string $title = 'General Settings';

    protected static string $view = 'filament.pages.general-settings';

    public ?array $data = [];

    public ?array $aiData = [];

    public ?array $aiTestResult = null;

    public string $accentColor = '';

    // How stale the heartbeat (written every minute by routes/console.php) can be before we
    // call the cron dead rather than just between ticks - generous enough to absorb a slow
    // request or two without flapping.
    private const CRON_STALE_AFTER_MINUTES = 3;

    // Curated rather than free text, to avoid a typo'd model id silently breaking translation -
    // "Custom" (below, reveals a plain text field) covers whatever a provider ships after this
    // list was written, since neither list can stay current forever.
    private const CLAUDE_MODELS = [
        'claude-sonnet-4-5-20250929' => 'Claude Sonnet 4.5',
        'claude-sonnet-5' => 'Claude Sonnet 5',
        'claude-opus-5' => 'Claude Opus 5',
        'claude-fable-5' => 'Claude Fable 5',
        'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
        'custom' => 'Custom / other…',
    ];

    private const CHATGPT_MODELS = [
        'gpt-4o' => 'GPT-4o',
        'gpt-4o-mini' => 'GPT-4o mini',
        'gpt-4.1' => 'GPT-4.1',
        'gpt-4.1-mini' => 'GPT-4.1 mini',
        'gpt-5' => 'GPT-5',
        'o3' => 'o3',
        'o4-mini' => 'o4-mini',
        'custom' => 'Custom / other…',
    ];

    // Reached from the account menu (top-right avatar -> Settings) instead of the sidebar -
    // see AdminPanelProvider::panel()'s userMenuItems().
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    // Filament pages only wire up the default 'form' unless every form in use is listed here -
    // AI settings save independently of the account form below (picking a model shouldn't force
    // re-entering the account password, which that form requires on every save).
    protected function getForms(): array
    {
        return ['form', 'aiForm'];
    }

    public function mount(SettingsService $settings, AiSettingsService $aiSettings): void
    {
        $this->form->fill([
            'email' => Auth::user()->email,
        ]);

        $this->accentColor = $settings->getAccentColorKey();

        $claudeModel = $aiSettings->getModel('claude');
        $chatgptModel = $aiSettings->getModel('chatgpt');

        $this->aiForm->fill([
            'aiProvider' => $aiSettings->getProvider(),
            // Never pre-filled with the real secret - blank means "keep the saved one" on save.
            'aiApiKey' => null,
            'aiModelClaude' => array_key_exists($claudeModel, self::CLAUDE_MODELS) ? $claudeModel : 'custom',
            'aiModelClaudeCustom' => array_key_exists($claudeModel, self::CLAUDE_MODELS) ? null : $claudeModel,
            'aiModelChatgpt' => array_key_exists($chatgptModel, self::CHATGPT_MODELS) ? $chatgptModel : 'custom',
            'aiModelChatgptCustom' => array_key_exists($chatgptModel, self::CHATGPT_MODELS) ? null : $chatgptModel,
            'aiMaxConcurrentTranslations' => $aiSettings->getMaxConcurrentTranslations(),
        ]);
    }

    public function getAccentColorPresets(): array
    {
        return SettingsService::ACCENT_COLOR_PRESETS;
    }

    // The panel's colors (nav, buttons, ...) are resolved into CSS variables once per full page
    // load (AdminPanelProvider::panel(), read server-side into the layout's <head>) - a Livewire
    // property update alone can't repaint chrome that's already on the page, so this forces a
    // real browser navigation instead of a SPA-style partial update.
    public function setAccentColor(string $key, SettingsService $settings): void
    {
        $settings->setAccentColor($key);

        Notification::make()
            ->title('Panel color updated')
            ->success()
            ->send();

        $this->redirect(static::getUrl());
    }

    public function getCronStatus(SettingsService $settings): array
    {
        $heartbeat = $settings->getCronHeartbeatAt();

        return [
            'heartbeat' => $heartbeat,
            'active' => $heartbeat !== null && $heartbeat->gt(now()->subMinutes(self::CRON_STALE_AFTER_MINUTES)),
        ];
    }

    // Every update this panel ships that changes the database needs `php artisan migrate` run
    // once after deploying it - normally a one-line SSH command, but this host's admin has no
    // terminal access at all, only FTP/cPanel file upload. This button is the only way those
    // updates can ever actually take effect: upload the new files, then click this instead of
    // needing shell access. Safe to click any time, including with nothing pending - already-
    // applied migrations are tracked and skipped automatically.
    public function pendingMigrationsCount(): int
    {
        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles(database_path('migrations'));
        $ran = $migrator->getRepository()->getRan();

        return count(array_diff(array_keys($files), $ran));
    }

    public function runMigrations(): void
    {
        Artisan::call('migrate', ['--force' => true]);
        $output = trim(Artisan::output());

        Notification::make()
            ->title('Database updated')
            ->body($output !== '' ? $output : 'Nothing to update - already up to date.')
            ->success()
            ->send();
    }

    // Zip is whatever `git archive` produces - the same file sent for every update. Livewire
    // handles the actual upload (to a temp disk) via $updateZip; this just hands the saved
    // temp file to PanelUpdateService once "Install update" is clicked, rather than acting on
    // every keystroke/selection change the way a live-validated form field would.
    public $updateZip = null;

    public function installUpdate(PanelUpdateService $updater): void
    {
        $this->validate([
            'updateZip' => 'required|file|mimes:zip|max:51200',
        ]);

        $result = $updater->install($this->updateZip);

        $notification = Notification::make()
            ->title($result['ok'] ? 'Panel updated' : 'Update failed')
            ->body($result['ok']
                ? $result['message'].' If this update included a database change, click "Update database" below too.'
                : $result['message']);

        $result['ok'] ? $notification->success() : $notification->danger();
        $notification->send();

        $this->updateZip = null;
    }

    public function aiForm(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('AI Settings')
                    ->description('Used to auto-translate blog content that\'s missing a language. Pick one provider and enter its API key - "Test connection" checks it against the provider directly, without saving first.')
                    ->schema([
                        Select::make('aiProvider')
                            ->label('Provider')
                            ->options(AiSettingsService::PROVIDER_LABELS)
                            ->required()
                            ->live(),

                        TextInput::make('aiApiKey')
                            ->label('API key')
                            ->password()
                            ->revealable()
                            ->helperText(fn (Get $get) => app(AiSettingsService::class)->hasApiKey($get('aiProvider'))
                                ? 'A key is already saved for this provider - leave blank to keep it, or type a new one to replace it.'
                                : 'No key saved yet for this provider.'),

                        Select::make('aiModelClaude')
                            ->label('Claude model')
                            ->options(self::CLAUDE_MODELS)
                            ->visible(fn (Get $get) => $get('aiProvider') === 'claude')
                            ->live()
                            ->helperText('The Anthropic model used for translation calls - pick Custom to enter a model id directly if the provider ships something newer than this list.'),

                        TextInput::make('aiModelClaudeCustom')
                            ->label('Custom Claude model id')
                            ->visible(fn (Get $get) => $get('aiProvider') === 'claude' && $get('aiModelClaude') === 'custom'),

                        Select::make('aiModelChatgpt')
                            ->label('ChatGPT model')
                            ->options(self::CHATGPT_MODELS)
                            ->visible(fn (Get $get) => $get('aiProvider') === 'chatgpt')
                            ->live()
                            ->helperText('The OpenAI model used for translation calls - pick Custom to enter a model id directly if the provider ships something newer than this list.'),

                        TextInput::make('aiModelChatgptCustom')
                            ->label('Custom ChatGPT model id')
                            ->visible(fn (Get $get) => $get('aiProvider') === 'chatgpt' && $get('aiModelChatgpt') === 'custom'),

                        TextInput::make('aiMaxConcurrentTranslations')
                            ->label('Max concurrent translations')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(10)
                            ->required()
                            ->helperText('How many languages "Translate all missing languages" sends to the AI provider at once instead of one at a time. Higher is faster but more likely to hit the provider\'s own rate limits.'),
                    ])
                    ->columns(2),
            ])
            ->statePath('aiData');
    }

    public function saveAiSettings(AiSettingsService $aiSettings): void
    {
        $data = $this->aiForm->getState();

        $aiSettings->setProvider($data['aiProvider']);
        $aiSettings->setApiKey($data['aiProvider'], $data['aiApiKey'] ?: null);

        // Only the selected provider's model field is actually visible (and so present in
        // $data - Filament excludes hidden fields from form state entirely), and that's also
        // the only one this save should touch: the other provider's stored model is left alone
        // rather than being blanked out by a field that was never rendered this time.
        if ($data['aiProvider'] === 'claude') {
            $claudeModel = $data['aiModelClaude'] === 'custom' ? ($data['aiModelClaudeCustom'] ?? '') : $data['aiModelClaude'];
            $aiSettings->setModel('claude', $claudeModel ?: null);
        } else {
            $chatgptModel = $data['aiModelChatgpt'] === 'custom' ? ($data['aiModelChatgptCustom'] ?? '') : $data['aiModelChatgpt'];
            $aiSettings->setModel('chatgpt', $chatgptModel ?: null);
        }

        $aiSettings->setMaxConcurrentTranslations((int) $data['aiMaxConcurrentTranslations']);

        // The typed key was only ever meant to reach storage (encrypted) - don't leave it
        // sitting in the live form state after a successful save.
        $this->aiForm->fill([...$this->aiForm->getState(), 'aiApiKey' => null]);

        Notification::make()
            ->title('AI settings saved')
            ->success()
            ->send();
    }

    public function testAiConnection(AiSettingsService $aiSettings): void
    {
        $data = $this->aiForm->getState();
        $provider = $data['aiProvider'];
        $apiKey = filled($data['aiApiKey']) ? $data['aiApiKey'] : ($aiSettings->getApiKey($provider) ?? '');

        $this->aiTestResult = $aiSettings->testConnection($provider, $apiKey);

        $notification = Notification::make()
            ->title($this->aiTestResult['ok'] ? 'Connection successful' : 'Connection failed')
            ->body($this->aiTestResult['message']);

        $this->aiTestResult['ok'] ? $notification->success() : $notification->danger();

        $notification->send();
    }

    /**
     * Aggregates every completed translation's estimated cost (BlogAiTranslationService writes
     * one per attempt, success or fail - a failed call can still have burned real tokens) into
     * an overall total plus a per-topic breakdown, for the "AI Translation Costs" section below.
     * Guarded the same way every blog_translation_jobs feature is: the cost columns can lag
     * behind this code until "Update database" is clicked, since this host has no terminal
     * access to run migrations any other way.
     *
     * @return array{available: bool, totalCost: float, totalInputTokens: int, totalOutputTokens: int, totalJobs: int, unknownPricingCount: int, byTopic: \Illuminate\Support\Collection}
     */
    public function getAiCostStats(): array
    {
        if (! Schema::hasTable('blog_translation_jobs') || ! Schema::hasColumn('blog_translation_jobs', 'estimated_cost_usd')) {
            return [
                'available' => false,
                'totalCost' => 0.0,
                'totalInputTokens' => 0,
                'totalOutputTokens' => 0,
                'totalJobs' => 0,
                'unknownPricingCount' => 0,
                'byTopic' => collect(),
            ];
        }

        $attempted = BlogTranslationJob::query()->whereNotNull('provider');

        $totals = (clone $attempted)
            ->selectRaw('COALESCE(SUM(estimated_cost_usd), 0) as total_cost, COALESCE(SUM(input_tokens), 0) as total_input, COALESCE(SUM(output_tokens), 0) as total_output, COUNT(*) as total_jobs')
            ->first();

        // A custom/unlisted model has no known rate (AiSettingsService::estimateCost() returns
        // null rather than guessing) - called out separately so the total doesn't silently look
        // complete when part of it couldn't actually be priced.
        $unknownPricingCount = (clone $attempted)->whereNull('estimated_cost_usd')->count();

        $byGroup = (clone $attempted)
            ->selectRaw('group_key, COALESCE(SUM(estimated_cost_usd), 0) as cost, COALESCE(SUM(input_tokens), 0) as input_tokens, COALESCE(SUM(output_tokens), 0) as output_tokens, COUNT(*) as translations')
            ->groupBy('group_key')
            ->orderByDesc('cost')
            ->limit(50)
            ->get();

        $defaultLangCode = Language::query()->where('is_default', true)->value('code') ?? 'en';

        $topics = Url::query()
            ->where('lang', $defaultLangCode)
            ->whereIn('group_key', $byGroup->pluck('group_key'))
            ->get(['group_key', 'article_title', 'source_url'])
            ->keyBy('group_key');

        $byTopic = $byGroup->map(function ($row) use ($topics) {
            $topic = $topics->get($row->group_key);

            return [
                'groupKey' => $row->group_key,
                'title' => $topic?->article_title ?: ($topic?->source_url ?: $row->group_key),
                'sourceUrl' => $topic?->source_url,
                'cost' => (float) $row->cost,
                'inputTokens' => (int) $row->input_tokens,
                'outputTokens' => (int) $row->output_tokens,
                'translations' => (int) $row->translations,
            ];
        });

        return [
            'available' => true,
            'totalCost' => (float) $totals->total_cost,
            'totalInputTokens' => (int) $totals->total_input,
            'totalOutputTokens' => (int) $totals->total_output,
            'totalJobs' => (int) $totals->total_jobs,
            'unknownPricingCount' => $unknownPricingCount,
            'byTopic' => $byTopic,
        ];
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('email')
                    ->label('Admin email')
                    ->email()
                    ->required(),

                TextInput::make('newPassword')
                    ->label('New password')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->helperText('Leave this field empty if you don\'t want to change the password.'),

                TextInput::make('currentPassword')
                    ->label('Current password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->helperText('Enter your current password to confirm these changes.'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $user = Auth::user();

        if (! Hash::check($data['currentPassword'], $user->password)) {
            Notification::make()
                ->title('Current password is incorrect')
                ->danger()
                ->send();

            return;
        }

        $user->email = $data['email'];

        if (filled($data['newPassword'] ?? null)) {
            $user->password = $data['newPassword'];
        }

        $user->save();

        $this->form->fill([
            'email' => $user->email,
            'newPassword' => null,
            'currentPassword' => null,
        ]);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
