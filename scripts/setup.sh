#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
    cp .env.example .env
fi

docker compose build app
docker compose run --rm --no-deps app composer install --no-interaction

if ! grep -q '^APP_KEY=base64:' .env; then
    docker compose run --rm --no-deps app php artisan key:generate
fi

docker compose run --rm --no-deps app php -r '
    $path = ".env";
    $env = file_get_contents($path);
    $env = preg_replace("/^MARKETPLACE_WEBHOOK_SECRET=$/m", "MARKETPLACE_WEBHOOK_SECRET=".bin2hex(random_bytes(32)), $env);
    file_put_contents($path, $env);
'

docker compose up -d --wait db mailpit
docker compose run --rm app php artisan migrate --force
docker compose run --rm --no-deps node npm ci --ignore-scripts
docker compose run --rm --no-deps node npm run build
docker compose up -d --wait
