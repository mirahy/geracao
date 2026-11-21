<?php

namespace App\Services\Documents;

use App\Support\Br;
use RuntimeException;

/**
 * Interpreta o texto (pdftotext -layout) de uma fatura DANF3E da Energisa.
 */
class InvoiceParser
{
    private const NUM = '-?\d{1,3}(?:\.\d{3})*,\d{2,6}|-?\d+,\d{2,6}';

    public static function supports(string $text): bool
    {
        return str_contains($text, 'DANF3E') && str_contains($text, 'Itens da Fatura');
    }

    /** @return array<string, mixed> */
    public function parse(string $text): array
    {
        $lines = preg_split('/\R/u', $text);

        $data = [];
        $this->parseIdentification($text, $lines, $data);
        $this->parseHeaderLine($text, $data);
        $this->parseDocument($text, $data);
        $data['items'] = $this->parseItems($lines);
        $this->parseTotals($text, $data);
        $this->parseMeter($text, $data);
        $this->parseCostComposition($text, $data);
        $this->parseCredits($text, $data);
        $this->summarizeItems($data);

        if (empty($data['uc_number']) || empty($data['reference_month']) || $data['total_amount'] === null) {
            throw new RuntimeException('Não foi possível identificar a UC, o mês de referência ou o valor da fatura.');
        }

        return $data;
    }

    private function parseIdentification(string $text, array $lines, array &$data): void
    {
        // Número da UC impresso após o bairro (ex.: 917.358.051-98 / 1.665.339.051-67)
        $data['uc_number'] = null;
        if (preg_match('/\b(\d{1,3}(?:\.\d{3})+-\d{2})\b/', $text, $m)) {
            $data['uc_number'] = preg_replace('/\D/', '', $m[1]);
        }

        // Código da instalação: "...DÉBITO AUTOMÁTICO UTILIZANDO O CÓDIGO: 0002496572-5"
        $data['code'] = null;
        if (preg_match('/C[ÓO]DIGO:\s*0*(\d+-\d)/u', $text, $m)) {
            $data['code'] = $m[1];
        }

        if (preg_match('/Classifica[çc][ãa]o:\s*(.+?)\s*(?:\R|$)/u', $text, $m)) {
            $class = trim($m[1]);
            // a classificação pode quebrar em duas linhas (RESIDENCIAL / RESIDENCIAL)
            if (preg_match('/Classifica[çc][ãa]o:[^\n]*\n\s*([A-ZÀ-Ú \/]+?)\s{2,}LIGA/u', $text, $m2)) {
                $class .= ' '.trim($m2[1]);
            }
            $data['classification'] = preg_replace('/\s+/', ' ', trim($class));
        }

        if (preg_match('/Cônjuge:\s*(.+?)\s*(?:\R|$)/u', $text, $m)) {
            $data['spouse'] = trim($m[1]);
        }

        // Linha: NOME   leitura anterior   leitura atual   dias   próxima leitura
        $nameIndex = null;
        foreach ($lines as $i => $line) {
            if (preg_match('#^\s*(?<name>[A-ZÀ-Ú][A-ZÀ-Ú \.]+?)\s{2,}(?<prev>\d{2}/\d{2}/\d{4})\s+(?<curr>\d{2}/\d{2}/\d{4})\s+(?<days>\d+)\s+(?<next>\d{2}/\d{2}/\d{4})#u', $line, $m)) {
                $nameIndex = $i;
                $data['holder_name'] = trim($m['name']);
                $data['reading_previous_date'] = Br::date($m['prev']);
                $data['reading_current_date'] = Br::date($m['curr']);
                $data['billing_days'] = (int) $m['days'];
                $data['next_reading_date'] = Br::date($m['next']);
                break;
            }
        }

        if ($nameIndex !== null) {
            $rest = [];
            for ($j = $nameIndex + 1; $j < min(count($lines), $nameIndex + 9); $j++) {
                if (trim($lines[$j]) !== '') {
                    $rest[] = $lines[$j];
                }
            }

            // 1ª linha: endereço (pode quebrar em duas linhas terminando em "-")
            $address = preg_replace('/\s{2,}.*/', '', trim($rest[0] ?? ''));
            $next = 1;
            if (str_ends_with($address, '-') && isset($rest[1])) {
                $address .= ' '.preg_replace('/\s{2,}.*/', '', trim($rest[1]));
                $next = 2;
            }
            $data['address'] = $address ?: null;

            // Bairro: texto que antecede o número da UC
            $data['neighborhood'] = null;
            foreach (array_slice($rest, $next) as $line) {
                if (preg_match('/^\s*([A-ZÀ-Ú][A-ZÀ-Ú \.]+?)\s{2,}\d{1,3}(?:\.\d{3})+-\d{2}/u', $line, $m)) {
                    $data['neighborhood'] = trim($m[1]);
                    break;
                }
            }

            foreach (array_slice($rest, $next) as $line) {
                if (preg_match('/^\s*([A-ZÀ-Ú][A-ZÀ-Ú \.\']+?)\s*\(AG:\s*\d+\)/u', $line, $m)) {
                    $data['city'] = trim($m[1]);
                    break;
                }
            }
        }
    }

    private function parseHeaderLine(string $text, array &$data): void
    {
        // "Novembro / 2026    26/12/2026    R$ 110,56"
        $data['reference_month'] = null;
        $data['due_date'] = null;
        $data['total_amount'] = null;

        if (preg_match('#([A-Za-zçÇãÃ]+)\s*/\s*(\d{4})\s+(\d{2}/\d{2}/\d{4})\s+R\$\s*(' . self::NUM . ')#u', $text, $m)) {
            $data['reference_month'] = Br::monthName($m[1], (int) $m[2]);
            $data['due_date'] = Br::date($m[3]);
            $data['total_amount'] = Br::number($m[4]);
        }
    }

    private function parseDocument(string $text, array &$data): void
    {
        if (preg_match('/NOTA FISCAL Nº:\s*([\d.]+)\s*-\s*S[ée]rie:\s*(\d+)/u', $text, $m)) {
            $data['invoice_number'] = $m[1];
            $data['series'] = $m[2];
        }

        if (preg_match('#DATA DE EMISS[ÃA]O:\s*(\d{2}/\d{2}/\d{4})#u', $text, $m)) {
            $data['issue_date'] = Br::date($m[1]);
        }

        // Chave de acesso (44 dígitos) quebrada em duas linhas, ao lado de outros blocos
        $data['access_key'] = null;
        $lines = preg_split('/\R/u', $text);
        foreach ($lines as $i => $line) {
            if (stripos($line, 'chave de acesso:') !== false && ! str_contains($line, 'Consulte')) {
                $digits = '';
                for ($j = $i + 1; $j <= $i + 3 && isset($lines[$j]); $j++) {
                    if (preg_match('/((?:\d{4}\s?){1,11})\s*$/', rtrim($lines[$j]), $m)) {
                        $digits .= preg_replace('/\D/', '', $m[1]);
                    }
                    if (strlen($digits) >= 44) {
                        break;
                    }
                }
                if (strlen($digits) >= 44) {
                    $data['access_key'] = substr($digits, 0, 44);
                }
                break;
            }
        }

        $data['is_contingency'] = str_contains($text, 'EMITIDO EM CONTINGÊNCIA');

        $data['paid_at'] = null;
        if (preg_match('#CONTA PAGA\s*-\s*Data de Pagamento:\s*(\d{2}/\d{2}/\d{4})#u', $text, $m)) {
            $data['paid_at'] = Br::date($m[1]);
        }
    }

    /** @return list<array<string, mixed>> */
    private function parseItems(array $lines): array
    {
        $start = null;
        $end = count($lines);
        foreach ($lines as $i => $line) {
            if ($start === null && str_contains($line, 'Itens da Fatura')) {
                $start = $i;
            } elseif ($start !== null && preg_match('/^\s*TOTAL:\s/', $line)) {
                $end = $i;
                break;
            }
        }
        if ($start === null) {
            return [];
        }

        $n = self::NUM;
        $items = [];
        $position = 0;

        for ($i = $start + 1; $i < $end; $i++) {
            $line = $lines[$i];
            if (trim($line) === '' || strlen($line) - strlen(ltrim($line)) > 25) {
                continue; // colunas laterais (tributos, gráfico de consumo)
            }

            // Linha de energia: descrição KWH qtd preço valor pis base aliq icms tarifa
            if (preg_match("/^\s*(?<d>\S.*?)\s{2,}(?:(?<u>KWH)\s+)?(?<q>{$n})\s+(?<pu>{$n})\s+(?<v>{$n})\s+(?<pis>{$n})\s+(?<bi>{$n})\s+(?<al>\d+)\s+(?<ic>{$n})\s+(?<t>{$n})/u", $line, $m)) {
                $items[] = [
                    'position' => $position++,
                    'description' => $this->clean($m['d']),
                    'unit' => ($m['u'] ?? '') !== '' ? $m['u'] : 'KWH',
                    'quantity' => Br::number($m['q']),
                    'unit_price' => Br::number($m['pu']),
                    'amount' => Br::number($m['v']),
                    'pis_cofins_amount' => Br::number($m['pis']),
                    'icms_base' => Br::number($m['bi']),
                    'icms_rate' => (float) $m['al'],
                    'icms_amount' => Br::number($m['ic']),
                    'tariff' => Br::number($m['t']),
                ];

                continue;
            }

            // Linha de serviço/lançamento: descrição valor [pis base aliq icms]
            if (preg_match("/^\s*(?<d>[A-ZÀ-Ú][^\n]*?)\s{2,}(?<v>{$n})(?:\s+(?<pis>{$n})\s+(?<bi>{$n})\s+(?<al>\d+)\s+(?<ic>{$n}))?(?:\s{2,}.*)?$/u", $line, $m)) {
                $desc = $this->clean($m['d']);
                if (in_array($desc, ['PIS', 'COFINS', 'ICMS', 'Tributo', 'Itens da Fatura'], true)) {
                    continue;
                }
                $items[] = [
                    'position' => $position++,
                    'description' => $desc,
                    'unit' => null,
                    'quantity' => null,
                    'unit_price' => null,
                    'amount' => Br::number($m['v']),
                    'pis_cofins_amount' => isset($m['pis']) && $m['pis'] !== '' ? Br::number($m['pis']) : null,
                    'icms_base' => isset($m['bi']) && $m['bi'] !== '' ? Br::number($m['bi']) : null,
                    'icms_rate' => isset($m['al']) && $m['al'] !== '' ? (float) $m['al'] : null,
                    'icms_amount' => isset($m['ic']) && $m['ic'] !== '' ? Br::number($m['ic']) : null,
                    'tariff' => null,
                ];
            }
        }

        foreach ($items as &$item) {
            $item['category'] = $this->categorize($item['description'], $item['amount']);
        }

        return $items;
    }

    private function categorize(string $description, float $amount): string
    {
        $d = mb_strtolower($description);

        return match (true) {
            str_contains($d, 'energia atv injetada') || str_contains($d, 'energia ativa injetada') => 'compensation',
            str_starts_with($d, 'ajuste gd') => 'compensation_fee',
            str_starts_with($d, 'consumo em kwh') => 'consumption',
            str_contains($d, 'cip') || str_contains($d, 'ilum') => 'cip',
            str_starts_with($d, 'multa') || str_starts_with($d, 'juros') || str_contains($d, 'atualiza') => 'other_charge',
            default => 'other',
        };
    }

    private function clean(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value));
    }

    private function parseTotals(string $text, array &$data): void
    {
        $n = self::NUM;
        $data['pis_cofins_total'] = 0.0;
        $data['icms_base'] = 0.0;
        $data['icms_total'] = 0.0;

        if (preg_match("/^\s*TOTAL:\s+({$n})\s+({$n})\s+({$n})\s+({$n})/mu", $text, $m)) {
            $data['pis_cofins_total'] = Br::number($m[2]);
            $data['icms_base'] = Br::number($m[3]);
            $data['icms_total'] = Br::number($m[4]);
            // o TOTAL da tabela de itens deve ser igual ao valor da fatura
            $data['items_total'] = Br::number($m[1]);
        }
    }

    private function parseMeter(string $text, array &$data): void
    {
        $n = '\d+';
        $data['meter_number'] = null;
        if (preg_match('/Medidor:\s*(\S+)/', $text, $m)) {
            $data['meter_number'] = $m[1];
        }

        // "D6253983426   Energia ativa em kWh   Ponta   583   817   1   234"
        $data['consumption_kwh'] = 0.0;
        if (preg_match("/Energia ativa em kWh\s+\w+\s+({$n})\s+({$n})\s+({$n})\s+({$n})/u", $text, $m)) {
            $data['meter_reading_previous'] = (float) $m[1];
            $data['meter_reading_current'] = (float) $m[2];
            $data['consumption_kwh'] = (float) $m[4];
        }

        // Energia injetada medida (somente UC geradora)
        $data['injected_kwh'] = 0.0;
        if (preg_match("/Energia injetada\s+\w+\s+({$n})\s+({$n})\s+({$n})\s+({$n})/u", $text, $m)) {
            $data['injected_kwh'] = (float) $m[4];
        }
    }

    private function parseCostComposition(string $text, array &$data): void
    {
        $map = [
            'cost_distribution' => 'Serviço de distribuição',
            'cost_energy' => 'Compra de energia',
            'cost_transmission' => 'Serviço de transmissão',
            'cost_charges' => 'Encargos setoriais',
            'cost_taxes' => 'Impostos diretos e encargos',
            'cost_other' => 'Outros serviços',
        ];

        foreach ($map as $key => $label) {
            $data[$key] = 0.0;
            if (preg_match('/^\s*'.preg_quote($label, '/').'\s+('.self::NUM.')\s+('.self::NUM.')/mu', $text, $m)) {
                $data[$key] = Br::number($m[1]);
            }
        }
    }

    private function parseCredits(string $text, array &$data): void
    {
        $data['credit_balance_kwh'] = 0.0;
        $data['credit_expiring_kwh'] = 0.0;
        if (preg_match('/Saldo Acumulado:\s*(\d+(?:,\d+)?)\s+A expirar no pr[óo]ximo ciclo:\s*(\d+(?:,\d+)?)/u', $text, $m)) {
            $data['credit_balance_kwh'] = Br::number($m[1]);
            $data['credit_expiring_kwh'] = Br::number($m[2]);
        }
    }

    /** Resume os itens em colunas agregadas usadas nos dashboards. */
    private function summarizeItems(array &$data): void
    {
        $data['energy_amount'] = 0.0;
        $data['compensation_credit_amount'] = 0.0;
        $data['compensation_fee_amount'] = 0.0;
        $data['cip_amount'] = 0.0;
        $data['other_charges_amount'] = 0.0;
        $data['compensated_kwh'] = 0.0;
        $data['tariff_per_kwh'] = null;
        $data['credit_origin'] = null;
        $data['credit_cycle'] = null;

        foreach ($data['items'] as $item) {
            switch ($item['category']) {
                case 'consumption':
                    $data['energy_amount'] += $item['amount'];
                    $data['tariff_per_kwh'] = $item['unit_price'];
                    if (empty($data['consumption_kwh'])) {
                        $data['consumption_kwh'] = (float) $item['quantity'];
                    }
                    break;
                case 'compensation':
                    $data['compensation_credit_amount'] += abs($item['amount']);
                    $data['compensated_kwh'] += (float) $item['quantity'];
                    // "Energia Atv Injetada GDII mUC 9/2026 mPT"
                    if (preg_match('/GD\s*[I]+\s+(.*)$/u', $item['description'], $m) && trim($m[1]) !== '') {
                        $data['credit_origin'] = trim($m[1]);
                        if (preg_match('#(\d{1,2}/\d{4})#', $m[1], $mm)) {
                            $data['credit_cycle'] = Br::monthYear($mm[1]);
                        }
                    }
                    break;
                case 'compensation_fee':
                    $data['compensation_fee_amount'] += $item['amount'];
                    break;
                case 'cip':
                    $data['cip_amount'] += $item['amount'];
                    break;
                case 'other_charge':
                    $data['other_charges_amount'] += $item['amount'];
                    break;
            }
        }

        foreach (['energy_amount', 'compensation_credit_amount', 'compensation_fee_amount', 'cip_amount', 'other_charges_amount'] as $key) {
            $data[$key] = round($data[$key], 2);
        }
    }
}
