=== Digital Xperts Social Meta ===
Contributors: digitalxperts
Requires at least: 6.2
Tested up to: 7.1.4
Requires PHP: 7.4
Stable tag: 2.0.1
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

* Ustawienia: opcje `dxsm_settings`, `dxsm_modules` i `dxsm_editor_settings`.
* Pola SEO: `_dxsm_seo_title` i `_dxsm_seo_description`.
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

= 2.0.1 =

* Jeden link „Zobacz szczegóły” na liście wtyczek. WordPress dodaje własny, gdy zna wydanie z GitHuba.

= 2.0.0 =

* Jedna wtyczka dla wszystkich stron zamiast osobnych wariantów d-x.pl, RS2 i Centrum Psychologii.
* Aktualizacje z wydań na GitHubie.
* Moduł „Pełne SEO” z wariantu RS2, włączany w ustawieniach.
* Przejęcie ustawień i pól SEO ze starszych wariantów przy pierwszym uruchomieniu.
* Karty i opisy także na stronach kategorii, tagów i archiwów.
* Filtr `dxsm_social_image` dla własnego obrazu kart.
