<?php

namespace App\Services\Solar;

use InvalidArgumentException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Lê a planilha de "Estatísticas Diárias" exportada pelo aplicativo do inversor (.xlsx),
 * sem depender de bibliotecas externas: um .xlsx é um zip com XML.
 */
class DailyProductionSpreadsheet
{
    /**
     * @return array<int, array{date: string, kwh: float, revenue: ?float}>
     */
    public function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new InvalidArgumentException('Arquivo .xlsx inválido.');
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();

        if ($sheetXml === false) {
            throw new InvalidArgumentException('Planilha sem dados.');
        }

        $shared = [];
        if ($sharedXml !== false) {
            foreach ((new SimpleXMLElement($sharedXml))->si as $si) {
                $shared[] = $this->plain((string) ($si->t ?? collect($si->r)->map(fn ($r) => (string) $r->t)->implode('')));
            }
        }

        $rows = [];
        foreach ((new SimpleXMLElement($sheetXml))->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $column = preg_replace('/\d+/', '', (string) $cell['r']);
                $value = (string) $cell->v;
                $cells[$column] = ((string) $cell['t']) === 's' ? ($shared[(int) $value] ?? '') : $this->plain($value);
            }
            $rows[] = $cells;
        }

        $header = array_shift($rows) ?? [];
        $dateColumn = $this->findColumn($header, 'tempo');
        $kwhColumn = $this->findColumn($header, 'produ');
        $revenueColumn = $this->findColumn($header, 'rendimento');

        if (! $dateColumn || ! $kwhColumn) {
            throw new InvalidArgumentException('Formato não reconhecido: esperadas as colunas de data e "Produção(kWh)".');
        }

        $result = [];
        foreach ($rows as $cells) {
            $date = $this->parseDate($cells[$dateColumn] ?? '');
            $kwh = $this->parseNumber($cells[$kwhColumn] ?? '');
            if ($date === null || $kwh === null) {
                continue;
            }
            $result[$date] = [
                'date' => $date,
                'kwh' => $kwh,
                'revenue' => $revenueColumn ? $this->parseNumber($cells[$revenueColumn] ?? '') : null,
            ];
        }

        ksort($result);

        return array_values($result);
    }

    private function plain(string $value): string
    {
        return trim(str_replace("\u{a0}", ' ', $value));
    }

    private function findColumn(array $header, string $needle): ?string
    {
        foreach ($header as $column => $title) {
            if (str_contains(mb_strtolower($title), $needle)) {
                return $column;
            }
        }

        return null;
    }

    private function parseDate(string $value): ?string
    {
        return preg_match('#^(\d{4})[/-](\d{2})[/-](\d{2})#', $value, $m) ? "{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    private function parseNumber(string $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }
}
