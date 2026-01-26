# HYGMap Makefile
# Provides standard commands for development and CI

.PHONY: test test-php test-unit test-integration test-api test-frontend test-coverage \
        analyse ci ci-php ci-api ci-frontend help up down logs rebuild

# Default target
help:
	@echo "HYGMap Development Commands"
	@echo ""
	@echo "Testing:"
	@echo "  make test             Run all tests (PHP + API + Frontend)"
	@echo "  make test-php         Run PHP tests only (via Docker)"
	@echo "  make test-unit        Run PHP unit tests only (via Docker)"
	@echo "  make test-integration Run PHP integration tests (via Docker, requires database)"
	@echo "  make test-api         Run FastAPI backend tests (via Docker)"
	@echo "  make test-frontend    Run React frontend tests (via Docker)"
	@echo ""
	@echo "Analysis:"
	@echo "  make analyse          Run PHPStan static analysis (via Docker)"
	@echo "  make lint-frontend    Run ESLint on frontend (via Docker)"
	@echo ""
	@echo "CI Pipelines:"
	@echo "  make ci               Run full CI pipeline (all components)"
	@echo "  make ci-php           Run PHP CI pipeline (analyse + test)"
	@echo "  make ci-api           Run API CI pipeline (test)"
	@echo "  make ci-frontend      Run Frontend CI pipeline (lint + test)"
	@echo ""
	@echo "Docker:"
	@echo "  make up               Start Docker containers"
	@echo "  make down             Stop Docker containers"
	@echo "  make logs             Show container logs"
	@echo "  make rebuild          Rebuild and restart containers"
	@echo ""
	@echo "Note: Tests run inside Docker containers. No local installations required."
	@echo ""

# =============================================================================
# PHP Tests
# =============================================================================

# Run PHP unit tests via Docker (integration tests require database - use test-integration)
test-php:
	docker run --rm -v $(PWD)/hygmap-php:/app -w /app composer:2 sh -c "composer install --quiet && vendor/bin/phpunit --testsuite Unit --testdox"

# Run PHP unit tests only via Docker
test-unit:
	docker run --rm -v $(PWD)/hygmap-php:/app -w /app composer:2 sh -c "composer install --quiet && vendor/bin/phpunit --testsuite Unit --testdox"

# Run PHP integration tests via Docker (requires database to be running)
test-integration:
	@if [ -d hygmap-php/tests/Integration ]; then \
		docker run --rm -v $(PWD)/hygmap-php:/app -w /app \
			--network hygmap_default \
			-e DB_HOST=hygmap_db \
			-e DB_PORT=5432 \
			-e DB_NAME=$${POSTGRES_DB:-hygmap} \
			-e DB_USERNAME=$${POSTGRES_USER:-hygmap_user} \
			-e DB_PASSWORD=$${POSTGRES_PASSWORD} \
			composer:2 sh -c "composer install --quiet && vendor/bin/phpunit --testsuite Integration --testdox"; \
	else \
		echo "Integration tests not present; skipping"; \
	fi

# Run PHP tests with coverage via Docker
test-coverage:
	docker run --rm -v $(PWD)/hygmap-php:/app -w /app composer:2 sh -c "composer install --quiet && vendor/bin/phpunit --coverage-text"

# Run PHPStan static analysis via Docker
analyse:
	docker run --rm -v $(PWD)/hygmap-php:/app -w /app composer:2 sh -c "composer install --quiet && vendor/bin/phpstan analyse"

# =============================================================================
# API Tests (FastAPI/Python)
# =============================================================================

# Run FastAPI backend tests via Docker
test-api:
	docker run --rm -v $(PWD)/hygmap-api:/app -w /app python:3.11-slim sh -c \
		"pip install --quiet --root-user-action=ignore -r requirements.txt && python -m pytest tests/ -v"

# =============================================================================
# Frontend Tests (React/TypeScript)
# =============================================================================

# Run React frontend tests via Docker
test-frontend:
	docker run --rm -v $(PWD)/hygmap-frontend:/app -w /app node:20-slim sh -c \
		"npm ci --silent && npm test -- --run"

# Run ESLint on frontend via Docker
lint-frontend:
	docker run --rm -v $(PWD)/hygmap-frontend:/app -w /app node:20-slim sh -c \
		"npm ci --silent && npm run lint"

# =============================================================================
# CI Pipelines
# =============================================================================

# PHP CI pipeline
ci-php: analyse test-php

# API CI pipeline
ci-api: test-api

# Frontend CI pipeline
ci-frontend: lint-frontend test-frontend

# Full CI pipeline (all components)
ci: ci-php ci-api ci-frontend

# Legacy alias for backwards compatibility
test: test-php test-api test-frontend

# =============================================================================
# Docker commands
# =============================================================================

up:
	docker compose up -d

down:
	docker compose down

logs:
	docker compose logs -f

rebuild:
	docker compose build && docker compose up -d
