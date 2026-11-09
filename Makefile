.DEFAULT_GOAL := up

.PHONY: up down restart logs status test-mysql

up:
	docker compose up -d --build app
	@echo "Aplikacja uruchomiona. Domyślny adres: http://localhost:8000"

down:
	docker compose down

restart:
	docker compose restart app

logs:
	docker compose logs -f app

status:
	docker compose ps

test-mysql:
	docker compose --profile testing up -d --wait mysql-test
	docker compose run --rm --no-deps -e DB_HOST=mysql-test app composer test:mysql
