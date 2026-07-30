<?php

namespace App\Filament\Pages;

use App\Services\BlogTranslationDetectionService;
use App\Services\TranslationSettingsService;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;

class TranslationSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Translation';

    protected static ?string $navigationLabel = 'Settings';

    // Pushed to the end of the group - everything else in the group defaults to sort -1.
    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.pages.translation-settings';

    public ?array $data = [];

    public ?array $lastRunResult = null;

    public function mount(TranslationSettingsService $settings): void
    {
        $this->form->fill([
            'autoHideEnabled' => $settings->isAutoHideEnabled(),
            'recheckIntervalHours' => $settings->getRecheckIntervalHours(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Toggle::make('autoHideEnabled')
                    ->label('Automatically hide untranslated blog pages')
                    ->helperText('When a non-default-language blog page\'s title still matches the default language, hide it from the sitemap until it\'s translated.'),

                TextInput::make('recheckIntervalHours')
                    ->label('Recheck cycle (hours)')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->helperText('How often each blog URL gets re-checked. Runs hourly in the background but only actually re-checks a URL once this many hours have passed since its last check.'),
            ])
            ->statePath('data');
    }

    public function save(TranslationSettingsService $settings): void
    {
        $data = $this->form->getState();

        $settings->setSettings(
            (bool) $data['autoHideEnabled'],
            (int) $data['recheckIntervalHours'],
        );

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    public function runNow(BlogTranslationDetectionService $detector): void
    {
        // Bounded well below what would risk a PHP execution timeout on this host - a large
        // backlog is worked off a batch at a time by clicking again, or by the hourly schedule.
        // The pending count below shows whether everything's actually been covered yet.
        $this->lastRunResult = $detector->refresh(force: true, limit: 40);

        unset($this->pendingCount);

        Notification::make()
            ->title('Translation check complete')
            ->body("Checked {$this->lastRunResult['checked']}, hid {$this->lastRunResult['hidden']}, unhid {$this->lastRunResult['unhidden']}, errors {$this->lastRunResult['errors']}.")
            ->success()
            ->send();
    }

    #[Computed]
    public function pendingCount(): int
    {
        return app(BlogTranslationDetectionService::class)->pendingCount();
    }
}
