.DEFAULT_GOAL := up

.PHONY: up down restart logs status

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
