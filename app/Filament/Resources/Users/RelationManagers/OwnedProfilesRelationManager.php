<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Models\Profile;
use App\Services\ProfileClaimReviewService;
use App\Support\AuditLogger;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class OwnedProfilesRelationManager extends RelationManager
{
    protected static string $relationship = 'ownedProfiles';

    protected static ?string $title = 'Профілі у власності';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-rectangle-stack';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('id')->label('ID')->width('1%')->grow(false),
                TextColumn::make('name')->label('Профіль')->searchable()->limit(36)
                    ->description(fn (Profile $record) => $record->city ?: $record->slug),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'hidden', 'draft' => 'warning',
                        default => 'gray',
                    }),
                IconColumn::make('is_owner_verified')->label('Права власника')->boolean(),
                IconColumn::make('is_pro')->label('PRO')->boolean(),
                TextColumn::make('rating_avg')->label('Рейтинг')->numeric(1)->placeholder('—'),
                TextColumn::make('reviews_count')->label('Відгуків')->numeric()->alignCenter(),
            ])
            ->headerActions([
                Action::make('linkProfile')
                    ->label('Привʼязати профіль')
                    ->icon('heroicon-o-link')
                    ->color('primary')
                    ->modalWidth('lg')
                    ->modalHeading('Привʼязати існуючий профіль до користувача')
                    ->form([
                        Select::make('profile_id')
                            ->label('Профіль')
                            ->required()
                            ->searchable()
                            // 27k+ профілів — вантажимо лише результати пошуку, не весь список.
                            ->getSearchResultsUsing(fn (string $search): array => Profile::query()
                                ->where(function ($query) use ($search): void {
                                    $query->where('name', 'like', "%{$search}%")
                                        ->orWhere('slug', 'like', "%{$search}%")
                                        ->orWhere('email', 'like', "%{$search}%");
                                })
                                ->orderByRaw('owner_user_id is null desc')
                                ->orderBy('name')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Profile $profile): array => [
                                    $profile->id => trim(sprintf(
                                        '%s — %s%s',
                                        $profile->name,
                                        $profile->city ?: $profile->slug,
                                        $profile->owner_user_id ? '  ⚠ вже має власника' : ''
                                    )),
                                ])
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => Profile::query()->whereKey($value)->value('name'))
                            ->helperText('Шукай за назвою, slug або email профілю. Профілі без власника — вгорі списку.'),
                    ])
                    ->action(function (array $data): void {
                        /** @var \App\Models\User $user */
                        $user = $this->getOwnerRecord();
                        $profile = Profile::query()->find($data['profile_id']);

                        if (! $profile) {
                            Notification::make()->title('Профіль не знайдено')->danger()->send();

                            return;
                        }

                        // Через сервіс: власник отримає in-app + email, адмін —
                        // Telegram, і зʼявиться слід у «Заявках».
                        app(ProfileClaimReviewService::class)->linkOwnerManually($profile, $user);

                        Notification::make()
                            ->title('Профіль привʼязано — сповіщення надіслано')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Відкрити')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Profile $record): string => route('filament.admin.resources.profiles.edit', ['record' => $record]))
                    ->openUrlInNewTab(),
                Action::make('unlink')
                    ->label('Відвʼязати')
                    ->icon('heroicon-o-link-slash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Відвʼязати профіль від користувача')
                    ->modalDescription('Профіль лишиться без власника. Підтвердження прав власника та PRO будуть зняті.')
                    ->action(function (Profile $record): void {
                        $previousOwnerId = $record->owner_user_id;
                        $record->owner_user_id = null;
                        $record->is_owner_verified = false;
                        $record->is_pro = false;
                        $record->save();

                        AuditLogger::log('admin.profile_unlinked_from_user', $record, [
                            'profile_id' => $record->id,
                            'previous_owner_user_id' => $previousOwnerId,
                        ]);

                        Notification::make()
                            ->title('Профіль відвʼязано')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
