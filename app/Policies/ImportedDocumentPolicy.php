<?php

namespace App\Policies;

class ImportedDocumentPolicy extends UnitScopedPolicy
{
    protected function prefix(): string
    {
        return 'documentos';
    }

    protected function editPermission(): string
    {
        return 'documentos.importar';
    }
}
