<?php

namespace App\Services\Rateio;

use App\Services\Documents\PdfTextExtractor;
use Carbon\CarbonImmutable;
use Smalot\PdfParser\Parser;
use Throwable;

/**
 * Extrai o texto de um comprovante em PDF. Usa o leitor em PHP puro (funciona em qualquer hospedagem)
 * e, se ele não achar texto, tenta o pdftotext. Imagens não têm texto extraível: o usuário preenche os dados.
 */
class PaymentReceiptReader
{
    public function __construct(private readonly PdfTextExtractor $extractor) {}

    public function isPdf(string $path): bool
    {
        return is_file($path) && str_starts_with((string) file_get_contents($path, false, null, 0, 5), '%PDF');
    }

    public function text(string $path): ?string
    {
        if (! $this->isPdf($path)) {
            return null;
        }

        try {
            $text = (new Parser)->parseFile($path)->getText();
        } catch (Throwable) {
            $text = '';
        }

        if (trim($text) === '') {
            try {
                $text = $this->extractor->extract($path);
            } catch (Throwable) {
                return null;
            }
        }

        return trim($text) === '' ? null : $text;
    }

    /** @return array{amount: ?float, paid_at: ?CarbonImmutable, transaction_id: ?string, payer_name: ?string}|null null = sem texto para ler */
    public function read(string $path): ?array
    {
        $text = $this->text($path);

        return $text === null ? null : app(PaymentReceiptParser::class)->parse($text);
    }
}
