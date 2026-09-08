# Makefile пакета verdeect/identity-laravel
#
# Обёртка над сценариями composer и над тем, что композер сценарием не описан:
# прогон по фильтру, покрытие, проверка манифеста перед тегом. Источник истины
# по-прежнему composer.json — цели ниже его не подменяют, а дают короткий вход.
#
# Это библиотека: своего приложения, базы данных и фронтенда у неё нет,
# поэтому целей запуска сервера, миграций и контейнеров здесь тоже нет.

SHELL := bash
.ONESHELL:
.SHELLFLAGS := -eu -o pipefail -c
.DELETE_ON_ERROR:
MAKEFLAGS += --warn-undefined-variables
MAKEFLAGS += --no-builtin-rules

# `.ONESHELL` работает начиная с GNU Make 3.82, а в поставке macOS идёт 3.81,
# где он молча не действует. Поэтому ни один рецепт не переносит состояние
# оболочки между строками: каждая строка самодостаточна.

# --- Инструменты ---
PHP      ?= php
COMPOSER ?= composer
PEST     ?= vendor/bin/pest
PHPSTAN  ?= vendor/bin/phpstan

# --- Отметки выпуска ---
VERSION ?= $(shell git describe --tags --always --dirty 2>/dev/null || echo 'dev')
COMMIT  ?= $(shell git rev-parse --short HEAD 2>/dev/null || echo 'unknown')

# --- Параметры целей ---
# Часть названия набора для `make test-filter`.
FILTER ?=

# ============================================================================
.DEFAULT_GOAL := help

##@ Зависимости

.PHONY: install
install: ## Поставить зависимости
	$(COMPOSER) install

.PHONY: update
update: ## Обновить зависимости
	$(COMPOSER) update

.PHONY: outdated
outdated: ## Показать зависимости с вышедшими новыми версиями
	$(COMPOSER) outdated --direct

##@ Прогон

.PHONY: test
test: ## Прогон наборов (composer test)
	$(PEST)

.PHONY: test-filter
test-filter: ## Прогон по части названия: make test-filter FILTER='refreshes ahead'
	@test -n "$(FILTER)" || { echo "Задайте FILTER, например: make test-filter FILTER='refreshes ahead'"; exit 1; }
	$(PEST) --filter="$(FILTER)"

.PHONY: test-parallel
test-parallel: ## Прогон в несколько процессов (paratest уже в зависимостях)
	$(PEST) --parallel

.PHONY: test-cover
test-cover: ## Прогон с отчётом о покрытии; нужен Xdebug либо pcov
	@$(PHP) -m | grep -qiE 'xdebug|pcov' || { echo "Нет драйвера покрытия: включите Xdebug или pcov."; exit 1; }
	$(PEST) --coverage

##@ Качество

.PHONY: types
types: ## Статический разбор, уровень 7 (composer types:check)
	$(PHPSTAN) analyse --memory-limit=1G

.PHONY: check
check: types test ## Разбор и прогон — то же, что composer ci:check

.PHONY: ci
ci: install check ## Полная проверка с нуля: установка, разбор, прогон

##@ Выпуск

.PHONY: validate
validate: ## Проверить composer.json перед тегом
	$(COMPOSER) validate --strict

.PHONY: audit
audit: ## Проверить зависимости на известные уязвимости
	$(COMPOSER) audit

.PHONY: version
version: ## Показать отметки текущего состояния и версии инструментов
	@echo "Версия:  $(VERSION)"
	@echo "Коммит:  $(COMMIT)"
	@$(PHP) -r 'echo "PHP:     ".PHP_VERSION."\n";'
	@$(COMPOSER) --version --no-interaction 2>/dev/null | head -1

##@ Уборка

.PHONY: clean
clean: ## Убрать кэши прогона и отчёт о покрытии
	rm -rf .phpunit.cache .phpunit.result.cache coverage

.PHONY: clean-all
clean-all: clean ## То же плюс vendor/ — после неё нужна make install
	rm -rf vendor

##@ Справка

.PHONY: help
help: ## Показать эту справку
	@awk 'BEGIN {FS = ":.*##"; printf "Использование:\n  make \033[36m<цель>\033[0m\n"} \
		/^[a-zA-Z_-]+:.*##/ {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2} \
		/^##@/ {printf "\n\033[1m%s\033[0m\n", substr($$0, 5)}' $(MAKEFILE_LIST)
