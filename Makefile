# One-command Docker workflow. `make up` builds the frontend on the host (avoids
# the flaky in-container npm on Docker Desktop / macOS) then starts the stack.

.PHONY: up build down logs ps restart

up: build ## Build frontend + start the whole stack
	@[ -f .env ] || { echo "→ creating .env from .env.docker.example (edit it, then re-run)"; cp .env.docker.example .env; exit 1; }
	docker compose up -d --build
	@echo "✓ up — open http://localhost:$${WEB_PORT:-8080}  (admin at /<ADMIN_PANEL_PATH>)"

build: ## Build the frontend assets on the host (public/build)
	npm ci
	npm run build

down: ## Stop the stack (keeps data volumes)
	docker compose down

logs: ## Tail logs from all services
	docker compose logs -f

ps: ## Show container status
	docker compose ps

restart: ## Restart app containers without rebuilding
	docker compose restart
