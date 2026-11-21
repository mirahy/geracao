<?php

namespace App\Services\Documents;

use App\Support\Br;
use RuntimeException;

/**
 * Interpreta o "Demonstrativo de Compensação de Energia Injetada" da Energisa.
 */
class StatementParser
{
    public static function supports(string $text): bool
    {
        return str_contains($text, 'Demonstrativo de Compensação de Energia Injetada');
    }

    /** @return array<string, mixed> */
    public function parse(string $text): array
    {
        $lines = preg_split('/\R/u', $text);

        $data = [
            'holder_name' => null,
            'address' => null,
        ];

        if (! preg_match('/N[úu]mero da UC:\s*([\d.\-]+)/u', $text, $m)) {
            throw new RuntimeException('Número da UC não encontrado no demonstrativo.');
        }
        $data['uc_number'] = preg_replace('/\D/', '', $m[1]);

        if (! preg_match('#Refer[êe]ncia:\s*(\d{2}/\d{4})#u', $text, $m)) {
            throw new RuntimeException('Mês de referência não encontrado no demonstrativo.');
        }
        $data['reference_month'] = Br::monthYear($m[1]);

        // Nome (primeira linha após o site) e endereço, separados por "|"
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*(\S.*?)\s+\|\s+N[úu]mero da UC/u', $line, $mm)) {
                $data['holder_name'] = trim($mm[1]);
                $second = $lines[$i + 1] ?? '';
                if (preg_match('/^\s*(\S.*?)\s{2,}(\S.*)$/u', $second, $m2)) {
                    $data['holder_name'] .= ' '.trim($m2[1]);
                    $data['address'] = trim($m2[2]);
                }
                break;
            }
        }

        $data['history'] = $this->parseHistory($lines);
        $data['movements'] = $this->parseMovements($lines);
        $data['transfers'] = $this->parseTransfers($lines, $data['reference_month']);
        $data['previous_balance_composition'] = $this->parseComposition($lines);

        if (empty($data['history']) && empty($data['movements'])) {
            throw new RuntimeException('Nenhuma tabela de compensação reconhecida no demonstrativo.');
        }

        return $data;
    }

    /** Histórico mensal: Ano Mês Med.kWh Irr. Injetado Injet.Comp. Sobra Bruto */
    private function parseHistory(array $lines): array
    {
        $rows = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*(\d{4})\s+(\d{2})\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*$/', $line, $m)) {
                $rows[] = [
                    'reference_month' => Br::monthYear("{$m[2]}/{$m[1]}"),
                    'measured_kwh' => (float) $m[3],
                    'irregular_kwh' => (float) $m[4],
                    'injected_kwh' => (float) $m[5],
                    'compensated_kwh' => (float) $m[6],
                    'surplus_kwh' => (float) $m[7],
                    'gross_kwh' => (float) $m[8],
                ];
            }
        }

        return $rows;
    }

    /**
     * Movimentações do mês no posto Convencional (soma de GD I, II e III):
     * Saldo Anterior, Injetado, Recebido, Compensado, Transferido, Expirado, Disponível, Consumo.
     */
    private function parseMovements(array $lines): ?array
    {
        $inSection = false;
        $inConv = false;
        $sum = array_fill(0, 8, 0.0);
        $found = false;
        $expiration = null;

        foreach ($lines as $line) {
            if (str_contains($line, 'Movimentações do Mês')) {
                $inSection = true;

                continue;
            }
            if (! $inSection) {
                continue;
            }
            if (str_contains($line, 'Discriminação das Transferências') || str_contains($line, 'Composição e Utilização')) {
                break;
            }
            if (preg_match('/^\s*Conv\s*$/', $line)) {
                $inConv = true;

                continue;
            }
            if (preg_match('/^\s*(Pt|FPt|Itmdr)\s*$/', $line)) {
                $inConv = false;

                continue;
            }
            if ($inConv && preg_match('/^\s*GD\s*I{1,3}\s+((?:\d+\s+){7}\d+)(?:\s+(\d{2}\/\d{4}))?\s*$/', $line, $m)) {
                $values = preg_split('/\s+/', trim($m[1]));
                foreach ($values as $k => $v) {
                    $sum[$k] += (float) $v;
                }
                $found = true;
                $expiration = $m[2] ?? $expiration;
            }
        }

        if (! $found) {
            return null;
        }

        return [
            'balance_previous_kwh' => $sum[0],
            'injected_kwh' => $sum[1],
            'received_kwh' => $sum[2],
            'compensated_kwh' => $sum[3],
            'transferred_kwh' => $sum[4],
            'expired_kwh' => $sum[5],
            'balance_available_kwh' => $sum[6],
            'cycle_consumption_kwh' => $sum[7],
            'expiration_cycle' => $expiration,
        ];
    }

    /** % | UC compensada | 12 valores (3 por posto) | ciclo */
    private function parseTransfers(array $lines, $reference): array
    {
        $transfers = [];
        $inSection = false;

        foreach ($lines as $line) {
            if (str_contains($line, 'Discriminação das Transferências')) {
                $inSection = true;

                continue;
            }
            if (! $inSection) {
                continue;
            }
            if (str_contains($line, 'Composição e Utilização')) {
                break;
            }
            if (preg_match('/^\s*(\d{1,3})\s+(\d{9,13})\s+((?:-?\d+\s+){11}-?\d+)\s+(\d{2}\/\d{4})\s*$/', $line, $m)) {
                $values = array_map('floatval', preg_split('/\s+/', trim($m[3])));
                $transfers[] = [
                    'percentage' => (int) $m[1],
                    'uc_number' => $m[2],
                    // posto convencional = 3 primeiras colunas (GD I, II, III)
                    'kwh' => abs(array_sum(array_slice($values, 0, 3))),
                    'kwh_all_posts' => abs(array_sum($values)),
                    'cycle_month' => Br::monthYear($m[4]),
                ];
            }
        }

        return $transfers;
    }

    /** Composição do saldo anterior (créditos por mês de origem). */
    private function parseComposition(array $lines): array
    {
        $result = [];
        $inSection = false;
        $current = null;

        foreach ($lines as $line) {
            if (str_contains($line, 'Composição e Utilização do Saldo Anterior')) {
                $inSection = true;

                continue;
            }
            if (! $inSection) {
                continue;
            }
            if (preg_match('#^\s*(\d{4})\s+(\d{2})\s+Conv\s+((?:[\d.]+\s+){8}[\d.]+)\s+(\d{2}/\d{4})\s*$#', $line, $m)) {
                $v = array_map('floatval', preg_split('/\s+/', trim($m[3])));
                $result[] = [
                    'month' => sprintf('%s-%s', $m[1], $m[2]),
                    'previous' => $v[0] + $v[1] + $v[2],
                    'compensated' => $v[3] + $v[4] + $v[5],
                    'available' => $v[6] + $v[7] + $v[8],
                    'expiration' => $m[4],
                ];
            }
        }

        return $result;
    }
}
