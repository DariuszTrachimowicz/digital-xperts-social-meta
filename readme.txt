=== Digital Xperts Social Meta ===
Contributors: digitalxperts
Requires at least: 6.2
Tested up to: 7.2
Requires PHP: 7.4
Stable tag: 2.5.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tytuły i opisy SEO z podglądem Google, znaczniki Open Graph i karty X oraz opcjonalne pełne SEO dla każdej strony WordPress.

== Description ==

Digital Xperts Social Meta to jedna wtyczka dla wszystkich stron. Pilnuje, co wyszukiwarka i serwisy społecznościowe widzą o każdej podstronie: tytułu, opisu, obrazu i danych strukturalnych.

= Co zyskujesz =

* Panel „Social Meta” z listą wpisów i stron, wyszukiwarką, filtrami i licznikiem „Do uzupełnienia SEO”.
* Podgląd wyniku Google na telefonie i komputerze oraz kart Facebooka, LinkedIna i X, który zmienia się podczas pisania.
* Przyciski „Zapisz i przejdź do następnego”, „Pomiń” i „Wróć do poprzedniego”, żeby szybko przejść przez wszystkie treści.
* Podgląd wszystkich danych strukturalnych JSON-LD odczytanych z publicznej strony.
* Sekcję „Podgląd SEO” w edycji wpisu i strony.
* Pole „Fraza kluczowa”, które sprawdza tytuł, opis, adres i treść, także gdy fraza jest odmieniona.
* Podpowiedzi linków wewnętrznych: lista innych treści, których fraza kluczowa pada w pisanym tekście.
* Kontrolę czytelności po polsku: długość zdań i akapitów, śródtytuły, wskaźnik mglistości.
* Kontrolę stylu: pauzy, kontrasty „a nie”, słowa-wytrychy, rozbiegi i strona bierna.
* Przekierowania starych adresów na nowe i import danych z Yoast SEO.

= Jak działa na stronie =

* Dopisuje opis, Open Graph i karty X tylko wtedy, gdy ich brakuje. Nie dubluje znaczników motywu ani innych wtyczek.
* Tytuł i opis wpisany w panelu zastępują wartości z innych źródeł. Puste pole zostawia to, co strona miała wcześniej.
* Usuwa z tytułów, opisów i kart półpauzy i pauzy, także te, które WordPress wstawia między tytuł a nazwę witryny. Adresy stron i obrazów zostają bez zmian.
* Obraz kart to obraz wyróżniający wpisu albo obraz domyślny z ustawień.

= Moduł „Pełne SEO” =

Domyślnie wyłączony. Po włączeniu w ustawieniach przejmuje to, co zwykle robi wtyczka SEO:

* mapę witryny WordPressa z wybranymi typami treści i taksonomiami,
* noindex i nofollow dla stron, typów treści i taksonomii, canonical dla pojedynczych stron,
* dane firmy (LocalBusiness), WebSite, Service i Article, gdy strona ich nie ma,
* import danych z SEOPress z weryfikacją sum kontrolnych.

Moduł sam się wycofuje, gdy działa Yoast, Rank Math albo SEOPress.

= Aktualizacje =

Wtyczka aktualizuje się z wydań na GitHubie: https://github.com/DariuszTrachimowicz/digital-xperts-social-meta. Nowa wersja pojawia się na liście wtyczek jak każda inna aktualizacja.

= Dane techniczne =

* Ustawienia: opcje `dxsm_settings` (także `fb_app_id`), `dxsm_modules`, `dxsm_editor_settings` i `dxsm_redirects`.
* Pola SEO: `_dxsm_seo_title`, `_dxsm_seo_description` i `_dxsm_seo_keyphrase`.
* Moduł pełnego SEO zapisuje dane pod nazwami z pierwszego wdrożenia: `dxsm_rs2_seo_settings` i pola `_dxsm_rs2_*`.
* Funkcje strony (np. własny obraz kart) można podać przez filtr `dxsm_social_image`.

== Installation ==

1. Pobierz paczkę `digital-xperts-social-meta-X.Y.Z.zip` z wydań na GitHubie.
2. W WordPressie wybierz Wtyczki, Dodaj wtyczkę, Wyślij wtyczkę na serwer i włącz ją.
3. W menu „Social Meta”, „Ustawienia” wpisz nazwę strony, opis i obraz domyślny.
4. Wyczyść pamięć podręczną strony i sprawdź kod HTML publicznej strony.

Strona ze starszym wariantem Social Meta (d-x.pl, RS2, Centrum Psychologii) przejmuje przy pierwszym uruchomieniu jego ustawienia i pola SEO. Stare dane zostają w bazie.

== Frequently Asked Questions ==

= Czy wtyczka gryzie się z Yoast albo Rank Math? =

Nie. Znaczniki, które już są na stronie, zostają, a moduł pełnego SEO wyłącza się sam przy innej wtyczce SEO.

= Dlaczego panel pokazuje opis, którego nie wpisałem? =

Panel pokazuje opis, który strona ma dziś w kodzie, na przykład z motywu albo innej wtyczki. Wpisz własny, żeby go zastąpić.

= Czy wtyczka wysyła dane poza stronę? =

Nie wysyła treści ani danych osobowych. Raz na kilka godzin sprawdza na GitHubie numer najnowszego wydania.

== Privacy ==

Wtyczka korzysta z danych zapisanych w WordPressie. Do sprawdzenia aktualizacji łączy się z github.com bez przesyłania danych strony. Panel czyta publiczną wersję strony z tego samego serwera.

== Changelog ==

= 2.5.0 =

* Przekierowania: w ustawieniach Social Meta można dodać stary adres i nowy adres (301 na stałe albo 302 tymczasowo). Lista przyjmuje też zwykłe przekierowania z Yoast SEO Premium. Reguły tworzące pętlę są odrzucane, a krótkotrwałe ciasteczko `dxsm_redirected` zatrzymuje pętle, których nie widać na liście.
* Import z Yoast SEO: tytuły SEO, opisy meta i frazy kluczowe przechodzą do pustych pól Social Meta. Zmienne Yoasta, na przykład nazwa witryny, są zamieniane na tekst. Dane Yoasta zostają nietknięte.
* Kontrola stylu: progi kontrastów, słów-wytrychów i strony biernej rosną z długością tekstu.

= 2.4.0 =

* Nowa lista „Styl i prosty język” w sekcji „Podgląd SEO” i w panelu Social Meta. Liczy pauzy i półpauzy, kontrasty „a nie” oraz „nie tylko, ale”, słowa-wytrychy („kluczowy”, „istotny”, „dedykowany” i podobne), rozbiegi typu „warto zauważyć” i stronę bierną z „został”.
* Słowa-wytrychy są rozpoznawane w odmianie, a lista pokazuje, które padają najczęściej.
* Kontrola nie zmienia nic na stronie publicznej.

= 2.3.0 =

* Nowa lista „Czytelność” w sekcji „Podgląd SEO” i w panelu Social Meta. Sprawdza udział zdań dłuższych niż 20 słów, akapity dłuższe niż 150 słów, fragmenty bez śródtytułu dłuższe niż 300 słów, wskaźnik mglistości (FOG) liczony dla języka polskiego oraz zdania zaczynające się trzy razy z rzędu tym samym słowem.
* W edytorze blokowym lista odświeża się podczas pisania.
* Kontrola nie wymaga frazy kluczowej i nie zmienia nic na stronie publicznej.

= 2.2.0 =

* Nowa lista „Podpowiedzi linków wewnętrznych” pod polem frazy kluczowej. Pokazuje do pięciu innych treści, których fraza kluczowa pada w edytowanym tekście, razem ze zdaniem, w którym można wstawić link, i przyciskiem kopiowania adresu.
* Podpowiedzi rozumieją polską odmianę i pomijają treści, do których tekst już linkuje.
* Podpowiadane są tylko treści z wpisaną frazą kluczową, więc lista rośnie razem z liczbą uzupełnionych fraz.

= 2.1.1 =

Poprawki po sprawdzeniu frazy kluczowej na 53 wpisach.

* Pierwszy akapit: fraza liczy się także wtedy, gdy jej słowa stoją w dwóch sąsiednich zdaniach.
* Treść: gdy słowa frazy padają, ale nigdy w jednym zdaniu, lista mówi o tym wprost i nie pokazuje już „Fraza nie pada w treści”.
* Adres: wtyczka rozpoznaje odmianę także w adresie bez polskich znaków, na przykład „agentów” i „agenci” albo „opis” i „opisac”.
* Słowa z sąsiednich elementów (pytanie i odpowiedź w FAQ, elementy listy) nie sklejają się już w jedno.

= 2.1.0 =

* Nowe pole „Fraza kluczowa” w sekcji „Podgląd SEO” i w panelu Social Meta. Lista kontroli pokazuje, czy fraza jest w tytule SEO, opisie meta, końcówce adresu, pierwszym akapicie i śródtytule oraz ile razy pada w treści.
* Fraza jest rozpoznawana w polskiej odmianie: „usługa księgowa” znajdzie też „usług księgowych” i „usłudze księgowej”. W teście na słowach z wpisów blogowych wtyczka połączyła z formą podstawową 98 % odmienionych rzeczowników i 89 % przymiotników.
* W edytorze blokowym lista odświeża się podczas pisania, bez zapisywania wpisu.
* Stopniowanie („tani” i „tańszy”) i formy nieregularne („człowiek” i „ludzie”) nie są jeszcze łączone.

= 2.0.5 =

* Okno „Zobacz szczegóły” nie pokazuje już ostrzeżenia o braku testów z WordPressem 7.1.5: zgodność podana jako 7.2 (sprawdzone na 7.1.5 i 7.2 RC2).

= 2.0.4 =

* Okno „Zobacz szczegóły” działa także przy wtyczkach, które przechwytują informacje o wszystkich wtyczkach (np. CMS Portal z motywów CMSSuperHeroes).

= 2.0.3 =

* Pole „Facebook App ID” w ustawieniach: po wpisaniu numeru strona wysyła znacznik fb:app_id, a debuger Facebooka przestaje pokazywać ostrzeżenie.

= 2.0.2 =

* Nowa wersja pojawia się na liście aktualizacji także wtedy, gdy WordPress nie odświeża sprawdzania wtyczek (np. przy wtyczce blokującej zapytania do wordpress.org).

= 2.0.1 =

* Jeden link „Zobacz szczegóły” na liście wtyczek. WordPress dodaje własny, gdy zna wydanie z GitHuba.

= 2.0.0 =

* Jedna wtyczka dla wszystkich stron zamiast osobnych wariantów d-x.pl, RS2 i Centrum Psychologii.
* Aktualizacje z wydań na GitHubie.
* Moduł „Pełne SEO” z wariantu RS2, włączany w ustawieniach.
* Przejęcie ustawień i pól SEO ze starszych wariantów przy pierwszym uruchomieniu.
* Karty i opisy także na stronach kategorii, tagów i archiwów.
* Filtr `dxsm_social_image` dla własnego obrazu kart.
