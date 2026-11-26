# Усі команди виконуються всередині контейнера через Sail (див. CLAUDE.md).
SAIL := ./vendor/bin/sail

.DEFAULT_GOAL := help
.PHONY: help up up-ollama up-obs up-workers up-all down test stan pint pint-check artisan shell composer check queue-setup worker worker-generation workers-restart reverb-logs build reindex ai-usage search-eval search-stats tf-fmt tf-validate

help: ## Список команд
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN{FS=":.*?## "}{printf "  \033[36m%-12s\033[0m %s\n",$$1,$$2}'

up: ## Базові сервіси (app, reverb, pgsql, redis, rabbitmq)
	$(SAIL) up -d

up-ollama: ## + Ollama (profile ollama)
	$(SAIL) --profile ollama up -d

up-obs: ## + Prometheus/Loki/Grafana (profile obs)
	$(SAIL) --profile obs up -d

up-workers: ## + воркери embeddings і generation окремими контейнерами (profile workers)
	$(SAIL) --profile workers up -d

up-all: ## Усе разом (обережно з RAM)
	$(SAIL) --profile ollama --profile obs --profile workers up -d

down: ## Зупинити всі сервіси (включно з profiles)
	$(SAIL) --profile ollama --profile obs --profile workers down

test: ## Pest
	$(SAIL) pest

stan: ## PHPStan/Larastan
	$(SAIL) php vendor/bin/phpstan analyse --memory-limit=1G

pint: ## Pint (виправляє стиль)
	$(SAIL) pint

pint-check: ## Pint без змін файлів (для CI)
	$(SAIL) pint --test

check: pint-check stan test ## Pint → PHPStan → Pest

queue-setup: ## RabbitMQ: failed-exchange, DLQ і прив'язки (ідемпотентно)
	$(SAIL) artisan dwellio:rabbitmq-setup

reindex: ## Поставити в чергу квартири без ембединга (потім make worker)
	$(SAIL) artisan listings:reindex

worker: ## Воркер черги embeddings у laravel.test (queue:work; rabbitmq:consume не використовуємо)
	$(SAIL) artisan queue:work rabbitmq --queue=embeddings --timeout=120

worker-generation: ## Воркер черги generation у laravel.test (--timeout 270 > timeout Job-а 240 с)
	$(SAIL) artisan queue:work rabbitmq --queue=generation --timeout=270

workers-restart: ## М'який перезапуск воркерів після зміни коду (дочекаються поточних Job)
	$(SAIL) artisan queue:restart

reverb-logs: ## Логи Reverb
	$(SAIL) logs -f reverb

ai-usage: ## Денне споживання токенів проти бюджетів (лічильники в Redis)
	$(SAIL) artisan ai:usage

search-eval: ## Якість пошуку на golden set; make search-eval c="--rrf-k=10,30,60 --candidates=20,50,100 --details"
	$(SAIL) artisan search:eval $(c)

search-stats: ## Hit rate кешів пошуку за сьогодні (розбір запиту LLM і ембединг)
	$(SAIL) artisan search:cache-stats

# Terraform — у Docker-контейнері, без локальної установки й без AWS-акаунта (state не потрібен).
TF := docker run --rm -u $$(id -u):$$(id -g) -e HOME=/tmp -v $(CURDIR)/terraform:/tf hashicorp/terraform:1.13

tf-fmt: ## terraform fmt (виправляє форматування)
	$(TF) -chdir=/tf fmt -recursive

tf-validate: ## terraform fmt -check + init -backend=false + validate
	$(TF) -chdir=/tf fmt -check -recursive -diff
	$(TF) -chdir=/tf/envs/prod init -backend=false -input=false
	$(TF) -chdir=/tf/envs/prod validate

build: ## Зібрати фронтенд (Vite)
	$(SAIL) npm run build

artisan: ## make artisan c="migrate:status"
	$(SAIL) artisan $(c)

composer: ## make composer c="require x/y"
	$(SAIL) composer $(c)

shell: ## bash у контейнері
	$(SAIL) shell
