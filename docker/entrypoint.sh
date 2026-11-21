#!/bin/sh
set -e
cd /app

# Tudo que precisa persistir fica no volume /app/storage (banco SQLite, PDFs enviados, chave).
mkdir -p storage/database storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs
[ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"

# Chave da aplicação: usa APP_KEY, ou gera uma vez e reaproveita nas próximas execuções.
if [ -z "$APP_KEY" ]; then
    if [ ! -f storage/.app_key ]; then
        php artisan key:generate --show --no-interaction > storage/.app_key
    fi
    export APP_KEY="$(cat storage/.app_key)"
fi

chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

php artisan migrate --force --no-interaction

# Usuário inicial (opcional): ADMIN_EMAIL + ADMIN_PASSWORD
if [ -n "$ADMIN_EMAIL" ] && [ -n "$ADMIN_PASSWORD" ]; then
    php artisan make:filament-user --name="${ADMIN_NAME:-Administrador}" --email="$ADMIN_EMAIL" --password="$ADMIN_PASSWORD" --no-interaction 2>/dev/null \
        && echo "Usuário $ADMIN_EMAIL criado." || echo "Usuário $ADMIN_EMAIL já existe (ou não pôde ser criado)."
fi

# Perfis e permissões padrão; o primeiro usuário vira Administrador se ainda não houver um.
php artisan db:seed --class=RolesAndPermissionsSeeder --force --no-interaction

exec "$@"
