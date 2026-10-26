# ActiveFit

Fundament aplikacji klubu fitness zgodny z punktem 3 w [specyfikacji architektury](docs/architecture/repository-setup.md): Laravel 13, oficjalny starter Vue 3 + Inertia, TypeScript, Tailwind CSS, sesyjne logowanie Laravel Fortify, MySQL i Pest. Starter zawiera rejestrację, reset hasła, weryfikację e-mail oraz 2FA. Nie korzysta z WorkOS.

## Uruchomienie przez Docker Compose

Wymagany Docker z Compose. Kontener dostarcza PHP 8.4 z rozszerzeniami, Composer 2 oraz Node.js 24; nie trzeba zmieniać systemowego PHP. Polecenia wykonuj w głównym katalogu projektu `fitnesclub` — aplikacja jest już utworzona, nie uruchamiaj ponownie `laravel new`.

Po wykonaniu pierwszej instalacji uruchom aplikację jedną komendą (wymagany `make`):

```bash
make up
```

Samo `make` również uruchamia aplikację. `make down` zatrzymuje środowisko bez usuwania danych bazy, `make restart` restartuje aplikację, `make logs` pokazuje logi, a `make status` wyświetla stan kontenerów.

Pierwsza instalacja na nowym komputerze:

```bash
cp .env.example .env  # tylko przy pierwszej instalacji, jeśli .env nie istnieje
docker compose build app
docker compose up -d mysql
docker compose run --rm app composer setup
docker compose up -d app
```

`composer setup` instaluje zależności z plików lock, ustawia klucz wyłącznie wtedy, gdy jest pusty, wykonuje migracje i buduje frontend. MySQL musi być dostępny przed tym krokiem. `.env.example` ma ustawienia lokalnej bazy Compose i poczty w logach. Podane hasła są wyłącznie do lokalnego developmentu. Dla innego UID/GID ustaw `LOCAL_UID` i `LOCAL_GID` w `.env` (na tym komputerze oba wynoszą 1000).

Aplikacja: **http://localhost:8000**. Vite: port 5173. W razie konfliktu ustaw `APP_PORT`, odpowiedni `APP_URL` oraz `VITE_PORT` w `.env`. Dane MySQL pozostają w wolumenie `mysql_data` po zatrzymaniu kontenerów.

```bash
docker compose logs -f app
docker compose exec app php artisan migrate
docker compose exec app php artisan test
docker compose exec app composer lint:check
docker compose exec app composer types:check
docker compose exec app npm run check
docker compose exec app npm run types:check
docker compose exec app npm run build
docker compose down
```

Testy używają izolowanej bazy SQLite w pamięci, zgodnie z `phpunit.xml`. Nie modyfikują lokalnej bazy MySQL. Weryfikacja e-mail i reset hasła trafiają do `storage/logs/laravel.log`; do prawdziwej wysyłki skonfiguruj SMTP w `.env`.

## Uruchomienie bez Dockera

Wymagane PHP 8.4 z rozszerzeniami Laravel oraz `pdo_mysql`, `pdo_sqlite`, `intl`, `mbstring`, `zip`, `pcntl`, Composer 2, Node.js 24 (`.nvmrc`), npm i MySQL 8.x. Utwórz bazę i użytkownika z uprawnieniami do niej. Skopiuj `.env.example` tylko jeśli `.env` nie istnieje, ustaw `DB_HOST=127.0.0.1` i własne dane bazy, a następnie:

```bash
composer install
npm ci
php scripts/ensure-app-key.php
php artisan migrate
composer run dev
```

## Zakres

To inicjalizacja projektu i standardowy interfejs startera. Moduły karnetów, sprzedaży i Przelewy24 wymagają dalszej implementacji. Dokument `docs/product/requirements.md` przywołany w architekturze nie został jeszcze dostarczony. Lokalne repozytorium Git korzysta z gałęzi `main`. Repozytorium zdalne i ochrona gałęzi wymagają wskazania docelowego konta/organizacji GitHub.

Źródło startera: [laravel/vue-starter-kit](https://github.com/laravel/vue-starter-kit), licencja MIT. Wersje zależności zapisane są w `composer.lock` i `package-lock.json`.
