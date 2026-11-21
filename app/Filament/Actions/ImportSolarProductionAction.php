<?php

namespace App\Filament\Actions;

use App\Models\ConsumerUnit;
use App\Models\SolarDailyProduction;
use App\Services\Solar\DailyProductionSpreadsheet;
use App\Services\Solar\SolarGenerationConsolidator;
use App\Support\UnitOptions;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
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
            ->visible(fn () => auth()->user()?->can('geracao.editar'))
            ->modalHeading('Importar produção diária das placas')
            ->modalDescription('Envie a planilha "Estatísticas Diárias" (.xlsx) do aplicativo do inversor. Os dias são somados conforme o período de leitura de cada fatura da unidade geradora. Períodos incompletos e valores diferentes dos lançados manualmente abrem uma tela para você decidir.')
            ->modalSubmitActionLabel('Importar')
            ->schema([
                Select::make('consumer_unit_id')
                    ->label('Unidade geradora')
                    ->options(fn () => UnitOptions::editableGeneratorOptions())
                    ->default(fn () => UnitOptions::editableGenerators()->value('id'))
                    ->required(),
                FileUpload::make('file')
                    ->label('Planilha (.xlsx)')
                    ->acceptedFileTypes(['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                    ->maxSize(5120)
                    ->disk('local')
                    ->directory('imports')
                    ->visibility('private')
                    ->required(),
            ])
            ->action(function (array $data, $livewire): void {
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

                $result = app(SolarGenerationConsolidator::class)->consolidate($unit);
                $pending = $result['pending']->count();

                Notification::make()
                    ->title('Planilha importada')
                    ->body(collect([
                        count($rows).' dia(s) importado(s).',
                        $result['updated'] ? "{$result['updated']} mês(es) gravado(s) pelo período da fatura." : null,
                        $pending ? "{$pending} período(s) aguardam sua decisão." : null,
                        ! $result['updated'] && ! $pending ? 'Nenhum mês fechado: importe as faturas da unidade geradora cujo período esteja coberto pela planilha.' : null,
                    ])->filter()->implode("\n"))
                    ->color($pending ? 'warning' : 'success')
                    ->send();

                if ($pending) {
                    $livewire->replaceMountedAction('revisarProducaoDiaria');
                }
            });
    }
}
