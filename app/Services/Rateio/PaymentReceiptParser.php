<?php

namespace App\Services\Rateio;

use Carbon\CarbonImmutable;

/**
 * Lê o texto de um comprovante de Pix (PicPay, Nubank e layouts parecidos) e devolve os dados
 * que dá para identificar. O que não for encontrado volta nulo para o usuário preencher.
 */
class PaymentReceiptParser
{
    private const MONTHS = [
        'jan' => 1, 'fev' => 2, 'mar' => 3, 'abr' => 4, 'mai' => 5, 'jun' => 6,
        'jul' => 7, 'ago' => 8, 'set' => 9, 'out' => 10, 'nov' => 11, 'dez' => 12,
    ];

    /** @return array{amount: ?float, paid_at: ?CarbonImmutable, transaction_id: ?string, payer_name: ?string} */
    public function parse(string $text): array
    {
        // caracteres de uso privado (ícones da fonte do PDF) no lugar dos ":" da hora viram espaço
        $text = preg_replace('/\p{Co}/u', ' ', str_replace("\r", '', $text));

        return [
            'amount' => $this->amount($text),
            'paid_at' => $this->paidAt($text),
            'transaction_id' => $this->transactionId($text),
            'payer_name' => $this->payer($text),
        ];
    }

    private function amount(string $text): ?float
    {
        $pattern = '/R\$\s*(\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2})/u';

        if (preg_match('/Valor[^\n\d]*\n?\s*'.substr($pattern, 1, -2).'/iu', $text, $m)
            || preg_match($pattern, $text, $m)) {
            return (float) str_replace(',', '.', str_replace('.', '', $m[1]));
        }

        return null;
    }

    private function paidAt(string $text): ?CarbonImmutable
    {
        $pattern = '/(\d{1,2})\s*(?:\/|-|\s)\s*([A-Za-zçÇ]{3,9}|\d{1,2})\s*(?:\/|-|\s)\s*(\d{4})(?:[\s\-–,às]*(\d{2})\D{0,3}(\d{2})(?:\D{0,3}(\d{2}))?)?/iu';

        if (! preg_match_all($pattern, $text, $all, PREG_SET_ORDER)) {
            return null;
        }

        foreach ($all as $m) {
            $month = ctype_digit($m[2]) ? (int) $m[2] : (self::MONTHS[mb_strtolower(mb_substr($m[2], 0, 3))] ?? null);
            if ($month === null || $month < 1 || $month > 12 || ! checkdate($month, (int) $m[1], (int) $m[3])) {
                continue;
            }

            return CarbonImmutable::create((int) $m[3], $month, (int) $m[1], (int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0));
        }

        return null;
    }

    /** Código do Pix (E2E): 32 caracteres que começam com "E"; alguns comprovantes quebram a linha no meio. */
    private function transactionId(string $text): ?string
    {
        if (! preg_match('/ID\s+d[aoe]\s+transa[çc][ãa]o\s*:?\s*\n?\s*([A-Za-z0-9]{10,})[ \t]*(?:\n[ \t]*([A-Za-z0-9]{1,12})[ \t]*(?=\n|$))?/iu', $text, $m)) {
            return null;
        }

        $id = $m[1];
        if (isset($m[2]) && strlen($id) < 32 && strlen($id.$m[2]) <= 32) {
            $id .= $m[2];
        }

        return $id;
    }

    private function payer(string $text): ?string
    {
        // Nubank: bloco "Origem" com a linha "Nome ..."
        if (preg_match('/Origem.*?\n\s*Nome[ \t]+([^\n]+)/isu', $text, $m)) {
            return $this->clean($m[1]);
        }

        // PicPay: bloco "De" seguido do nome em até 3 linhas, antes do CPF mascarado
        if (preg_match('/^[ \t]*De[ \t]*\n((?:[ \t]*[^\n*•\d]+\n){1,3})/mu', $text, $m)) {
            return $this->clean($m[1]);
        }

        if (preg_match('/(?:Pagador|Remetente)[ \t]*:?[ \t]*\n?[ \t]*([^\n]+)/iu', $text, $m)) {
            return $this->clean($m[1]);
        }

        return null;
    }

    private function clean(string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name));

        return $name === '' ? null : $name;
    }
}
