<?php
// Przekierowania starych adresów na nowe. Lista w opcji dxsm_redirects, obsługa w ustawieniach Social Meta.
namespace DigitalXperts\SocialMeta;

defined('ABSPATH') || exit;

final class Redirects {
    const OPTION = 'dxsm_redirects';
    // Lista przekierowań Yoast SEO Premium (zwykłe adresy, bez wyrażeń regularnych).
    const YOAST_OPTION = 'wpseo-premium-redirects-base';
    // Znacznik ostatniego przekierowania w przeglądarce odwiedzającego (bezpiecznik pętli), ważny kilka sekund.
    const COOKIE = 'dxsm_redirected';
    const COOKIE_SECONDS = 10;

    public static function boot() {
        add_action('template_redirect', array(__CLASS__, 'maybe_redirect'), 0);
        add_action('admin_post_dxsm_redirects_save', array(__CLASS__, 'handle'));
    }

    public static function all() {
        $rows = get_option(self::OPTION, array());
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : array();
    }

    // Stary adres jako ścieżka liczona od strony głównej: bez domeny, parametrów i ukośnika na końcu. Pusty wynik oznacza adres nie do przyjęcia.
    // Katalog witryny (WordPress w podkatalogu) jest odcinany tylko od pełnego adresu i od adresu żądania ($absolute),
    // nigdy od ścieżki wpisanej w formularzu ani zapisanej na liście: inaczej ponowny zapis skracałby ją drugi raz.
    public static function path($value, $absolute = false) {
        $value = trim((string) $value);
        if ('' === $value) {
            return '';
        }
        $full = (bool) preg_match('~^https?://~i', $value);
        $parts = wp_parse_url($full ? $value : 'https://x/' . ltrim($value, '/'));
        $path = isset($parts['path']) ? rawurldecode($parts['path']) : '';
        $home = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);
        if (($full || $absolute) && '/' !== $home && '' !== $home && 0 === strpos($path, untrailingslashit($home) . '/')) {
            $path = substr($path, strlen(untrailingslashit($home)));
        }
        $path = '/' . trim(preg_replace('~/+~', '/', $path), '/');
        // Segmenty „.” i „..” przeglądarka rozwija sama, więc taka ścieżka mogłaby ukryć pętlę.
        if (preg_match('~/\.\.?(/|$)~', $path)) {
            return '';
        }
        // Znaki „%”, „?” i „#” wracają do postaci zakodowanej, żeby ponowny zapis tej samej ścieżki dawał ten sam wynik.
        $path = str_replace(array('%', '?', '#'), array('%25', '%3F', '%23'), $path);
        // Strona główna, panel i pliki WordPressa nie mogą być źródłem przekierowania.
        if ('/' === $path || preg_match('~^/(wp-admin|wp-login\.php|wp-json|wp-content|wp-includes)(/|$)~i', $path)) {
            return '';
        }
        return $path;
    }

    // Nowy adres: ścieżka w tej witrynie albo pełny adres http(s).
    public static function destination($value) {
        $value = trim((string) $value);
        if (preg_match('~^https?://~i', $value)) {
            return esc_url_raw($value, array('http', 'https'));
        }
        // Ukośnik przed oczyszczeniem: bez niego WordPress dopisałby „http://” do ścieżki typu „oferta/nowa”.
        return '' === $value ? '' : esc_url_raw('/' . ltrim($value, '/'));
    }

    // Jeden wiersz po sprawdzeniu; null, gdy adresy są puste, takie same albo źródło jest zabronione.
    public static function row($from, $to, $code) {
        $from = self::path($from);
        $to = self::destination($to);
        if ('' === $from || '' === $to) {
            return null;
        }
        // Cel z segmentem „.” albo „..” przeglądarka rozwinęłaby do innego adresu, niż widać na liście.
        if (preg_match('~/\.\.?(/|$)~', rawurldecode((string) wp_parse_url($to, PHP_URL_PATH)))) {
            return null;
        }
        // Przekierowanie adresu na samego siebie dałoby pętlę.
        if (in_array(strtolower($from), self::local($to), true)) {
            return null;
        }
        return array('from' => $from, 'to' => $to, 'code' => 302 === (int) $code ? 302 : 301);
    }

    // Ścieżki tej witryny, pod które trafi przeglądarka po przejściu do celu (małymi literami); pusta lista dla adresu zewnętrznego.
    // Pierwsza to ścieżka zapisana w celu, bo ją dopasuje kolejna reguła. Druga pojawia się przy krótkim adresie treści
    // (/?p=42): WordPress odsyła z niego na właściwy adres wpisu, więc on też liczy się przy szukaniu pętli.
    // Host porównujemy bez wielkości liter i bez „www”, bo oba warianty domeny zwykle obsługuje ta sama instalacja.
    private static function local($to) {
        $host = function ($url) {
            return preg_replace('~^www\.~', '', strtolower((string) wp_parse_url($url, PHP_URL_HOST)));
        };
        $full = (bool) preg_match('~^https?://~i', $to);
        if ($full && $host($to) !== $host(home_url('/'))) {
            return array();
        }
        // Pełny adres traci katalog witryny tak samo jak adres żądania, żeby oba dało się porównać.
        $paths = array(self::path($to, $full));
        $post_id = url_to_postid($full ? $to : home_url($to));
        if ($post_id) {
            $paths[] = self::path((string) get_permalink($post_id), true);
        }
        return array_values(array_unique(array_filter(array_map('strtolower', $paths), 'strlen')));
    }

    // Lista po sprawdzeniu: bez błędnych wierszy, powtórzonych źródeł i reguł domykających pętlę.
    public static function normalize($rows) {
        $unique = array();
        foreach ($rows as $row) {
            if (is_array($row) && isset($row['from'], $row['to'])) {
                $clean = self::row($row['from'], $row['to'], isset($row['code']) ? $row['code'] : 301);
                if ($clean) {
                    // Jeden stary adres ma jedno przekierowanie: wygrywa wpisane później i idzie na koniec listy,
                    // żeby przy sprawdzaniu pętli odpadła nowa reguła, a nie starsza.
                    unset($unique[strtolower($clean['from'])]);
                    $unique[strtolower($clean['from'])] = $clean;
                }
            }
        }
        // Reguła, która domyka pętlę (/a na /b i /b na /a), nie wchodzi na listę: zostają wcześniejsze.
        $accepted = array();
        foreach ($unique as $key => $row) {
            $queue = self::local($row['to']);
            $seen = array();
            $loops = false;
            while ($queue && !$loops) {
                $next = array_shift($queue);
                if ($next === $key) {
                    $loops = true;
                } elseif (!isset($seen[$next]) && isset($accepted[$next])) {
                    $seen[$next] = true;
                    $queue = array_merge($queue, self::local($accepted[$next]['to']));
                }
            }
            if (!$loops) {
                $accepted[$key] = $row;
            }
        }
        return array_values($accepted);
    }

    // Blokada zapisu listy w bazie danych (GET_LOCK): ma ją najwyżej jedno połączenie naraz, a baza zwalnia ją sama,
    // gdy żądanie się kończy albo zostaje przerwane. Drugie żądanie czeka do 3 sekund i rezygnuje.
    public static function lock() {
        global $wpdb;
        return '1' === (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', self::lock_name(), 3));
    }

    public static function unlock() {
        global $wpdb;
        $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name()));
    }

    // Nazwa z bazą i prefiksem tabel, żeby dwie witryny na jednym serwerze bazy nie blokowały się nawzajem.
    public static function lock_name() {
        global $wpdb;
        return substr(md5(DB_NAME . '|' . $wpdb->prefix) . '_dxsm_redirects', 0, 64);
    }

    public static function save($rows) {
        $rows = self::normalize($rows);
        update_option(self::OPTION, $rows, false);
        return count($rows);
    }

    // Zapis z formularza: przechodzi tylko wtedy, gdy lista w bazie jest nadal taka, jaką odczytaliśmy ($before, null gdy jej nie było).
    // Dzięki temu nawet zapis, który z jakiegoś powodu stracił blokadę, nie nadpisze cudzej zmiany.
    public static function replace($rows, $before) {
        global $wpdb;
        $after = maybe_serialize(self::normalize($rows));
        if (null === $before) {
            // INSERT IGNORE: wiersz powstaje tylko wtedy, gdy opcji naprawdę jeszcze nie ma.
            $done = 1 === (int) $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::OPTION, $after));
        } else {
            $done = $after === $before || 1 === (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $after, self::OPTION, $before));
        }
        foreach (array(self::OPTION, 'notoptions', 'alloptions') as $key) {
            wp_cache_delete($key, 'options');
        }
        return $done;
    }

    // Znacznik wiersza w formularzu: stary adres, nowy adres i rodzaj razem.
    public static function signature($row) {
        return md5(wp_json_encode(array((string) $row['from'], (string) $row['to'], (int) $row['code'])));
    }

    // Wiersz przekierowania dla danej ścieżki albo null, gdy go nie ma.
    public static function target($request_path) {
        $path = self::path($request_path, true);
        if ('' === $path) {
            return null;
        }
        foreach (self::all() as $row) {
            if (isset($row['from'], $row['to']) && 0 === strcasecmp($row['from'], $path)) {
                return $row;
            }
        }
        return null;
    }

    public static function maybe_redirect() {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if (is_admin() || !in_array($method, array('GET', 'HEAD'), true) || empty($_SERVER['REQUEST_URI']) || !self::all()) {
            return;
        }
        $row = self::target(wp_unslash($_SERVER['REQUEST_URI']));
        if (!$row) {
            return;
        }
        // Bezpiecznik pętli: listy reguł nie da się sprawdzić z góry pod kątem przekierowań WordPressa (dawne adresy wpisów,
        // zgadywanie adresu przy 404), serwera i innych wtyczek. Gdy przeglądarka wraca na ten sam stary adres w ciągu
        // kilku sekund od naszego przekierowania, drugi raz już nie przekierowujemy.
        $cookie = isset($_COOKIE[self::COOKIE]) && is_string($_COOKIE[self::COOKIE]) ? wp_unslash($_COOKIE[self::COOKIE]) : '';
        if (self::returned($row['from'], $cookie)) {
            return;
        }
        $to = preg_match('~^https?://~i', $row['to']) ? $row['to'] : home_url($row['to']);
        if (!headers_sent()) {
            setcookie(self::COOKIE, self::trail($row['from'], $cookie), time() + self::COOKIE_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true);
        }
        // Nowy adres może prowadzić poza witrynę, więc wp_redirect, a nie wp_safe_redirect; adres pochodzi tylko od administratora.
        if (wp_redirect($to, (int) $row['code'], 'Digital Xperts Social Meta')) {
            exit;
        }
    }

    private static function mark($from) {
        return md5(strtolower($from));
    }

    // Znaczniki z ciasteczka: najwyżej pięć ostatnich przekierowań, żeby pętla przez kilka reguł też się zatrzymała.
    private static function marks($cookie) {
        return array_slice(array_values(array_filter(explode('.', (string) $cookie), function ($mark) {
            return 1 === preg_match('/^[a-f0-9]{32}$/', $mark);
        })), -5);
    }

    // Wartość ciasteczka po przekierowaniu z danego adresu: dotychczasowe znaczniki i nowy na końcu.
    public static function trail($from, $cookie) {
        return implode('.', array_slice(array_merge(self::marks($cookie), array(self::mark($from))), -5));
    }

    // Czy to żądanie jest powrotem na adres, z którego przed chwilą przekierowaliśmy.
    public static function returned($from, $cookie) {
        return in_array(self::mark($from), self::marks($cookie), true);
    }

    // Zwykłe przekierowania z Yoast SEO Premium, których jeszcze nie ma na liście.
    public static function yoast_rows() {
        $source = get_option(self::YOAST_OPTION, array());
        $known = array();
        foreach (self::all() as $row) {
            $known[strtolower($row['from'])] = true;
        }
        $rows = array();
        foreach (is_array($source) ? $source : array() as $item) {
            if (!is_array($item) || !isset($item['origin'], $item['url']) || (isset($item['format']) && 'plain' !== $item['format'])) {
                continue;
            }
            $type = isset($item['type']) ? (int) $item['type'] : 301;
            if (!in_array($type, array(301, 302, 307), true)) {
                continue;
            }
            $row = self::row($item['origin'], $item['url'], 301 === $type ? 301 : 302);
            if ($row && !isset($known[strtolower($row['from'])])) {
                $rows[strtolower($row['from'])] = $row;
            }
        }
        return array_values($rows);
    }

    public static function handle() {
        if (!current_user_can('manage_options')) {
            wp_die('Brak uprawnień.', '', array('response' => 403));
        }
        check_admin_referer('dxsm_redirects_save');
        // Jeden zapis naraz: drugi formularz wysłany w tej samej chwili dostaje komunikat i niczego nie nadpisuje.
        if (!self::lock()) {
            wp_safe_redirect(add_query_arg(array('page' => 'dxsm-social-meta', 'dxsm_view' => 'settings', 'dxsm_redirects' => 'busy'), admin_url('admin.php')) . '#dxsm-redirects');
            exit;
        }
        // Lista czytana już pod blokadą i prosto z bazy, z pominięciem pamięci podręcznej WordPressa.
        global $wpdb;
        $before = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION));
        $rows = is_string($before) ? maybe_unserialize($before) : array();
        $rows = is_array($rows) ? array_values(array_filter($rows, 'is_array')) : array();
        $remove = isset($_POST['dxsm_redirect_remove']) && is_array($_POST['dxsm_redirect_remove']) ? array_map('strval', wp_unslash($_POST['dxsm_redirect_remove'])) : array();
        // Usuwamy tylko wiersz dokładnie taki, jaki administrator widział w formularzu (adresy i rodzaj).
        // Reguła zmieniona w międzyczasie przez kogoś innego ma już inny znacznik i zostaje.
        $rows = array_values(array_filter($rows, function ($row) use ($remove) {
            return !in_array(self::signature($row), $remove, true);
        }));
        $status = $remove ? 'removed' : 'none';
        if (!empty($_POST['dxsm_redirect_yoast'])) {
            $rows = array_merge($rows, self::yoast_rows());
            $status = 'imported';
        }
        $from = isset($_POST['dxsm_redirect_from']) && is_string($_POST['dxsm_redirect_from']) ? wp_unslash($_POST['dxsm_redirect_from']) : '';
        $to = isset($_POST['dxsm_redirect_to']) && is_string($_POST['dxsm_redirect_to']) ? wp_unslash($_POST['dxsm_redirect_to']) : '';
        if ('' !== trim($from) || '' !== trim($to)) {
            $row = self::row($from, $to, isset($_POST['dxsm_redirect_code']) ? (int) $_POST['dxsm_redirect_code'] : 301);
            // Nowa reguła wchodzi tylko wtedy, gdy przetrwa sprawdzenie całej listy (nie domyka pętli); inaczej lista zostaje bez zmian.
            $status = $row && in_array($row, self::normalize(array_merge($rows, array($row))), true) ? 'added' : 'invalid';
            if ('added' === $status) {
                $rows[] = $row;
            }
        }
        // Błędna nowa reguła zatrzymuje cały zapis: zaznaczone do usunięcia i importowane wiersze też zostają bez zmian.
        if ('invalid' !== $status && !self::replace($rows, $before)) {
            $status = 'busy';
        }
        self::unlock();
        wp_safe_redirect(add_query_arg(array('page' => 'dxsm-social-meta', 'dxsm_view' => 'settings', 'dxsm_redirects' => $status), admin_url('admin.php')) . '#dxsm-redirects');
        exit;
    }

    public static function panel() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $rows = self::all();
        $yoast = count(self::yoast_rows());
        $status = isset($_GET['dxsm_redirects']) ? sanitize_key($_GET['dxsm_redirects']) : '';
        $messages = array(
            'added'    => array('success', 'Dodano przekierowanie.'),
            'removed'  => array('success', 'Usunięto zaznaczone przekierowania.'),
            'busy'     => array('error', 'Nic nie zmieniono, bo listę zapisuje właśnie ktoś inny. Spróbuj ponownie za chwilę.'),
            'imported' => array('success', 'Przeniesiono przekierowania z Yoast SEO Premium.'),
            'invalid'  => array('error', 'Nic nie zmieniono. Stary adres musi być ścieżką tej witryny (nie stroną główną ani panelem), a nowy adres innym adresem, który nie prowadzi z powrotem do starego.'),
        );
        echo '<h2 id="dxsm-redirects">Przekierowania</h2>';
        if (isset($messages[$status])) {
            echo '<div class="notice notice-' . esc_attr($messages[$status][0]) . ' inline" role="status"><p>' . esc_html($messages[$status][1]) . '</p></div>';
        }
        echo '<p>Stary adres prowadzi na nowy. Przydaje się po zmianie adresu wpisu, usunięciu strony albo przenosinach z innej wtyczki SEO. Parametry w starym adresie są pomijane.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('dxsm_redirects_save');
        echo '<input type="hidden" name="action" value="dxsm_redirects_save" />';
        if ($rows) {
            echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th scope="col">Usuń</th><th scope="col">Stary adres</th><th scope="col">Nowy adres</th><th scope="col">Rodzaj</th></tr></thead><tbody>';
            foreach ($rows as $index => $row) {
                $id = 'dxsm-redirect-remove-' . $index;
                echo '<tr><td><input type="checkbox" id="' . esc_attr($id) . '" name="dxsm_redirect_remove[]" value="' . esc_attr(self::signature($row)) . '" /><label class="screen-reader-text" for="' . esc_attr($id) . '">Usuń przekierowanie z ' . esc_html($row['from']) . '</label></td>';
                echo '<td><code>' . esc_html($row['from']) . '</code></td><td><code>' . esc_html($row['to']) . '</code></td><td>' . esc_html(301 === (int) $row['code'] ? '301, na stałe' : '302, tymczasowo') . '</td></tr>';
            }
            echo '</tbody></table>';
        } else {
            echo '<p><em>Lista jest pusta.</em></p>';
        }
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row"><label for="dxsm-redirect-from">Stary adres</label></th><td><input class="regular-text" type="text" id="dxsm-redirect-from" name="dxsm_redirect_from" placeholder="/stary-adres/" /></td></tr>';
        echo '<tr><th scope="row"><label for="dxsm-redirect-to">Nowy adres</label></th><td><input class="regular-text" type="text" id="dxsm-redirect-to" name="dxsm_redirect_to" placeholder="/nowy-adres/" /></td></tr>';
        echo '<tr><th scope="row"><label for="dxsm-redirect-code">Rodzaj</label></th><td><select id="dxsm-redirect-code" name="dxsm_redirect_code"><option value="301">301, na stałe</option><option value="302">302, tymczasowo</option></select></td></tr>';
        echo '</table>';
        if ($yoast) {
            echo '<p><label><input type="checkbox" name="dxsm_redirect_yoast" value="1" /> Przenieś przekierowania z Yoast SEO Premium (' . esc_html((string) $yoast) . ')</label></p>';
        }
        submit_button('Zapisz przekierowania', 'secondary');
        echo '</form>';
    }
}
