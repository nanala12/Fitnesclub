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

## Pierwszy administrator (TASK-007)

W prywatnym `.env` lub konfiguracji sekretów ustaw `BOOTSTRAP_ADMIN_NAME`, `BOOTSTRAP_ADMIN_EMAIL`, `BOOTSTRAP_ADMIN_PHONE` (format międzynarodowy z `+` lub `00`) i `BOOTSTRAP_ADMIN_PASSWORD`. Hasło musi mieć co najmniej 12 znaków, małą i wielką literę, cyfrę oraz symbol. W `.env` ujmij wartości zawierające spacje lub znaki specjalne w odpowiednie cudzysłowy. Nie umieszczaj sekretów w poleceniach zapisywanych w historii terminala.

Po skonfigurowaniu lokalnej bazy wykonaj:

```bash
docker compose run --rm app php artisan config:clear
docker compose run --rm app php artisan migrate
docker compose run --rm app php artisan db:seed --class=AdminUserSeeder
```

Seeder jest wywoływany jawnie; zwykłe `db:seed` nie tworzy administratora. Tworzy jedno konto z rolą `admin` i zweryfikowanym e-mailem dostarczonym przez operatora wdrożenia, umożliwiając wejście do `/admin`. Nie wysyła wiadomości ani nie ujawnia hasła. Unikalny znacznik `users.bootstrap_key` pozwala rozpoznać konto utworzone przez ten seeder. Kolizja e-maila lub znormalizowanego telefonu z dowolnym innym kontem kończy operację błędem, bez przejęcia konta.

Ponowne uruchomienie wymaga tego samego skonfigurowanego e-maila; nie wymaga ponownego podania hasła, imienia ani telefonu i nie zmienia żadnych danych istniejącego konta, także zawieszenia. Zmiana e-maila konta lub odebranie mu roli administratora wymaga ręcznego, autoryzowanego przeglądu przed ponownym bootstrapem. Seeder nie służy do resetowania hasła ani nadawania uprawnień istniejącym użytkownikom.

Po inicjalizacji usuń dane bootstrapu z konfiguracji dostępnej aplikacji, przebuduj cache konfiguracji bez nich, jeśli jest używany, i uruchom ponownie długotrwałe procesy. Nie usuwaj znacznika w bazie. Powyższe komendy dotyczą środowiska lokalnego; migracje i inicjalizacja produkcyjna wymagają osobnej autoryzacji wdrożenia.

## Edycja własnego profilu (TASK-008)

Klient, pracownik i administrator edytują swoje imię, nazwisko, e-mail i telefon w `/settings/profile`. Po aktualizacji kodu uruchom lokalnie `docker compose run --rm app php artisan migrate`. Migracja dodaje osobne pola imienia i nazwiska; zachowuje dotychczasowe dane i nie zgaduje podziału wcześniejszej wspólnej nazwy. Przy pierwszym zapisie starszego konta uzupełnij oba pola i telefon z kierunkowym kraju. Nowa rejestracja zbiera już osobno imię i nazwisko.

Zmiana e-maila wymaga potwierdzenia nowego adresu i ogranicza dostęp do chronionych paneli do czasu weryfikacji. Link wysyła kolejka dopiero po zapisie danych; lokalny `composer run dev` uruchamia jej proces. Przy osobnym uruchamianiu aplikacji uruchom też worker, np. `docker compose run --rm app php artisan queue:work --tries=3`. W lokalnej konfiguracji `MAIL_MAILER=log` wiadomości trafiają do logu. Błąd wysyłki nie cofa danych; zadanie ponawia próbę, a użytkownik może też wysłać link ponownie z profilu. Zadanie dla nieaktualnego adresu jest pomijane.

Hasło zmienisz w `/settings/security`, podając obecne hasło i dwukrotnie nowe. Edycja własnego konta nie pozwala zmienić roli, zawieszenia ani znacznika administratora.

## Konta pracowników (TASK-009)

Administrator zarządza zespołem pod `/admin/pracownicy` (po aktualizacji kodu uruchom lokalne `docker compose run --rm app php artisan migrate`). Lista obejmuje konta pracowników i administratorów, z paginacją. Można dodać konto, edytować dane i rolę, zawiesić lub przywrócić dostęp oraz ponowić zaproszenie oczekujące na ustawienie hasła. Własne dane edytujesz przez „Mój profil”; panel nie pozwala zmienić własnej roli ani zawiesić własnego konta. Kont klientów nie przekształcamy tutaj w konta zespołu. Konta zespołu nie mają operacji usuwania, także przez własny formularz profilu.

Nowe konto otrzymuje losowe, nieujawniane hasło zastępcze. Kolejka wysyła zaproszenie z jednorazowym linkiem istniejącego mechanizmu ustawiania hasła; ważność wynika z konfiguracji brokera (domyślnie 60 minut). Ustawienie hasła potwierdza własność adresu e-mail i umożliwia zalogowanie do panelu. Token jest hashowany w bazie. Wiadomość nigdy nie zawiera hasła. Wysyłka wymaga działającego workera; lokalnie uruchamia go `composer run dev`, a `MAIL_MAILER=log` zapisuje wiadomość w logu. Błędy wysyłki są ponawiane do trzech prób; ponowne zaproszenie jest dostępne tylko dla niezawieszonego konta oczekującego na hasło. Nie zmienia ono istniejącego hasła.

Zawieszenie blokuje kolejne żądania i usuwa sesje z używanego w projekcie magazynu `database`, unieważnia zapamiętane logowanie oraz link do ustawienia hasła. Przywrócenie dostępu wymaga ponownego logowania (lub nowego zaproszenia, jeśli hasło nie zostało jeszcze ustawione). Zmiana e-maila wymaga jego potwierdzenia i unieważnia wcześniejszy link do hasła. Operacje zapisują audyt w tej samej transakcji; audyt zawiera aktora, rodzaj zmiany i bezpieczne informacje o zmienionych polach, bez haseł, tokenów i surowych danych kontaktowych. Pełny podgląd audytu i wspólna infrastruktura dla kolejnych modułów pozostają TASK-010.

## Historia zmian (TASK-010)

Administrator otwiera historię przez „Historia zmian” lub `/admin/audyt`. Widok pokazuje rzeczywiste wpisy, po 20 na stronę: rodzaj operacji, wykonawcę i jego rolę w chwili operacji, konto objęte zmianą, czas warszawski oraz bezpieczne informacje o zmienionych polach. Procesy automatyczne mają wykonawcę „System”. Nazwa wykonawcy pochodzi z bieżącego konta; identyfikator i zapisana rola pozwalają rozróżnić konta mimo późniejszych zmian danych. Nie ma edycji, usuwania ani eksportu wpisów. Pusta baza nie zawiera przykładowej historii.

Wspólny `RecordAudit` wywołujemy tylko z autoryzowanej akcji biznesowej, wewnątrz tej samej transakcji co zmiana. Przekazujemy jawnie wykonawcę (`User` pracownika/administratora lub `null` dla systemu), zdarzenie z `AuditAction` i identyfikator obiektu. Zapis poza transakcją jest odrzucany; błąd operacji wycofuje też audyt. Istniejące operacje pracownicze korzystają już z tego zapisu. Kolejne moduły dodają własne zdarzenia do katalogu oraz jawnie dozwolone pola i ich typy do `AuditPayload`, wraz z testami; nie przekazują całych modeli ani surowych żądań. Aktualny katalog obejmuje pięć zdarzeń pracowniczych; karnety, płatności i CMS podłączymy przy ich implementacji.

Ochrona modelu i standardowych zapytań Eloquent blokuje nadpisanie/usuwanie historii, także zbiorcze i z wyłączonymi zdarzeniami modelu. Nie zastępuje uprawnień serwera DB: przed produkcją oddziel konto migracji od konta aplikacji i zapewnij temu drugiemu tylko `SELECT`/`INSERT` dla `audit_logs`, bez szerszych grantów pozwalających zmienić lub usunąć tę tabelę. Nie ustawiamy takich uprawnień automatycznie. Retencja i ewentualna anonimizacja wymagają osobnych ustaleń.

TASK-010 używa tabeli dodanej w TASK-009 i nie dodaje kolejnej migracji.

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

## Strona publiczna (TASK-011)

Dostępne bez logowania: `/`, `/oferta`, `/oferta/{id}`, `/cennik`, `/grafik`, `/aktualnosci`, `/aktualnosci/{slug}`, `/galeria`, `/kontakt` i `/regulamin`. Wspólne menu, stopka i widoki korzystają z rzeczywistych danych tabel treści. Szkice, przyszłe publikacje i ukryte kategorie nie są publicznie dostępne. Bez opublikowanych danych strony pokazują stany puste. Wzorce HTML pozostają materiałem wizualnym — przykładowe ceny, godziny, zdjęcia i regulamin nie są kopiowane do bazy.

Migracja `2026_11_16_000000_create_public_content_tables` dodaje podstawę odczytu CMS i została zastosowana lokalnie. Przy pobraniu zmian do innego środowiska wykonaj jego zwykłą, zatwierdzoną procedurę migracji. Nie uruchamiamy seedera demonstracyjnych treści. Dane kontaktowe z makiet zostały odrzucone przez właścicielkę; `club_settings` pozostaje pusty do podania poprawnych danych. Dotyczy to również stopki aktywacji konta, która korzysta teraz z tego samego źródła.

Edytory i publikowanie w CMS należą do TASK-013–018. Wspólna obsługa uploadu, pojedynczego przypisania pliku i usuwania mediów jest gotowa w TASK-012. Zdjęcia tej podstawy odczytu są prywatne (`local`, ścieżki `cms/…`) i udostępniane przez `/media/{id}` tylko przy opublikowanym powiązaniu; niedostępne pliki, niezgodny MIME i ścieżki spoza katalogu są odrzucane. Nie wymaga to `storage:link`. Zapis zdjęć i podgląd szkiców są dostępne wyłącznie dla aktywnego, zweryfikowanego administratora; nie ma publicznego uploadu.

Cennik i wyróżnione karnety korzystają z jednego źródła `membership_plans` (TASK-019/020). Pusty katalog pokazuje informację o braku oferty. Metadane publicznych stron trafiają również do początkowego HTML; pełny rendering treści pozostaje w Inertia (bez nowej konfiguracji SSR).

## Zdjęcia CMS (TASK-012)

Wspólna obsługa mediów przyjmuje JPEG, PNG, WebP i AVIF: maksymalnie 5 MB, 6000 px na bok i 24 miliony pikseli. Serwer sprawdza rzeczywistą zawartość/MIME i wymiary; oryginalna nazwa ani deklaracja typu nie decydują o ścieżce. Pliki otrzymują losowe nazwy w prywatnym `local/cms`. Nie wymagają `storage:link`. Lokalny Compose montuje `docker/local/uploads.ini` (PHP: upload 6 MB, POST 8 MB, z miejscem na narzut formularza); przy własnym hostingu ustaw też odpowiedni limit PHP i serwera HTTP.

Migracja `2026_11_16_010000_add_media_ownership_and_cleanup` została zastosowana lokalnie. Dodaje właściciela w `media_assets` i trwałą kolejkę usuwania `media_file_deletions`. Przed zmianą schematu sprawdza istniejące współdzielenie i odmawia migracji, jeśli je znajdzie. Zamiana/usunięcie zdjęcia atomowo usuwa jego referencję i rekord mediów; fizyczny plik usuwa zadanie dopiero po commit. Rollback zachowuje stary plik. Usuwanie jest idempotentne, a awaria dysku lub kolejki pozostawia zadanie do ponowienia. Niedokończony upload ma godzinny okres ochronny, po którym również może zostać posprzątany.

Do obsługi plików uruchom worker i Laravel Scheduler w swoim środowisku. Lokalnie `composer run dev` uruchamia worker; scheduler można uruchomić osobno:

```bash
docker compose exec app php artisan schedule:work
```

Scheduler co minutę zleca do 100 oczekujących operacji. Ręczne ponowienie (nadal wymaga workera):

```bash
docker compose exec app php artisan cms:retry-media-cleanup
```

Na wdrożeniu skonfiguruj ciągły worker i standardowe wywołanie `schedule:run` co minutę oraz monitorowanie nieudanych zadań. Retry nie przywraca publicznych referencji ani nie zmienia zapisanej treści. Ten mechanizm dotyczy wyłącznie zdjęć CMS, nie retencji PDF ani danych klientów.

Dla kolejnych formularzy dostępne są `ManageCmsMedia` i komponent `OwnedImageField.vue`. Upload tworzy zdjęcie i zapisuje właściciela w callbacku jednej transakcji; akcja uploadu musi być jej zewnętrzną granicą. Zwykłe i ciche zapisy/usunięcia modeli CMS pilnują własności i cleanupu; nie używaj masowych operacji zmieniających referencje, surowego SQL ani `toBase()` do zapisu mediów. Usunięcie zdjęcia slajdu lub pozycji galerii usuwa też ten element, bo bez zdjęcia nie może istnieć; opcjonalne zdjęcie aktualności/kafelka pozostawia treść. Zdjęć opublikowanej wersji regulaminu nie zmieniamy. Formularze kafelków/karuzeli, aktualności, galerii, regulaminu, kontaktu i grafiku są dostępne od TASK-013–017, pozostała integracja CMS należy do TASK-018; nie dodano wspólnej biblioteki plików do wielokrotnego użycia.

Kafelki oferty pokazują maksymalnie trzy wiersze skrótu z wielokropkiem; kliknięcie otwiera `/oferta/{id}` z pełnym opisem i zdjęciem. Podstrony ukrytych/usuniętych ofert zwracają 404. Pełna treść pochodzi z istniejącego pola opisu, bez nowej kopii danych. Opcjonalny odnośnik z edytora jest dalszym przyciskiem na podstronie szczegółów. Karuzela ma wysokość 560 px na telefonach i 540 px od szerokości 640 px; dłuższy tekst przewija się wewnątrz, a zmiana slajdu przewija go do początku.

## Edycja strony głównej (TASK-013)

Aktywny administrator ze zweryfikowanym e-mailem ma w menu pozycje **Kafelki oferty** i **Karuzela**:

- `/admin/cms/strona-glowna/kafelki`
- `/admin/cms/strona-glowna/karuzela`

Można dodawać/edytować elementy, publikować i ukrywać je, przesuwać wyżej/niżej oraz usuwać z potwierdzeniem. Po udanym dodaniu lub zapisaniu edycji formularz przekierowuje do listy właściwej sekcji; błędy walidacji pozostawiają użytkownika w formularzu. Listy są stronicowane po 20 elementów; kolejność obejmuje także ukryte pozycje i działa przez granice stron. Nowy element trafia na koniec i jest domyślnie ukryty. Kafelki są widoczne na stronie głównej i w Ofercie, slajdy w karuzeli strony głównej. Slajd wymaga zdjęcia; kafelek może go nie mieć. Można usunąć ostatni element — publiczna strona obsługuje pustą sekcję.

Treść, zdjęcie, tekst alternatywny i widoczność zapisują się razem. Zamiana/usunięcie pliku korzysta z mechanizmu TASK-012. Zdjęcie kafelka można usunąć bez usuwania tekstu; zdjęcie slajdu usuwa się przez usunięcie całego slajdu albo zastępuje nowym. Zwykłe opisy mają limit 500 znaków dla kafelków i 1000 dla slajdów; tytuły do 150, alt text do 255. Dalszy odnośnik w szczegółach oferty może prowadzić wyłącznie do obsługiwanej podstrony serwisu, np. `/cennik`; puste pole nie dodaje kolejnego przycisku. CTA karuzeli pozostaje częścią istniejącego widoku.

Migracja `2026_11_16_020000_create_homepage_content_lists` została zastosowana lokalnie. Dodaje dwa techniczne wiersze blokady/rewizji sekcji, bez treści demonstracyjnych. Każda zmiana z edytora i wspólnych tras mediów aktualizuje rewizję właściwej sekcji; starszy formularz dostaje komunikat i odnośnik „Wczytaj aktualne dane”. Operacje i audyt są atomowe; zdjęcia nadal usuwa worker po commit. Podglądy miniatur mają osobny limit żądań, aby nie zużywały limitu zapisów. Nie dodano edytora dowolnych podstron usług, zdalnego pobierania zdjęć ani przykładowych danych klubu.

## Aktualności (TASK-014)

W menu administratora jest pozycja **Aktualności** (`/admin/cms/aktualnosci`). Można dodać/edytować wpis, zdjęcie i tekst alternatywny, włączyć/wyłączyć publikację oraz usunąć wpis z potwierdzeniem. Lista ma po 20 pozycji i stany **Ukryta**, **Zaplanowana**, **Opublikowana**. Po dodaniu lub edycji wracamy do indeksu. Wpisy otrzymują unikalny adres, który nie zmienia się przy zmianie tytułu.

Edytor obsługuje akapity, nagłówki, pogrubienie, kursywę, podkreślenie, listy oraz bezpieczne linki HTTPS/do podstron serwisu. Wklejanie i przeciąganie wstawia zwykły tekst; zdjęcia dodajemy osobnym polem. Serwer oczyszcza HTML przy zapisie oraz przy odczycie starych treści. Nie dopuszcza skryptów, zdarzeń, stylów, osadzonych obrazów, ramek, SVG/MathML ani niebezpiecznych URI. Treść po oczyszczeniu musi zawierać tekst. Techniczne limity: tytuł 200, wstęp 1000, body HTML 50 000 znaków; zdjęcia jak w TASK-012. Parser korzysta z natywnego HTML5 DOM w wymaganym PHP 8.4; nie dodano pakietów.

Data w formularzu jest w czasie polskim. Pusta data przy włączonej publikacji oznacza „teraz”; przyszła data oznacza planowanie. Ukryty wpis pozostaje ukryty niezależnie od daty. Godziny nieistniejące/podwójne podczas zmiany czasu są odrzucane z komunikatem, aby uniknąć cichego przesunięcia publikacji. Instants są przechowywane w UTC, a listy i formularze pokazują czas `Europe/Warsaw`. Zaplanowane treści pojawiają się przez warunek publikacji przy odczycie — bez nowego zadania schedulerowego.

Migracja `2026_11_18_000000_add_revision_to_news_posts` została zastosowana lokalnie. Dodaje licznik rewizji; nie seeduje wpisów i nie zmienia istniejących treści. Aktualizacje blokują wiersz i sprawdzają rewizję/bieżące zdjęcie, także po zmianach ze wspólnego endpointu mediów. Zapis treści, zdjęć i audytu jest atomowy; fizyczny cleanup pozostaje po commit. Szkice/przyszłe wpisy nie mają publicznego podglądu, również przez ręczny URL. Regulamin korzysta z tego samego mechanizmu oczyszczania HTML od TASK-016.

## Galeria (TASK-015)

W menu administratora pozycja **Galeria** (`/admin/cms/galeria`) pozwala zarządzać kategoriami i zdjęciami: dodawać, edytować, publikować/ukrywać, przesuwać wyżej/niżej oraz usuwać z potwierdzeniem. Kategorie mają nazwę (150 znaków) i krótki opis (500); zdjęcia mają tekst alternatywny (255), bez tytułów i opisów redakcyjnych. Każde zdjęcie należy do jednej kategorii; można je przenieść bez ponownego uploadu. Niepustą kategorię można usunąć dopiero po przeniesieniu lub usunięciu jej zdjęć.

Formularz dodawania pozwala wybrać wiele zdjęć, pokazuje podglądy i osobny tekst alternatywny dla każdego pliku. Kolejne wybory dodają pliki do listy; przed wysłaniem można je usunąć. Zdjęcia przesyłane są kolejno, każde osobnym żądaniem z limitem 5 MB, do wybranej kategorii i z wybraną widocznością. Błąd zatrzymuje kolejkę i pokazuje komunikat przy pliku; ponowienie pomija już zapisane zdjęcia. Po zakończeniu wracamy do indeksu. Odświeżenie/opuszczenie formularza usuwa lokalną kolejkę, zachowując już zapisane zdjęcia. Edycja istniejącego zdjęcia pozostaje pojedyncza.

Nowe kategorie i zdjęcia są domyślnie ukryte. Publiczna galeria i dostęp do pliku wymagają widoczności zarówno zdjęcia, jak i kategorii. Kategorie mają własną kolejność, a zdjęcia kolejność wewnątrz kategorii, obejmującą też ukryte pozycje. Lista ma osobne stronicowanie kategorii (12) i zdjęć (24), z filtrem kategorii. Udane dodanie/edycja wraca do indeksu. Stare formularze są odrzucane na podstawie wspólnej rewizji galerii; zmiany treści, zdjęć i audytu są atomowe. Zamiana/usunięcie korzysta z cleanupu po commit opisanego wyżej.

Migrację `2026_11_18_010000_add_gallery_visibility_and_revision` zastosowano lokalnie. Dodaje widoczność zdjęć i techniczny wiersz rewizji, bez treści demonstracyjnych. Domyślne `true` kolumny zachowuje dotychczasową widoczność istniejących zdjęć; model i edytor tworzą nowe zdjęcia jako ukryte.

## Regulamin i historia wersji (TASK-016)

W menu administratora **Regulamin** (`/admin/cms/regulamin`) można utworzyć szkic, edytować treść z formatowaniem i opcjonalne zdjęcie/tekst alternatywny, usunąć szkic lub potwierdzić publikację. Zapis szkicu wymaga potwierdzenia w oknie dialogowym; anulowanie zachowuje wpisane dane bez wysyłania formularza. Błędy zapisu pozostają przy polach formularza. Po zapisie wracamy do indeksu. Nowy szkic rozpoczyna się od treści aktualnego regulaminu; zdjęcie trzeba przesłać osobno, ponieważ pliki nie są współdzielone. Lista wersji jest stronicowana po 20 pozycji, z datami publikacji w czasie polskim i podglądem historii.

Publikacja zastępuje aktualną treść pod `/regulamin`. Opublikowanych wersji nie można edytować, ukryć ani usunąć, również przez wspólne operacje zdjęć. Poprzednia treść i jej zdjęcie pozostają w historii administratora; publicznie dostępny jest aktualny regulamin i jego zdjęcie. Szkic o numerze starszym od już opublikowanej wersji wymaga przygotowania nowej wersji. HTML jest oczyszczany na serwerze przy zapisie i odczycie, tak samo jak aktualności; limit treści to 50 000 znaków, zdjęć jak w TASK-012. Nie dodano treści regulaminu za klub.

Migracja `2026_11_18_020000_create_regulation_content_states` została zastosowana lokalnie i dodaje wyłącznie techniczny wiersz blokady/rewizji. Chroni równoczesne utworzenia i publikacje oraz odrzuca stare formularze; także wspólne operacje mediów aktualizują rewizję. Zapis treści, plików i audytu jest atomowy. Usunięcie szkicu korzysta z dotychczasowego cleanupu po commit.

**Pozostała integracja TASK-016:** TASK-019 dodaje zamówienia z FK `accepted_regulation_version_id`, czasem `regulation_accepted_at` i ochroną historycznej wersji. Formularz checkoutu, obowiązkowy checkbox konkretnej opublikowanej wersji i zapis zgody z żądania pozostają do TASK-022/TASK-030. Wymóg jest potwierdzony (OD-10, CMS-OD-03); zgoda marketingowa nie zastępuje akceptacji regulaminu.

## Kontakt, godziny i grafik (TASK-017)

W panelu administratora są dwie sekcje:

- **Kontakt i godziny** (`/admin/cms/kontakt`) — podgląd i edycja nazwy klubu, e-maila, telefonu, adresu oraz odnośnika HTTPS do mapy. Wszystkie pola kontaktu są opcjonalne; telefon wymaga prefiksu kraju i jest normalizowany, a mapa nie przyjmuje kodu iframe ani adresu z danymi logowania. Zapisane dane od razu trafiają na stronę Kontakt i do wspólnej stopki.
- **Grafik zajęć** (`/admin/cms/grafik`) — rodzaje zajęć (nazwa/opis) i tygodniowe terminy (dzień, początek, koniec, opcjonalny prowadzący). Można dodawać, edytować, publikować/ukrywać i usuwać elementy. Nowe rodzaje i terminy są domyślnie ukryte; publiczny termin wymaga widoczności także jego rodzaju. Listy są niezależnie stronicowane: 12 rodzajów i 20 terminów. Niepusty rodzaj można usunąć po przeniesieniu lub usunięciu jego terminów.

W sekcji **Grafik zajęć** przycisk **Ukryj publiczny grafik** usuwa „Grafik” z menu strony (także mobilnego) i wyłącza publiczną podstronę `/grafik` (404). Zajęcia pozostają w panelu i zachowują własne flagi publikacji. **Pokaż publiczny grafik** przywraca stronę oraz przycisk. Ukrywane są też dalsze odnośniki do grafiku na podstronach ofert. Domyślna widoczność pozostaje włączona; migracja `2026_11_18_040000_add_schedule_visibility_to_club_settings` dodaje wyłącznie flagę `is_schedule_visible`, zachowując dane klubu.

Każdy dzień ma stan **Otwarte**, **Nieczynne** albo **Brak informacji**. Pola godzin w kontakcie i grafiku korzystają z wyboru 00–23 oraz minut 00–59; format 24-godzinny jest niezależny od ustawień przeglądarki. Otwarcie wymaga jednego przedziału z końcem późniejszym od początku, w czasie polskim. Nieczynne/nieuzupełnione dni zapisują puste godziny, także przy zmianie wcześniej otwartego dnia. Formularz zapisuje wszystkie siedem dni razem. Nie ma wyjątków świątecznych, przedziałów przez północ, rezerwacji ani sprawdzania kolizji sal/prowadzących. Terminy tygodniowe są lokalnymi godzinami zegarowymi, a nie zdarzeniami UTC.

Udany zapis/dodanie wraca do indeksu. Walidacja pozostawia wpisane dane w formularzu, usuwanie wymaga potwierdzenia. Starsze formularze są odrzucane na podstawie wspólnej rewizji kontaktu i grafiku; zmiany i audyt są atomowe. Dostęp edycyjny ma wyłącznie aktywny zweryfikowany administrator. Opisy zajęć są zwykłym tekstem, bez wykonywania HTML. Limity: nazwy/prowadzący 150, opis zajęć 2000, adres 255, kod pocztowy 20 i odnośnik mapy 1000 znaków.

Migracja `2026_11_18_030000_create_club_content_states` została zastosowana lokalnie i dodaje tylko techniczny wiersz blokady/rewizji. Nie wprowadzono kontaktów z referencyjnych widoków, godzin ani przykładowego grafiku. Aktualne dane klubu należy wpisać przez panel.

## Przegląd CMS (TASK-018)

Sprawdzono wspólnie kafelki, karuzelę, aktualności, galerię, regulamin, kontakt i grafik: zapis/indeks, publikację i ukrywanie, dostęp do zdjęć, uprawnienia oraz cleanup po commit. Starszej aktualności bez treści po oczyszczeniu HTML nie można opublikować — trzeba najpierw uzupełnić jej treść. Opublikowany regulamin zachowuje niezmienną historię.

Test integracyjny całego CMS:

```bash
docker compose run --rm --no-deps app php artisan test tests/Feature/Cms/CmsIntegrationTest.php
```

Pełna kontrola z MySQL:

```bash
docker compose --profile testing up -d --wait mysql-test
docker compose run --rm --no-deps -e DB_HOST=mysql-test app composer ci:check
```

Usunięcie treści od razu usuwa publiczną referencję do zdjęcia; fizyczny plik usuwa worker po zatwierdzeniu transakcji. Worker i scheduler pozostają wymagane według instrukcji powyżej. Weryfikacja została wykonana na syntetycznych danych w osobnym środowisku.

Cennik korzysta z jednego źródła `membership_plans`. TASK-019 dodaje tabelę i snapshoty zakupów; TASK-020 udostępnia edycję cen, limit trzech wyróżnień i publiczną ofertę. Zapis akceptacji regulaminu podczas checkoutu pozostaje do TASK-022/030.

## Modele sprzedaży i karnetów (TASK-019)

Dodano osiem tabel: plany, zamówienia, płatności, karnety, wejścia, zmiany salda, zlecenia dostarczenia PDF i próby dostarczenia. Istniejące konta, CMS i audyt pozostają zachowane. Migracja została zastosowana lokalnie bez dodawania przykładowych zakupów lub planów.

Kupujący i posiadacz mają odrębne snapshoty i opcjonalne powiązania z kontem. Dane gości mogą się powtarzać i nie są przypisywane do późniejszych kont. Jedno zamówienie może mieć najwyżej jeden karnet. Historycznych cen, danych posiadacza oraz raz zapisanych dat nie można nadpisać zwykłymi operacjami modeli. Kwoty przyjmują wyłącznie nieujemne liczby całkowite w groszach. Nowy karnet zachowuje daty NULL do pierwszej wizyty; potwierdzenie płatności nie wyznacza początku ważności.

Testy schematu i modeli:

```bash
docker compose run --rm --no-deps app php artisan test tests/Feature/Sales/SalesSchemaTest.php
docker compose run --rm --no-deps -e DB_HOST=mysql-test app php vendor/bin/pest --configuration=phpunit.mysql.xml tests/Feature/Sales/SalesSchemaTest.php
```

TASK-019 przygotowuje strukturę danych. Formularze sprzedaży, weryfikacja płatności, aktywacja, rozliczanie wejść i generowanie/wysyłanie PDF będą wdrażane w kolejnych zadaniach. Katalog pozostaje pusty do dodania planów przez moduł TASK-020. Szczegóły ograniczeń i ochrony historii: `docs/architecture/database.md`, §14.

## Karnety i cennik (TASK-020)

Administrator zarządza ofertą w **Karnety i cennik** (`/admin/karnety`). Formularz obsługuje nazwę, zwykły opis tekstowy, cenę w złotych, rodzaj czasowy lub wejściowy, okres w miesiącach, limit wejść i kolejność. Karnet wejściowy ma wyłącznie liczbę wejść, bez ważności w miesiącach. Nowy plan domyślnie jest niedostępny i ukryty. Zapis wymaga potwierdzenia i przenosi na listę; sukces pojawia się w powiadomieniu, a błędy przy polach. Nieaktualna edycja wymaga wczytania nowszych danych.

Publiczny cennik pokazuje wyłącznie plany jednocześnie dostępne i widoczne. Strona główna korzysta z tych samych danych i dodatkowo z flagi wyróżnienia. Można oznaczyć najwyżej trzy plany, łącznie z ukrytymi; blokada transakcyjna chroni limit przy równoczesnych zapisach. Ceny w formularzu przyjmują przecinek lub kropkę i są przeliczane na całkowite grosze bez użycia float. Zmiany nie nadpisują wcześniejszych zamówień i karnetów. Plan z historią zakupów można wyłączyć, ale nie usunąć. Zmiany są zapisywane w audycie, w tym dawna i nowa cena.

```bash
docker compose run --rm --no-deps app php artisan test tests/Feature/Sales
docker compose run --rm --no-deps -e DB_HOST=mysql-test app php vendor/bin/pest --configuration=phpunit.mysql.xml tests/Feature/Sales
```

Migracja dodaje rewizję planu i techniczną blokadę katalogu; nie wstawia przykładowych cen. Karty publiczne kierują obecnie do kontaktu z klubem. Checkout i ostateczna kontrola dostępności podczas zakupu pozostają TASK-022/030; przyszła akcja zakupu musi ponownie sprawdzić plan po stronie serwera.

## Kategorie i pozycje cennika

Publiczny `/cennik` ma dwa poziomy: **kategoria → karnety i usługi**. Filtry wybierają kategorię, a każda kategoria prezentuje bezpośrednio nazwy pozycji i ceny. Osobna sekcja pokazuje wyróżnione karnety. Na telefonie kategorie i karty układają się w jednej kolumnie.

W panelu **Kategorie i usługi cennika** (`/admin/cennik`) dodasz kategorie oraz informacyjne usługi. W formularzu usługi wybierasz kategorię i cenę; w formularzu karnetu również wybierasz kategorię bezpośrednio. Nie ma pośrednich grup ani formularzy ich edycji. Nowe kategorie i usługi domyślnie są ukryte. Zapis wymaga potwierdzenia, wraca na indeks i zapisuje audyt; nieaktualne zmiany są odrzucane.

Ukrycie kategorii ukrywa jej usługi i karnety. Nie można usunąć kategorii zawierającej pozycje — można je przenieść albo ukryć kategorię. Domyślna kategoria siłowni pozostaje zachowana. Ceny planów w wierszach i wyróżnionych kartach pochodzą z jednego źródła. Usługi informacyjne nie są produktami sprzedaży karnetów. Ceny i historia zakupów pozostają zachowane; nie wstawiono przykładowej oferty ani kontaktów z makiety.

Na indeksach kategorii, usług i karnetów obok „Edytuj” są przyciski **Pokaż w cenniku / Ukryj**. Działają bez otwierania formularza i zachowują pozycję listy. Włączona usługa pod ukrytą kategorią ma informację „Ukryta przez kategorię” — aby ją opublikować, pokaż także jej kategorię.

## Obliczanie ważności (TASK-021)

`App\Support\MembershipPeriod` wylicza daty karnetu czasowego zgodnie z PLN-004. `startingOn('2027-01-31', 1)` zwraca `startsOn = 2027-01-31` oraz `expiresOn = 2027-02-28` (włącznie). `fromActivation($activatedAt, $durationMonths)` zamienia czas wizyty na lokalny dzień w `Europe/Warsaw`. Długość okresu przy aktywacji musi pochodzić z zakupionego snapshotu. Kalkulator przygotowuje logikę dla kolejnych zadań; sam nie aktywuje karnetu ani nie zapisuje dat w bazie. Karnety wejściowe pozostają bez ograniczenia czasowego.

## Zapis zamówienia online (TASK-022)

`App\Actions\Orders\CreateOnlineOrder::handle($account, $input)` przygotowuje jedno nieopłacone zamówienie na jeden karnet. Konto pochodzi z sesji (`null` oznacza zakup gościnny), dane kontaktowe nie służą do wyszukiwania ani łączenia kont. Dla siebie posiadacz jest kopią kupującego; dla innej osoby wymagane są osobne dane posiadacza. PDF po opłaceniu trafi na e-mail posiadacza, zgodnie z ustaleniem z 2026-11-23.

Akcja waliduje dane i akceptację aktualnego opublikowanego regulaminu, pobiera cenę oraz snapshot z serwera i odrzuca nieaktualną rewizję oferty. Nie tworzy płatności ani karnetu; formularz zakupu i integracja płatności należą do kolejnych zadań. Pełny kontrakt danych i zabezpieczeń: `docs/architecture/database.md`, §20.

## Wydanie opłaconego karnetu (TASK-023)

`IssuePaidMembership::handle($orderId, $actor)` wydaje jeden karnet po zapisanym, zweryfikowanym potwierdzeniu płatności. `$actor` oznacza uprawnionego pracownika/admina; jawne `null` jest zarezerwowane dla zaufanego procesu systemowego. Akcja sprawdza opłacenie zamówienia oraz zgodność płatności, korzysta z zakupionego snapshotu i pozostawia daty aktywacji/ważności puste. Ponowienie zwraca ten sam karnet bez resetowania salda.

W tej samej transakcji powstają audyt oraz pojedynczy rekord `document_deliveries` do późniejszego przygotowania i wysłania PDF. Generowanie i wysyłka PDF należą do TASK-031/032, weryfikacja powiadomień Przelewy24 do TASK-029. TASK-023 nie dodaje publicznego endpointu ani możliwości potwierdzenia płatności z przeglądarki; testy używają wyłącznie fikcyjnych potwierdzeń w bazie testowej.

## Aktywacja przy pierwszej wizycie (TASK-024)

`ActivateMembership::handle($actor, $membershipId)` uruchamia opłacony karnet po weryfikacji przez uprawnionego pracownika lub administratora. Zapisuje czas UTC i pracownika; dzień rozpoczęcia pochodzi ze strefy `Europe/Warsaw`. Karnet czasowy otrzymuje datę końca z zakupionego snapshotu, wejściowy pozostaje bez daty końca. Aktywacja nie odlicza wejścia, nie zmienia kupionego limitu ani nie zleca drugiej wysyłki PDF. Powtórzenie i równoczesne wywołania zachowują pierwszy zapis i jeden audyt.

Migracja `2026_11_23_020000_allow_non_expiring_entry_activation` dopuszcza aktywne karnety wejściowe bez daty końca, zachowując ograniczenia dla czasowych oraz historię i klucze obce. Jej cofnięcie jest blokowane, jeśli istnieją aktywowane karnety bezterminowe — nie dopisujemy im fikcyjnych dat. Migrację zastosowano lokalnie w bazie `activefit`, zachowując liczbę rekordów. Ekran wyszukiwania posiadaczy i przycisk aktywacji należą do TASK-035; TASK-024 przygotowuje zabezpieczony backend.

## Rozliczanie wejść (TASK-025)

`RedeemMembershipEntry::handle($actor, $membershipId, $requestId)` zapisuje świadomie wskazane wejście z aktywowanego, opłaconego karnetu wejściowego. Odejmuje dokładnie jedno wejście, rejestruje pracownika i czas UTC oraz zapisuje audyt w jednej transakcji. UUID identyfikuje jedną operację: ponowienie przez tego samego pracownika dla tego samego karnetu zwraca poprzedni zapis, również po wyczerpaniu salda. Kolejne wejście wymaga nowego UUID. Ostatniego wejścia nie można wykorzystać dwukrotnie przy równoczesnych żądaniach.

Aktywacja pozostaje osobną czynnością i niczego nie odlicza. Bezterminowe karnety wejściowe nie wygasają z upływem miesięcy. `GetMembershipEntryHistory` udostępnia uprawnionemu pracownikowi/adminowi stronicowaną historię z datą i wykonawcą, także gdy karnetu nie można już użyć. Migracja TASK-025 zmienia techniczne znaczniki czasu karnetu na `DATETIME(6)`, aby użycie po 2038 roku nie było blokowane limitem typu `TIMESTAMP`. Migrację zastosowano lokalnie, zachowując liczbę rekordów. Ekran obsługi recepcji i przyciski powstaną w TASK-035.

## Przedłużenia i niewykorzystane wejścia (TASK-026)

`RenewPaidMembership` obsługuje opłacone przedłużenie wskazanego karnetu: ten sam plan oraz zatwierdzone przejście 4 → 8 wejść. Czasowy otrzymuje kolejny okres od dnia po poprzedniej dacie końca; kilka zakupów układa się kolejno, bez nakładania dni. Wejściowy dodaje pozostałe wejścia do kupionej liczby (np. 3 + 8 = 11), od razu pozwala z nich korzystać i nie wymaga nowej aktywacji. Stary karnet zachowuje historię, a przeniesione saldo spada do zera. Transfer zapisuje obie strony ruchu wejść i audyt w jednej transakcji.

Pracownik jawnie wskazuje źródłowy karnet, a wybór zostaje trwale powiązany z zamówieniem. Zaufany proces płatności może automatycznie wydać przedłużenie przez `IssuePaidMembership`, jeśli zamówienie ma już zatwierdzone powiązanie. Obecny formularz danych nowego zakupu odrzuca surowe źródłowe ID od klienta; bezpieczny wybór w interfejsach zakupów/recepcji powstaje w kolejnych zadaniach. Migrację powiązań przedłużeń zastosowano lokalnie, zachowując liczbę rekordów. Nie łączymy historii gościnnej z kontem ani nie dobieramy posiadacza po samym telefonie. Kolejkowanie początkowych nieaktywowanych karnetów, wygasłe źródła i pozostałe zmiany typów pozostają poza zatwierdzonym zakresem.

## Sprzedaż na recepcji (TASK-027)

`CreateReceptionOrder::handle($actor, $input)` zapisuje nieopłacone zamówienie na aktywny plan z ceną i warunkami pobranymi z katalogu. Wymaga uprawnionego pracownika/admina, UUID operacji, rewizji planu, gotówki/karty oraz osobnych danych kupującego i ewentualnego innego posiadacza. Konto wybiera się jawnie; zgodny telefon/e-mail nie łączy gościa z kontem. Widoczność planu w publicznym cenniku nie blokuje jego sprzedaży na recepcji. Akceptacja regulaminu online pozostaje odrębnym wymaganiem ścieżki internetowej.

`ConfirmReceptionPayment::handle($actor, $order)` zapisuje potwierdzenie pieniędzy już otrzymanych przez pracownika. Rejestruje kanał `reception`, gotówkę/kartę, kwotę w groszach, czas UTC i wykonawcę; w tej samej transakcji wydaje karnet oraz zlecenie PDF. Nowy karnet czeka na pierwszą aktywację, a zatwierdzone przedłużenie korzysta z TASK-026. Akcja nie obciąża terminala ani banku. UUID zapobiega podwójnemu zapisowi zamówienia, a blokady zamówienia chronią potwierdzenie przed powieleniem płatności i karnetu. Przy łączeniu obu kroków w transakcji przekaż do potwierdzenia model zwrócony przez tworzenie; krytyczne dane są zawsze ponownie czytane pod blokadą.

Migracja dodaje wyłącznie unikalny, opcjonalny identyfikator operacji recepcji, zachowując historię i ograniczenia bazy. Odwrócenie, które usunęłoby użyte klucze operacji, jest blokowane. Migrację zastosowano lokalnie bez zmiany liczby rekordów. Formularz i przyciski sprzedaży recepcyjnej są częścią ekranów pracownika w TASK-035.

## Rozpoczęcie płatności Przelewy24 (TASK-028)

`InitiatePrzelewy24Payment::handle($orderId, $purchaser)` przygotowuje transakcję dla zapisanego i zatwierdzonego zamówienia online. Wymaga wcześniejszego zakończenia transakcji tworzącej zamówienie. Dla zakupu z konta sprawdza aktualnego właściciela; jawne `null` jest przeznaczone wyłącznie dla zaufanego kodu checkoutu gościa, który właśnie utworzył zamówienie lub zweryfikował dostęp do niego. Akcja nie jest publicznym endpointem. Kwota i waluta pochodzą z utrwalonego zakupu, a e-mail płatnika z danych kupującego, również przy zakupie dla innej osoby. API operatora ogranicza ten e-mail do 50 znaków; dłuższego adresu nie obcinamy.

Konfiguracja w `config/payments.php` jest domyślnie wyłączona. `.env.example` zawiera wyłącznie puste pola i bezpieczne wartości domyślne: `P24_ENABLED`, `P24_ENVIRONMENT`, `P24_MERCHANT_ID`, `P24_POS_ID`, `P24_API_KEY`, `P24_CRC`, `P24_RETURN_URL`, `P24_STATUS_URL` i `P24_LIVE_ENABLED`. Dane sandbox wprowadza się lokalnie poza Gitem; klucz API/report i CRC są odrębnymi sekretami. Oba adresy wymagają HTTPS. Środowisko produkcyjne operatora wymaga jednocześnie `APP_ENV=production` i jawnego `P24_LIVE_ENABLED=true`. Nie włączaj płatności przed wdrożeniem oraz sprawdzeniem obsługi powiadomień TASK-029 i checkoutu TASK-030.

Unikalna sesja jest zapisana przed kontaktem z operatorem; otrzymany token jest szyfrowany przy użyciu `APP_KEY`. Ponowienie gotowej płatności wykorzystuje ten sam token. Timeout, niejednoznaczna odpowiedź lub utrata wyniku nie uruchamiają automatycznie nowego żądania rejestracji; zachowana sesja wymaga sprawdzenia w TASK-029. Jawnie odrzucone rejestracje można ponowić do trzech prób. Migracja dodaje metadane rejestracji i unikalność sesji operatora, a jej cofnięcie blokuje usunięcie użytej historii.

Adapter korzysta z [oficjalnej specyfikacji REST Przelewy24](https://developers.przelewy24.pl/yaml/en_documentation_1.0.yaml). Testy używają fikcyjnych odpowiedzi HTTP, bez rzeczywistych płatności. Samo przygotowanie transakcji lub powrót przeglądarki nie potwierdza opłaty i nie wydaje karnetu. Weryfikacja operatora należy do TASK-029, a przycisk zakupu i przekierowanie w interfejsie do TASK-030.

## Potwierdzanie płatności Przelewy24 (TASK-029)

Adres `POST /payments/przelewy24/notification` przyjmuje powiadomienia JSON operatora. Ustaw `P24_STATUS_URL` na pełny publiczny adres HTTPS tego endpointu. Ma własny limit żądań i działa bez sesji przeglądarki. Weryfikuje podpis całego powiadomienia, konto operatora, sesję, identyfikator transakcji, kwoty w groszach i walutę względem utrwalonego zamówienia. Następnie potwierdza opłatę przez API P24. Sam powrót przeglądarki nie zmienia statusu zamówienia.

Potwierdzenie opłaty, `paid_at` w UTC, wydanie karnetu, audyt i zlecenie PDF zapisują się atomowo. Nowy karnet czeka na aktywację przy pierwszej wizycie; zatwierdzone przedłużenie zachowuje reguły TASK-026. Powtórzone lub równoczesne powiadomienia nie tworzą kolejnego karnetu ani zlecenia PDF. Transakcji operatora nie można przypisać do drugiego zakupu.

Zapisane sesje można sprawdzić poleceniem uruchamianym w środowisku z właściwą konfiguracją P24:

```bash
php artisan payments:reconcile-p24 --limit=25
php artisan payments:reconcile-p24 --payment=123 --limit=1
```

`--payment` jest wewnętrznym ID z tabeli `payments`. Polecenie nie rejestruje ani nie obciąża nowej transakcji; pyta operatora o istniejącą sesję i kończy potwierdzenie po zgodnej odpowiedzi. Nie jest podłączone do harmonogramu. Brak sesji lub brak wpłaty nie oznacza automatycznie anulowania zamówienia; zwrócone i niespójne płatności wymagają sprawdzenia. Błąd API lub lokalnego zapisu zachowuje identyfikatory do kolejnej próby. Diagnostyka przechowuje stałe kody błędów, liczbę prób i czas sprawdzenia, bez danych osobowych i treści odpowiedzi P24.

Migracja TASK-029 dodaje metadane weryfikacji i unikalność transakcji w obrębie operatora, środowiska oraz konta sprzedawcy. Cofnięcie użytej historii weryfikacji jest blokowane. Testy nadal korzystają wyłącznie z symulacji HTTP. Przed włączeniem sprzedaży wymagany jest rzeczywisty test sandbox z publicznym HTTPS i właściwymi danymi operatora. Checkout powstaje w TASK-030, generowanie i wysyłka PDF w TASK-031/032.

## Kluczowe ustalenia

- Opłacenie karnetu **nie rozpoczyna jego ważności**. Pierwszą aktywację wykonuje pracownik lub administrator przy pierwszej wizycie posiadacza.
- PDF jest wysyłany po skutecznym potwierdzeniu płatności również dla karnetu oczekującego na aktywację. Nie pokazuje dat, których jeszcze nie znamy.
- Przedłużenie już aktywnego karnetu (w zatwierdzonym scenariuszu) zaczyna się po końcu bieżącego okresu.
- Jedno zamówienie = jeden karnet; kupujący i posiadacz mogą być różnymi osobami; zakupy gościnne nie są łączone z późniejszym kontem.
- Kwoty w bazie zapisujemy w groszach jako liczby całkowite.
- Otwarte decyzje biznesowe pozostają oznaczone `OPEN` — agent nie powinien ich rozstrzygać samodzielnie.

## Wersjonowanie dokumentów

Ostatni przegląd paczki: 2026-11-05. Szczegóły rozbieżności i decyzji znaleźć można w `docs/architecture/decisions/` oraz w polach `OPEN` dokumentacji. Przed wykonaniem zadania należy zweryfikować wymogi jego modułu i przygotować testy.
