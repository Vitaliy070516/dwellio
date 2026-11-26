# Усі команди виконуються всередині контейнера через Sail (див. CLAUDE.md).
SAIL := ./vendor/bin/sail

.DEFAULT_GOAL := help
.PHONY: help up up-ollama up-obs up-workers up-all down test stan pint pint-check artisan shell composer check test-js queue-setup worker worker-generation workers-restart reverb-logs build reindex ai-usage search-eval search-stats openapi openapi-check demo-up demo-url demo-check demo-down tf-fmt tf-validate aws-sso-setup aws-login aws-whoami tf-bootstrap-plan tf-bootstrap-apply tf-init tf-plan tf-apply tf-destroy

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
	$(SAIL) --profile ollama --profile obs --profile workers --profile demo down

test: ## Pest
	$(SAIL) pest

stan: ## PHPStan/Larastan
	$(SAIL) php vendor/bin/phpstan analyse --memory-limit=1G

test-js: ## JS-тести фронту (Vitest + jsdom): стани деградації, WebSocket, помилки API
	$(SAIL) npm test

pint: ## Pint (виправляє стиль)
	$(SAIL) pint

pint-check: ## Pint без змін файлів (для CI)
	$(SAIL) pint --test

check: pint-check stan openapi-check test test-js ## Pint → PHPStan → OpenAPI → Pest → JS-тести

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

openapi: ## Згенерувати docs/openapi.json з коду (Scramble); закомітити разом зі зміною API
	$(SAIL) artisan scramble:export

openapi-check: ## Чи збігається закомічена специфікація з кодом (те саме, що в CI)
	$(SAIL) artisan scramble:export
	git diff --exit-code -- docs/openapi.json

# ---- Публічне демо через Cloudflare Tunnel (День 12) ----
# Змінні shell пріоритетніші за .env при інтерполяції compose.yaml (x-app-env): .env не міняємо.
# Саме `docker compose`, а не sail: скрипт sail робить `source .env` і перетер би APP_ENV/APP_DEBUG.
# Окремий файл кешу конфігу — локальна розробка після демо не підхопить demo-налаштування.
DEMO_ENV := DEMO_MODE=true APP_ENV=production APP_DEBUG=false APP_CONFIG_CACHE=bootstrap/cache/config.demo.php
DEMO_PROFILES := --profile ollama --profile workers --profile demo
DEMO_APP := laravel.test reverb ollama worker-embeddings worker-generation

demo-up: ## Демо: збірка фронту, кеш конфігу, чеклист, прогрів моделей, тунель → публічний URL
	$(DEMO_ENV) docker compose $(DEMO_PROFILES) up -d $(DEMO_APP)
	$(SAIL) npm run build
	rm -f public/hot
	$(SAIL) artisan config:cache
	$(SAIL) artisan demo:check
	@# Довгоживучі процеси читають конфіг лише при старті: перезапускаємо вже з кешем.
	$(DEMO_ENV) docker compose $(DEMO_PROFILES) restart reverb worker-embeddings worker-generation
	-$(SAIL) artisan ai:warmup
	$(DEMO_ENV) docker compose $(DEMO_PROFILES) up -d demo-proxy tunnel
	@$(MAKE) --no-print-directory demo-url

demo-url: ## Публічна адреса тунелю (нова при кожному старті Quick Tunnel)
	@# api.trycloudflare.com — службова адреса з логів (запит тунелю), не адреса демо.
	@for i in $$(seq 1 60); do \
		url=$$($(SAIL) logs tunnel 2>&1 | grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' | grep -v '//api\.' | tail -1); \
		if [ -n "$$url" ]; then echo "Demo: $$url  (API docs: $$url/docs/api)"; exit 0; fi; \
		sleep 1; \
	done; echo "Tunnel URL not found yet: $(SAIL) logs tunnel"; exit 1

demo-check: ## Передпольотний чеклист демо (у demo-оточенні)
	$(SAIL) artisan demo:check

demo-down: ## Закрити тунель і повернути контейнери до звичайного .env (локальна розробка)
	$(SAIL) --profile demo rm -sf tunnel demo-proxy
	rm -f bootstrap/cache/config.demo.php
	$(SAIL) $(DEMO_PROFILES) up -d $(DEMO_APP)

# Terraform — у Docker-контейнері, без локальної установки. fmt/validate — без AWS-акаунта (state не потрібен).
TF_IMAGE := hashicorp/terraform:1.13
TF := docker run --rm -u $$(id -u):$$(id -g) -e HOME=/tmp -v $(CURDIR)/terraform:/tf $(TF_IMAGE)
TF_STACKS := bootstrap envs/prod

tf-fmt: ## terraform fmt (виправляє форматування)
	$(TF) -chdir=/tf fmt -recursive

tf-validate: ## terraform fmt -check + init -backend=false + validate (bootstrap і prod)
	$(TF) -chdir=/tf fmt -check -recursive -diff
	@for s in $(TF_STACKS); do \
		echo "== $$s"; \
		$(TF) -chdir=/tf/$$s init -backend=false -input=false >/dev/null && \
		$(TF) -chdir=/tf/$$s validate || exit 1; \
	done

# AWS — профілі IAM Identity Center (SSO) у ~/.aws: короткоживучі токени замість access keys.
# dwellio-admin — лише для bootstrap; dwellio-tf — щоденний least privilege (docs/runbooks/aws-account-setup.md).
AWS_PROFILE_ADMIN ?= dwellio-admin
AWS_PROFILE_TF    ?= dwellio-tf
p ?= $(AWS_PROFILE_TF)
AWS_DOCKER = docker run --rm -it -u $$(id -u):$$(id -g) -e HOME=/tmp -v $(HOME)/.aws:/tmp/.aws
AWS_CLI = $(AWS_DOCKER) amazon/aws-cli:2.31.0
TF_AWS = $(AWS_DOCKER) -e AWS_PROFILE=$(1) -v $(CURDIR)/terraform:/tf $(TF_IMAGE) -chdir=/tf/$(2)

$(HOME)/.aws:
	mkdir -p $@

aws-sso-setup: $(HOME)/.aws ## Створити SSO-профіль інтерактивно: make aws-sso-setup p=dwellio-tf
	$(AWS_CLI) configure sso --profile $(p) --use-device-code

aws-login: $(HOME)/.aws ## Увійти через SSO (device code + MFA): make aws-login [p=dwellio-admin]
	$(AWS_CLI) sso login --profile $(p) --use-device-code

aws-whoami: ## Під ким я зараз в AWS: make aws-whoami [p=dwellio-admin]
	$(AWS_CLI) sts get-caller-identity --profile $(p)

tf-bootstrap-plan: ## Bootstrap (state-бакет, бюджети, IAM): init + plan під адмін-профілем
	$(call TF_AWS,$(AWS_PROFILE_ADMIN),bootstrap) init -input=false
	$(call TF_AWS,$(AWS_PROFILE_ADMIN),bootstrap) plan -input=false -out=bootstrap.tfplan

tf-bootstrap-apply: ## Застосувати збережений план bootstrap
	$(call TF_AWS,$(AWS_PROFILE_ADMIN),bootstrap) apply -input=false bootstrap.tfplan

tf-init: ## Prod: init з remote state (envs/prod/backend.hcl)
	$(call TF_AWS,$(AWS_PROFILE_TF),envs/prod) init -input=false -backend-config=backend.hcl

tf-plan: ## Prod: plan у файл (apply застосує саме його)
	$(call TF_AWS,$(AWS_PROFILE_TF),envs/prod) plan -input=false -out=prod.tfplan

tf-apply: ## Prod: застосувати збережений план
	$(call TF_AWS,$(AWS_PROFILE_TF),envs/prod) apply -input=false prod.tfplan

tf-destroy: ## Prod: згорнути все після демо (FinOps). State і бюджети з bootstrap лишаються
	$(call TF_AWS,$(AWS_PROFILE_TF),envs/prod) destroy -input=false

build: ## Зібрати фронтенд (Vite)
	$(SAIL) npm run build

artisan: ## make artisan c="migrate:status"
	$(SAIL) artisan $(c)

composer: ## make composer c="require x/y"
	$(SAIL) composer $(c)

shell: ## bash у контейнері
	$(SAIL) shell
