<?php

namespace App\Filament\Components;

use App\Models\ReviewInstruction;
use App\Services\ReviewInstructions;
use App\Services\ReviewStack;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * The form and table of the review instructions, which the defaults in the admin
 * panel and a tenant's own in its panel share.
 */
class ReviewInstructionSchema
{
    /**
     * @param  int|null  $tenantId  whose instructions these are; null for the defaults
     */
    public static function form(Schema $schema, ?int $tenantId): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(2)
                ->columnSpanFull()
                ->schema([
                    TextInput::make('key')
                        ->label(__('Key'))
                        ->required()
                        ->maxLength(64)
                        ->regex(ReviewInstructions::KEY_PATTERN)
                        // How a review works is the platform's to change, and it does so by its own action.
                        // The platform's own copy keeps its name, so the rule is for a file that is not one.
                        ->rule(Rule::notIn(array_keys(ReviewInstructions::BASE)), fn (?ReviewInstruction $record): bool => $record === null || ! array_key_exists($record->key, ReviewInstructions::BASE))
                        ->disabled(fn (?ReviewInstruction $record): bool => $record !== null && array_key_exists($record->key, ReviewInstructions::BASE))
                        ->validationMessages(['regex' => __('Lowercase letters, digits, dots, dashes and underscores, starting with a letter or digit: the review refuses any other file name.')])
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $tenantId === null ? $rule->whereNull('tenant_id') : $rule->where('tenant_id', $tenantId))
                        ->helperText(__('The review reads it as .review-instructions/<key>.md. `method` is the guidance for a pull request\'s review, and `stack-<name>` a stack\'s guide: keep those keys.')),
                    TextInput::make('title')->label(__('Title'))->required()->maxLength(255),
                    TextInput::make('stack')
                        ->label(__('Stack'))
                        ->maxLength(40)
                        ->regex(ReviewStack::NAME_PATTERN)
                        ->datalist(ReviewStack::STACKS)
                        ->placeholder(__('Every repository'))
                        ->validationMessages(['regex' => __('Lowercase letters, digits and dashes, such as symfony or next-js.')])
                        ->helperText(__('A stack guide goes only to repositories set to that stack. One of the stacks listed, or a name of your own for a stack there is no guide for yet: give it the key stack-<name>, and it can be chosen on a repository.')),
                    TextInput::make('position')
                        ->label(__('Position'))
                        ->integer()
                        ->default(20)
                        ->required()
                        ->helperText(__('The review reads them in this order, lowest first.')),
                ]),
            Section::make(__('This is how a review works, not what it looks for'))
                ->description(__('It replaces what ships with the review for every tenant, and the review\'s scripts read the report it describes. Keep the report\'s fields and the names of the sections; a report the scripts cannot read is a review that fails. Deleting this copy puts the shipped text back.'))
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->iconColor('warning')
                ->columnSpanFull()
                ->visible(fn (?ReviewInstruction $record): bool => $record !== null && $record->tenant_id === null && array_key_exists($record->key, ReviewInstructions::BASE))
                ->schema([]),
            Section::make(__('Text'))
                ->description(__('What the review reads, from its next run on. How a review works and what its report looks like is not in here: that is fixed, with the review itself.'))
                ->columnSpanFull()
                ->schema([
                    Textarea::make('body')
                        ->hiddenLabel()
                        ->required()
                        ->rows(30)
                        ->extraInputAttributes(['class' => 'font-mono text-sm']),
                ]),
            Section::make(__('What this release ships'))
                ->description(__('The text as it comes with the review, to compare with or to copy from. For a default, "Import the bundled instructions" puts it back.'))
                ->columnSpanFull()
                ->collapsible()
                ->collapsed()
                ->visible(fn (?ReviewInstruction $record): bool => $record !== null && ReviewInstructions::shipped($record->key) !== null)
                ->schema([
                    TextEntry::make('shipped')
                        ->hiddenLabel()
                        ->state(fn (?ReviewInstruction $record): ?string => $record === null ? null : ReviewInstructions::shipped($record->key))
                        ->fontFamily('mono')
                        ->extraAttributes(['style' => 'white-space: pre-wrap']),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('position')->label('#')->sortable(),
                TextColumn::make('key')
                    ->label(__('Key'))
                    ->fontFamily('mono')
                    ->description(fn (ReviewInstruction $record): string => $record->title)
                    ->searchable(['key', 'title']),
                TextColumn::make('stack')->label(__('Stack'))->badge()->color('gray')->placeholder(__('Every repository')),
                TextColumn::make('updated_at')->label(__('Updated'))->since()->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
