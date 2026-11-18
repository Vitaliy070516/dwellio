# Усі команди виконуються всередині контейнера через Sail (див. CLAUDE.md).
SAIL := ./vendor/bin/sail

.DEFAULT_GOAL := help
.PHONY: help up up-ollama up-obs up-all down test stan pint pint-check artisan shell composer check

help: ## Список команд
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-12s\033[0m %s\n",$$1,$$2}'

up: ## Базові сервіси (app, pgsql, redis, rabbitmq)
	$(SAIL) up -d

up-ollama: ## + Ollama (profile ollama)
	$(SAIL) --profile ollama up -d

up-obs: ## + Prometheus/Loki/Grafana (profile obs)
	$(SAIL) --profile obs up -d

up-all: ## Усе разом (обережно з RAM)
	$(SAIL) --profile ollama --profile obs up -d

down: ## Зупинити всі сервіси (включно з profiles)
	$(SAIL) --profile ollama --profile obs down

test: ## Pest
	$(SAIL) pest

stan: ## PHPStan/Larastan
	$(SAIL) php vendor/bin/phpstan analyse --memory-limit=1G

pint: ## Pint (виправляє стиль)
	$(SAIL) pint

pint-check: ## Pint без змін файлів (для CI)
	$(SAIL) pint --test

check: pint-check stan test ## Pint → PHPStan → Pest

artisan: ## make artisan c="migrate:status"
	$(SAIL) artisan $(c)

composer: ## make composer c="require x/y"
	$(SAIL) composer $(c)

shell: ## bash у контейнері
	$(SAIL) shell
