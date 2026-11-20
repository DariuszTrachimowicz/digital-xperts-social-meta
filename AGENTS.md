# Digital Xperts Social Meta: instrukcja dla agentów

Kanoniczne źródło wspólnej wtyczki Social Meta od 18 listopada 2026. Publiczne repozytorium GitHub, wydania = aktualizacje stron.

## Zasady

- Claude koduje, testuje i wdraża. Codex jest tylko sędzią (`codex exec -s read-only ... < /dev/null`).
- Kod musi działać na każdej domenie: żadnych treści, numerów grafik ani nazw typów treści jednej strony w rdzeniu. Funkcje jednej strony idą do jej dodatku (np. `d-x.pl/wordpress/digital-xperts-dx-pl`) albo przez filtr.
- Nazwy danych w bazie są stałe: `_dxsm_seo_title`, `_dxsm_seo_description`, `_dxsm_seo_keyphrase`, `dxsm_settings`, `dxsm_modules`, `dxsm_editor_settings`, `dxsm_redirects`; moduł pełnego SEO: `dxsm_rs2_seo_settings`, `_dxsm_rs2_*`, `dxsm_rs2_sp_*`.
- Polskie teksty widoczne dla ludzi bez pauz i półpauz, procent ze spacją („30 %”).
- Wersja w nagłówku, w `const VERSION` i w `Stable tag` readme musi być ta sama. Changelog w readme.txt.

## Wydanie

1. Testy w Dockerze: `cd tests && docker compose -p dxsm-core up -d`, instalacja WordPressa, `wp eval-file /tests/integration.php`, `/tests/unified.php` i `/tests/tools.php`. Lint PHP 7.4 (`php:7.4-cli php -l`). Fraza kluczowa i polska odmiana: `node tests/keyphrase.test.js`. Kontener `cli` uruchamiać z `-e WP_ENVIRONMENT_TYPE=local`, w Git Bash z `MSYS_NO_PATHCONV=1`.
2. Werdykt Codexa: PRZYJĘTE.
3. `python build.py`, commit, `gh release create vX.Y.Z dist/digital-xperts-social-meta-X.Y.Z.zip --title X.Y.Z --notes "..."`.
4. Opublikowane wydanie trafia na strony klientów przez aktualizacje: publikować tylko za zgodą Darka („wydaj” to publikacja, „aktualizuj” to aktualizacja stron).

## Strony

- d-x.pl: rdzeń + dodatek `digital-xperts-dx-pl` (katalog wtyczek, działy bloga, przyciski udostępniania, dane strukturalne artykułów).
- centrumpsychologii.xyz: sam rdzeń; opisy 15 stron wypisuje też mu-plugin `cp-seo.php` (nie ruszać).
- rs2.pl: rdzeń z włączonym modułem pełnego SEO.
