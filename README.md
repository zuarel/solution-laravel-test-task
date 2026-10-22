# MarketHub — Laravel

Окружение для [тестового задания](task/README.md): Laravel 13, PHP 8.3,
PostgreSQL 17, очередь в PostgreSQL и Mailpit.
Бизнес-логика вебхуков и API заказов пока не реализована.

## Запуск с нуля

Нужен только запущенный Docker с Docker Compose. PHP, Composer и Node.js на хосте не нужны.

```sh
sh scripts/setup.sh
```

Скрипт создаёт `.env`, собирает PHP-образ, устанавливает зависимости по lock-файлам,
генерирует ключ приложения и секрет вебхуков, применяет миграции
и запускает приложение, PostgreSQL, воркер очереди и Mailpit.
Повторный запуск сохраняет существующие ключи и данные.

## Адреса

| Сервис | Адрес |
| --- | --- |
| Приложение | http://localhost:8000 |
| Проверка HTTP | http://localhost:8000/up |
| Mailpit — просмотр писем | http://localhost:8025 |
| SMTP с хоста | localhost:1025 |
| PostgreSQL с хоста | localhost:5433 |

База, пользователь и пароль для локальной разработки: `markethub`, `markethub`,
`local-password`. Внутри Docker приложение обращается к `db:5432` и `mailpit:1025`.
Порты можно изменить в `.env` (`APP_PORT`, `DB_FORWARD_PORT`, `MAILPIT_PORT`,
`MAILPIT_SMTP_PORT`); при смене порта приложения обновите `APP_URL`.
Секрет подписи задаётся в `.env` через `MARKETPLACE_WEBHOOK_SECRET`.

## Работа с проектом

```sh
# Запуск после остановки
docker compose up -d --wait

# Artisan и Composer
docker compose exec app php artisan migrate
docker compose exec app composer install

# Тесты и форматирование
docker compose exec app php artisan test
docker compose exec app vendor/bin/pint --test

# Логи и состояние
docker compose ps
docker compose logs -f app queue

# Перезапуск воркера после изменения кода заданий
docker compose restart queue

# Остановка с сохранением данных PostgreSQL
docker compose down
```

Приложение использует локальный сервер `artisan serve`; окружение предназначено для разработки.
Тесты по умолчанию используют SQLite в памяти и не меняют рабочую PostgreSQL.
Исходные материалы задания в `task/` сохранены без изменений.
