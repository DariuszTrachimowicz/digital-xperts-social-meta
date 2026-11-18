<?php
// Aktualizacje z wydań GitHuba przez natywny mechanizm WordPressa (nagłówek Update URI), wzorzec: Menedżer Strony AI.
// Wydanie ma tag v1.2.3 i załącznik digital-xperts-social-meta-1.2.3.zip z katalogiem digital-xperts-social-meta/.
// shortcut: tylko publiczne repozytorium, prywatne wymaga tokenu w żądaniach i pobieraniu.

namespace DigitalXperts\SocialMeta;

defined('ABSPATH') || exit;

final class Updater {
    const CACHE = 'dxsm_update_release';

    public static function repo() {
        $repo = defined('DXSM_UPDATE_REPO') ? (string) DXSM_UPDATE_REPO : 'DariuszTrachimowicz/digital-xperts-social-meta';
        return preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D', $repo) === 1 ? $repo : '';
    }

    public static function hooks() {
        // Nazwa filtra pochodzi z hosta w nagłówku Update URI pliku wtyczki.
        add_filter('update_plugins_d-x.pl', array(__CLASS__, 'check'), 10, 3);
        // Na części hostingów (np. z wtyczką blokującą zapytania do wordpress.org) WordPress nie odświeża listy
        // aktualizacji, więc filtr Update URI nie rusza. Wydanie dopisujemy też przy każdym odczycie tej listy.
        add_filter('site_transient_update_plugins', array(__CLASS__, 'inject'));
        // Ostatni priorytet: część wtyczek (np. CMS Portal motywów CMSSuperHeroes, priorytet 20) zwraca false
        // dla każdego obcego sluga i kasuje dane okna "Zobacz szczegóły" podane wcześniej.
        add_filter('plugins_api', array(__CLASS__, 'info'), PHP_INT_MAX, 3);
    }

    public static function inject($transient) {
        if (!is_object($transient)) {
            return $transient;
        }
        // Na froncie (np. pasek administratora) tylko pamięć podręczna: zapytanie do GitHuba robi panel albo cron.
        $release = self::release(false, !is_admin() && !wp_doing_cron());
        if (!$release) {
            return $transient;
        }
        $file = plugin_basename(DXSM_PLUGIN_FILE);
        $item = (object) array(
            'id' => 'github.com/' . self::repo(),
            'slug' => Plugin::SLUG,
            'plugin' => $file,
            'new_version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires_php' => '7.4',
        );
        $response = isset($transient->response) && is_array($transient->response) ? $transient->response : array();
        $no_update = isset($transient->no_update) && is_array($transient->no_update) ? $transient->no_update : array();
        if (version_compare($release['version'], VERSION, '>')) {
            $response[$file] = $item;
            unset($no_update[$file]);
        } else {
            $no_update[$file] = $item;
            unset($response[$file]);
        }
        $transient->response = $response;
        $transient->no_update = $no_update;
        return $transient;
    }

    public static function release($fresh = false, $cache_only = false) {
        $cached = get_site_transient(self::CACHE);
        if (!$fresh && is_array($cached)) {
            return $cached ?: null;
        }
        if ($cache_only) {
            return null;
        }
        $release = array();
        $repo = self::repo();
        // Adres releases/latest przekierowuje na tag najnowszego wydania. API GitHuba ma limit 60 zapytań
        // na godzinę na adres IP, który na współdzielonym hostingu wyczerpują inne strony.
        $reply = $repo === '' ? null : wp_remote_head('https://github.com/' . $repo . '/releases/latest', array('timeout' => 8, 'redirection' => 0));
        $location = $reply && !is_wp_error($reply) ? (string) wp_remote_retrieve_header($reply, 'location') : '';
        if (preg_match('~^https://github\.com/' . preg_quote($repo, '~') . '/releases/tag/v(\d+\.\d+\.\d+)$~D', $location, $match) === 1) {
            $release = array(
                'version' => $match[1],
                'package' => 'https://github.com/' . $repo . '/releases/download/v' . $match[1] . '/' . Plugin::SLUG . '-' . $match[1] . '.zip',
                'url' => $location,
            );
        }
        // Nieudany odczyt pamiętamy krócej, żeby nie odpytywać GitHuba przy każdym wejściu do panelu.
        set_site_transient(self::CACHE, $release, $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS);
        return $release ?: null;
    }

    public static function check($update, $plugin_data, $plugin_file) {
        if ($plugin_file !== plugin_basename(DXSM_PLUGIN_FILE)) {
            return $update;
        }
        $release = self::release();
        if (!$release) {
            return $update;
        }
        // WordPress sam porównuje wersję z zainstalowaną.
        return array(
            'slug' => Plugin::SLUG,
            'version' => $release['version'],
            'url' => $release['url'],
            'package' => $release['package'],
            'requires_php' => '7.4',
        );
    }

    public static function info($result, $action, $args) {
        if ($action !== 'plugin_information' || !is_object($args) || (isset($args->slug) ? $args->slug : '') !== Plugin::SLUG) {
            return $result;
        }
        return Plugin::details(self::release());
    }
}
