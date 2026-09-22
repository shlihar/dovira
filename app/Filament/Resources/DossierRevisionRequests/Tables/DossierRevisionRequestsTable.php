<?php

namespace App\Filament\Resources\DossierRevisionRequests\Tables;

use App\Models\DossierRevisionRequest;
use App\Services\ProProfileNotificationService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DossierRevisionRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        DossierRevisionRequest::STATUS_PENDING => 'Нова',
                        DossierRevisionRequest::STATUS_IN_REVIEW => 'В роботі',
                        DossierRevisionRequest::STATUS_DONE => 'Виконано',
                        DossierRevisionRequest::STATUS_REJECTED => 'Відхилено',
                        default => $state,
                    })
                    ->color(fn (string $state) => match ($state) {
                        DossierRevisionRequest::STATUS_DONE => 'success',
                        DossierRevisionRequest::STATUS_PENDING => 'warning',
                        DossierRevisionRequest::STATUS_IN_REVIEW => 'info',
                        DossierRevisionRequest::STATUS_REJECTED => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('profile.name')
                    ->label('Профіль')
                    ->searchable()
                    ->description(fn ($record) => $record->profile?->slug ?: '—')
                    ->limit(36),
                TextColumn::make('user.email')
                    ->label('Від кого')
                    ->searchable()
                    ->limit(30),
                TextColumn::make('message')
                    ->label('Що виправити')
                    ->wrap()
                    ->limit(160)
                    ->tooltip(fn ($record) => $record->message),
                TextColumn::make('admin_note')
                    ->label('Нотатка')
                    ->placeholder('—')
                    ->limit(60)
                    ->tooltip(fn ($record) => $record->admin_note),
                TextColumn::make('created_at')
                    ->label('Створено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        DossierRevisionRequest::STATUS_PENDING => 'Нова',
                        DossierRevisionRequest::STATUS_IN_REVIEW => 'В роботі',
                        DossierRevisionRequest::STATUS_DONE => 'Виконано',
                        DossierRevisionRequest::STATUS_REJECTED => 'Відхилено',
                    ])
                    ->default(null),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('open_profile')
                        ->label('Профіль в адмінці')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn ($record) => route('filament.admin.resources.profiles.edit', ['record' => $record->profile_id])),
                    Action::make('in_review')
                        ->label('Взяти в роботу')
                        ->icon('heroicon-o-wrench-screwdriver')
                        ->color('info')
                        ->visible(fn ($record) => $record->status === DossierRevisionRequest::STATUS_PENDING)
                        ->action(function ($record): void {
                            $record->forceFill(['status' => DossierRevisionRequest::STATUS_IN_REVIEW])->save();
                            Notification::make()->title('Взято в роботу')->info()->send();
                        }),
                    Action::make('done')
                        ->label('Виконано (досьє оновлено)')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn ($record) => $record->status !== DossierRevisionRequest::STATUS_DONE)
                        ->form([
                            Textarea::make('admin_note')
                                ->label('Нотатка (необовʼязково)')
                                ->rows(3)
                                ->maxLength(2000),
                        ])
                        ->action(function ($record, array $data): void {
                            $record->forceFill([
                                'status' => DossierRevisionRequest::STATUS_DONE,
                                'admin_note' => trim((string) ($data['admin_note'] ?? '')) ?: $record->admin_note,
                                'resolved_at' => now(),
                            ])->save();

                            if ($record->profile) {
                                app(ProProfileNotificationService::class)->create(
                                    $record->profile,
                                    'dossier_revision_done',
                                    'Досьє оновлено за вашим запитом',
                                    'Ми перевірили факти й оновили досьє профілю. Перегляньте результат на публічній сторінці.',
                                    ['severity' => 'success']
                                );
                            }

                            Notification::make()->title('Запит закрито, власника сповіщено')->success()->send();
                        }),
                    Action::make('reject')
                        ->label('Відхилити')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn ($record) => ! in_array($record->status, [DossierRevisionRequest::STATUS_DONE, DossierRevisionRequest::STATUS_REJECTED], true))
                        ->form([
                            Textarea::make('admin_note')
                                ->label('Причина (побачить власник)')
                                ->required()
                                ->rows(3)
                                ->maxLength(2000),
                        ])
                        ->action(function ($record, array $data): void {
                            $record->forceFill([
                                'status' => DossierRevisionRequest::STATUS_REJECTED,
                                'admin_note' => trim((string) $data['admin_note']),
                                'resolved_at' => now(),
                            ])->save();

                            if ($record->profile) {
                                app(ProProfileNotificationService::class)->create(
                                    $record->profile,
                                    'dossier_revision_rejected',
                                    'Запит щодо досьє відхилено',
                                    'Ми перевірили запит, але фактів для зміни досьє недостатньо. Причина: ' . trim((string) $data['admin_note']),
                                    ['severity' => 'warning']
                                );
                            }

                            Notification::make()->title('Запит відхилено, власника сповіщено')->warning()->send();
                        }),
                ]),
            ]);
    }
}
