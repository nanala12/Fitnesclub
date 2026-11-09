# ActiveFit — specyfikacja techniczna repozytorium

> **Wersja:** 1.1-draft · **Data:** 2026-10-26 · **Etap AI SDLC:** Repository Foundation  
> **Przeznaczenie:** instrukcja dla developera i agenta AI zakładających repozytorium.  
> **Dokument bazowy:** `docs/product/requirements.md` (wymagania biznesowe mają pierwszeństwo).  
> **Statusy:** **CONFIRMED** — decyzja zespołu; **RECOMMENDED** — propozycja techniczna do zatwierdzenia; **OPEN** — nie podejmować decyzji bez uzgodnienia.

## 1. Cel repozytorium

Pojedyncze repozytorium aplikacji **ActiveFit**: publiczna strona klubu, CMS, panel administratora, panel pracownika, panel klienta, sprzedaż karnetów online (również bez konta), sprzedaż recepcyjna i integracja z Przelewy24. Nie tworzymy osobnego frontendowego SPA/API ani mikroserwisów. Istniejące podstawowe UX/UI stanowi punkt wyjścia — nie przepisujemy go bez potrzeby.

## 2. Stos technologiczny

| Element | Wybór | Status / uwagi |
|---|---|---|
| Backend | **Laravel** | CONFIRMED |
| Integracja backend–frontend | **Inertia.js** | CONFIRMED |
| Frontend | **Vue 3** | CONFIRMED |
| Framework version | **Laravel 13.x** | RECOMMENDED — aktualne wydanie; wymaga PHP >= 8.3 |
| PHP | **8.4** | RECOMMENDED — wersję ustalić na poziomie obrazu/hostingu i CI |
| TypeScript | **TypeScript** + Vue Composition API | CONFIRMED — zgodnie z oficjalnym starterem Vue |
| UI | Tailwind CSS + komponenty startera, adaptowane do istniejącego UX/UI | CONFIRMED; bez narzucania dodatkowej biblioteki |
| Build | Vite + npm | RECOMMENDED |
| Database | MySQL 8.x | CONFIRMED; sprawdzić zgodność wybranej wersji z hostingiem |
| Auth | Sesje Laravel, starter Vue z auth | RECOMMENDED; brak obowiązku konta przy zakupie |
| Testy | Pest (PHP), Vitest (komponenty, jeśli potrzebne), Playwright (kluczowe E2E) | Pest CONFIRMED; Vitest i Playwright RECOMMENDED |
| Format / analiza | Laravel Pint, ESLint, Prettier, Vue/TS typecheck | RECOMMENDED |
| Queue | Laravel Queue (początkowo database), worker na produkcji | RECOMMENDED |
| Płatności | Przelewy24 | CONFIRMED; integracja po konfiguracji sandbox |
| Pliki/PDF | Laravel Storage + generator PDF do wyboru na etapie implementacji | PDF wymagany; biblioteka OPEN |
| Język UI / czas | polski (`pl`), runtime i sesja MySQL `UTC`, daty biznesowe `Europe/Warsaw` | Implemented in TASK-002; `config('app.business_timezone')` for business dates |

Źródła wersji: [Laravel 13 release notes](https://laravel.com/docs/13.x/releases), [oficjalny Vue starter kit](https://laravel.com/docs/13.x/starter-kits). **Nie przypinaj najnowszych patchy na sztywno w tym dokumencie:** źródłem prawdy są wersjonowane `composer.lock` i `package-lock.json`.

## 3. Utworzenie projektu — developer quick start

### Wymagania lokalne

- PHP 8.4 + rozszerzenia wymagane przez Composer/Laravel oraz sterownik MySQL; Composer 2.
- Node.js **LTS** i npm (dokładne wersje zapisać w `.nvmrc` i CI po inicjalizacji).
- MySQL, Git, opcjonalnie Docker / Laravel Sail. Dostęp do SMTP sandbox (np. Mailpit) przy testowaniu maili.

### Komendy

```bash
# 1. Zainstaluj Laravel Installer, jeśli go nie masz
composer global require laravel/installer

# 2. Utwórz aplikację; wybierz oficjalny starter Vue + Inertia
laravel new activefit
# W kreatorze wybierz: Vue, odpowiedni system testów oraz bazę danych.
# Jeśli instalator pyta o WorkOS, nie wybieraj go bez świadomej decyzji.

cd activefit

# 3. Zainstaluj zależności JS
npm install

# 4. Skonfiguruj .env (DB, MAIL, APP_URL) przed migracjami
php artisan key:generate  # wykonaj tylko gdy APP_KEY nie został już ustawiony
php artisan migrate

# 5. Uruchom środowisko developerskie
composer run dev
```

**Uwaga:** kreator Laravel zmienia się między wersjami; po wygenerowaniu projektu zweryfikuj `composer.json`, `package.json`, dostępne skrypty oraz konfigurację auth. Nie instaluj Inertia drugi raz, jeśli starter ją zawiera.

### Pierwszy commit

```bash
git init
git branch -M main
git add .
git commit -m "chore: initialize ActiveFit application"
# Repozytorium zdalne utwórz jako PRYWATNE w wybranej organizacji / na koncie.
git remote add origin <REPOSITORY_SSH_URL>
git push -u origin main
```

Nie zapisuj rzeczywistego URL zdalnego repozytorium, nazwy organizacji ani danych dostępowych w dokumentacji bez decyzji właściciela.

## 4. Zalecana struktura katalogów

```text
activefit/
├── app/
│   ├── Actions/               # operacje biznesowe (np. aktywacja karnetu)
│   ├── Http/Controllers/      # cienkie kontrolery web/Inertia
│   ├── Http/Requests/         # walidacja wejścia
│   ├── Models/                # Eloquent; dopasować do biznesu
│   ├── Policies/              # autoryzacja zasobów
│   ├── Jobs/                  # e-mail, PDF, zadania w tle
│   └── Notifications/         # powiadomienia
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── resources/js/
│   ├── components/            # współdzielone komponenty UI
│   ├── composables/
│   ├── layouts/                # Public, Customer, Staff, Admin
│   ├── pages/                  # zgodnie z konwencją startera
│   │   ├── public/
│   │   ├── account/
│   │   ├── staff/
│   │   └── admin/
│   └── types/
├── routes/
│   └── web.php                # można dzielić na dodatkowe pliki, gdy urośnie
├── tests/
│   ├── Feature/
│   └── Unit/
├── docs/
│   ├── product/requirements.md
│   └── architecture/repository-setup.md
├── .github/workflows/ci.yml
├── AGENTS.md
├── README.md
├── .editorconfig
├── .env.example
├── composer.lock
└── package-lock.json
```

To **katalogi docelowe**, nie wszystkie muszą istnieć po wygenerowaniu startera. Nie tworzyć pustych klas ani sztucznych abstrakcji. Akcje biznesowe tylko gdy logika wykracza poza prosty CRUD. Nie dodawać warstwy repository dla Eloquent bez uzasadnienia.

## 5. Główne granice domenowe

- **Identity & Access** — użytkownicy z kontem, pracownicy, administratorzy, role/policies.
- **People / Holders** — osoba uprawniona do korzystania z karnetu; może nie mieć konta. Telefon nie jest unikatowym identyfikatorem.
- **Catalog** — plany karnetów i ich wersjonowane warunki ofertowe.
- **Sales / Payments** — zamówienie (w MVP dokładnie jeden karnet), kupujący (może różnić się od posiadacza), Przelewy24, gotówka/karta na recepcji.
- **Memberships** — nabyte karnety, terminy, limity wejść, historia, aktywacja.
- **CMS** — regulamin, aktualności, galeria, cennik, kontakt, kafelki oferty, karuzela i grafik.
- **Notifications / Documents** — e-mail po zakupie, PDF karnetu, mail powitalny pracownika.

**Kluczowe invarianty:** (1) zamówienie i płatność nie są karnetem; (2) karnet jest przypisany do posiadacza, niekoniecznie do kupującego; (3) brak konta nie blokuje zakupu; (4) potwierdzenie płatności jest przetwarzane idempotentnie; (5) snapshot ceny i zasad zakupu jest niemodyfikowalny; (6) odjęcie wejścia wymaga transakcji i zabezpieczenia współbieżności.

## 6. Konwencje kodu i architektury

- PHP: PSR-12 + Laravel Pint; deklaracje typów i jawne typy zwracane tam, gdzie praktyczne.
- Vue: `<script setup lang="ts">`, Composition API; props i emit typowane; komponenty małe i ukierunkowane na odpowiedzialność.
- Routes: nazewnictwo `public.*`, `account.*`, `staff.*`, `admin.*`, osobne middleware i Policies dla operacji wrażliwych.
- Backend: `FormRequest` do walidacji, `Policy` do autoryzacji, `Action` dla logiki kupna/aktywacji/przedłużenia; kontroler koordynuje, nie implementuje reguł.
- Jedna aplikacja, jedna baza danych. Nie tworzyć osobnego REST API na potrzeby Inertia bez konkretnego przypadku użycia.
- Waluta: **integer w groszach (CONFIRMED)** dla wszystkich kwot w bazie, DTO i logice biznesowej (np. `price_grosz`, `total_grosz`). Nigdy `float` do obliczeń pieniężnych. Na granicy integracji z Przelewy24 stosuj jednostkę wymaganą przez API; przy formatowaniu PLN pokazuj dwie cyfry po przecinku.
- Daty: instants transakcyjne UTC; reguły ważności w polskiej strefie i z testami 29/30/31, DST. `OD-01`: dla daty stanowiącej ostatni dzień miesiąca stosować ostatni dzień kolejnego miesiąca; szczegóły granicy ważności pozostają do zatwierdzenia. `OD-02`: przedłużenie czasowe po zakończeniu obecnego okresu, niewykorzystane wejścia dodawać do nowego pakietu; nie zakładać zachowania przy mieszanych typach. `OD-03` pozostaje otwarte.
- Nazwy klas/zmiennych/komentarzy w kodzie po angielsku; teksty interfejsu po polsku.
- Frontend nie jest źródłem autoryzacji; backend ogranicza dostęp do każdego widoku i działania.
- Dane historyczne zamówienia i zakupionego karnetu nie mogą zmieniać się po aktualizacji planu.

## 7. Środowiska i konfiguracja

**Stan implementacji — TASK-002 (2026-11-09):** aktualne polecenia instalacji, resetu i kontroli jakości są w `README.md`. Runtime Laravel i sesja MySQL mają stałą strefę UTC; dni biznesowe korzystają z `config('app.business_timezone') = Europe/Warsaw`. Jest to doprecyzowanie wcześniejszej rekomendacji pojedynczej strefy aplikacji, zgodne z ACT-01. Kolejka `database` ma `after_commit=true`, a lokalny transport poczty to `log`. Domyślny seeder nie tworzy danych demonstracyjnych; jawny `LocalDatabaseSeeder` odmawia pracy poza `local`. Testy SQLite działają w pamięci, a testy MySQL w osobnej usłudze Compose `mysql-test` z bazą/użytkownikiem `activefit_testing`. Konfiguracja testów wymusza bezpieczne transporty i odmawia użycia bazy developerskiej.

| Środowisko | Przeznaczenie | Zasady |
|---|---|---|
| local | development | `APP_DEBUG=true`; testowy SMTP i sandbox P24; dane fikcyjne |
| testing/CI | testy automatyczne | oddzielna baza, fake mail/queue, brak prawdziwych płatności |
| staging | testy akceptacyjne | HTTPS, sandbox P24, izolowane dane |
| production | użytkownicy i płatności | HTTPS, `APP_DEBUG=false`, backup, worker kolejki, scheduler, monitoring |

Minimalna lista ustawień w `.env.example` (bez wartości sekretów):

```dotenv
APP_NAME=ActiveFit
APP_ENV=local
APP_URL=http://localhost:8000
# Runtime timezone is fixed to UTC; business dates use Europe/Warsaw.
APP_LOCALE=pl
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=activefit
DB_USERNAME=activefit
DB_PASSWORD=
QUEUE_CONNECTION=database
MAIL_MAILER=log
# P24_MERCHANT_ID=
# P24_POS_ID=
# P24_CRC=
# P24_API_KEY=
# P24_ENV=sandbox
```

Zmienne P24 to **proponowany interfejs konfiguracji aplikacji**, nie potwierdzona lista zmiennych SDK. Właściwe parametry, podpisy i endpointy sprawdzić w aktualnej dokumentacji Przelewy24 podczas integracji. Nie umieszczać sekretów w `.env.example`, Git ani frontendzie. `.env` w `.gitignore`. Sprawdzić środowiskowy `APP_KEY`, logi, konfigurację cookie i nagłówki bezpieczeństwa.

## 8. Bezpieczeństwo i prywatność — wymagania repozytorium

1. Login pracownika tylko poprzez aktywne konto; zawieszenie blokuje dalszy dostęp (również aktualne sesje — implementacja do rozstrzygnięcia).
2. Zaproszenie pracownika na e-mail z jednorazowym, ograniczonym czasowo linkiem ustawienia hasła; nigdy hasło w wiadomości.
3. Rate limiting: logowanie, odzyskiwanie hasła, wyszukiwanie klientów, checkout oraz endpointy płatności stosownie do charakteru ruchu.
4. Pliki PDF zawierające dane osobowe w **private storage**; bez publicznych, zgadywalnych ścieżek. Weryfikować uprawnienia dostępu i okres przechowywania.
5. Zamówienia dostępne wyłącznie właściwemu kupującemu (lub uprawnionemu personelowi), a status płatności ustala serwer po walidacji z dostawcą, nigdy URL powrotu.
6. Na stronie CMS bezpiecznie renderować HTML z edytora (sanityzacja / whitelist); ograniczać upload MIME, rozmiar i weryfikować rzeczywisty typ pliku.
7. Logi nie mogą zawierać haseł, kluczy P24, pełnych danych płatniczych ani nadmiarowych danych osobowych.
8. Pamiętać o informacjach dla kupującego i obowiązkach prawnych; zakres regulaminu i retencji danych jest nadal otwarty.

## 9. Minimalne testy przed pierwszym modułem sprzedaży

| Obszar | Minimalny scenariusz |
|---|---|
| Auth | gość nie otwiera `admin` / `staff`; zawieszony pracownik nie wykonuje operacji |
| Checkout | zakup bez konta i zakup dla innej osoby |
| Płatności | wielokrotny ten sam callback nie aktywuje dodatkowych karnetów; błędny callback odrzucony |
| Karnety | kupujący ≠ posiadacz; ważność i przedłużanie zgodnie z zatwierdzoną regułą |
| Wejścia | jednoczesne dwa żądania nie zużywają więcej wejść niż dostępne |
| CMS | element ukryty nie jest publiczny; niedozwolony upload zostaje odrzucony |
| E-mail / PDF | wysyłka kierowana na ustalony adres; dokument nie jest dostępny publicznie |

Preferować testy Feature dla zachowania biznesowego. Użyć fake queue/mail/storage w testach; integrację Przelewy24 testować na sandbox/staging. Nie wykorzystywać produkcyjnych danych klientów w fixture'ach.

## 10. CI, Git i jakość

**Branching:** `main` — stabilny; praca przez krótkie gałęzie `feat/*`, `fix/*`, `chore/*` i Pull Requests. Commity w formacie Conventional Commits, np. `feat(memberships): add holder lookup`.

**Minimalne kontrole Pull Request:**

```bash
composer validate
vendor/bin/pint --test
php artisan test
npm ci
npm run build
```

Dodatkowo: `npm run lint`, `npm run types` / `typecheck` oraz skan zależności, **jeśli takie skrypty istnieją w wygenerowanym starterze**. Zapisz dokładne komendy w README po inicjalizacji. W CI konfiguruj PHP, Node LTS, MySQL service i zmienne testowe. Nie uruchamiaj migracji przeciwko produkcji. Chroń `main` regułą wymagającą udanego CI i przeglądu PR; sekretów nie wystawiaj do PR z forków. GitHub jest zatwierdzony — można skonfigurować Dependabot po utworzeniu repozytorium.

## 11. Pliki obowiązkowe w początkowym repozytorium

| Plik | Zawartość |
|---|---|
| `README.md` | instalacja, `composer run dev`, env, migrate, testy, deploy overview |
| `AGENTS.md` | instrukcje AI: źródło prawdy, ograniczenia, testy, zakaz dopowiadania OPEN |
| `docs/product/requirements.md` | aktualne wymagania z ID i statusem |
| `docs/architecture/repository-setup.md` | niniejszy dokument |
| `.env.example` | komplet wymaganych nazw konfiguracji bez sekretów |
| `.editorconfig` | stała konwencja końca linii i wcięć |
| `.gitignore` | `.env`, `vendor`, `node_modules`, build outputs, logi, lokalne pliki |
| `.github/workflows/ci.yml` | kontrola jakości i testy (dla repo na GitHub) |
| `composer.lock`, `package-lock.json` | reproducible installs |

### Proponowany minimalny `AGENTS.md`

```md
# ActiveFit — Agent Guidelines

- Read `docs/product/requirements.md` before implementing functionality.
- Respect CONFIRMED requirements; do not invent behavior for OPEN decisions.
- Use Laravel + Inertia + Vue 3 in a single repository.
- Apply server-side validation and authorization to every sensitive action.
- Keep controllers thin; use actions for nontrivial business logic.
- Add tests for business rules, payment idempotency, and concurrency.
- Never commit credentials, private customer data, or generated membership PDFs.
- Preserve existing approved UX/UI; write user-facing copy in Polish.
- Summarize changes, tests run, and unresolved decisions in every PR.
```

## 12. Co należy ustalić przed rozpoczęciem implementacji domeny

| ID | Temat | Wpływ |
|---|---|---|
| TECH-01 | Hosting / deployment target, baza i wersje runtime na produkcji | wybór PHP, MySQL, worker, scheduler, storage |
| TECH-02 | **CONFIRMED: GitHub**; organizacja i uprawnienia dostępu do ustalenia | repo, branch protection, GitHub Actions CI |
| TECH-03 | **CONFIRMED: TypeScript, Tailwind CSS, MySQL, Pest** | finalny zestaw narzędzi |
| TECH-04 | Storage plików, dostawca SMTP i sposób wysyłania PDF | powiadomienia i bezpieczeństwo |
| TECH-05 | P24 sandbox, proces rozliczania płatności i konfiguracja merchant | integracja płatności |
| TECH-06 | Otwarte decyzje w requirements.md (`OD-05` rozstrzygnięte) | nie implementować na podstawie domysłów |
| TECH-07 | **CONFIRMED: integer (grosze)**; bez float/DECIMAL dla kwot domenowych | typy pól, migracje i kontrakty |
| TECH-08 | SSR publicznych stron vs standardowy rendering Inertia | SEO i proces produkcyjny |

## 13. Kryteria ukończenia etapu Repository Foundation

- [ ] Repozytorium prywatne istnieje; zdalna gałąź `main` jest chroniona.
- [ ] Starter Laravel/Vue/Inertia uruchamia się lokalnie.
- [ ] `composer.lock` oraz `package-lock.json` są commitowane.
- [ ] `README.md`, `AGENTS.md`, requirements i specyfikacja repozytorium są w repo.
- [ ] `.env.example` działa po podstawowej konfiguracji; brak sekretów w historii Git.
- [ ] Lokalne migracje oraz testy przechodzą.
- [ ] CI wykonuje testy i podstawowe sprawdzenia jakości.
- [ ] Role i pierwsze trasy mają testy odmowy dostępu.
- [ ] Spisano otwarte decyzje techniczne i biznesowe, bez blokowania prac nad fundamentem.

---

**Zasada nadrzędna:** repozytorium i fundament można utworzyć teraz, ale nie wolno uznawać niezatwierdzonych reguł biznesowych za finalne. Każda zmiana architektoniczna powinna wskazywać odpowiedni identyfikator wymagania (`MEM-*`, `PAY-*`, `CMS-*`, itd.).

## Decyzja OD-05 — konta i zakupy gościnne (zatwierdzona)

- Dla `users`: unikalny adres e-mail oraz unikalny numer telefonu przechowywany w ujednoliconym formacie (np. E.164). Wymusić to również indeksami `UNIQUE` w bazie.
- Dane kupującego i posiadacza przy zakupach bez konta nie muszą być unikalne. Nie należy wiązać ich z `users` na podstawie zgodności telefonu lub e-maila.
- Osoba, która kupowała bez konta, może później zarejestrować konto z tym samym kontaktem, o ile nie należy już do innego zarejestrowanego konta. Historia gościnna pozostaje niezależna i niedostępna z nowego konta.
- Wyszukiwanie pracownicze po numerze telefonu może zwrócić wiele osobnych rekordów; przy prezentowaniu wyników nie scalać automatycznie tożsamości.
- Testy: unikalność e-maila i znormalizowanego telefonu w `users`, dozwolone powtórzenia danych w zamówieniach gościnnych, brak przejęcia historii przy rejestracji oraz wyszukiwanie wielu rekordów.

## 14. Decyzje implementacyjne potwierdzone 2026-11-04

- Uruchomienie produkcyjne wymaga jawnego, idempotentnego seedera początkowego konta `admin`. Sekrety tylko w env/secret managerze, nigdy w commicie.
- Każda rola ma trasę własnego profilu; zmiana e-maila wymaga potwierdzenia.
- PDF ma być wysyłany przez kolejkę po potwierdzeniu płatności, także dla przedłużeń z przyszłym startem; nie wymagamy zadania „wyślij na starts_on”.
- `ACT-01` zatwierdzone: nowy opłacony karnet pozostaje `pending_activation` aż do pierwszej wizyty, gdy pracownik/admin zapisze okres ważności; PDF wysyłamy już po płatności, bez nieznanych dat.
- Źródła prawdy: `docs/product/requirements.md`, `docs/architecture/database.md`, `cms.md`, `business-flows.md`, `decisions/ADR-001-activation.md`.


## Potwierdzona reguła aktywacji — ACT-01 (2026-11-05)

**CONFIRMED:** opłacenie zakupu wydaje karnet o statusie `pending_activation`, ale **nie uruchamia** jego okresu ważności. Przy **pierwszej wizycie posiadacza** pracownik lub administrator weryfikuje właściwy karnet i uruchamia okres ważności; zapisujemy `activated_at` (UTC), `activated_by_user_id`, `starts_on` (lokalny dzień wizyty według `Europe/Warsaw`) i wyliczone `expires_on` (włącznie) według zakupionego snapshotu planu. Nie można aktywować nieopłaconego karnetu. Powtórzenie aktywacji niczego nie przelicza. Zapis aktywacji i audytu jest atomowy.

**Przedłużenia:** opłacone przedłużenie aktywnego karnetu o tym samym obsługiwanym typie ma przyszły okres od `previous.expires_on + 1 day` — **bez ponownej wizyty aktywacyjnej**. Dla karnetu, który sam jeszcze nie został aktywowany, sposób kolejkowania następnych zakupów pozostaje `OPEN`; nie przypisujemy automatycznie dat bez znanej daty startu źródła.

**PDF po opłacie:** generujemy i wysyłamy także dla `pending_activation`. Zawiera imię i nazwisko posiadacza, dane planu i komunikat „Ważność rozpocznie się przy pierwszej wizycie w klubie”; **nie zawiera fikcyjnych dat**. Dla opłaconego przedłużenia ze znanym przyszłym okresem PDF pokazuje rzeczywiste daty. Nie wymagamy automatycznej drugiej wysyłki PDF po aktywacji; ewentualne późniejsze pobranie aktualnego PDF może uwzględniać ustalone daty. Błąd e-maila nie cofa zakupu ani nie tworzy nowego karnetu.

**Granica stanów:** `orders/payments: paid` ≠ `memberships: active`. Statusy UI obejmują `awaiting_payment`, `pending_activation`, `scheduled` (przedłużenie) i `active`, a także `expired`/`exhausted` w odpowiednim kontekście. Faktyczną aktywację i wejście do klubu zapisujemy jako **różne zdarzenia**; odliczenie wejścia z karnetu limitowanego wymaga osobnej świadomej akcji, nawet jeśli odbywa się podczas tej samej wizyty.
