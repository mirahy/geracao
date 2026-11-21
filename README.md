# Geração Solar — faturas Energisa + Filament

Sistema Laravel 13 + Filament 5 que lê **faturas (DANF3E)** e **demonstrativos de compensação de energia injetada** da Energisa em PDF, grava os dados em banco e monta dashboards de **geração, transferências, consumo e custos** por unidade consumidora (UC). A geração total das placas é informada manualmente, mês a mês.

## Requisitos
- PHP 8.3+, Composer, SQLite (padrão) ou outro banco suportado pelo Laravel
- **poppler-utils** (`pdftotext`) — `apt install poppler-utils` / `brew install poppler`. Outro caminho: `PDFTOTEXT_BINARY` no `.env`.
- Upload de PDFs: faturas têm ~2 MB; ajuste `upload_max_filesize`/`post_max_size` no php.ini (padrão 2 MB não basta).

## Instalação
```bash
composer install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan make:filament-user        # cria o usuário de acesso
php artisan serve                      # http://localhost:8000/admin
```

## Docker
```bash
ADMIN_EMAIL=voce@exemplo.com ADMIN_PASSWORD='senha-forte' docker compose up -d --build
# http://localhost:8080/admin  (porta: APP_PORT)
```
Um único container (FrankenPHP + `pdftotext`) com SQLite; banco, PDFs enviados e a chave da aplicação ficam no volume `geracao_storage`. Sem `ADMIN_EMAIL`/`ADMIN_PASSWORD`, crie o usuário com `docker compose exec app php artisan make:filament-user`. Se publicar atrás de HTTPS/domínio, defina `APP_URL`.

## Uso
1. **Importar PDFs** (botão no Painel, Faturas, Compensações ou Importações) — aceita vários arquivos de uma vez; arquivos repetidos (mesmo hash) são ignorados, reimportar um mês atualiza os dados. Também via CLI: `php artisan geracao:importar arquivo1.pdf arquivo2.pdf`.
2. **Geração das placas** → *Informar geração*: kWh totais gerados no mês pela UC geradora.
3. **Unidades consumidoras**: renomeie as UCs (UCs citadas só nos demonstrativos aparecem como "UC 310.856.051-52" até chegar uma fatura delas). A página de cada UC traz o dashboard individual.
4. **Painel**: filtros por UC e período; KPIs, gráficos e tabela-resumo.

## O que é extraído
- **Fatura**: UC, instalação, referência, vencimento, leituras, medidor, consumo, energia injetada/compensada, saldo de créditos, itens (consumo, crédito, ajuste Lei 14.300, CIP, multa/juros), composição do valor, total.
- **Demonstrativo**: histórico mensal (consumo medido, injetado, compensado), movimentação do mês (saldo anterior, injetado, compensado, transferido, saldo), transferências por UC de destino (% e kWh) e composição do saldo.

**Custo das placas e rateio**: cadastre a parcela em *Parcelas das placas*. Custo do kWh = parcela do mês ÷ geração do mês. No mês seguinte à transferência, cada UC beneficiária paga `kWh transferido × custo do kWh` desse mês; o valor é abatido da parcela da UC geradora. Em *Unidades consumidoras*, desative "Cobrar rateio do custo das placas" para excluir uma UC do rateio (ex.: Campo Grande).

**Economia** = crédito da energia compensada − ajuste GD II. **Autoconsumo** = geração − injetado.

## Testes
`php artisan test` (parsers usam fixtures de texto anonimizadas em `tests/Fixtures`).
