# ActiveFit — repozytorium projektu

Aplikacja: Laravel + Inertia.js + Vue 3 + TypeScript + Tailwind CSS + MySQL.  
Repozytorium: GitHub. Testy backendu: Pest. Płatności: Przelewy24.

> **Stan paczki:** dokumentacja AI SDLC i referencyjne widoki HTML. **Nie zawiera** jeszcze wygenerowanej aplikacji Laravel, migracji ani działających testów. To materiały do umieszczenia w katalogu głównym istniejącego lub nowego repozytorium.

## Gdzie zacząć

1. `AGENTS.md` — zasady pracy agentów AI.
2. `docs/product/requirements.md` — zakres i zatwierdzone reguły.
3. `docs/architecture/decisions/ADR-001-activation.md` — zatwierdzona aktywacja podczas pierwszej wizyty.
4. `docs/architecture/overview.md` — architektura aplikacji.
5. `docs/architecture/database.md`, `docs/architecture/cms.md`, `docs/architecture/security.md`, `docs/architecture/frontend.md`, `docs/architecture/business-flows.md` — szczegóły projektowe.
6. `docs/development/testing-strategy.md` — strategia testów.
7. `docs/development/implementation-plan.md` — zadania TASK-001–040.
8. `docs/architecture/repository-setup.md` — instrukcje konfiguracji projektu.
9. `design/reference-html/` — otrzymane, niezmodyfikowane widoki UX/UI; **referencja**, nie kod produkcyjny.

## Kluczowe ustalenia

- Opłacenie karnetu **nie rozpoczyna jego ważności**. Pierwszą aktywację wykonuje pracownik lub administrator przy pierwszej wizycie posiadacza.
- PDF jest wysyłany po skutecznym potwierdzeniu płatności również dla karnetu oczekującego na aktywację. Nie pokazuje dat, których jeszcze nie znamy.
- Przedłużenie już aktywnego karnetu (w zatwierdzonym scenariuszu) zaczyna się po końcu bieżącego okresu.
- Jedno zamówienie = jeden karnet; kupujący i posiadacz mogą być różnymi osobami; zakupy gościnne nie są łączone z późniejszym kontem.
- Kwoty w bazie zapisujemy w groszach jako liczby całkowite.
- Otwarte decyzje biznesowe pozostają oznaczone `OPEN` — agent nie powinien ich rozstrzygać samodzielnie.

## Pierwszy etap implementacji

Rozpocznij od `TASK-001` w `docs/development/implementation-plan.md`. Wygeneruj projekt Laravel z oficjalnym starterem Vue/Inertia i skonfiguruj MySQL; **nie** nadpisuj `AGENTS.md`, `docs/` ani `design/`. Dopiero po instalacji aplikacji dostępne będą polecenia `composer`, `artisan` i `npm` odnoszące się do właściwego kodu.

## Wersjonowanie dokumentów

Ostatni przegląd paczki: 2026-11-05. Szczegóły rozbieżności i decyzji znaleźć można w `docs/architecture/decisions/` oraz w polach `OPEN` dokumentacji. Przed wykonaniem zadania należy zweryfikować wymogi jego modułu i przygotować testy.
