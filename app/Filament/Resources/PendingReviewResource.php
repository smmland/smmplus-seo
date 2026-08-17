<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PendingReviewResource\Pages;
use App\Models\Review;
use App\Support\PanelSection;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The moderation queue for reviews submitted through POST /api/reviews (App\Http\Controllers\
 * Api\ReviewsController::store()) - everything here is status=pending. Deliberately its own
 * resource rather than a filtered view bolted onto ReviewResource: it needs a completely
 * different interaction (fast approve/reject triage, newest submission first, nothing to edit)
 * from ReviewResource's general-purpose CRUD (which still covers editing any review, including
 * ones already moderated here, and admin-authored ones that never touch this queue at all since
 * they're created as status=approved).
 *
 * Approving or rejecting a row removes it from Eloquent's WHERE status = pending scope below, so
 * Filament's table simply no longer includes it on its next render - no extra JS/Livewire wiring
 * needed to make a handled review "disappear and load the next one."
 */
class PendingReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static ?string $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?string $navigationGroup = 'Reviews';

    protected static ?string $navigationLabel = 'Moderation';

    protected static ?string $recordTitleAttribute = 'author_name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('status', Review::STATUS_PENDING);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyAccess(PanelSection::viewOrEditKeys(PanelSection::REVIEWS)) ?? false;
    }

    private static function canModerate(): bool
    {
        return auth()->user()?->hasAccess(PanelSection::key(PanelSection::REVIEWS, PanelSection::TIER_EDIT)) ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getEloquentQuery()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Submitted')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('lang')
                    ->label('Lang')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => strtoupper($state)),

                Tables\Columns\TextColumn::make('author_name')
                    ->label('Reviewer')
                    ->searchable(),

                Tables\Columns\TextColumn::make('rating')
                    ->label('Rating')
                    ->formatStateUsing(fn (int $state) => str_repeat('★', $state).str_repeat('☆', 5 - $state)),

                Tables\Columns\TextColumn::make('body')
                    ->label('Review')
                    ->searchable()
                    ->wrap()
                    ->limit(500),

                Tables\Columns\TextColumn::make('related_service')
                    ->label('Service')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('country_name')
                    ->label('Country')
                    ->formatStateUsing(fn (Review $record) => trim(($record->countryFlag() ?? '').' '.$record->country_name))
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('submitted_username')
                    ->label('Submitted by')
                    ->placeholder('—')
                    ->searchable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('Approve')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (): bool => static::canModerate())
                    ->action(fn (Review $record) => $record->update(['is_approved' => true, 'status' => Review::STATUS_APPROVED])),

                Tables\Actions\Action::make('reject')
                    ->label('Reject')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (): bool => static::canModerate())
                    ->action(fn (Review $record) => $record->update(['status' => Review::STATUS_REJECTED])),
            ])
            ->emptyStateHeading('Nothing to review')
            ->emptyStateDescription('Every submitted review has been approved or rejected.')
            ->emptyStateIcon('heroicon-o-check-badge');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPendingReviews::route('/'),
        ];
    }
}
