# ActiveFit — wymagania produktu

> **Wersja:** 1.0-draft  
> **Data:** 2026-10-26  
> **Etap AI SDLC:** Requirements  
> **Źródło:** ustalenia projektu v0.8 oraz decyzje z rozmów  
> **Status:** DRAFT — wymagania zatwierdzone kierunkowo, kwestie otwarte wskazane osobno

## 1. Cel i zakres

ActiveFit to strona publiczna klubu fitness z panelem CMS, sprzedażą karnetów online (również bez rejestracji), sprzedażą na recepcji oraz panelami klienta, pracownika i administratora. Pracownik sprawdza ważność karnetu po danych posiadacza, przede wszystkim numerze telefonu. System **nie** wymaga skanowania karnetów, bramek ani czytników.

### 1.1 Technologia i proces

- **Zatwierdzony stack:** Laravel, Inertia.js, Vue 3.
- **Proces:** AI SDLC: discovery → requirements → architecture → implementation → tests/review → delivery.
- **Do potwierdzenia technicznie:** TypeScript, Tailwind CSS, MySQL, Pest, konfiguracja CI/CD. Ich użycie było rekomendowane, nie stanowi zatwierdzonego wymagania biznesowego.
- **Interfejs:** podstawowe UX/UI już istnieje. Dokument określa zachowanie widoków, nie narzuca szczegółowego layoutu.

### 1.2 Słownik

| Termin | Znaczenie |
|---|---|
| Kupujący | Osoba zamawiająca i opłacająca karnet. |
| Posiadacz | Osoba uprawniona do korzystania z karnetu; może być inna niż kupujący. |
| Konto | Dane uwierzytelniania w aplikacji; kupujący i posiadacz nie muszą posiadać konta. |
| Plan karnetu | Aktualnie oferowany produkt: cena, nazwa, typ i warunki. |
| Karnet | Konkretne uprawnienie przypisane do posiadacza z utrwalonymi warunkami zakupu. |
| Zamówienie | Jeden zakup obejmujący dokładnie jeden karnet w MVP. |
| Wejście | Ręcznie odnotowane skorzystanie z karnetu z limitem wejść. |

**Statusy wymagań:** `CONFIRMED` — potwierdzone przez użytkownika; `PROPOSED` — rekomendacja wymagająca potwierdzenia; `OPEN` — decyzja jeszcze niepodjęta. **Priorytet:** `MUST` — przewidziane do MVP; `SHOULD` — warte realizacji po podstawowym zakresie.

## 2. Aktorzy i granice dostępu

| ID | Rola | Uprawnienia / cel | Status |
|---|---|---|---|
| ROLE-01 | Odwiedzający | Przeglądanie strony i zakup bez konta. | CONFIRMED |
| ROLE-02 | Klient z kontem | Zakup i dostęp do własnych karnetów oraz historii. | CONFIRMED |
| ROLE-03 | Pracownik | Wyszukiwanie posiadaczy, sprawdzenie ważności, obsługa sprzedaży recepcyjnej i wejść. | CONFIRMED |
| ROLE-04 | Administrator | Nadzór nad karnetami, klientami, sprzedażą, pracownikami i CMS. | CONFIRMED |

Dostęp do danych osobowych i operacji musi być kontrolowany po stronie serwera; ukrywanie przycisków w Vue nie zastępuje autoryzacji.

## 3. Wymagania funkcjonalne

### 3.1 WEB — publiczna strona internetowa

| ID | Wymaganie | Priorytet | Status |
|---|---|---|---|
| WEB-001 | Strona główna prezentuje ofertę siłowni i odsyła do cennika/zakupu. | MUST | CONFIRMED |
| WEB-002 | Publicznie dostępne strony: oferta, cennik, grafik, aktualności, galeria, kontakt i regulamin. | MUST | CONFIRMED |
| WEB-003 | Oferta prezentuje edytowalne kafelki usług, np. trening siłowy. | MUST | CONFIRMED |
| WEB-004 | Strona prezentuje karuzelę slajdów zawierających zdjęcia i opisy. | MUST | CONFIRMED |
| WEB-005 | Cennik pokazuje tylko plany udostępnione do sprzedaży. | MUST | PROPOSED |
| WEB-006 | Strona działa czytelnie na urządzeniach mobilnych; publiczne strony wspierają indeksowanie. | MUST | PROPOSED |

**AC-WEB-01** — Given administrator opublikuje kafelek lub slajd, When odwiedzający otworzy odpowiedni widok, Then widzi opublikowany element we wskazanej kolejności, bez elementów ukrytych.

### 3.2 CMS — zarządzanie treściami

| ID | Wymaganie | Priorytet | Status |
|---|---|---|---|
| CMS-001 | Administrator edytuje i publikuje regulamin. | MUST | CONFIRMED |
| CMS-002 | Administrator dodaje, edytuje, ukrywa, sortuje i usuwa zdjęcia galerii. | MUST | CONFIRMED |
| CMS-003 | Administrator tworzy, edytuje, publikuje i ukrywa aktualności (tytuł, treść, zdjęcie, data publikacji). | MUST | CONFIRMED |
| CMS-004 | Administrator edytuje cennik: ceny i opisy oferowanych planów. | MUST | CONFIRMED |
| CMS-005 | Administrator edytuje dane kontaktowe oraz godziny otwarcia. | MUST | CONFIRMED |
| CMS-006 | Administrator zarządza kafelkami oferty: tytuł, opis, zdjęcie, kolejność, widoczność. | MUST | CONFIRMED |
| CMS-007 | Administrator zarządza karuzelą: zdjęcie, opis, kolejność, widoczność. | MUST | CONFIRMED |
| CMS-008 | Administrator edytuje treści podstron i grafik zajęć według ustalonych szablonów. | MUST | CONFIRMED |
| CMS-009 | Nie implementujemy dowolnego kreatora stron (page builder). | MUST | CONFIRMED |
| CMS-010 | Zmiana aktualnej ceny nie zmienia danych historycznych karnetów ani zamówień. | MUST | PROPOSED |
| CMS-011 | Regulamin może być wersjonowany, a zaakceptowana wersja powiązana z zakupem. | SHOULD | OPEN |

**AC-CMS-01** — Given administrator zapisze treść jako ukrytą, When użytkownik odwiedzi stronę, Then element nie jest publicznie widoczny.  
**AC-CMS-02** — Given plan został kupiony za 155 zł, When administrator zmieni cennik na 169 zł, Then historyczna cena zakupu nadal wynosi 155 zł.  
**AC-CMS-03** — Given przesłano niepoprawny lub niedozwolony plik graficzny, When system sprawdzi plik, Then odrzuca go z komunikatem i nie publikuje.

### 3.3 ACC — konta klienta i dane osób

| ID | Wymaganie | Priorytet | Status |
|---|---|---|---|
| ACC-001 | Kupujący może kupić karnet online bez rejestracji. | MUST | CONFIRMED (D-01) |
| ACC-002 | Rejestracja i logowanie pozostają dostępne. | MUST | CONFIRMED |
| ACC-003 | Posiadacz może korzystać z karnetu bez konta. | MUST | CONFIRMED (D-13) |
| ACC-004 | Formularz zbiera imię, nazwisko, numer telefonu oraz e-mail potrzebny do zakupu i dostarczenia dokumentu. | MUST | CONFIRMED (D-13) |
| ACC-005 | Dane kupującego i posiadacza są rozdzielone, jeśli zakup jest dla innej osoby. | MUST | CONFIRMED |
| ACC-006 | Klient z kontem może przeglądać własne karnety i historię zakupów. | MUST | CONFIRMED |
| ACC-007 | Numer telefonu i adres e-mail są unikalne w obrębie zarejestrowanych kont (`users`); dane z zakupów gościnnych nie są objęte tym ograniczeniem. | MUST | CONFIRMED (OD-05) |
| ACC-008 | Nie łączymy zakupów gościnnych z kontami zakładanymi później — automatycznie ani ręcznie. | MUST | CONFIRMED (OD-05) |
| ACC-009 | Rejestracja konta jest możliwa również z telefonem i e-mailem używanymi wcześniej przy zakupach gościnnych, o ile nie są już zajęte przez inne konto. | MUST | CONFIRMED (OD-05) |
| ACC-010 | Numer telefonu należy normalizować przed weryfikacją unikalności i wyszukiwaniem. | MUST | CONFIRMED (OD-05) |

| ACC-011 | Każda rola (`customer`, `employee`, `admin`) może edytować własne imię, nazwisko, e-mail i telefon oraz zmienić hasło. | MUST | CONFIRMED |
| ACC-012 | Zmiana e-maila wymaga weryfikacji nowego adresu, hasło zmieniamy po podaniu obecnego; edycja profilu nie pozwala zmienić roli/statusu i nie modyfikuje snapshotów zamówień. | MUST | CONFIRMED |
| ACC-013 | Pierwszy administrator jest zakładany idempotentnym seederem z sekretami poza kodem; seeder nie przejmuje konta po kolizji adresu e-mail. | MUST | CONFIRMED |

**AC-ACC-06** — Given zalogowany pracownik aktualizuje swoje dane, When zapisuje formularz, Then zmienia tylko własny profil, nie rolę i nie dane historycznych zamówień.  
**AC-ACC-07** — Given seeder administratora jest ponownie uruchomiony, When konto już istnieje, Then nie powstaje duplikat i hasło nie jest nadpisywane.  
**AC-ACC-01** — Given osoba nie ma konta, When wybiera zakup bez rejestracji i poprawnie podaje wymagane dane, Then może przejść do płatności bez ustawiania hasła.  
**AC-ACC-02** — Given ten sam numer występuje przy koncie i zamówieniu gościnnym, When pracownik wyszuka numer, Then widzi odrębne wyniki z danymi posiadacza bez łączenia historii.  
**AC-ACC-03** — Given istnieje zakup gościnny na adres X i telefon Y, When użytkownik rejestruje nowe konto z X i Y (nieużywanymi w `users`), Then rejestracja jest możliwa, ale zakup gościnny nie pojawia się na nowym koncie.  
**AC-ACC-04** — Given w `users` istnieje już konto z adresem X lub numerem Y, When ktoś rejestruje kolejne konto z tymi danymi, Then walidacja odrzuca duplikat.  
**AC-ACC-05** — Given dwie osoby kupują karnety bez rejestracji na ten sam telefon lub e-mail, When zapisują odrębne zakupy, Then system dopuszcza obie transakcje i zachowuje odrębne dane kupującego i posiadacza.

### 3.4 PLN — rodzaje karnetów i oferta

| ID | Wymaganie | Priorytet | Status |
|---|---|---|---|
| PLN-001 | Administrator zarządza planami: nazwa, opis, cena, zasady ważności i dostępność. | MUST | CONFIRMED |
| PLN-002 | System obsługuje karnety czasowe. | MUST | CONFIRMED |
| PLN-003 | System obsługuje karnety z limitem wejść, w tym 4 i 8 wejść. | MUST | CONFIRMED (D-06) |
| PLN-004 | Karnet miesięczny: data końca jest włącznie; gdy ten sam numer dnia istnieje w kolejnym miesiącu — dzień poprzedni; gdy nie istnieje — ostatni dzień kolejnego miesiąca. Przykłady: 28.09→27.10, 30.04→29.05, 31.01.2027→28.02.2027, 31.01.2028→29.02.2028, 28.02.2027→27.03.2027. | MUST | CONFIRMED (OD-01) |
| PLN-005 | Przy zakupie utrwalamy snapshot nazwy, ceny w groszach, rodzaju, limitu wejść i pozostałych warunków planu. Zmiany cennika nie zmieniają historii. | MUST | CONFIRMED |

**AC-PLN-03** — Given start 31.01.2027, When naliczamy miesiąc, Then data końca to 28.02.2027 (włącznie); dla 30.04 → 29.05.  
**AC-PLN-01** — Given administrator ukrył plan, When klient otworzy stronę zakupu, Then plan nie może zostać zakupiony przez publiczny formularz.  
**AC-PLN-02** — Given karnet ma limit 4 wejść, When pracownik prawidłowo odnotuje jedno wejście, Then pozostała liczba wejść zmniejsza się z 4 do 3.

### 3.5 ORD — zamówienia i zakup dla innych osób

| ID | Wymaganie | Priorytet | Status |
|---|---|---|---|
| ORD-001 | Formularz umożliwia wybór „Dla siebie” albo „Dla innej osoby”. | MUST | CONFIRMED |
| ORD-002 | Kupujący może zamawiać karnety dla partnera, członka rodziny lub innej osoby. | MUST | CONFIRMED |
| ORD-003 | Jedno zamówienie obejmuje dokładnie jeden karnet dla jednego posiadacza. | MUST | CONFIRMED (D-14) |
| ORD-004 | Jedna osoba kupująca może nabywać wiele osobnych karnetów dla różnych posiadaczy. | MUST | CONFIRMED (D-04) |
| ORD-005 | Zamówienie przechowuje oddzielnie identyfikację kupującego i posiadacza, plan, snapshot warunków, kwotę w groszach, kanał sprzedaży, metodę płatności i status. | MUST | CONFIRMED |
| ORD-006 | Po potwierdzeniu płatności system przypisuje karnet właściwemu posiadaczowi. | MUST | CONFIRMED |
| ORD-007 | Zasady zakupów dla niepełnoletnich i weryfikacji posiadacza wymagają osobnej decyzji. | MUST | OPEN (D-15) |

**AC-ORD-01** — Given kupujący zaznaczy „Dla innej osoby”, When uzupełni dane posiadacza, Then zamówienie zapisuje rozdzielnie kupującego i posiadacza.  
**AC-ORD-02** — Given kupujący kupuje dwa karnety dla rodziny, When finalizuje transakcje, Then system tworzy dwa osobne zamówienia, każde z jednym karnetem.  
**AC-ORD-03** — Given płatność nie została potwierdzona, When klient wraca na stronę po płatności, Then system nie nadaje aktywnego karnetu wyłącznie na podstawie powrotu na stronę.

### 3.6 PAY — płatności internetowe i stacjonarne

| ID | Wymaganie | Priorytet | Status |
|---|---|---|---|
| PAY-001 | Operator płatności internetowych: Przelewy24. | MUST | CONFIRMED (D-10) |
| PAY-002 | System rejestruje zamówienie przed przekierowaniem do płatności. | MUST | PROPOSED |
| PAY-003 | Po zweryfikowanym potwierdzeniu płatności powstaje opłacony karnet `pending_activation`; okres zaczyna się przy pierwszej wizycie posiadacza (ACT-01). | MUST | CONFIRMED |
| PAY-004 | Obsługa powtórzonych powiadomień Przelewy24 jest idempotentna. | MUST | MUST — techniczne |
| PAY-005 | Na recepcji dopuszczamy płatność gotówką lub kartą. | MUST | CONFIRMED (D-08) |
| PAY-006 | Historia zamówień identyfikuje kanał sprzedaży (`online`/`reception`) i metodę płatności (`przelewy24`/`cash`/`card`). | MUST | CONFIRMED |
| PAY-007 | Nieudane, przerwane i niepewne płatności mają czytelne komunikaty; brak potwierdzenia nie wydaje karnetu, a retry nie dubluje wydania. | MUST | CONFIRMED |
| PAY-008 | Polityka zwrotów i powiązane działania w systemie. | MUST | OPEN (D-10, D-17) |

**AC-PAY-01** — Given Przelewy24 wyśle dwa identyczne prawidłowe potwierdzenia, When oba zostaną obsłużone, Then powstaje dokładnie jeden opłacony karnet oczekujący na pierwszą aktywację.  
**AC-PAY-02** — Given potwierdzenie jest niepoprawne lub niezgodne z kwotą/zamówieniem, When system je przetworzy, Then nie wydaje opłaconego karnetu i rejestruje błąd do diagnostyki.  
**AC-PAY-03** — Given klient zapłaci na recepcji, When pracownik potwierdzi wybraną formę płatności i zapisze operację, Then system rejestruje sprzedaż z informacją o operatorze czynności.

### 3.7 MEM — cykl życia karnetu i wejścia

| ID | Wymaganie | Priorytet | Status |
|---|---|---|---|
| MEM-001 | Pierwszy okres nowego opłaconego karnetu rozpoczyna się przy pierwszej wizycie posiadacza, po aktywacji przez pracownika/admina; opłata nie uruchamia ważności. | MUST | CONFIRMED (ACT-01) |
| MEM-010 | Aktywacja rejestruje czas UTC, aktora, lokalny dzień rozpoczęcia, koniec ważności i zdarzenie audytowe; ponowienie nie zmienia dat. | MUST | CONFIRMED (ACT-01) |
| MEM-002 | Przedłużenie karnetu czasowego dla danego posiadacza rozpoczyna kolejny okres po zakończeniu aktualnego, a nie od daty przedterminowego zakupu. | MUST | CONFIRMED (D-05, OD-02) |
| MEM-003 | Karnet zapisuje posiadacza, rodzaj i warunki zakupu; `starts_on`/`expires_on` mogą być NULL do pierwszej aktywacji, później zawierają rzeczywisty okres. | MUST | CONFIRMED (ACT-01) |
| MEM-004 | Pracownik może ręcznie odnotować wykorzystanie wejścia w karnecie limitowanym. | MUST | CONFIRMED (D-06) |
| MEM-005 | System nie pozwala wykorzystać więcej wejść niż przyznano. | MUST | MUST — techniczne |
| MEM-006 | Historia karnetów, wejść i krytycznych operacji jest dostępna również po wygaśnięciu; operacje mają datę i identyfikację wykonawcy. | MUST | CONFIRMED |
| MEM-007 | Korekty i anulowanie karnetu przez pracownika/administratora wymagają polityki uprawnień. | MUST | OPEN (D-07, D-17) |
| MEM-008 | Przy przedłużeniu karnetu wejściowego niewykorzystane wejścia przechodzą na nowy limit i są do niego dodawane. | MUST | CONFIRMED (OD-02) |
| MEM-009 | Zasady łączenia różnych typów karnetów (np. czasowy z wejściowym) i niezależnych pakietów jednej osoby wymagają ustalenia. | MUST | OPEN (OD-02a) |

**AC-MEM-01** — Given posiadacz ma aktywny karnet, When kupuje kolejny objęty regułą przedłużenia, Then nowy karnet nie rozpoczyna się przed końcem bieżącego. Dla karnetu czasowego kolejny okres nie biegnie od dnia wcześniejszego zakupu; nie zachodzi nakładanie się dni ważności.  
**AC-MEM-04** — Given karnet wejściowy ma 3 niewykorzystane wejścia, When posiadacz kupuje przedłużenie na 8 wejść, Then nowy dostępny limit wynosi 11 wejść, bez utraty trzech poprzednich.  
**AC-MEM-05** — Given karnet miesięczny kończy się 30 listopada i klient kupuje przedłużenie 20 listopada, When płatność zostaje potwierdzona, Then nowy okres nie rozpoczyna się 20 listopada, lecz po zakończeniu dotychczasowego.  
**AC-MEM-02** — Given w karnecie pozostało 0 wejść, When pracownik próbuje odnotować kolejne, Then system odrzuca operację i nie tworzy ujemnego salda.  
**AC-MEM-06** — Given pracownik zużywa wejście, When zapis się powiedzie, Then system zapisuje `used_at` (UTC) oraz `recorded_by_user_id` i pokazuje historię także po wygaśnięciu.  
**AC-MEM-03** — Given dwóch pracowników jednocześnie próbuje wykorzystać ostatnie wejście, When operacje dotrą do serwera, Then co najwyżej jedna kończy się sukcesem.

## Potwierdzona reguła aktywacji — ACT-01 (2026-11-05)

**CONFIRMED:** opłacenie zakupu wydaje karnet o statusie `pending_activation`, ale **nie uruchamia** jego okresu ważności. Przy **pierwszej wizycie posiadacza** pracownik lub administrator weryfikuje właściwy karnet i uruchamia okres ważności; zapisujemy `activated_at` (UTC), `activated_by_user_id`, `starts_on` (lokalny dzień wizyty według `Europe/Warsaw`) i wyliczone `expires_on` (włącznie) według zakupionego snapshotu planu. Nie można aktywować nieopłaconego karnetu. Powtórzenie aktywacji niczego nie przelicza. Zapis aktywacji i audytu jest atomowy.

**Przedłużenia:** opłacone przedłużenie aktywnego karnetu o tym samym obsługiwanym typie ma przyszły okres od `previous.expires_on + 1 day` — **bez ponownej wizyty aktywacyjnej**. Dla karnetu, który sam jeszcze nie został aktywowany, sposób kolejkowania następnych zakupów pozostaje `OPEN`; nie przypisujemy automatycznie dat bez znanej daty startu źródła.

**PDF po opłacie:** generujemy i wysyłamy także dla `pending_activation`. Zawiera imię i nazwisko posiadacza, dane planu i komunikat „Ważność rozpocznie się przy pierwszej wizycie w klubie”; **nie zawiera fikcyjnych dat**. Dla opłaconego przedłużenia ze znanym przyszłym okresem PDF pokazuje rzeczywiste daty. Nie wymagamy automatycznej drugiej wysyłki PDF po aktywacji; ewentualne późniejsze pobranie aktualnego PDF może uwzględniać ustalone daty. Błąd e-maila nie cofa zakupu ani nie tworzy nowego karnetu.

**Granica stanów:** `orders/payments: paid` ≠ `memberships: active`. Statusy UI obejmują `awaiting_payment`, `pending_activation`, `scheduled` (przedłużenie) i `active`, a także `expired`/`exhausted` w odpowiednim kontekście. Faktyczną aktywację i wejście do klubu zapisujemy jako **różne zdarzenia**; odliczenie wejścia z karnetu limitowanego wymaga osobnej świadomej akcji, nawet jeśli odbywa się podczas tej samej wizyty.


### 3.8 PDF / NTF — wydanie karnetu i powiadomienia

| ID | Wymaganie | Priorytet | Status |
|---|---|---|---|
| PDF-001 | Kupujący bez konta otrzymuje karnet jako PDF na podany adres e-mail. | MUST | CONFIRMED (D-13) |
| PDF-002 | Karnet kupiony dla innej osoby jest dostarczany e-mailem pod wskazany adres. | MUST | CONFIRMED (D-15) |
| PDF-003 | PDF zawiera imię i nazwisko posiadacza, nazwę klubu i planu, limit wejść (jeśli dotyczy) i ustalone daty; dla `pending_activation` zamiast dat komunikat o aktywacji przy pierwszej wizycie. Bez telefonu i e-maila w treści PDF. | MUST | CONFIRMED (ACT-01) |
| NTF-001 | Po założeniu konta pracownik otrzymuje e-mail informacyjny z bezpieczną możliwością ustanowienia hasła. | MUST | CONFIRMED (D-12 / D-18) |
| NTF-002 | Dla każdego zakupionego karnetu (z kontem, bez konta, na rzecz innej osoby, przedłużenie, recepcja) PDF generujemy i zlecamy do wysyłki po skutecznym potwierdzeniu płatności, także gdy okres ważności zacznie się później. | MUST | CONFIRMED (2026-11-04) |
| NTF-003 | Błąd wysyłki PDF nie cofa płatności, nie generuje ponownego karnetu; zapisujemy próby i umożliwiamy retry oraz ponowną wysyłkę przez administratora. | MUST | CONFIRMED |
| NTF-004 | Integracja z Apple Wallet / Google Wallet pozostaje poza zatwierdzonym MVP. | SHOULD | OPEN (D-11) |

**AC-PDF-02** — Given opłacone przedłużenie rozpoczyna się za 10 dni, When płatność zostanie potwierdzona, Then PDF wysyłamy teraz z przyszłymi datami ważności.  
**AC-PDF-01** — Given zakup bez konta został poprawnie opłacony, When zweryfikowane potwierdzenie płatności wydaje karnet `pending_activation`, Then system wysyła PDF bez nieustalonych dat na wskazany adres.  
**AC-NTF-02** — Given e-mail z PDF nie został wysłany, When system ponawia dostarczenie, Then nie zmienia płatności i nie wydaje drugiego karnetu.  
**AC-NTF-01** — Given administrator tworzy konto pracownika, When konto powstaje, Then wysyłane jest powiadomienie z bezpiecznym linkiem do ustanowienia hasła; hasło nie jest przesyłane jawnym tekstem.

### 3.9 STAFF — panel pracownika i recepcja

| ID | Wymaganie | Priorytet | Status |
|---|---|---|---|
| STF-001 | Wyszukiwanie posiadaczy przede wszystkim po numerze telefonu. | MUST | CONFIRMED (D-09) |
| STF-002 | Wyniki po wspólnym numerze telefonu rozróżniają posiadaczy po imieniu i nazwisku oraz danych ich karnetów; nie łączymy tożsamości po telefonie. | MUST | CONFIRMED |
| STF-003 | Podgląd aktywności, końca ważności i pozostałych wejść karnetu. | MUST | CONFIRMED |
| STF-004 | Pracownik może przypisać karnet osobie bez konta i zarejestrować sprzedaż na recepcji. | MUST | CONFIRMED |
| STF-005 | Pracownik może odnotować wejście z limitu karnetu. | MUST | CONFIRMED |
| STF-006 | Pracownik nie może zarządzać innymi pracownikami ani konfiguracją globalną (weryfikacja na backendzie: 403). | MUST | CONFIRMED |

**AC-STF-01** — Given osoba poda numer telefonu, When pracownik wpisze go w wyszukiwarkę, Then może odczytać status i daty ważności właściwego posiadacza.  
**AC-STF-02** — Given osoba nie ma konta, When opłaci karnet na recepcji, Then pracownik może przypisać jej karnet bez tworzenia hasła.

### 3.10 ADM — panel administratora

| ID | Wymaganie | Priorytet | Status |
|---|---|---|---|
| ADM-001 | Dashboard: aktywne i wygasające karnety, klienci, sprzedaż i ostatnie zamówienia. | SHOULD | CONFIRMED |
| ADM-002 | Lista klientów/posiadaczy i kupujących z wyszukiwaniem oraz historią karnetów. | MUST | CONFIRMED kierunkowo |
| ADM-003 | Zarządzanie planami, cenami i dostępnością sprzedaży. | MUST | CONFIRMED |
| ADM-004 | Lista zamówień i płatności (online / recepcja) ze szczegółami. | MUST | CONFIRMED kierunkowo |
| ADM-005 | Tworzenie kont pracowników: imię, nazwisko, e-mail, telefon, rola. | MUST | CONFIRMED (D-18) |
| ADM-006 | Edycja danych pracowników i zawieszanie kont. | MUST | CONFIRMED (D-18) |
| ADM-007 | Zawieszone konto nie daje dostępu do panelu. | MUST | CONFIRMED |
| ADM-008 | Administrator zarządza rolami zgodnie z przyjętym modelem uprawnień. | MUST | CONFIRMED |
| ADM-009 | Pełny CMS zgodnie z sekcją 3.2. | MUST | CONFIRMED |
| ADM-010 | Audit Log: kto, kiedy, co zmienił w karnetach i konfiguracji sprzedaży; historia niemodyfikowalna z poziomu panelu pracownika. | MUST | CONFIRMED |
| ADM-011 | Eksporty i rozbudowane raporty sprzedaży. | SHOULD | OPEN (D-19) |

**AC-ADM-01** — Given administrator utworzy konto pracownika, When zapis się powiedzie, Then konto ma wskazane dane i rolę, a pracownik otrzymuje powiadomienie e-mail.  
**AC-ADM-02** — Given administrator zawiesi pracownika, When pracownik próbuje wykonać kolejne chronione żądanie, Then serwer odmawia dostępu, także gdy sesja została utworzona wcześniej.  
**AC-ADM-03** — Given pracownik podejmie próbę otwarcia funkcji dostępnej tylko dla administratora, When serwer sprawdzi autoryzację, Then operacja zostaje odrzucona.

## 4. Reguły biznesowe przekrojowe

| ID | Reguła | Status |
|---|---|---|
| BR-001 | Każdy karnet należy do jednego posiadacza, niezależnie od osoby płacącej. | CONFIRMED |
| BR-002 | Jedno zamówienie = jeden karnet; jedna osoba może realizować wiele zamówień. | CONFIRMED |
| BR-003 | Zakup i korzystanie z karnetu nie wymagają konta. | CONFIRMED |
| BR-004 | Wydanie opłaconego karnetu online wymaga weryfikacji Przelewy24; aktywacja okresu wymaga pierwszej wizyty i uprawnionego pracownika. | CONFIRMED (ACT-01) |
| BR-005 | Przedłużony karnet czasowy rozpoczyna kolejny okres po zakończeniu obecnego, niezależnie od daty wcześniejszego zakupu; nie traci się pozostałych dni. | CONFIRMED (OD-02) |
| BR-010 | Data końca włącznie: jeśli analogiczny numer dnia istnieje w następnym miesiącu, `expires_on = następny_miesiąc_ten_sam_dzień - 1 dzień`; jeśli nie istnieje, `expires_on = ostatni dzień następnego miesiąca`. Przykłady: 15.09→14.10, 30.04→29.05, 31.01.2027→28.02.2027, 28.02.2027→27.03.2027. Nie stosować automatycznego kotwiczenia „ostatniego dnia miesiąca”. | CONFIRMED (OD-01) |
| BR-011 | Niewykorzystane wejścia z przedłużanego pakietu sumują się z nowo kupionymi wejściami. | CONFIRMED (OD-02) |
| BR-006 | Opłacony karnet zachowuje warunki historyczne mimo zmiany oferty. | CONFIRMED |
| BR-007 | Telefon jest kryterium wyszukiwania, nie identyfikatorem jednoznacznym posiadacza karnetu. | CONFIRMED |
| BR-008 | Aktywowanie karnetu oraz zużycie wejścia są atomowe i odporne na ponowienie operacji. | MUST — techniczne |
| BR-009 | Nie ma automatycznej kontroli wejść ani wymogu skanowania dokumentów. | CONFIRMED |

## 5. Wymagania niefunkcjonalne

| ID | Wymaganie | Priorytet |
|---|---|---|
| NFR-001 | Walidacja wejścia i autoryzacja serwerowa każdego działania modyfikującego dane. | MUST |
| NFR-002 | Ochrona danych osobowych, minimalizacja dostępu, zabezpieczenie eksportów i załączników PDF. | MUST |
| NFR-003 | Weryfikacja podpisu/źródła i zgodności transakcji Przelewy24; idempotencja webhooków. | MUST |
| NFR-004 | Transakcje/locking dla jednoczesnego zużycia wejść i realizacji płatności. | MUST |
| NFR-005 | Responsywne widoki, stany puste, błędów, ładowania i potwierdzenia operacji. | MUST |
| NFR-006 | Publiczne strony z poprawnymi adresami, metadanymi i podstawową optymalizacją SEO. | MUST |
| NFR-007 | Audyt operacji administracyjnych i ręcznej sprzedaży; brak haseł w wiadomościach e-mail i logach. | MUST |
| NFR-008 | Testy automatyczne: zakup bez konta, zakup dla innej osoby, potwierdzenia płatności, terminy, równoczesne wejścia i autoryzacja. | MUST |
| NFR-009 | Zasady retencji danych, polityka prywatności, obowiązki informacyjne i treści zakupowe do weryfikacji prawnej przed wdrożeniem. | MUST |
| NFR-010 | Dokładne wartości SLO (np. czasy odpowiedzi, dostępność) zostaną zdefiniowane na etapie architektury. | OPEN |

## 6. Scenariusze end-to-end (do testów akceptacyjnych)

**E2E-01 — Zakup bez konta:** odwiedzający wybiera plan → opłaca Przelewy24 → potwierdzenie tworzy jeden karnet `pending_activation` → system wysyła PDF na e-mail → pracownik uruchamia okres przy pierwszej wizycie.

**E2E-02 — Zakup dla partnera:** kupujący zaznacza „Dla innej osoby” → podaje dane partnera → opłaca zamówienie → system rejestruje osobno kupującego i posiadacza → PDF wysyłany jest na wskazany adres e-mail.

**E2E-03 — Sprzedaż recepcyjna:** pracownik wyszukuje posiadacza po numerze telefonu lub wprowadza nowego → wybiera plan → rejestruje gotówkę/kartę → przypisuje karnet osobie bez wymogu konta.

**E2E-04 — Karnet z limitem:** pracownik odnajduje posiadacza → odnotowuje wejście → limit maleje o jeden → po wyczerpaniu wejść system nie umożliwia kolejnego odnotowania.

**E2E-05 — Przedłużenie:** posiadacz ma ważny karnet → zostaje kupiony kolejny → nowy zaczyna obowiązywać po zakończeniu bieżącego; szczegóły algorytmu dat zależą od `OD-01` i `OD-02`.

**E2E-06 — Zarządzanie pracownikiem:** administrator tworzy konto → pracownik dostaje e-mail umożliwiający ustanowienie hasła → administrator zawiesza konto → kolejne operacje pracownika są odrzucane.

## 7. Decyzje otwarte i blokery implementacyjne

| ID | Stan na 2026-11-04 | Wpływ |
|---|---|---|
| OD-01 | RESOLVED — reguła dat z BR-010 (bez kotwiczenia ostatniego dnia). | Testy dat 28/29/30/31 |
| OD-02 | PARTIAL — przedłużenie miesięcznego od końca poprzedniego; wejścia niewykorzystane doliczane. Zasady łączenia różnych typów i przenoszenia po wygaśnięciu nadal OPEN. | Reguły przejść |
| OD-03 | RESOLVED — potwierdzenie płatności wyznacza `paid_at` i wydanie karnetu; ACT-01 wyznacza start ważności przy pierwszej wizycie. | Płatności i aktywacja |
| OD-04 | OPEN — zwroty, anulowanie i korekty. | Administracja |
| OD-05 | RESOLVED — unikalność telefonu i e-maila w `users`, brak łączenia gości z kontami. | Tożsamość |
| OD-06 | RESOLVED — PDF obligatoryjny dla wszystkich sposobów zakupu, wysyłka po opłacie również przy przyszłym okresie. Wallet poza MVP. | Powiadomienia |
| OD-07 | OPEN — małoletni i weryfikacja odbiorcy prezentu. | Checkout |
| OD-08 | RESOLVED — podstawowe metryki dashboardu zatwierdzone. | Admin |
| OD-09 | OPEN — eksporty i zaawansowane filtry. | Raporty |
| OD-10 | RESOLVED — wersjonowany regulamin i wymagana akceptacja konkretnej wersji przy zakupie online. | CMS/Checkout |
| OD-11 | PARTIAL — pola osobowe PDF: wyłącznie imię i nazwisko; szczegóły retencji plików pozostają OPEN. | Prywatność |
| ACT-01 | **CONFIRMED (2026-11-05)** — start nowego karnetu przy pierwszej wizycie po ręcznej aktywacji przez staff/admin; aktywacja i płatność niezależne. Przedłużenie istniejącego aktywnego okresu zaczyna się po jego zakończeniu. | Model cyklu życia |

## 8. Poza zakresem MVP

- Bramki, czytniki QR, automatyczna kontrola wejść i natywna aplikacja mobilna.
- Rezerwacja zajęć, wielooddziałowość, rozbudowany CRM i program lojalnościowy.
- Automatycznie odnawiane subskrypcje i koszyk wielu karnetów w jednym zamówieniu.
- Apple Wallet / Google Wallet — **do rozważenia**, ale nie są zatwierdzonym wymogiem MVP.
- Rozbudowane raportowanie i fakturowanie jako odrębny system — wymagają osobnego zakresu.

## 9. Kryteria ukończenia (Definition of Done)

Funkcjonalność jest gotowa, kiedy: (1) spełnia powiązane wymagania i AC; (2) ma walidację i autoryzację po stronie serwera; (3) ma obsługę błędów oraz stanów pustych/ładowania; (4) ma testy krytycznej logiki biznesowej i przypadków współbieżności; (5) nie ujawnia niepotrzebnych danych; (6) jest użyteczna na telefonie; (7) przeszła code review i aktualizację dokumentacji; (8) nie opiera się na niezatwierdzonych decyzjach `OPEN`.

## 10. Macierz śledzenia decyzji

| Decyzja | Pokrycie w wymaganiach |
|---|---|
| D-01 | ACC-001, E2E-01 |
| D-03 | PLN-004, OD-01 |
| D-04 | ORD-004, MEM-008 |
| D-05 | MEM-002, OD-02 |
| D-06 | PLN-003, MEM-004, E2E-04 |
| D-08 | PAY-005, E2E-03 |
| D-09 | STF-001, ACC-007, OD-05 |
| D-10 | PAY-001–004, OD-04 |
| D-12 | CMS-001–009, NTF-001 |
| D-13 | ACC-001–004, PDF-001 |
| D-14 | ORD-003 |
| D-15 | PDF-002, OD-07 |
| D-18 | ADM-005–008, E2E-06 |

---

**Następny krok AI SDLC:** akceptacja niniejszego dokumentu, rozstrzygnięcie blokujących `OD-01`–`OD-07`, a następnie przygotowanie architektury, modelu danych i testów kontraktowych dla implementacji.

## Dodatek: zatwierdzenia CMS (2026-11-04)

- CMS-OD-01: zdjęcie należy tylko do jednej kategorii; brak współdzielonych mediów.
- CMS-OD-02: maksymalnie jeden przedział godzin dziennie; brak wyjątków świątecznych w MVP.
- CMS-OD-03: przy zakupie online obowiązkowa akceptacja konkretnej opublikowanej wersji regulaminu; zapis wersji i czasu akceptacji w zamówieniu.
- CMS-OD-04: sformatowane treści CMS w oczyszczonym HTML; bez wykonywalnych skryptów.
- CMS-OD-05: obraz może występować tylko w jednym miejscu; usunięcie usuwa rekord i plik (plik fizycznie po commit, retry błędów).
