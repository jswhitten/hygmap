# HYGMap Makefile
# Provides standard commands for development and CI

.PHONY: test test-unit test-integration test-coverage analyse ci help up down logs rebuild

# Default target
help:
	@echo "HYGMap Development Commands"
	@echo ""
	@echo "  make test             Run all tests (via Docker)"
	@echo "  make test-unit        Run unit tests only (via Docker)"
	@echo "  make test-integration Run integration tests (via Docker, requires database)"
	@echo "  make analyse          Run PHPStan static analysis (via Docker)"
	@echo "  make ci               Run full CI pipeline (analyse + test)"
	@echo ""
	@echo "  make up               Start Docker containers"
	@echo "  make down             Stop Docker containers"
	@echo "  make logs             Show container logs"
	@echo "  make rebuild          Rebuild and restart containers"
	@echo ""
	@echo "Note: Tests run inside Docker containers. No local PHP installation required."
	@echo ""

# Run all tests via Docker
test:
	docker run --rm -v $(PWD)/hygmap-php:/app -w /app composer:2 sh -c "composer install --quiet && vendor/bin/phpunit --testdox"

# Run unit tests only via Docker
test-unit:
	docker run --rm -v $(PWD)/hygmap-php:/app -w /app composer:2 sh -c "composer install --quiet && vendor/bin/phpunit --testsuite Unit --testdox"

# Run integration tests via Docker (requires database to be running)
test-integration:
	docker run --rm -v $(PWD)/hygmap-php:/app -w /app \
		--network hygmap_default \
		-e DB_HOST=hygmap_db \
		-e DB_PORT=5432 \
		-e DB_NAME=$${POSTGRES_DB:-hygmap} \
		-e DB_USERNAME=$${POSTGRES_USER:-hygmap_user} \
		-e DB_PASSWORD=$${POSTGRES_PASSWORD} \
		composer:2 sh -c "composer install --quiet && vendor/bin/phpunit --testsuite Integration --testdox"

# Run tests with coverage via Docker
test-coverage:
	docker run --rm -v $(PWD)/hygmap-php:/app -w /app composer:2 sh -c "composer install --quiet && vendor/bin/phpunit --coverage-text"

# Run PHPStan static analysis via Docker
analyse:
	docker run --rm -v $(PWD)/hygmap-php:/app -w /app composer:2 sh -c "composer install --quiet && vendor/bin/phpstan analyse"

# Full CI pipeline
ci: analyse test

# Docker commands
up:
	docker compose up -d

down:
	docker compose down

logs:
	docker compose logs -f

rebuild:
	docker compose build && docker compose up -d
