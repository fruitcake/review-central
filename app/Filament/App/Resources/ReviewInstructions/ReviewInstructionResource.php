<?php

namespace App\Filament\App\Resources\ReviewInstructions;

use App\Filament\App\Resources\ReviewInstructions\Pages\CreateReviewInstruction;
use App\Filament\App\Resources\ReviewInstructions\Pages\EditReviewInstruction;
use App\Filament\App\Resources\ReviewInstructions\Pages\ListReviewInstructions;
use App\Filament\Components\ReviewInstructionSchema;
use App\Filament\Concerns\ForTenantAdmins;
use App\Filament\CurrentTenant;
use App\Models\ReviewInstruction;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * A tenant's own review instructions. A file with the key of a default replaces
 * that default for this tenant; any other key is read in addition.
 */
class ReviewInstructionResource extends Resource
{
    use ForTenantAdmins;

    protected static ?string $model = ReviewInstruction::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?string $navigationLabel = 'Instructions';

    protected static ?string $modelLabel = 'instruction';

    protected static ?string $recordTitleAttribute = 'key';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return ReviewInstructionSchema::form($schema, CurrentTenant::get()->getKey());
    }

    public static function table(Table $table): Table
    {
        return ReviewInstructionSchema::table($table)
            ->emptyStateHeading('The default instructions apply')
            ->emptyStateDescription('Add a file of your own, or start from a default to change it for your repositories.');
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
