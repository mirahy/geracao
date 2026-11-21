<?php

namespace App\Filament\Actions;

use App\Models\ConsumerUnit;
use App\Services\Solar\SolarGenerationConsolidator;
use Filament\Actions\Action;
use Filament\Forms\Components\Toggle;
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
            ->visible(fn () => self::pending()->isNotEmpty())
            ->modalHeading('Revisar produção importada')
            ->modalDescription('Estes meses não foram gravados automaticamente. Marque os que deseja gravar; os demais permanecem como estão.')
            ->modalSubmitActionLabel('Gravar selecionados')
            ->modalCancelActionLabel('Não gravar agora')
            ->schema(fn () => self::pending()->map(fn (array $item) => self::section($item))->all())
            ->action(function (array $data): void {
                $consolidator = app(SolarGenerationConsolidator::class);
                $saved = 0;

                foreach (self::generators() as $unit) {
                    $months = collect($data)->filter()->keys()
                        ->map(fn ($key) => preg_replace('/^m_(\d{4})(\d{2})$/', '$1-$2', $key))->all();
                    $saved += $consolidator->apply($unit, $months);
                }

                Notification::make()
                    ->title($saved ? "{$saved} mês(es) gravado(s)" : 'Nada foi gravado')
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
        return ConsumerUnit::where('role', ConsumerUnit::ROLE_GENERATOR)->get();
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
                Toggle::make('m_'.str_replace('-', '', substr($item['month'], 0, 7)))
                    ->label($action)
                    ->default(false),
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
