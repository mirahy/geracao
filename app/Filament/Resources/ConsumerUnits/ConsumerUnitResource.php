<?php

namespace App\Filament\Resources\ConsumerUnits;

use App\Filament\Resources\ConsumerUnits\Pages\CreateConsumerUnit;
use App\Filament\Resources\ConsumerUnits\Pages\EditConsumerUnit;
use App\Filament\Resources\ConsumerUnits\Pages\ListConsumerUnits;
use App\Filament\Resources\ConsumerUnits\Pages\ViewConsumerUnit;
use App\Filament\Resources\ConsumerUnits\Schemas\ConsumerUnitForm;
use App\Filament\Resources\ConsumerUnits\Schemas\ConsumerUnitInfolist;
use App\Filament\Resources\ConsumerUnits\Tables\ConsumerUnitsTable;
use App\Models\ConsumerUnit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ConsumerUnitResource extends Resource
{
    protected static ?string $model = ConsumerUnit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHomeModern;

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?string $modelLabel = 'unidade consumidora';

    protected static ?string $pluralModelLabel = 'unidades consumidoras';

    protected static ?string $navigationLabel = 'Unidades consumidoras';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return ConsumerUnitForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ConsumerUnitInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConsumerUnitsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConsumerUnits::route('/'),
            'create' => CreateConsumerUnit::route('/create'),
            'view' => ViewConsumerUnit::route('/{record}'),
            'edit' => EditConsumerUnit::route('/{record}/edit'),
        ];
    }
}
