<?php

namespace App\Services\Documents;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Extrai o texto de um PDF preservando o layout (pdftotext -layout, do poppler-utils).
 * O layout é necessário para ler as tabelas das faturas e demonstrativos da Energisa.
 */
class PdfTextExtractor
{
    public function extract(string $pdfPath): string
    {
        $binary = config('geracao.pdftotext_binary', 'pdftotext');

        $process = new Process([$binary, '-layout', '-enc', 'UTF-8', $pdfPath, '-']);
        $process->setTimeout(60);

        try {
            $process->run();
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Não foi possível executar o pdftotext ({$binary}). Instale o poppler-utils. ".$e->getMessage()
            );
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException('Falha ao ler o PDF: '.trim($process->getErrorOutput()));
        }

        $text = $process->getOutput();
        if (trim($text) === '') {
            throw new RuntimeException('O PDF não contém texto extraível (pode ser uma imagem escaneada).');
        }

        return $text;
    }
}
