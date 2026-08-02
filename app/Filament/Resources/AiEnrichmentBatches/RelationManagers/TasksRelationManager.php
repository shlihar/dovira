<?php

namespace App\Filament\Resources\AiEnrichmentBatches\RelationManagers;

use App\Filament\Resources\Profiles\ProfileResource;
use App\Jobs\RefreshProfileReviewAiAnalysis;
use App\Models\AiEnrichmentTask;
use App\Models\ProfileReview;
use App\Services\ProfileReviewStatsService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Задачі збагачення';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('raw_name')
            ->striped()
            ->paginated([15, 25, 50, 100])
            ->defaultPaginationPageOption(15)
            ->columns([
                TextColumn::make('row_number')->label('#')->sortable()->alignCenter(),
                TextColumn::make('raw_name')
                    ->label('Стартова назва')
                    ->searchable()
                    ->weight('semibold')
                    ->limit(48)
                    ->description(fn (AiEnrichmentTask $record): string => implode(' · ', array_filter([
                        $record->raw_city ?: null,
                        $record->category?->name ?: null,
                    ]))),
                TextColumn::make('profile.name')
                    ->label('Профіль')
                    ->searchable()
                    ->placeholder('—')
                    ->limit(38)
                    ->description(fn (AiEnrichmentTask $record): string => $record->profile?->slug ?: 'Не створено'),
                TextColumn::make('status')
                    ->label('Статус')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        'data_found', 'completed' => 'success',
                        'needs_review', 'possible_duplicate' => 'warning',
                        'processing' => 'info',
                        'error', 'rejected' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('confidence_score')
                    ->label('AI %')
                    ->suffix('%')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('duplicate_candidates')
                    ->label('Дубл.')
                    ->formatStateUsing(fn ($state) => is_array($state) ? count($state) : 0)
                    ->badge()
                    ->color(fn ($state) => is_array($state) && count($state) > 0 ? 'warning' : 'gray'),
                TextColumn::make('data_sources_count')
                    ->label('Джерела')
                    ->counts('dataSources')
                    ->badge()
                    ->color(fn ($state) => (int) $state > 0 ? 'info' : 'gray')
                    ->sortable(),
                TextColumn::make('external_mentions_count')
                    ->label('Згадки')
                    ->counts('externalMentions')
                    ->badge()
                    ->color(fn ($state) => (int) $state > 0 ? 'warning' : 'gray')
                    ->sortable(),
                TextColumn::make('processed_at')
                    ->label('Оновлено')
                    ->state(fn (AiEnrichmentTask $record) => $record->processed_at ?: $record->updated_at)
                    ->dateTime('d.m H:i')
                    ->sortable(),
                TextColumn::make('error_message')
                    ->label('Помилка')
                    ->limit(42)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('id')->label('ID')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Статус')
                    ->options([
                        'pending' => 'Очікує',
                        'processing' => 'В процесі',
                        'data_found' => 'Дані знайдено',
                        'needs_review' => 'Потрібна перевірка',
                        'possible_duplicate' => 'Можливий дублікат',
                        'completed' => 'Завершено',
                        'error' => 'Помилка',
                        'rejected' => 'Відхилено',
                    ]),
                SelectFilter::make('confidence')
                    ->label('Confidence')
                    ->options([
                        'high' => '90-100%',
                        'good' => '70-89%',
                        'medium' => '40-69%',
                        'low' => '0-39%',
                        'empty' => 'Без оцінки',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'high' => $query->where('confidence_score', '>=', 90),
                            'good' => $query->whereBetween('confidence_score', [70, 89]),
                            'medium' => $query->whereBetween('confidence_score', [40, 69]),
                            'low' => $query->whereNotNull('confidence_score')->where('confidence_score', '<', 40),
                            'empty' => $query->whereNull('confidence_score'),
                            default => $query,
                        };
                    }),
                SelectFilter::make('profile_state')
                    ->label('Профіль')
                    ->options([
                        'created' => 'Профіль створено',
                        'missing' => 'Профіль не створено',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'created' => $query->whereNotNull('profile_id'),
                            'missing' => $query->whereNull('profile_id'),
                            default => $query,
                        };
                    }),
                Filter::make('has_duplicates')
                    ->label('Є дублікати')
                    ->query(fn (Builder $query): Builder => $query->whereJsonLength('duplicate_candidates', '>', 0)),
                Filter::make('has_sources')
                    ->label('Є джерела')
                    ->query(fn (Builder $query): Builder => $query->whereHas('dataSources')),
                Filter::make('has_external_mentions')
                    ->label('Є зовнішні згадки')
                    ->query(fn (Builder $query): Builder => $query->whereHas('externalMentions')),
                Filter::make('has_errors')
                    ->label('З помилками')
                    ->query(fn (Builder $query): Builder => $query->where(function (Builder $inner): void {
                        $inner->where('status', 'error')->orWhereNotNull('error_message');
                    })),
                Filter::make('insufficient_data')
                    ->label('Недостатньо даних')
                    ->query(fn (Builder $query): Builder => $query
                        ->where('status', 'rejected')
                        ->where('error_message', 'like', 'Недостатньо публічних даних:%')),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('details')
                        ->label('Деталі')
                        ->icon('heroicon-o-eye')
                        ->modalHeading(fn (AiEnrichmentTask $record) => 'AI-збагачення: '.$record->raw_name)
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel('Закрити')
                        ->modalWidth('5xl')
                        ->modalContent(fn (AiEnrichmentTask $record) => view(
                            'filament.resources.ai-enrichment-batches.task-details',
                            ['task' => $record->loadMissing(['profile', 'category', 'dataSources', 'externalMentions'])]
                        )),
                    Action::make('open_profile')
                        ->label('Відкрити профіль')
                        ->icon('heroicon-o-arrow-top-right-on-square')
                        ->url(fn (AiEnrichmentTask $record) => $record->profile_id
                            ? ProfileResource::getUrl('edit', ['record' => $record->profile_id])
                            : null)
                        ->visible(fn (AiEnrichmentTask $record) => filled($record->profile_id)),
                    Action::make('accept_suggestions')
                        ->label('Прийняти AI-дані')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->visible(fn (AiEnrichmentTask $record) => filled($record->profile_id) && ! empty($record->suggested_data))
                        ->action(function (AiEnrichmentTask $record): void {
                            $this->applySuggestedData($record);

                            Notification::make()
                                ->title('AI-дані застосовано до профілю')
                                ->success()
                                ->send();
                        }),
                    Action::make('publish_profile')
                        ->label('Опублікувати')
                        ->icon('heroicon-o-arrow-up-on-square')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->visible(fn (AiEnrichmentTask $record) => filled($record->profile_id))
                        ->action(function (AiEnrichmentTask $record): void {
                            $record->profile?->update([
                                'status' => 'active',
                                'is_published' => true,
                                'show_in_catalog' => true,
                                'ai_enrichment_status' => 'published',
                            ]);

                            $this->publishTaskReviews($record);
                            $record->update(['status' => 'completed']);

                            Notification::make()
                                ->title('Профіль і пов’язані відгуки опубліковано')
                                ->success()
                                ->send();
                        }),
                    Action::make('needs_review')
                        ->label('На перевірку')
                        ->icon('heroicon-o-exclamation-triangle')
                        ->color('warning')
                        ->visible(fn (AiEnrichmentTask $record) => $record->status !== 'needs_review')
                        ->action(function (AiEnrichmentTask $record): void {
                            $record->profile?->update(['ai_enrichment_status' => 'needs_review']);
                            $record->update(['status' => 'needs_review']);

                            Notification::make()
                                ->title('Задачу позначено як таку, що потребує перевірки')
                                ->warning()
                                ->send();
                        }),
                    Action::make('reject')
                        ->label('Відхилити')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->visible(fn (AiEnrichmentTask $record) => $record->status !== 'rejected')
                        ->action(function (AiEnrichmentTask $record): void {
                            $record->profile?->update([
                                'status' => 'draft',
                                'is_published' => false,
                                'show_in_catalog' => false,
                                'ai_enrichment_status' => 'rejected',
                            ]);

                            $record->update(['status' => 'rejected']);

                            Notification::make()
                                ->title('Задачу відхилено')
                                ->danger()
                                ->send();
                        }),
                ])->label('Дії')->icon('heroicon-o-ellipsis-horizontal')->size('sm'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('accept_suggestions')
                        ->label('Прийняти AI-дані')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $processed = 0;

                            $records->each(function (AiEnrichmentTask $record) use (&$processed): void {
                                if (! $record->profile_id || empty($record->suggested_data)) {
                                    return;
                                }

                                $this->applySuggestedData($record);
                                $processed++;
                            });

                            Notification::make()
                                ->title("AI-дані застосовано: {$processed}")
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('publish_profiles')
                        ->label('Опублікувати профілі')
                        ->icon('heroicon-o-arrow-up-on-square')
                        ->color('primary')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $processed = 0;

                            $records->each(function (AiEnrichmentTask $record) use (&$processed): void {
                                if (! $record->profile) {
                                    return;
                                }

                                $record->profile->update([
                                    'status' => 'active',
                                    'is_published' => true,
                                    'show_in_catalog' => true,
                                    'ai_enrichment_status' => 'published',
                                ]);

                                $this->publishTaskReviews($record);
                                $record->update(['status' => 'completed']);
                                $processed++;
                            });

                            Notification::make()
                                ->title("Профілі та відгуки опубліковано: {$processed}")
                                ->success()
                                ->send();
                        }),
                    BulkAction::make('mark_needs_review')
                        ->label('Позначити на перевірку')
                        ->icon('heroicon-o-exclamation-triangle')
                        ->color('warning')
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $records->each(function (AiEnrichmentTask $record): void {
                                $record->profile?->update(['ai_enrichment_status' => 'needs_review']);
                                $record->update(['status' => 'needs_review']);
                            });

                            Notification::make()
                                ->title('Вибрані задачі позначено як такі, що потребують перевірки')
                                ->warning()
                                ->send();
                        }),
                    BulkAction::make('reject')
                        ->label('Відхилити')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $records->each(function (AiEnrichmentTask $record): void {
                                $record->profile?->update([
                                    'status' => 'draft',
                                    'is_published' => false,
                                    'show_in_catalog' => false,
                                    'ai_enrichment_status' => 'rejected',
                                ]);

                                $record->update(['status' => 'rejected']);
                            });

                            Notification::make()
                                ->title('Вибрані задачі відхилено')
                                ->danger()
                                ->send();
                        }),
                ]),
            ])
            ->defaultSort('row_number');
    }

    private function applySuggestedData(AiEnrichmentTask $task): void
    {
        $profile = $task->profile;

        if (! $profile) {
            return;
        }

        $data = $task->suggested_data ?? [];

        $profile->fill([
            'name' => $data['name'] ?? $profile->name,
            'short_description' => $data['short_description'] ?? $profile->short_description,
            'description' => $data['description'] ?? $profile->description,
            'logo_url' => $profile->logo_url ?: ($data['logo_url'] ?? null),
            'website' => $data['website'] ?? $profile->website,
            'email' => $data['email'] ?? $profile->email,
            'phone' => $data['phone'] ?? $profile->phone,
            'address' => $data['address'] ?? $profile->address,
            'city' => $data['city'] ?? $profile->city,
            'social_links' => $data['social_links'] ?? $profile->social_links,
            'seo_title' => $data['seo_title'] ?? $profile->seo_title,
            'seo_description' => $data['seo_description'] ?? $profile->seo_description,
            'ai_suggested_data' => $data,
            'ai_confidence_score' => $task->confidence_score,
            'ai_enrichment_status' => 'accepted',
            'ai_enriched_at' => now(),
        ]);

        $profile->save();

        $task->update(['status' => 'completed']);
    }

    private function publishTaskReviews(AiEnrichmentTask $task): void
    {
        if (! $task->profile_id) {
            return;
        }

        $reviews = ProfileReview::query()
            ->where('profile_id', $task->profile_id)
            ->where('verification_type', 'external_ai_draft')
            ->where('moderation_reason', 'external_review_candidate')
            ->where(function (Builder $query) use ($task): void {
                $query->where('moderation_note', 'like', "%AI-збагачення #{$task->id}%")
                    ->orWhere('admin_note', 'like', "%AI-збагачення #{$task->id}%");
            })
            ->whereIn('status', ['pending', 'under_review', 'draft', 'hidden'])
            ->get();

        foreach ($reviews as $review) {
            if (blank($review->external_source_url) && filled($review->resolved_external_source_url)) {
                $review->external_source_url = $review->resolved_external_source_url;
            }

            $review->status = 'published';
            $review->published_at = now();
            $review->save();
        }

        app(ProfileReviewStatsService::class)->recalculateForProfileId((int) $task->profile_id);
        RefreshProfileReviewAiAnalysis::dispatch((int) $task->profile_id)->afterCommit();
    }
}
