#!/usr/bin/env bash
set -e

php artisan config:clear
php artisan migrate --force

# Popula as contas iniciais apenas no primeiro deploy (quando a tabela users estiver vazia)
USER_COUNT=$(php artisan tinker --execute="echo \App\Models\User::count();" | tr -d '[:space:]')
if [ "$USER_COUNT" = "0" ]; then
    php artisan db:seed --force
fi

exec php artisan serve --host=0.0.0.0 --port="${PORT:-10000}"
