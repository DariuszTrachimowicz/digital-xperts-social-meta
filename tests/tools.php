<?php
// Import z Yoast SEO i przekierowania. Uruchamiać w jednorazowym WordPressie: wp eval-file /tests/tools.php
use DigitalXperts\SocialMeta\Redirects;
use DigitalXperts\SocialMeta\YoastImport;

if (wp_get_environment_type() !== 'local') {
    throw new RuntimeException('Local fixture required.');
}
$GLOBALS['checks'] = 0;
function check($condition, $message) {
    if (!$condition) { throw new RuntimeException($message); }
    $GLOBALS['checks']++;
}
$admin = get_users(array('role' => 'administrator', 'number' => 1))[0];
wp_set_current_user($admin->ID);
$name = get_bloginfo('name');

// 1. Zmienne Yoasta.
$post = wp_insert_post(array('post_title' => 'Usługi księgowe', 'post_excerpt' => 'Zajawka wpisu.', 'post_content' => '<p>Treść.</p>', 'post_status' => 'publish', 'post_type' => 'post'));
$object = get_post($post);
check(YoastImport::expand('%%title%% %%page%% %%sep%% %%sitename%%', $object) === 'Usługi księgowe - ' . $name, 'Yoast variables must expand and an empty variable must not leave a gap.');
check(YoastImport::expand('%%title%% %%sep%% %%nieznana%% %%sep%% %%sitename%%', $object) === 'Usługi księgowe - ' . $name, 'Unknown variable must not leave a doubled separator.');
check(YoastImport::expand('%%excerpt%% <b>Stały</b> tekst &amp; znak', $object) === 'Zajawka wpisu. Stały tekst & znak', 'Markup and entities must become plain text.');
check(YoastImport::expand('%%sep%% %%sitename%% %%sep%%', $object) === $name, 'Leading and trailing separators must be trimmed.');
check(YoastImport::expand('Oferta z %%date%%', $object) === 'Oferta z ' . get_the_date('', $object), 'Date variable must expand.');
check(YoastImport::expand('%%ct_typ-oferty%% %%sep%% %%title%%', $object) === 'Usługi księgowe' && YoastImport::expand('%%term404%%', $object) === '' && YoastImport::expand('%%title%% %%sep%% %%term404%%', $object) === 'Usługi księgowe', 'Variables with digits must not be stored literally.');
$secret = wp_insert_post(array('post_title' => 'Tajny', 'post_excerpt' => 'Tajna zajawka.', 'post_content' => '<p>Tajna treść.</p>', 'post_password' => 'haslo', 'post_status' => 'publish', 'post_type' => 'post'));
check(YoastImport::expand('%%title%% %%sep%% %%excerpt%%%%excerpt_only%%', get_post($secret)) === 'Tajny', 'Protected content must not leak into SEO fields.');
wp_delete_post($secret, true);
$bare = wp_insert_post(array('post_title' => 'Bez zajawki', 'post_content' => '<p>Początek treści wpisu.</p>', 'post_status' => 'publish', 'post_type' => 'post'));
check(YoastImport::expand('%%excerpt%%', get_post($bare)) === 'Początek treści wpisu.' && YoastImport::expand('%%excerpt_only%%', get_post($bare)) === '', 'Excerpt falls back to the content, excerpt_only does not.');
$first_cat = wp_insert_term('Aaa pierwsza ' . wp_generate_password(4, false), 'category');
$main_cat = wp_insert_term('Zzz główna ' . wp_generate_password(4, false), 'category');
wp_set_post_categories($bare, array($first_cat['term_id'], $main_cat['term_id']));
update_post_meta($bare, '_yoast_wpseo_primary_category', $main_cat['term_id']);
check(YoastImport::expand('%%primary_category%%|%%category%%', get_post($bare)) === get_term($main_cat['term_id'])->name . '|' . get_term($first_cat['term_id'])->name, 'Primary category chosen in Yoast must win.');
wp_delete_post($bare, true); wp_delete_term($first_cat['term_id'], 'category'); wp_delete_term($main_cat['term_id'], 'category');

// 2. Import tylko do pustych pól, bez nadpisywania.
$other = wp_insert_post(array('post_title' => 'Kontakt', 'post_content' => '<p>Treść.</p>', 'post_status' => 'publish', 'post_type' => 'page'));
$trashed = wp_insert_post(array('post_title' => 'Kosz', 'post_status' => 'trash', 'post_type' => 'post'));
update_post_meta($post, '_yoast_wpseo_title', '%%title%% %%sep%% %%sitename%%');
update_post_meta($post, '_yoast_wpseo_metadesc', wp_slash('Opis z Yoasta – z półpauzą i "cudzysłowem".'));
update_post_meta($post, '_yoast_wpseo_focuskw', 'usługi księgowe');
update_post_meta($other, '_yoast_wpseo_title', 'Tytuł Yoasta');
update_post_meta($other, '_dxsm_seo_title', 'Własny tytuł');
update_post_meta($other, '_yoast_wpseo_metadesc', '');
update_post_meta($trashed, '_yoast_wpseo_title', 'Z kosza');
// Szablon, który po rozwinięciu jest pusty, nie liczy się jako pole do przeniesienia.
update_post_meta($other, '_yoast_wpseo_focuskw', '%%nieznana%%');
$scan = YoastImport::scan();
check($scan === array('posts' => 2, 'new' => 3, 'kept' => 1), 'Scan must count fields to import and fields already set: ' . wp_json_encode($scan));
check(YoastImport::run() === 3, 'Import must fill exactly the empty fields.');
check(get_post_meta($post, '_dxsm_seo_title', true) === 'Usługi księgowe - ' . $name, 'Imported title must be expanded.');
check(get_post_meta($post, '_dxsm_seo_description', true) === 'Opis z Yoasta - z półpauzą i "cudzysłowem".', 'Imported description must lose the dash and keep quotes.');
check(get_post_meta($post, '_dxsm_seo_keyphrase', true) === 'usługi księgowe', 'Focus keyphrase must be imported.');
check(get_post_meta($other, '_dxsm_seo_title', true) === 'Własny tytuł', 'Existing Social Meta value must not be overwritten.');
check(!metadata_exists('post', $other, '_dxsm_seo_keyphrase'), 'An empty expanded template must not be stored.');
// Puste pole Social Meta jest zastępowane, a pole wypełnione w ostatniej chwili zostaje.
$late = wp_insert_post(array('post_title' => 'Późny', 'post_status' => 'publish', 'post_type' => 'post'));
update_post_meta($late, '_yoast_wpseo_title', 'Tytuł Yoasta'); update_post_meta($late, '_yoast_wpseo_metadesc', 'Opis Yoasta');
update_post_meta($late, '_dxsm_seo_title', '');
update_post_meta($late, '_dxsm_seo_keyphrase', '');
update_post_meta($late, '_yoast_wpseo_focuskw', 'fraza yoasta');
// Redaktor zapisuje własne wartości w chwili, gdy import już uznał pola za puste: tuż przed usunięciem pustego pola (fraza)
// i tuż przed zapisem (opis). Obie wartości muszą przetrwać.
$race = function ($query) use ($late) {
    global $wpdb;
    if (0 === stripos(ltrim($query), 'UPDATE') && false !== strpos($query, '_dxsm_seo_keyphrase') && false !== strpos($query, 'fraza yoasta')) {
        remove_filter('query', $GLOBALS['dxsm_test_race']);
        $wpdb->update($wpdb->postmeta, array('meta_value' => 'fraza redaktora'), array('post_id' => $late, 'meta_key' => '_dxsm_seo_keyphrase'));
        wp_cache_delete($late, 'post_meta');
    }
    return $query;
};
// Opis redaktora powstaje tuż przed zapytaniem wstawiającym pole: wstawienie z warunkiem „nadal nie istnieje” musi ustąpić.
$guard = function ($query) use ($late) {
    global $wpdb;
    if (0 === stripos(ltrim($query), 'INSERT') && false !== strpos($query, '_dxsm_seo_description') && false !== strpos($query, 'Opis Yoasta')) {
        remove_filter('query', $GLOBALS['dxsm_test_guard']);
        $wpdb->insert($wpdb->postmeta, array('post_id' => $late, 'meta_key' => '_dxsm_seo_description', 'meta_value' => 'Opis redaktora'));
    }
    return $query;
};
$GLOBALS['dxsm_test_guard'] = $guard;
$GLOBALS['dxsm_test_race'] = $race;
add_filter('query', $race);
add_filter('query', $guard);
check(YoastImport::run() === 1, 'Only the field that is still empty at write time is imported.');
remove_all_filters('query');
wp_cache_delete($late, 'post_meta');
check(get_post_meta($late, '_dxsm_seo_title', true) === 'Tytuł Yoasta' && get_post_meta($late, '_dxsm_seo_description') === array('Opis redaktora') && get_post_meta($late, '_dxsm_seo_keyphrase') === array('fraza redaktora'), 'Values saved in the meantime must survive the import: ' . wp_json_encode(get_post_meta($late)));
wp_delete_post($late, true);
check(!metadata_exists('post', $trashed, '_dxsm_seo_title'), 'Trashed content must be skipped.');
check(get_post_meta($post, '_yoast_wpseo_title', true) === '%%title%% %%sep%% %%sitename%%', 'Yoast data must stay untouched.');
check(YoastImport::run() === 0 && YoastImport::scan()['new'] === 0, 'Second import must change nothing.');
ob_start(); YoastImport::panel(); $panel = ob_get_clean();
check(strpos($panel, 'Import z Yoast SEO') !== false && strpos($panel, 'Przenieś dane z Yoast SEO') === false, 'Panel must show counts and hide the button when nothing is left.');

// 3. Przekierowania: zapis, odrzucanie złych adresów, dopasowanie.
delete_option(Redirects::OPTION);
check(Redirects::path(home_url('/Stary-Adres/?utm=1#x')) === '/Stary-Adres', 'Path must drop the domain, query and trailing slash.');
check(Redirects::path('stary') === '/stary' && Redirects::path('/a//b/') === '/a/b', 'Path must be normalised.');
check(Redirects::path('/produkt%3Farchiwum') === '/produkt%3Farchiwum' && Redirects::path(Redirects::path('/produkt%3Farchiwum')) === '/produkt%3Farchiwum', 'Saving a path twice must give the same path.');
check(Redirects::path('/r%252Fb') === '/r%252Fb' && Redirects::path(Redirects::path('/r%252Fb')) === '/r%252Fb' && Redirects::path('/r%252Fb') !== Redirects::path('/r/b'), 'A doubly encoded path must stay distinct and stable.');
check(Redirects::path('/oferta/../oferta') === '' && Redirects::row('/oferta', '/oferta/../oferta', 301) === null && Redirects::row('/a/./b', '/c', 301) === null, 'Dot segments must be rejected.');
check(Redirects::path('/us%C5%82ugi/') === '/usługi' && Redirects::path('/usługi') === '/usługi', 'Encoded and typed Polish letters must meet.');
check(Redirects::destination('oferta/nowa') === '/oferta/nowa' && Redirects::row('/stary/', 'oferta/nowa', 301)['to'] === '/oferta/nowa', 'A target without a leading slash stays a local path.');
foreach (array('', '/', '/wp-admin/', '/wp-login.php', '/wp-json/wp/v2', '/wp-content/x.png') as $bad) {
    check(Redirects::path($bad) === '', 'Forbidden source must be rejected: ' . $bad);
}
check(Redirects::row('/stary/', '/stary', 301) === null && Redirects::row('/stary/', home_url('/STARY/'), 301) === null, 'Redirect to itself must be rejected.');
check(strpos(Redirects::row('/stary/', 'javascript:alert(1)', 301)['to'], '/') === 0, 'A script address can only become a local path, never a script target.');
check(Redirects::row('/stary/', 'https://example.org/nowy?a=1', 307) === array('from' => '/stary', 'to' => 'https://example.org/nowy?a=1', 'code' => 301), 'External target is allowed and unknown codes fall back to 301.');
$count = Redirects::save(array(
    array('from' => '/stary-wpis/', 'to' => '/nowy-wpis/', 'code' => 301),
    array('from' => '/promocja', 'to' => 'https://example.org/oferta', 'code' => 302),
    array('from' => '/stary-wpis', 'to' => '/jeszcze-nowszy/', 'code' => 301),
    array('from' => '/', 'to' => '/x/'),
    'smieci',
));
check($count === 2 && count(Redirects::all()) === 2, 'Duplicates and invalid rows must be dropped.');
check(Redirects::target('/STARY-WPIS/?utm_source=x')['to'] === '/jeszcze-nowszy/', 'The later entry wins and matching ignores case, slash and query.');
check(Redirects::target('/promocja')['code'] === 302 && Redirects::target('/inny/') === null && Redirects::target('/') === null, 'Only listed paths redirect.');
// Pętle: reguła domykająca krąg nie wchodzi na listę, także przez pełny adres z inną wielkością liter w domenie.
$host = wp_parse_url(home_url('/'), PHP_URL_HOST);
check(Redirects::row('/petla/', 'http://' . strtoupper($host) . '/petla', 301) === null, 'A full address of the same page must be rejected whatever the host case.');
check(Redirects::row('/petla/', 'http://www.' . $host . '/petla/', 301) === null, 'The www variant of this site is the same site.');
check(Redirects::row('/oferta', '/a/%2e%2e/oferta', 301) === null, 'Encoded dot segments must be rejected.');
// Krótki adres treści jako cel: WordPress odesłałby z niego na właściwy adres, czyli z powrotem do źródła.
$GLOBALS['wp_rewrite']->set_permalink_structure('/%postname%/');
$looped = wp_insert_post(array('post_title' => 'Oferta zapętlona', 'post_name' => 'oferta-zapetlona', 'post_status' => 'publish', 'post_type' => 'post'));
check(Redirects::row('/oferta-zapetlona/', '/?p=' . $looped, 301) === null && Redirects::row('/inna-stara/', '/?p=' . $looped, 301) !== null, 'A short address of the same content must be rejected as a target.');
// Cel z parametrem na innej ścieżce: następna reguła dopasuje samą ścieżkę, więc pętla /a i /b musi zostać wykryta.
$pair = Redirects::normalize(array(array('from' => '/a', 'to' => '/b?p=' . $looped, 'code' => 302), array('from' => '/b', 'to' => '/a', 'code' => 302)));
check(count($pair) === 1 && $pair[0]['from'] === '/a', 'A loop through a target with a query must be detected: ' . wp_json_encode($pair));
// Krótki adres treści prowadzi do jej właściwego adresu, więc reguła z właściwego adresu z powrotem domyka pętlę.
$via = Redirects::normalize(array(array('from' => '/stara', 'to' => '/?p=' . $looped), array('from' => '/oferta-zapetlona', 'to' => '/stara')));
check(count($via) === 1 && $via[0]['from'] === '/stara', 'A loop through the real address of the content must be detected: ' . wp_json_encode($via));
wp_delete_post($looped, true);
$GLOBALS['wp_rewrite']->set_permalink_structure('');
// Bezpiecznik pętli: powrót na ten sam stary adres ze znacznikiem przekierowania nie przekierowuje drugi raz.
check(Redirects::returned('/Stary', md5('/stary')) && !Redirects::returned('/stary', md5('/inny')) && !Redirects::returned('/stary', ''), 'The loop guard must recognise only a return to the same source.');
$trail = Redirects::trail('/b', Redirects::trail('/a', ''));
check($trail === md5('/a') . '.' . md5('/b') && Redirects::returned('/a', $trail) && Redirects::returned('/b', $trail) && !Redirects::returned('/c', $trail), 'The guard remembers the last redirects, so a loop through two rules stops too.');
check(Redirects::trail('/x', 'smieci.<script>.' . md5('/a')) === md5('/a') . '.' . md5('/x') && count(explode('.', Redirects::trail('/7', implode('.', array_map('md5', array('1', '2', '3', '4', '5', '6')))))) === 5, 'The cookie keeps only valid marks and at most five.');
$loops = Redirects::save(array(
    array('from' => '/a', 'to' => '/b'),
    array('from' => '/b', 'to' => '/c'),
    array('from' => '/c', 'to' => 'http://' . strtoupper($host) . '/a/'),
    array('from' => '/d', 'to' => 'https://example.org/a'),
));
check($loops === 3 && Redirects::target('/c') === null && Redirects::target('/b')['to'] === '/c' && Redirects::target('/d') !== null, 'The rule closing a loop must be dropped and external targets kept.');
// Zmiana celu, która domknęłaby pętlę, odpada sama i nie usuwa starszej reguły.
$kept = Redirects::normalize(array(array('from' => '/a', 'to' => '/x'), array('from' => '/b', 'to' => '/a'), array('from' => '/a', 'to' => '/b')));
check($kept === array(array('from' => '/b', 'to' => '/a', 'code' => 301)), 'The new looping rule is dropped, the older rule stays: ' . wp_json_encode($kept));
// WordPress w podkatalogu: zapisana ścieżka nie skraca się przy kolejnym zapisie, a adres żądania traci katalog witryny.
$subdir = function () { return 'http://example.org/blog'; };
add_filter('home_url', function ($url, $path) { return 'http://example.org/blog' . ('' === $path || '/' === $path[0] ? $path : '/' . $path); }, 10, 2);
check(Redirects::path('/blog/oferta') === '/blog/oferta' && Redirects::path(Redirects::path('/blog/oferta')) === '/blog/oferta', 'A typed path is relative to the home address and stable when saved again.');
check(Redirects::path('/blog/oferta/?x=1', true) === '/oferta' && Redirects::path('http://example.org/blog/oferta/') === '/oferta', 'Request and full addresses lose the site directory once.');
check(Redirects::row('/stara/', 'http://example.org/blog/stara/', 301) === null && Redirects::row('/stara/', 'http://example.org/blog/nowa/', 301) !== null, 'In a subdirectory site a full address of the same page is still the same page.');
remove_all_filters('home_url');
Redirects::save(array(
    array('from' => '/stary-wpis', 'to' => '/jeszcze-nowszy/', 'code' => 301),
    array('from' => '/promocja', 'to' => 'https://example.org/oferta', 'code' => 302),
));

// Blokada zapisu: drugie połączenie z bazą nie dostaje blokady, dopóki pierwsze jej nie zwolni.
$second = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
$try = function () use ($second) {
    return (string) $second->get_var($second->prepare('SELECT GET_LOCK(%s, 0)', Redirects::lock_name()));
};
check(Redirects::lock() === true, 'The first save must get the lock.');
check($try() === '0', 'A second connection must not get the lock while the first holds it.');
Redirects::unlock();
check($try() === '1', 'After unlock another connection can take the lock.');
check(Redirects::lock() === false, 'While another connection holds the lock, this save must give up.');
$second->query($second->prepare('SELECT RELEASE_LOCK(%s)', Redirects::lock_name()));
check(Redirects::lock() === true, 'The lock is free again after the other connection releases it.');
Redirects::unlock();
$second->close();

// Znacznik wiersza w formularzu zmienia się razem z celem i rodzajem, więc stary formularz nie usunie zmienionej reguły.
$one = array('from' => '/oferta', 'to' => '/wiosna', 'code' => 301);
check(Redirects::signature($one) !== Redirects::signature(array('from' => '/oferta', 'to' => '/lato', 'code' => 301)) && Redirects::signature($one) !== Redirects::signature(array('from' => '/oferta', 'to' => '/wiosna', 'code' => 302)) && Redirects::signature($one) === Redirects::signature(array('code' => '301', 'to' => '/wiosna', 'from' => '/oferta')), 'The row signature covers the source, the target and the kind.');
ob_start(); Redirects::panel(); $signed = ob_get_clean();
check(strpos($signed, 'value="' . Redirects::signature(Redirects::all()[0]) . '"') !== false, 'The remove checkbox must carry the row signature.');

// Zapis z formularza przechodzi tylko wtedy, gdy lista w bazie nie zmieniła się od odczytu.
delete_option(Redirects::OPTION);
$raw = function () { global $wpdb; return $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Redirects::OPTION)); };
check(Redirects::replace(array(array('from' => '/pierwsza', 'to' => '/a1')), null) === true && count(Redirects::all()) === 1, 'The first save creates the list.');
check(Redirects::replace(array(array('from' => '/druga', 'to' => '/a2')), null) === false && Redirects::all()[0]['from'] === '/pierwsza', 'A save that thinks the list does not exist must not overwrite it.');
$seen = $raw();
Redirects::save(array_merge(Redirects::all(), array(array('from' => '/cudza', 'to' => '/a3'))));
check(Redirects::replace(array(array('from' => '/moja', 'to' => '/a4')), $seen) === false && count(Redirects::all()) === 2 && Redirects::target('/cudza') !== null, 'A save based on a stale list must be refused and the other change kept.');
check(Redirects::replace(array_merge(Redirects::all(), array(array('from' => '/moja', 'to' => '/a4'))), $raw()) === true && count(Redirects::all()) === 3, 'A save based on the current list goes through.');
check(Redirects::replace(Redirects::all(), $raw()) === true, 'Saving an unchanged list is a success.');
Redirects::save(array(
    array('from' => '/stary-wpis', 'to' => '/jeszcze-nowszy/', 'code' => 301),
    array('from' => '/promocja', 'to' => 'https://example.org/oferta', 'code' => 302),
));

// 4. Przekierowania z Yoast SEO Premium: tylko zwykłe adresy i tylko nowe.
update_option(Redirects::YOAST_OPTION, array(
    array('origin' => 'yoast-stary', 'url' => '/yoast-nowy/', 'type' => 301, 'format' => 'plain'),
    array('origin' => 'tymczasowy', 'url' => 'https://example.org/t', 'type' => 307, 'format' => 'plain'),
    array('origin' => '^regex/(.*)', 'url' => '/x/$1', 'type' => 301, 'format' => 'regex'),
    array('origin' => 'usuniety', 'url' => '', 'type' => 410, 'format' => 'plain'),
    array('origin' => 'promocja', 'url' => '/inna/', 'type' => 301, 'format' => 'plain'),
));
$yoast = Redirects::yoast_rows();
check(count($yoast) === 2 && $yoast[0] === array('from' => '/yoast-stary', 'to' => '/yoast-nowy/', 'code' => 301) && $yoast[1]['code'] === 302, 'Yoast Premium import takes plain 301, 302 and 307 rows that are not listed yet: ' . wp_json_encode($yoast));
Redirects::save(array_merge(Redirects::all(), $yoast));
check(count(Redirects::all()) === 4 && Redirects::yoast_rows() === array(), 'After import nothing is left to import.');
ob_start(); Redirects::panel(); $panel = ob_get_clean();
check(strpos($panel, '<code>/yoast-stary</code>') !== false && strpos($panel, 'dxsm_redirects_save') !== false && strpos($panel, 'Yoast SEO Premium (') === false, 'Panel must list redirects and hide the finished import.');

// 5. Uprawnienia: redaktor nie widzi paneli.
$editor = wp_insert_user(array('user_login' => 'tools-editor-' . wp_generate_password(6, false), 'user_pass' => wp_generate_password(), 'role' => 'editor'));
wp_set_current_user($editor);
ob_start(); Redirects::panel(); YoastImport::panel(); $hidden = ob_get_clean();
check($hidden === '', 'Panels are for administrators only.');
wp_set_current_user($admin->ID);

require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user($editor);
wp_delete_post($post, true); wp_delete_post($other, true); wp_delete_post($trashed, true);
delete_option(Redirects::OPTION); delete_option(Redirects::YOAST_OPTION);
echo 'PASS: ' . $GLOBALS['checks'] . " tools checks on real WordPress\n";
