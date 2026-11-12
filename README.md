# ActiveFit — repozytorium projektu

Aplikacja: Laravel + Inertia.js + Vue 3 + TypeScript + Tailwind CSS + MySQL.  
Repozytorium: GitHub. Testy backendu: Pest. Płatności: Przelewy24.

Repozytorium zawiera działającą aplikację opartą na oficjalnym starterze Vue/Inertia: stronę powitalną, bazowe uwierzytelnianie, migracje i testy. Moduły ActiveFit są wdrażane według planu implementacji; starter nie jest jeszcze gotowym panelem klubu.

## Wymagania i wersje

- Zalecane środowisko lokalne: Docker Engine z Compose v2; opcjonalnie `make`.
- Bez Dockera: PHP **8.4+**, Composer **2**, Node.js **24 LTS** (patrz `.nvmrc`), npm i MySQL **8.4**. PHP wymaga rozszerzeń Laravel oraz `pdo_mysql`, `pdo_sqlite` (testy), `mbstring`, `intl`, `zip`, `pcntl` i `bcmath` — kompletną konfigurację zawiera `docker/local/Dockerfile`.
- Lockfile: Laravel **13.38.0**, Inertia Laravel **3.5.2**, Inertia Vue **3.9.1**, Vue **3.5.44**, TypeScript **5.9.3**, Tailwind CSS **4.3.3**, Pest **4.7.8**, Vite+ **1.4.0**. Dokładne wersje zależności określają `composer.lock` i `package-lock.json`.
- Vite+ 1.4.0 wymaga Node `^22.18.0 || ^24.11.0 || >=26.0.0`; Node 20 nie wystarcza dla tego repozytorium.

Stos zweryfikowano w ramach TASK-001 z [dokumentacją Laravel 13](https://laravel.com/docs/13.x/releases) i [oficjalnego startera Vue](https://laravel.com/docs/13.x/starter-kits). Wymaganie PHP projektu (`^8.4`) jest wyższe niż minimum frameworka (8.3). Nie generuj startera ponownie w tym katalogu.

## Pierwsze uruchomienie — Docker

W katalogu świeżo sklonowanego repozytorium:

```bash
cp .env.example .env
docker compose build app
docker compose run --rm app composer setup
docker compose up -d app
```

`composer setup` instaluje zależności z lockfile, generuje klucz tylko wtedy, gdy go brakuje, wykonuje migracje i buduje frontend. Compose uruchamia MySQL i czeka na jego gotowość przed instalacją aplikacji. Konfiguracja `.env.example` jest przeznaczona dla lokalnego Compose; zawiera wyłącznie przykładowe dane dostępowe. Przy innym UID/GID ustaw `LOCAL_UID` i `LOCAL_GID` zgodnie z wynikiem `id -u` i `id -g`.

Otwórz **http://localhost:8000** — domyślna strona Inertia `Welcome`. **http://localhost:8000/up** powinno zwracać HTTP 200. Procesy developerskie obejmują serwer Laravel, Vite, worker kolejki i podgląd logów. Aplikacja łączy się z MySQL przez `mysql:3306`; klient na komputerze może użyć `127.0.0.1:3307`.

Kolejne uruchomienia: `make up` lub `docker compose up -d app`. Logi: `docker compose logs -f app`. Zatrzymanie: `docker compose down` (zachowuje bazę w wolumenie). Zajęte porty zmień przez `APP_PORT` i `VITE_PORT` w `.env`, aktualizując również `APP_URL`.

## Uruchomienie bez Dockera

Utwórz lokalną bazę MySQL i użytkownika z prawami do tej bazy. Następnie:

```bash
cp .env.example .env
# Ustaw DB_HOST=127.0.0.1 oraz własne DB_PORT, DB_DATABASE, DB_USERNAME i DB_PASSWORD.
composer setup
composer run dev
```

Polecenie `php` w PATH musi wskazywać PHP 8.4 lub nowszy zgodny z lockfile. W obu wariantach `MAIL_MAILER=log` zapisuje lokalne wiadomości w logu; integracja Przelewy24 nie jest jeszcze zaimplementowana i nie wymaga kluczy.

## Lokalna baza i dane demonstracyjne

`composer setup` tworzy schemat bez przykładowych kont. Domyślny `DatabaseSeeder` nie tworzy danych demonstracyjnych. Aby dodać lokalnego użytkownika:

```bash
docker compose run --rm app php artisan db:seed --class=LocalDatabaseSeeder
```

Seeder działa wyłącznie przy `APP_ENV=local`, jest powtarzalny i tworzy `developer@example.test` z lokalnym hasłem ustawionym w seederze, bez nadpisywania istniejących danych. Hasło możesz zmienić przez formularz odzyskiwania hasła; wiadomość z linkiem znajdziesz w lokalnym logu. To konto startera, bez uprawnień administratora klubu. Testy tworzą własne dane przez fabryki.

Pełny reset **lokalnej, jednorazowej bazy developerskiej** usuwa wszystkie jej dane i ponownie uruchamia migracje oraz opcjonalny seeder demonstracyjny:

```bash
docker compose run --rm app php artisan migrate:fresh --seed --seeder=LocalDatabaseSeeder
```

Przed resetem sprawdź, że `.env` wskazuje lokalną bazę i `APP_ENV=local`. Nie wykonuj tego polecenia dla staging ani produkcji. Bez Dockera pomiń prefiks Compose. Zwykła aktualizacja schematu bez resetu to `php artisan migrate`.

## Czas i lokalna kolejka poczty

Runtime Laravel oraz sesje MySQL używają **UTC**. `config('app.business_timezone')` wskazuje **Europe/Warsaw** do interpretacji dni biznesowych i prezentacji czasu. Daty ważności karnetów będą liczone osobno zgodnie z ACT-01; ustawienie strefy nie uruchamia ich ważności. Dawne `APP_TIMEZONE=Europe/Warsaw` nie steruje już strefą runtime. Po aktualizacji istniejącej instalacji wykonaj `php artisan config:clear` i uruchom ponownie worker.

Domyślnie `QUEUE_CONNECTION=database`, a transport poczty to `log`. Wiadomości jawnie zlecone przez `Mail::queue()` lub `ShouldQueue` trafiają do tabeli `jobs` dopiero po zatwierdzeniu transakcji; rollback odrzuca ich zlecenie. `composer run dev` uruchamia worker automatycznie. Bez procesu developerskiego pojedyncze opróżnienie kolejki:

```bash
docker compose run --rm app php artisan queue:work --stop-when-empty --tries=1
```

Transport `log` nie wysyła wiadomości do zewnętrznego SMTP. Treść jest dostępna lokalnie w `storage/logs/laravel.log`. Samo ustawienie `QUEUE_CONNECTION` nie zmienia synchronicznych powiadomień startera w asynchroniczne.

## Kontrole jakości

Po instalacji zależności:

```bash
docker compose run --rm app composer validate --strict
docker compose run --rm app php artisan test --filter=ApplicationSmokeTest
docker compose run --rm app composer test
docker compose run --rm app npm run check
docker compose run --rm app npm run types:check
docker compose run --rm app npm run build
```

Bez Dockera wykonaj te same polecenia bez prefiksu `docker compose run --rm app`. `composer test` obejmuje Pint, PHPStan i zestaw testów na SQLite w pamięci (`phpunit.xml`). SQLite służy do szybkich kontroli i nie potwierdza zachowania blokad ani ograniczeń MySQL. `npm run check` wykonuje kontrole Vite+ (formatowanie i lint), a `types:check` sprawdza typy Vue/TypeScript.

Pełny zestaw testów na osobnym MySQL 8.4:

```bash
make test-mysql
# Odpowiednik bez make:
docker compose --profile testing up -d --wait mysql-test
docker compose run --rm --no-deps -e DB_HOST=mysql-test app composer test:mysql
```

`phpunit.mysql.xml` wymusza bazę i użytkownika `activefit_testing` oraz przykładowe hasło testowe. Usługa `mysql-test` jest osobnym serwerem, nie publikuje portów i trzyma dane w pamięci (`tmpfs`); jej zatrzymanie usuwa dane testowe. Pozostaje uruchomiona między kolejnymi testami; zatrzymasz ją przez `docker compose --profile testing stop mysql-test`. Baza developerska korzysta z osobnego serwera i trwałego wolumenu. Bez Dockera utwórz bazę/użytkownika o wartościach z `phpunit.mysql.xml` i wykonaj `composer test:mysql`; domyślny host to `127.0.0.1`, port można zmienić przez `DB_PORT`.

Obie konfiguracje testów wymuszają `APP_ENV=testing`, nieużywanie `DB_URL`, pocztę/cache/sesje w pamięci i kolejkę synchroniczną. Test integracyjny kolejki świadomie używa tabeli `jobs` w bazie testowej. Bootstrap testów odmawia pracy na bazie innej niż SQLite `:memory:` lub MySQL `activefit_testing`, także przy przypadkowo zachowanej konfiguracji z developmentu. Nie uruchamiaj dwóch zestawów testów jednocześnie na tej samej bazie MySQL.

`.env`, zależności, wygenerowane trasy Wayfinder i artefakty buildu są ignorowane przez Git. Nie dodawaj sekretów ani logów do commitów.

## GitHub Actions

Workflow [tests.yml](.github/workflows/tests.yml) uruchamia check **CI** dla pull requestów, zmian na `main` i ręcznego uruchomienia. Korzysta z PHP 8.4, Node 24 z `.nvmrc` oraz jednorazowego MySQL 8.4 z bazą `activefit_testing`. Tworzy tymczasowy klucz aplikacji i nie wymaga sekretów produkcyjnych ani kluczy Przelewy24.

`composer ci:check` kolejno sprawdza manifest Composer, buduje frontend (w tym generuje trasy Wayfinder), sprawdza formatowanie/lint, typy Vue/TypeScript, Pint, PHPStan i uruchamia Pest na MySQL. Każdy błąd zatrzymuje proces. Cache przechowuje pobrane paczki Composer/npm z kluczami zależnymi od lockfile; instalacje nadal korzystają z `composer install` i `npm ci`. Akcje są przypięte do commitów, token ma tylko `contents: read`, a limit joba wynosi 15 minut.

Lokalne odtworzenie kontroli CI po instalacji zależności:

```bash
docker compose --profile testing up -d --wait mysql-test
docker compose run --rm --no-deps -e DB_HOST=mysql-test app composer ci:check
```

Po opublikowaniu zmian sprawdź wynik w zakładce **Actions**. Po pierwszym zielonym przebiegu skonfiguruj ochronę `main`: wymagany check **CI** i zatwierdzenie PR przed scaleniem. Sam plik workflow nie ustawia ochrony gałęzi. Prywatność repozytorium i reguły ochrony wymagają weryfikacji w ustawieniach GitHub; lokalne kontrole nie są potwierdzeniem wykonania joba na GitHubie.

## Gdzie zacząć

Wspólne UI (TASK-005): kolory i kształty odpowiadają referencjom HTML. Komponenty w `resources/js/components/shared/` obsługują przyciski, pola, komunikaty, puste wyniki, ładowanie, potwierdzenia, paginację i zdjęcia. Layouty publiczny, klienta, pracownika i administratora opisuje [architektura frontendu](docs/architecture/frontend.md#shared-ui--task-005). Layouty pracownika/administratora są gotowe do podłączenia przyszłych widoków; nie dodają uprawnień ani tras paneli.

Szkielet aplikacji (TASK-004): `/` jest obsługiwane przez `HomeController`, a `/dashboard` przez `DashboardController` z middleware `auth` i `verified`. Panel pokazuje dane bieżącego konta i link do edycji profilu. Wspólne propsy Inertia są zdefiniowane w `resources/js/types/shared.ts`, a propsy stron w `resources/js/types/pages.ts`; do przeglądarki trafiają wyłącznie jawnie wskazane pola użytkownika. Role i docelowe panele klubu pozostają zakresem kolejnych zadań.

1. `AGENTS.md` — zasady pracy agentów AI.
2. `docs/product/requirements.md` — zakres i zatwierdzone reguły.
3. `docs/architecture/decisions/ADR-001-activation.md` — zatwierdzona aktywacja podczas pierwszej wizyty.
4. `docs/architecture/overview.md` — architektura aplikacji.
5. `docs/architecture/database.md`, `docs/architecture/cms.md`, `docs/architecture/security.md`, `docs/architecture/frontend.md`, `docs/architecture/business-flows.md` — szczegóły projektowe.
6. `docs/development/testing-strategy.md` — strategia testów.
7. `docs/development/implementation-plan.md` — zadania TASK-001–040.
8. `docs/architecture/repository-setup.md` — instrukcje konfiguracji projektu.
9. `design/reference-html/` — docelowa lokalizacja referencji UX/UI. Obecnie pliki znajdują się w katalogu ` design/reference-html/` (początkowa spacja w nazwie katalogu). To niezmodyfikowane materiały referencyjne, wyłączone z automatycznego formatowania.

## Kluczowe ustalenia

- Opłacenie karnetu **nie rozpoczyna jego ważności**. Pierwszą aktywację wykonuje pracownik lub administrator przy pierwszej wizycie posiadacza.
- PDF jest wysyłany po skutecznym potwierdzeniu płatności również dla karnetu oczekującego na aktywację. Nie pokazuje dat, których jeszcze nie znamy.
- Przedłużenie już aktywnego karnetu (w zatwierdzonym scenariuszu) zaczyna się po końcu bieżącego okresu.
- Jedno zamówienie = jeden karnet; kupujący i posiadacz mogą być różnymi osobami; zakupy gościnne nie są łączone z późniejszym kontem.
- Kwoty w bazie zapisujemy w groszach jako liczby całkowite.
- Otwarte decyzje biznesowe pozostają oznaczone `OPEN` — agent nie powinien ich rozstrzygać samodzielnie.

## Wersjonowanie dokumentów

Ostatni przegląd paczki: 2026-11-05. Szczegóły rozbieżności i decyzji znaleźć można w `docs/architecture/decisions/` oraz w polach `OPEN` dokumentacji. Przed wykonaniem zadania należy zweryfikować wymogi jego modułu i przygotować testy.
