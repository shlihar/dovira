<?php

namespace App\Filament\Resources\Reviews\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ReviewForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Review')
                    ->schema([
                        Select::make('profile_id')
                            ->label('Profile')
                            ->relationship('profile', 'name')
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('user_id')
                            ->label('User / Author')
                            ->relationship('author', 'email')
                            ->searchable()
                            ->preload(),
                        TextInput::make('author_name')
                            ->label('Author Name')
                            ->maxLength(255)
                            ->required(),
                        TextInput::make('author_email')
                            ->label('Author Email')
                            ->email(),
                        TextInput::make('rating')
                            ->label('Rating')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(5)
                            ->required(),
                        TextInput::make('title')
                            ->label('Title')
                            ->maxLength(255),
                        Textarea::make('body')
                            ->label('Text')
                            ->rows(6)
                            ->required()
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('Moderation & Flags')
                    ->schema([
                        Select::make('status')
                            ->label('Status')
                            ->options([
                                'pending' => 'pending',
                                'published' => 'published',
                                'rejected' => 'rejected',
                                'hidden' => 'hidden',
                                'under_review' => 'under_review',
                            ])
                            ->required()
                            ->default('pending'),
                        Toggle::make('is_verified_purchase')->label('Is Verified'),
                        Toggle::make('is_anonymous')->label('Is Anonymous'),
                        Toggle::make('is_suspicious')->label('Is Suspicious'),
                        Toggle::make('is_featured')->label('Is Featured'),
                        Select::make('verification_type')
                            ->label('Verification Type')
                            ->options([
                                'none' => 'none',
                                'purchase' => 'purchase',
                                'invoice' => 'invoice',
                                'manual' => 'manual',
                            ])
                            ->default('none'),
                        TextInput::make('moderation_reason')
                            ->label('Moderation Reason')
                            ->maxLength(64),
                        Textarea::make('admin_note')
                            ->label('Admin Note')
                            ->rows(3)
                            ->columnSpanFull(),
                        Textarea::make('moderation_note')
                            ->label('Moderation Note')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),
                Section::make('External Source')
                    ->description('Дані для AI-кандидатів у відгуки. Такі відгуки мають пройти ручну модерацію перед публікацією.')
                    ->schema([
                        TextInput::make('external_source_url')
                            ->label('Source URL')
                            ->url()
                            ->maxLength(2048),
                        TextInput::make('external_source_type')
                            ->label('Source Type')
                            ->maxLength(64),
                        TextInput::make('external_review_author')
                            ->label('External Author')
                            ->maxLength(255),
                        TextInput::make('external_review_date')
                            ->label('External Review Date')
                            ->type('date'),
                        TextInput::make('external_review_hash')
                            ->label('External Review Hash')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(2)
                    ->collapsed(),
                Section::make('Technical Data')
                    ->schema([
                        TextInput::make('author_ip')
                            ->label('IP Address')
                            ->maxLength(45),
                        Textarea::make('author_user_agent')
                            ->label('User Agent')
                            ->rows(3),
                        TextInput::make('risk_score')
                            ->label('Risk Score')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(0),
                    ])
                    ->columns(3),
            ])
            ->columns(1);
    }
}
