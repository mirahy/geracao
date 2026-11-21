<?php

namespace App\Support;

/** Catálogo de permissões do sistema (nome técnico => rótulo), agrupadas para a tela de perfis. */
class AccessControl
{
    public const ADMIN_ROLE = 'Administrador';

    public const CONSULTA_ROLE = 'Consulta';

    /** @return array<string, array<string, string>> */
    public static function groups(): array
    {
        return [
            'Painel' => ['painel.ver' => 'Ver o painel de energia'],
            'Unidades consumidoras' => ['unidades.ver' => 'Ver unidades', 'unidades.editar' => 'Criar, editar e excluir unidades'],
            'Faturas' => ['faturas.ver' => 'Ver faturas'],
            'Demonstrativos de compensação' => ['demonstrativos.ver' => 'Ver demonstrativos'],
            'Geração das placas' => ['geracao.ver' => 'Ver geração', 'geracao.editar' => 'Informar geração e importar a planilha diária'],
            'Parcelas das placas' => ['parcelas.ver' => 'Ver parcelas', 'parcelas.editar' => 'Criar, editar e excluir parcelas'],
            'Importações (PDF)' => ['documentos.ver' => 'Ver importações e os PDFs originais', 'documentos.importar' => 'Importar PDFs e excluir importações'],
            'Acesso por unidade' => [
                'todas_unidades.ver' => 'Ver todas as unidades (ignora a lista de unidades do usuário)',
                'todas_unidades.editar' => 'Editar todas as unidades (ignora a lista de unidades do usuário)',
            ],
            'Administração' => ['usuarios.gerenciar' => 'Gerenciar usuários', 'perfis.gerenciar' => 'Gerenciar perfis e permissões'],
        ];
    }

    /** @return array<string, string> */
    public static function all(): array
    {
        return array_merge(...array_values(self::groups()));
    }

    /** Perfis criados na instalação. @return array<string, list<string>> */
    public static function defaultRoles(): array
    {
        $view = ['painel.ver', 'unidades.ver', 'faturas.ver', 'demonstrativos.ver', 'geracao.ver', 'parcelas.ver', 'documentos.ver'];

        return [
            self::ADMIN_ROLE => array_keys(self::all()),
            'Gestor' => [...$view, 'unidades.editar', 'geracao.editar', 'parcelas.editar', 'documentos.importar'],
            'Consulta' => $view,
        ];
    }
}
