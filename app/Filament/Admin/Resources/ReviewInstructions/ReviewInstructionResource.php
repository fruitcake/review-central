<?php

namespace App\Filament\Admin\Resources\ReviewInstructions;

use App\Filament\Admin\Resources\ReviewInstructions\Pages\CreateReviewInstruction;
use App\Filament\Admin\Resources\ReviewInstructions\Pages\EditReviewInstruction;
use App\Filament\Admin\Resources\ReviewInstructions\Pages\ListReviewInstructions;
use App\Filament\Components\ReviewInstructionSchema;
use App\Models\ReviewInstruction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The default review instructions: what every tenant's reviews are told to look
 * for, until a tenant writes its own file of the same key.
 */
class ReviewInstructionResource extends Resource
{
    protected static ?string $model = ReviewInstruction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $modelLabel = 'default instruction';

    protected static ?string $recordTitleAttribute = 'key';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereNull('tenant_id');
    }

    public static function form(Schema $schema): Schema
    {
        return ReviewInstructionSchema::form($schema, null);
    }

    public static function table(Table $table): Table
    {
        return ReviewInstructionSchema::table($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviewInstructions::route('/'),
            'create' => CreateReviewInstruction::route('/create'),
            'edit' => EditReviewInstruction::route('/{record}/edit'),
        ];
    }
}
