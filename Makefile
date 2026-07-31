# HYGMap Makefile
# Provides standard commands for development and CI

.PHONY: test test-php test-unit test-integration test-api test-frontend test-scripts test-coverage \
        analyse typecheck-frontend ci ci-full ci-php ci-api ci-frontend help up down logs rebuild

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
	@echo "  make test-scripts     Run db/scripts catalog + constellation tests (via Docker)"
	@echo ""
	@echo "Analysis:"
	@echo "  make analyse          Run PHPStan static analysis (via Docker)"
	@echo "  make lint-frontend    Run ESLint on frontend (via Docker)"
	@echo "  make typecheck-frontend Run tsc --noEmit on frontend (via Docker)"
	@echo ""
	@echo "CI Pipelines:"
	@echo "  make ci               Run full CI pipeline (no running stack needed)"
	@echo "  make ci-full          make ci + integration tests (needs docker compose up)"
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
	docker run --rm -v $(PWD)/hygmap-php:/app -v $(PWD)/tests/fixtures:/fixtures:ro -w /app composer:2 sh -c "composer install --quiet && vendor/bin/phpunit --testsuite Unit --testdox"

# Run PHP unit tests only via Docker
test-unit:
	docker run --rm -v $(PWD)/hygmap-php:/app -v $(PWD)/tests/fixtures:/fixtures:ro -w /app composer:2 sh -c "composer install --quiet && vendor/bin/phpunit --testsuite Unit --testdox"

# Run PHP integration tests via Docker (requires database to be running)
# Integration tests run against the live stack (API + database), so `docker compose up`
# must be running first. There is deliberately no "skip if absent" guard: this target
# previously checked only that tests/Integration existed, so an empty directory reported
# success while running nothing, for months. --fail-on-empty-test-suite makes that
# impossible — if there are no tests, this fails.
test-integration:
	docker run --rm -v $(PWD)/hygmap-php:/app -v $(PWD)/tests/fixtures:/fixtures:ro -w /app \
		--network hygmap_default \
		-e API_BASE_URL=$${API_BASE_URL:-http://hygmap-api:8000} \
		composer:2 sh -c "composer install --quiet && vendor/bin/phpunit --testsuite Integration --testdox --fail-on-empty-test-suite"

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
# docker-compose.prod.yml is mounted because TestProdComposeTrustSetting asserts on the
# deployed value of FORWARDED_ALLOW_IPS -- the exact misconfiguration PROXY-TRUST fixed.
# With only hygmap-api/ mounted the file was absent and the whole class skipped itself, in
# CI as well as locally, so the guard had never once run. It is mounted at / rather than a
# tidier path because the test resolves the repo root as tests/../.., which inside this
# container is /.
test-api:
	docker run --rm -v $(PWD)/hygmap-api:/app -v $(PWD)/tests/fixtures:/fixtures:ro \
		-v $(PWD)/docker-compose.prod.yml:/docker-compose.prod.yml:ro -w /app python:3.11-slim sh -c \
		"pip install --quiet --root-user-action=ignore -r requirements.txt && python -m pytest tests/ -v"

# =============================================================================
# Database Scripts (catalog matching, constellations)
# =============================================================================

# Run the db/scripts test suite via Docker.
#
# These cover match_cns5.py, match_gcns.py and compute_constellations.py -- the code that
# decides which catalog row is which star. They existed, passed, and were wired into
# nothing for a full audit cycle, while ROADMAP credited them with closing
# CATALOG-ID-INTEGRITY. Green tests nobody runs are not evidence.
#
# psycopg2-binary is needed because the matchers import it at module scope. astropy is
# needed by two tests only -- the ones that check the hand-rolled B1875 precession against
# the authoritative implementation, which is the whole reason the hand-rolled one is
# trustworthy. Installing it costs about 20s; skipping those two would cost more.
#
# Mounts db/ rather than db/scripts/ because the constellation tests load the boundary
# table from ../data/ at import time.
test-scripts:
	docker run --rm -v $(PWD)/db:/app -w /app/scripts python:3.11-slim sh -c \
		"pip install --quiet --root-user-action=ignore pytest psycopg2-binary astropy && python -m pytest . -v"

# =============================================================================
# Frontend Tests (React/TypeScript)
# =============================================================================

# Run React frontend tests via Docker
test-frontend:
	docker run --rm -v $(PWD)/hygmap-frontend:/app -v $(PWD)/tests/fixtures:/fixtures:ro -w /app node:20-slim sh -c \
		"npm ci --silent && npm test -- --run"

# Type-check the frontend via Docker.
#
# tsc lived only in `npm run build`, which no CI step invoked -- so TypeScript errors could
# not fail the build. A duplicate interface member sat in src/types/star.ts through several
# green `make ci` runs before `tsc --noEmit` was run by hand and found it.
typecheck-frontend:
	docker run --rm -v $(PWD)/hygmap-frontend:/app -w /app node:20-slim sh -c \
		"npm ci --silent && npx tsc --noEmit"

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
ci-frontend: typecheck-frontend lint-frontend test-frontend

# Full CI pipeline (all components)
# `make ci` runs everything that does NOT need a running stack: static analysis, PHP
# unit tests, API tests, frontend lint and tests. It is what you can run from a cold
# checkout.
#
# GitHub Actions runs all of that AND an `integration` job that brings the stack up and
# runs `make test-integration` plus HTTP smoke tests. So a green `make ci` is strictly
# weaker evidence than a green CI run, and that gap used to be invisible. Use
# `make ci-full` before pushing anything that touches the API contract, the PHP data
# path, or the renderer.
ci: ci-php ci-api ci-frontend test-scripts
	@echo ""
	@echo "make ci passed. NOTE: this did not run integration tests, which need the"
	@echo "stack up. CI runs them. For the same coverage locally: make ci-full"

# Everything `make ci` runs, plus the integration suite against a live stack.
# Requires: docker compose up -d
ci-full: ci test-integration

# Legacy alias for backwards compatibility
test: test-php test-api test-frontend test-scripts

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
