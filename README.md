# Digital Xperts Social Meta

Wtyczka WordPress, która pilnuje tytułów, opisów, kart Open Graph i X oraz danych strukturalnych na każdej podstronie. Jedna wersja dla wszystkich stron, aktualizowana z wydań w tym repozytorium.

## Co robi

- Panel „Social Meta”: lista treści, filtry, podgląd Google i kart społecznościowych, zapis tytułu i opisu SEO.
- Uzupełnia tylko brakujące znaczniki, więc działa obok motywu i innych wtyczek.
- Usuwa półpauzy i pauzy z tytułów, opisów i kart. Adresy zostawia bez zmian.
- Moduł „Pełne SEO” (domyślnie wyłączony): mapa witryny, robots, canonical, noindex, dane firmy i import z SEOPress.

## Instalacja

1. Pobierz `digital-xperts-social-meta-X.Y.Z.zip` z [najnowszego wydania](https://github.com/DariuszTrachimowicz/digital-xperts-social-meta/releases/latest).
2. W WordPressie: Wtyczki, Dodaj wtyczkę, Wyślij wtyczkę na serwer.
3. Włącz wtyczkę i uzupełnij menu Social Meta, Ustawienia.

Kolejne wersje pojawiają się w WordPressie jako zwykła aktualizacja wtyczki.

## Wymagania

WordPress 6.2 lub nowszy, PHP 7.4 lub nowszy. Czyszczenie tytułów wypisanych przez inne wtyczki działa od WordPressa 6.7.

## Rozwój

- Testy: `tests/compose.yml` (WordPress w Dockerze), potem `wp eval-file /tests/integration.php` i `/tests/unified.php`.
- Paczka: `python build.py`, wydanie: `gh release create vX.Y.Z dist/digital-xperts-social-meta-X.Y.Z.zip`.

Licencja GPL-2.0-or-later. Autor: Dariusz Trachimowicz, [d-x.pl](https://d-x.pl/).
