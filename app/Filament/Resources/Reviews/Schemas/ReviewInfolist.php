<?php

namespace App\Filament\Resources\Reviews\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ReviewInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Review Details')
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('id')->label('ID'),
                        TextEntry::make('profile.name')->label('Profile'),
                        TextEntry::make('author_name')->label('Author'),
                        TextEntry::make('author.email')->label('Author User')->placeholder('—'),
                        TextEntry::make('author_email')->label('Author Email')->placeholder('—'),
                        TextEntry::make('rating')->label('Rating'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state) => match ($state) {
                                'published' => 'success',
                                'pending', 'under_review' => 'warning',
                                'rejected', 'hidden' => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('verification_type')->label('Verification Type')->placeholder('none'),
                        IconEntry::make('is_verified_purchase')->label('Is Verified')->boolean(),
                        IconEntry::make('is_anonymous')->label('Is Anonymous')->boolean(),
                        IconEntry::make('is_suspicious')->label('Is Suspicious')->boolean(),
                        IconEntry::make('is_featured')->label('Is Featured')->boolean(),
                        TextEntry::make('reports_count')->label('Reports Count')->state(fn ($record) => $record->reports()->count()),
                        TextEntry::make('created_at')->label('Created At')->dateTime('Y-m-d H:i'),
                        TextEntry::make('author_ip')->label('IP Address')->placeholder('—'),
                        TextEntry::make('risk_score')->label('Risk Score')->placeholder('0'),
                        TextEntry::make('author_user_agent')->label('User Agent')->columnSpanFull()->placeholder('—'),
                        TextEntry::make('title')->label('Title')->placeholder('—'),
                        TextEntry::make('body')->label('Full Review Text')->markdown()->columnSpanFull(),
                        TextEntry::make('external_source_url')
                            ->label('External Source')
                            ->url(fn ($record) => $record->external_source_url)
                            ->openUrlInNewTab()
                            ->placeholder('—')
                            ->columnSpanFull(),
                        TextEntry::make('external_source_type')->label('External Source Type')->placeholder('—'),
                        TextEntry::make('external_review_author')->label('External Review Author')->placeholder('—'),
                        TextEntry::make('external_review_date')->label('External Review Date')->date()->placeholder('—'),
                        TextEntry::make('admin_note')->label('Admin Notes')->columnSpanFull()->placeholder('—'),
                        TextEntry::make('moderation_reason')->label('Moderation Reason')->placeholder('—'),
                        TextEntry::make('moderation_note')->label('Moderation History')->columnSpanFull()->placeholder('—'),
                    ])
                    ->columns(3),
            ]);
    }
}
