<?php

namespace App\Filament\Pages;

use App\Services\ReviewsSettingsService;
use App\Support\PanelSection;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class ReviewsSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Reviews';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.pages.reviews-settings';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAccess(PanelSection::key(PanelSection::REVIEWS, PanelSection::TIER_SETTINGS)) ?? false;
    }

    public ?array $data = [];

    public function mount(ReviewsSettingsService $settings): void
    {
        $this->form->fill([
            'enabled' => $settings->isEnabled(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Public visibility')
                    ->description('Turns the whole public side of Reviews off at once - GET /api/reviews returns an empty list and POST /api/reviews (submissions) is rejected. Managing reviews in the panel still works either way; this only controls what the live site receives.')
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Reviews enabled'),
                    ]),

                Section::make('How this works')
                    ->description('Nothing to configure here - just for reference.')
                    ->schema([
                        Placeholder::make('rotationInfo')
                            ->label('Display rotation')
                            ->content('The public list automatically reshuffles which approved reviews it shows every 6 hours, per language - no action needed.'),

                        Placeholder::make('submissionInfo')
                            ->label('Submitted reviews')
                            ->content('New reviews sent through POST /api/reviews are auto-tagged with a language and country guessed from the submitter\'s IP, and stay hidden from the public list until approved in the Reviews section below. Limited to one submission per IP every 2 hours.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(ReviewsSettingsService $settings): void
    {
        $data = $this->form->getState();

        $settings->setEnabled((bool) $data['enabled']);

        Notification::make()
            ->title('Reviews settings saved')
            ->success()
            ->send();
    }
}
