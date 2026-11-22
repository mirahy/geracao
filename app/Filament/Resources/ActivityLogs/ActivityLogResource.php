<?php

namespace App\Filament\Resources\ActivityLogs;

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Registro de atividades: acessos, visualizações e alterações (somente leitura). */
class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Administração';

    protected static ?string $modelLabel = 'registro';

    protected static ?string $pluralModelLabel = 'registro de atividades';

    protected static ?string $navigationLabel = 'Registro de atividades';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Quando')->dateTime('d/m/Y H:i:s')->sortable(),
                TextColumn::make('log_name')->label('Tipo')->badge()
                    ->formatStateUsing(fn (string $state) => ActivityLog::TYPE_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => ActivityLog::TYPE_COLORS[$state] ?? 'gray'),
                TextColumn::make('causer_name')->label('Usuário')->placeholder('Sistema')->searchable(),
                TextColumn::make('description')->label('O que aconteceu')->wrap()->searchable()
                    ->description(function (ActivityLog $record): ?string {
                        $properties = $record->properties?->toArray() ?? [];
                        if ($record->event !== 'updated' || empty($properties['attributes'])) {
                            return null;
                        }

                        return collect($properties['attributes'])
                            ->map(fn ($new, $field) => $field.': '.self::show($properties['old'][$field] ?? null).' → '.self::show($new))
                            ->implode(' · ');
                    }),
                TextColumn::make('channel')->label('Origem')->badge()
                    ->formatStateUsing(fn (string $state) => ActivityLog::CHANNEL_LABELS[$state] ?? $state)
                    ->color('gray'),
                TextColumn::make('ip')->label('IP')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('log_name')->label('Tipo')->options(ActivityLog::TYPE_LABELS),
                SelectFilter::make('causer_id')->label('Usuário')
                    ->options(fn () => ActivityLog::query()->whereNotNull('causer_id')->distinct()->orderBy('causer_name')->pluck('causer_name', 'causer_id')->all()),
                SelectFilter::make('channel')->label('Origem')->options(ActivityLog::CHANNEL_LABELS),
                Filter::make('period')->label('Período')
                    ->schema([
                        DatePicker::make('from')->label('De'),
                        DatePicker::make('to')->label('Até'),
                    ])->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['to'] ?? null, fn (Builder $q, $d) => $q->whereDate('created_at', '<=', $d))),
            ])
            ->defaultSort('created_at', 'desc')
            ->paginated([25, 50, 100]);
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE),
            is_bool($value) => $value ? 'sim' : 'não',
            default => (string) $value,
        };
    }

    public static function getPages(): array
    {
        return ['index' => ListActivityLogs::route('/')];
    }
}
