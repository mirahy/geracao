<?php

namespace App\Services\Rateio;

use App\Services\Documents\PdfTextExtractor;
use Carbon\CarbonImmutable;
use Smalot\PdfParser\Parser;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Extrai o texto de um comprovante em PDF. Usa o leitor em PHP puro (funciona em qualquer hospedagem)
 * e, se ele não achar texto, tenta o pdftotext. Imagens passam pelo Tesseract, se configurado (TESSERACT_BINARY); sem ele o usuário preenche os dados.
 */
class PaymentReceiptReader
{
    public function __construct(private readonly PdfTextExtractor $extractor) {}

    public function isPdf(string $path): bool
    {
        return is_file($path) && str_starts_with((string) file_get_contents($path, false, null, 0, 5), '%PDF');
    }

    public function ocrEnabled(): bool
    {
        return filled(config('geracao.tesseract_binary'));
    }

    public function text(string $path): ?string
    {
        if (! $this->isPdf($path)) {
            return $this->ocr($path);
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

    /** OCR de imagem (JPG, PNG, WebP) com o Tesseract; null se desligado, ilegível ou sem texto. */
    private function ocr(string $path): ?string
    {
        if (! $this->ocrEnabled()) {
            return null;
        }

        $prepared = $this->prepareImage($path);
        if ($prepared === null) {
            return null;
        }

        try {
            $process = new Process(
                [config('geracao.tesseract_binary'), $prepared, 'stdout', '-l', config('geracao.ocr_language', 'por'), '--psm', '6'],
                null,
                filled(config('geracao.tessdata_dir')) ? ['TESSDATA_PREFIX' => config('geracao.tessdata_dir')] : null,
            );
            $process->setTimeout(60)->run();

            $text = $process->isSuccessful() ? $process->getOutput() : '';
        } catch (Throwable) {
            $text = '';
        } finally {
            @unlink($prepared);
        }

        return trim($text) === '' ? null : $text;
    }

    /** Amplia imagens pequenas (o OCR erra mais em letras miúdas) e grava um PNG temporário. */
    private function prepareImage(string $path): ?string
    {
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $scale = min(3, max(1, (int) ceil(1200 / max($width, 1))));
        if ($scale > 1) {
            $image = imagescale($image, $width * $scale, imagesy($image) * $scale, IMG_BICUBIC) ?: $image;
        }

        $target = tempnam(sys_get_temp_dir(), 'ocr').'.png';
        $saved = imagepng($image, $target);

        return $saved ? $target : null;
    }

    /** @return array{amount: ?float, paid_at: ?CarbonImmutable, transaction_id: ?string, payer_name: ?string}|null null = sem texto para ler */
    public function read(string $path): ?array
    {
        $text = $this->text($path);

        return $text === null ? null : app(PaymentReceiptParser::class)->parse($text);
    }
}
