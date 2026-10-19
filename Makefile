# Local demo and test shortcuts. `make` alone lists them.
# DEMO picks the sandbox: main (8000, default), sylius (8003), encore (8004),
# or all. The e2e suite runs on its own `_e2e` instance of main, never on this.

SANDBOX := apps/content-blocks-sandbox
CORE    := packages/content-blocks
CONSOLE := php $(SANDBOX)/bin/console --no-interaction
DEMO    ?= main
DEMOCTL := scripts/demo.sh

.DEFAULT_GOAL := help
.PHONY: help up down restart status logs reset assets test test-unit test-e2e test-php

help: ## List the targets
	@grep -hE '^[a-z0-9-]+:.*## ' $(MAKEFILE_LIST) \
		| awk -F':.*## ' '{ printf "  \033[36mmake %-10s\033[0m %s\n", $$1, $$2 }'
	@echo '  DEMO=main|sylius|encore|all picks the demo (default: main)'

up: ## Start a demo (installs and seeds on first run)
	@$(DEMOCTL) up $(DEMO)

down: ## Stop a demo
	@$(DEMOCTL) down $(DEMO)

restart: ## Stop, then start a demo
	@$(DEMOCTL) restart $(DEMO)

status: ## Which demos are up, and where
	@$(DEMOCTL) status all

logs: ## Follow a demo's server log
	@$(DEMOCTL) logs $(DEMO)

reset: ## Empty a demo (database + uploads) and reseed its pages
	@$(DEMOCTL) reset $(DEMO)

assets: ## Recompile the assets of the three sandboxes
	rm -rf $(SANDBOX)/public/assets
	$(CONSOLE) cache:clear -q && $(CONSOLE) asset-map:compile -q
	rm -rf apps/content-blocks-sylius-sandbox/public/assets
	php apps/content-blocks-sylius-sandbox/bin/console cache:clear -q
	php apps/content-blocks-sylius-sandbox/bin/console asset-map:compile -q
	php apps/content-blocks-encore-sandbox/bin/console cache:clear -q
	npm run build --prefix apps/content-blocks-encore-sandbox

test: test-php test-unit test-e2e ## Run PHPUnit, Vitest and Playwright (core)

test-php: ## PHPUnit (core)
	cd $(CORE) && ./vendor/bin/phpunit

test-unit: ## Vitest (core)
	npm run test:unit --prefix $(CORE)

test-e2e: ## Playwright (core) — on its own `_e2e` instance, reset each run
	npm run test:e2e --prefix $(CORE)
