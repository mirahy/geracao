<?php

namespace App\Filament\Actions;

use App\Models\ConsumerUnit;
use App\Services\Solar\SolarGenerationConsolidator;
use App\Support\UnitOptions;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/** Revisão dos meses da planilha que não foram gravados sozinhos (incompletos ou divergentes do manual). */
class ReviewSolarProductionAction
{
    public static function make(string $name = 'revisarProducaoDiaria'): Action
    {
        return Action::make($name)
            ->label('Revisar importação')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->color('warning')
            ->badge(fn () => self::pending()->count() ?: null)
            ->visible(fn () => auth()->user()?->can('geracao.editar') && self::pending()->isNotEmpty())
            ->modalHeading('Revisar produção importada')
            ->modalDescription('Estes meses não foram gravados automaticamente. Escolha para cada mês: gravar o valor da planilha, deixar para decidir depois ou descartar a pendência (ela só volta se uma nova planilha mudar os dados do mês).')
            ->modalSubmitActionLabel('Aplicar')
            ->modalCancelActionLabel('Fechar')
            ->schema(fn () => self::pending()->map(fn (array $item) => self::section($item))->all())
            ->action(function (array $data): void {
                $consolidator = app(SolarGenerationConsolidator::class);
                $monthsWith = fn (string $choice) => collect($data)->filter(fn ($value) => $value === $choice)->keys()
                    ->map(fn ($key) => preg_replace('/^m_(\d{4})(\d{2})$/', '$1-$2', $key))->all();
                $saved = $dismissed = 0;

                foreach (self::generators() as $unit) {
                    $saved += $consolidator->apply($unit, $monthsWith('save'));
                    $dismissed += $consolidator->dismiss($unit, $monthsWith('dismiss'));
                }

                Notification::make()
                    ->title('Revisão concluída')
                    ->body(collect([
                        $saved ? "{$saved} mês(es) gravado(s)." : null,
                        $dismissed ? "{$dismissed} pendência(s) descartada(s)." : null,
                        ! $saved && ! $dismissed ? 'Nada foi alterado.' : null,
                    ])->filter()->implode(' '))
                    ->success()
                    ->send();
            });
    }

    /** @return Collection<int, array> */
    private static function pending(): Collection
    {
        $consolidator = app(SolarGenerationConsolidator::class);

        return self::generators()->flatMap(
            fn (ConsumerUnit $unit) => $consolidator->pending($unit)->map(fn (array $p) => $p + ['unit' => $unit->name])
        )->values();
    }

    /** @return Collection<int, ConsumerUnit> */
    private static function generators(): Collection
    {
        return UnitOptions::editableGenerators()->get();
    }

    private static function section(array $item): Section
    {
        $fmt = fn (float $kwh) => number_format($kwh, 2, ',', '.').' kWh';
        $period = $item['start']->format('d/m/Y').' a '.$item['end']->format('d/m/Y');
        $existing = $item['existing'];

        $lines = [
            "Período da fatura: {$period} ({$item['expected']} dias).",
            $item['complete']
                ? "Planilha: {$item['days']} de {$item['expected']} dias — {$fmt($item['kwh'])}."
                : "Planilha incompleta: só {$item['days']} de {$item['expected']} dias — soma parcial de {$fmt($item['kwh'])}.",
        ];

        if ($existing) {
            $origin = $existing->source === 'manual' ? 'lançado manualmente' : 'gravado de importação anterior';
            $diff = $item['kwh'] - $existing->generated_kwh;
            $lines[] = "Atualmente ({$origin}): {$fmt($existing->generated_kwh)}. Diferença da planilha: ".($diff >= 0 ? '+' : '−').$fmt(abs($diff)).'.';
        } else {
            $lines[] = 'Atualmente: nenhum valor gravado para este mês.';
        }

        $action = $existing ? 'Substituir pelo valor da planilha' : 'Gravar soma parcial da planilha';

        return Section::make(self::title($item))
            ->description(implode(' ', $lines))
            ->schema([
                Radio::make('m_'.str_replace('-', '', substr($item['month'], 0, 7)))
                    ->hiddenLabel()
                    ->options([
                        'later' => 'Decidir depois (manter como está)',
                        'save' => $action,
                        'dismiss' => 'Descartar esta pendência',
                    ])
                    ->default('later'),
            ])
            ->compact();
    }

    private static function title(array $item): string
    {
        $tags = [];
        if (! $item['complete']) {
            $tags[] = 'incompleto';
        }
        if ($item['conflict']) {
            $tags[] = 'diverge do manual';
        }

        return $item['label'].' — '.implode(', ', $tags);
    }
}
