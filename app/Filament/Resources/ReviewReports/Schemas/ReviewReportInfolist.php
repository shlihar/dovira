<?php

namespace App\Filament\Resources\ReviewReports\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ReviewReportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Скарга')
                    ->schema([
                        TextEntry::make('status')
                            ->label('Статус')
                            ->badge()
                            ->color(fn (string $state) => match ($state) {
                                'resolved' => 'success',
                                'in_review' => 'warning',
                                'rejected' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('reason')->label('Причина')->placeholder('—'),
                        TextEntry::make('details')->label('Деталі')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('reporter.email')->label('Хто подав')->placeholder('—'),
                        TextEntry::make('created_at')->label('Подано')->dateTime('d.m.Y H:i'),
                        TextEntry::make('moderator_note')->label('Нотатка модератора')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('resolver.email')->label('Хто вирішив')->placeholder('—'),
                        TextEntry::make('resolved_at')->label('Вирішено')->dateTime('d.m.Y H:i')->placeholder('—'),
                    ]),

                Section::make('Відгук')
                    ->schema([
                        TextEntry::make('review.profile.name')->label('Профіль')->placeholder('—'),
                        TextEntry::make('review.author_name')->label('Автор відгуку')->placeholder('Анонім'),
                        TextEntry::make('review.rating')->label('Оцінка')->placeholder('—'),
                        TextEntry::make('review.status')
                            ->label('Стан відгуку')
                            ->badge()
                            ->color(fn (?string $state) => match ($state) {
                                'published' => 'success',
                                'hidden' => 'warning',
                                'rejected' => 'danger',
                                default => 'gray',
                            })
                            ->placeholder('—'),
                        TextEntry::make('review.body')->label('Текст відгуку')->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }
}
