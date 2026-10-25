.DEFAULT_GOAL := help

COMPOSE ?= docker compose
APP_EXEC = $(COMPOSE) exec -T app
SERVICES ?= app queue
ARGS ?=
FILTER ?=

.PHONY: help setup up down stop restart build ps logs shell artisan composer migrate test test-webhook pint lint format queue-restart

help: ## Показать доступные команды
	@awk 'BEGIN {FS = ":.*## "} /^[a-zA-Z_-]+:.*## / {printf "  %-18s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

setup: ## Подготовить и запустить проект с нуля
	sh scripts/setup.sh

up: ## Запустить окружение
	$(COMPOSE) up -d --wait

down: ## Удалить контейнеры с сохранением данных базы
	$(COMPOSE) down

stop: ## Остановить контейнеры
	$(COMPOSE) stop

restart: ## Перезапустить окружение
	$(COMPOSE) restart

build: ## Собрать PHP-образ
	$(COMPOSE) build app

ps: ## Показать состояние контейнеров
	$(COMPOSE) ps

logs: ## Смотреть логи; SERVICES="app queue"
	$(COMPOSE) logs -f $(SERVICES)

shell: ## Открыть shell в контейнере приложения
	$(COMPOSE) exec app sh

artisan: ## Выполнить Artisan; ARGS="route:list"
	$(APP_EXEC) php artisan $(ARGS)

composer: ## Выполнить Composer; ARGS="install"
	$(APP_EXEC) composer $(ARGS)

migrate: ## Применить миграции
	$(APP_EXEC) php artisan migrate $(ARGS)

test: ## Запустить тесты; FILTER=имя, ARGS="--compact"
	$(APP_EXEC) php artisan test $(if $(FILTER),--filter="$(FILTER)") $(ARGS)

test-webhook: ## Запустить тесты приёма вебхуков
	$(APP_EXEC) php artisan test --filter=MarketplaceWebhookTest $(ARGS)

lint: ## Проверить форматирование PHP
	$(APP_EXEC) vendor/bin/pint --test $(ARGS)

pint: ## Запустить Laravel Pint; ARGS="--test" для проверки
	$(APP_EXEC) vendor/bin/pint $(ARGS)

format: pint ## Исправить форматирование PHP

queue-restart: ## Перезапустить воркер очереди
	$(COMPOSE) restart queue
