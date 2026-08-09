PORT ?= 8000

.PHONY: help serve test lint check

help:
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | sed 's/:.*## /\t/'

serve: ## Start the dev server (override with PORT=8080)
	php -S localhost:$(PORT) -t src/ src/router.php

test: ## Run the smoke test
	php tests/smoke.php

lint: ## Check every PHP file for syntax errors
	@find src tests -name '*.php' -print0 | xargs -0 -n1 php -l

check: lint test ## Everything CI runs
