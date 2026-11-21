<?php

namespace App\Filament\Actions;

use App\Models\ConsumerUnit;
use App\Models\SolarDailyProduction;
use App\Services\Solar\DailyProductionSpreadsheet;
use App\Services\Solar\SolarGenerationConsolidator;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ImportSolarProductionAction
{
    public static function make(string $name = 'importarProducaoDiaria'): Action
    {
        return Action::make($name)
            ->label('Importar planilha diária')
            ->icon(Heroicon::OutlinedTableCells)
            ->color('primary')
            ->modalHeading('Importar produção diária das placas')
            ->modalDescription('Envie a planilha "Estatísticas Diárias" (.xlsx) do aplicativo do inversor. Os dias são somados conforme o período de leitura de cada fatura da unidade geradora e gravados como a geração do mês. Lançamentos manuais não são sobrescritos.')
            ->modalSubmitActionLabel('Importar')
            ->schema([
                Select::make('consumer_unit_id')
                    ->label('Unidade geradora')
                    ->options(fn () => ConsumerUnit::where('role', ConsumerUnit::ROLE_GENERATOR)->orderBy('name')->pluck('name', 'id'))
                    ->default(fn () => ConsumerUnit::where('role', ConsumerUnit::ROLE_GENERATOR)->value('id'))
                    ->required(),
                FileUpload::make('file')
                    ->label('Planilha (.xlsx)')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    ->maxSize(5120)
                    ->disk('local')
                    ->directory('imports')
                    ->visibility('private')
                    ->required(),
                Toggle::make('allow_incomplete')
                    ->label('Gravar também períodos incompletos')
                    ->helperText('Quando a planilha não cobre todos os dias do período da fatura, grava a soma dos dias disponíveis (marcada como parcial nas observações). Desligado, esses meses são ignorados.')
                    ->default(false),
            ])
            ->action(function (array $data): void {
                $disk = Storage::disk('local');
                $path = $disk->path($data['file']);

                try {
                    $rows = app(DailyProductionSpreadsheet::class)->read($path);
                } catch (Throwable $e) {
                    Notification::make()->title('Não foi possível ler a planilha')->body($e->getMessage())->danger()->send();

                    return;
                } finally {
                    $disk->delete($data['file']);
                }

                $unit = ConsumerUnit::findOrFail($data['consumer_unit_id']);

                foreach ($rows as $row) {
                    SolarDailyProduction::updateOrCreate(
                        ['consumer_unit_id' => $unit->id, 'date' => $row['date']],
                        ['generated_kwh' => $row['kwh'], 'revenue' => $row['revenue']],
                    );
                }

                $result = app(SolarGenerationConsolidator::class)->consolidate($unit, (bool) ($data['allow_incomplete'] ?? false));

                $body = collect([
                    count($rows).' dia(s) importado(s).',
                    $result['updated'] ? "{$result['updated']} mês(es) atualizado(s) pelo período da fatura." : 'Nenhum mês fechado: importe as faturas da unidade geradora cujo período esteja coberto pela planilha.',
                    $result['incomplete'] ? 'Períodos com dias faltando ('.($data['allow_incomplete'] ?? false ? 'gravados como parciais' : 'não gravados').'): '.implode(', ', $result['incomplete']).'.' : null,
                    $result['kept_manual'] ? 'Mantidos os valores manuais de: '.implode(', ', $result['kept_manual']).'.' : null,
                ])->filter()->implode("\n");

                Notification::make()
                    ->title('Planilha importada')
                    ->body($body)
                    ->color($result['incomplete'] ? 'warning' : 'success')
                    ->persistent((bool) $result['incomplete'])
                    ->send();
            });
    }
}
