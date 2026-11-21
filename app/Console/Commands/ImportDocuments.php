<?php

namespace App\Console\Commands;

use App\Services\Documents\DocumentImporter;
use Illuminate\Console\Command;

class ImportDocuments extends Command
{
    protected $signature = 'geracao:importar {arquivos* : PDFs de faturas e demonstrativos de compensação}';

    protected $description = 'Extrai e grava no banco os dados de faturas e demonstrativos de compensação (PDF)';

    public function handle(DocumentImporter $importer): int
    {
        $failed = 0;

        foreach ($this->argument('arquivos') as $file) {
            if (! is_file($file)) {
                $this->error("Arquivo não encontrado: {$file}");
                $failed++;

                continue;
            }

            $doc = $importer->import($file);
            $label = $doc->wasDuplicate ? 'IGNORADO' : strtoupper($doc->status);
            $this->line(sprintf('[%s] %s — %s', $label, basename($file), $doc->message));
            $failed += $doc->status === 'failed' ? 1 : 0;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
