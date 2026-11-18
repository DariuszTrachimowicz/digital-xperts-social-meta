<?php
// Moduł pełnego SEO z wariantu RS2. Nazwy danych (dxsm_rs2_*, _dxsm_rs2_*) zostają z pierwszego wdrożenia, bo leżą już w bazie RS2.
namespace DigitalXperts\SocialMeta\FullSeo;
defined('ABSPATH') || exit;

/** Private, independently verifiable preservation of SEOPress SEO data. */
final class Migration {
    const ARCHIVE='dxsm_rs2_sp_archive';
    const REPORT='dxsm_rs2_sp_report';
    const LOCK='dxsm_rs2_sp_lock';
    private static $redactions=0;

    public static function boot() { add_action('admin_post_dxsm_rs2_migrate',array(__CLASS__,'handle')); }
    public static function owned_key($key) { return '_dxsm_rs2_sp_'.substr($key,strlen('_seopress_')); }
    public static function query_key($key) {
        $r=get_option(self::REPORT,array());
        return is_array($r) && ($r['status']??'')==='verified' ? self::owned_key($key) : $key;
    }
    public static function post_meta($id,$key,$single=true) {
        $source=self::query_key($key);
        return get_post_meta($id,metadata_exists('post',$id,$source)?$source:$key,$single);
    }
    public static function term_meta($id,$key,$single=true) {
        $source=self::query_key($key);
        return get_term_meta($id,metadata_exists('term',$id,$source)?$source:$key,$single);
    }
    private static function clean($value,$key='',$depth=0) {
        if ($depth>64) { throw new \RuntimeException('SEO data nesting limit exceeded.'); }
        if (preg_match('/password|passwd|secret|token|license|credential|oauth|authorization|(?:^|[_-])(?:key|api[_-]?key)(?:$|[_-])/i',$key)) {
            ++self::$redactions; return '[credential excluded]';
        }
        if (is_object($value)) { ++self::$redactions; return '[object excluded]'; }
        if (is_array($value)) { foreach ($value as $k=>$v) { $value[$k]=self::clean($v,(string)$k,$depth+1); } }
        elseif (is_string($value) && is_serialized($value)) {
            $decoded=@unserialize($value,array('allowed_classes'=>false,'max_depth'=>64));
            $clean=self::clean($decoded,'',$depth+1);
            if ($clean!==$decoded) { return maybe_serialize($clean); }
        } elseif (is_string($value) && in_array(substr(ltrim($value),0,1),array('{','['),true)) {
            $decoded=json_decode($value,true,64);
            if (is_array($decoded) && json_last_error()===JSON_ERROR_NONE) {
                $clean=self::clean($decoded,'',$depth+1);
                if ($clean!==$decoded) { return wp_json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
            }
        }
        return $value;
    }
    private static function clean_raw($value,$key='') {
        $decoded=is_serialized($value) ? @unserialize($value,array('allowed_classes'=>false,'max_depth'=>64)) : $value;
        $clean=self::clean($decoded,$key);
        return $clean===$decoded ? $value : maybe_serialize($clean);
    }
    private static function sql_rows($query) {
        global $wpdb;
        $rows=$wpdb->get_results($query,ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error!=='') { throw new \RuntimeException('SEO snapshot query failed.'); }
        return $rows;
    }
    private static function collect() {
        global $wpdb; self::$redactions=0;
        $data=array('options'=>array(),'postmeta'=>array(),'termmeta'=>array(),'posts'=>array(),'tables'=>array());
        $like=$wpdb->esc_like('seopress_').'%';$widgets=$wpdb->esc_like('widget_seopress_').'%';
        foreach (self::sql_rows($wpdb->prepare("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s ORDER BY option_name",$like,$widgets)) as $row) {
            $data['options'][$row['option_name']]=self::clean_raw($row['option_value'],$row['option_name']);
        }
        foreach (array('postmeta'=>'post_id','termmeta'=>'term_id') as $kind=>$owner) {
            $table=$wpdb->$kind;
            foreach (self::sql_rows($wpdb->prepare("SELECT {$owner} AS owner,meta_key,meta_value FROM {$table} WHERE meta_key LIKE %s ORDER BY {$owner},meta_key,meta_id",$wpdb->esc_like('_seopress_').'%')) as $row) {
                $row['meta_value']=self::clean_raw($row['meta_value'],$row['meta_key']);$data[$kind][]=$row;
            }
        }
        $posts=self::sql_rows("SELECT * FROM {$wpdb->posts} WHERE post_type IN ('seopress_404','seopress_schemas','seopress_bot') OR (post_type='revision' AND post_parent IN (SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('seopress_404','seopress_schemas','seopress_bot'))) ORDER BY ID");
        foreach ($posts as $post) {
            $id=(int)$post['ID'];
            $data['posts'][$id]=array('post'=>self::clean($post),'meta'=>array(), 'terms'=>self::sql_rows($wpdb->prepare("SELECT r.*,t.taxonomy,t.term_id FROM {$wpdb->term_relationships} r INNER JOIN {$wpdb->term_taxonomy} t ON r.term_taxonomy_id=t.term_taxonomy_id WHERE r.object_id=%d ORDER BY r.term_taxonomy_id",$id)));
            foreach (self::sql_rows($wpdb->prepare("SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key NOT LIKE %s ORDER BY meta_id",$id,$wpdb->esc_like('_dxsm_rs2_sp_').'%')) as $meta) {
                $meta['meta_value']=self::clean_raw($meta['meta_value'],$meta['meta_key']);$data['posts'][$id]['meta'][]=$meta;
            }
        }
        $tables=$wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s',$wpdb->esc_like($wpdb->prefix.'seopress_').'%'));
        if (!is_array($tables) || $wpdb->last_error!=='') { throw new \RuntimeException('SEO table inventory failed.'); }
        sort($tables);
        foreach ($tables as $table) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/D',$table)) { throw new \RuntimeException('Unexpected SEO table identifier.'); }
            $create=self::sql_rows('SHOW CREATE TABLE `'.$table.'`');
            $rows=self::sql_rows('SELECT * FROM `'.$table.'`');
            // Deterministic ordering without assuming a primary-key column name.
            usort($rows,function($a,$b){return strcmp(serialize($a),serialize($b));});
            $data['tables'][$table]=array('create'=>$create[0]['Create Table'],'rows'=>self::clean($rows));
        }
        $data['excluded_credentials']=self::$redactions;
        return $data;
    }
    private static function groups($rows) {
        $groups=array();
        foreach ($rows as $row) { $groups[$row['owner']][$row['meta_key']][]=$row['meta_value']; }
        return $groups;
    }
    private static function owned_values($kind,$owner,$key) {
        global $wpdb;$column=$kind==='postmeta'?'post_id':'term_id';$table=$wpdb->$kind;
        $rows=self::sql_rows($wpdb->prepare("SELECT meta_value FROM {$table} WHERE {$column}=%d AND meta_key=%s ORDER BY meta_id",$owner,self::owned_key($key)));
        return array_column($rows,'meta_value');
    }
    private static function copy_metadata($archive) {
        global $wpdb;
        foreach (array('postmeta'=>'post_id','termmeta'=>'term_id') as $kind=>$column) {
            foreach (self::groups($archive[$kind]) as $owner=>$keys) {
                foreach ($keys as $key=>$values) {
                    $current=self::owned_values($kind,$owner,$key);
                    if ($current && $current!==$values) { throw new \RuntimeException('Imported SEO field conflict; existing data was not overwritten.'); }
                    if (!$current) {
                        foreach ($values as $value) {
                            if ($wpdb->insert($wpdb->$kind,array($column=>(int)$owner,'meta_key'=>self::owned_key($key),'meta_value'=>$value),array('%d','%s','%s'))===false) {
                                throw new \RuntimeException('Could not preserve an SEO field.');
                            }
                        }
                    }
                }
                wp_cache_delete((int)$owner,$kind==='postmeta'?'post_meta':'term_meta');
            }
        }
    }
    public static function verify($allow_staged=false) {
        $archive=get_option(self::ARCHIVE,null);$report=get_option(self::REPORT,array());
        if (!is_array($archive) || !is_array($report) || empty($report['digest']) || !hash_equals($report['digest'],hash('sha256',serialize($archive)))) {
            return array('status'=>'failed','reason'=>'archive_digest');
        }
        if (($report['status']??'')!=='verified' && !($allow_staged && ($report['status']??'')==='staged')) {
            return array('status'=>'failed','reason'=>'incomplete_migration');
        }
        foreach (array('postmeta','termmeta') as $kind) {
            foreach (self::groups($archive[$kind]) as $owner=>$keys) {
                // Deleted private SEOPress records remain fully preserved in the archive.
                if ($kind==='postmeta' && isset($archive['posts'][$owner]) && !get_post((int)$owner)) { continue; }
                foreach ($keys as $key=>$values) {
                    if (self::owned_values($kind,$owner,$key)!==$values) { return array('status'=>'failed','reason'=>'owned_metadata'); }
                }
            }
        }
        $report['status']='verified';return $report;
    }
    public static function run() {
        if (!current_user_can('manage_options')) { throw new \RuntimeException('Administrator required.'); }
        if (array_intersect((array)get_option('active_plugins',array()),array('wp-seopress/seopress.php','wp-seopress-pro/seopress-pro.php'))) {
            throw new \RuntimeException('Disable SEOPress before migrating.');
        }
        $token=wp_generate_uuid4();
        if (!add_option(self::LOCK,$token,'',false)) { throw new \RuntimeException('Migration is already running or requires recovery.'); }
        try { return self::perform(); }
        finally { if (get_option(self::LOCK)===$token) { delete_option(self::LOCK); } }
    }
    private static function perform() {
        $existing=get_option(self::ARCHIVE,null);
        if ($existing!==null) {
            $verified=self::verify();
            if (($verified['status']??'')!=='verified') { throw new \RuntimeException('Existing archive failed verification; no data overwritten.'); }
            return $verified;
        }
        $archive=self::collect();
        if (!add_option(self::ARCHIVE,$archive,'',false)) { throw new \RuntimeException('Could not create private SEO archive.'); }
        $report=array('status'=>'staged','digest'=>hash('sha256',serialize($archive)),'created_at'=>gmdate('c'),
            'counts'=>array('options'=>count($archive['options']),'postmeta'=>count($archive['postmeta']),'termmeta'=>count($archive['termmeta']),
                'custom_posts'=>count($archive['posts']),'tables'=>array_map(function($t){return count($t['rows']);},$archive['tables'])),
            'excluded_credentials'=>$archive['excluded_credentials'],'cleanup_enabled'=>defined('SEOPRESS_UNINSTALL') && SEOPRESS_UNINSTALL);
        self::persist_report($report);
        self::copy_metadata($archive);
        if (!hash_equals($report['digest'],hash('sha256',serialize(self::collect())))) { throw new \RuntimeException('Source SEO data changed during migration; plugin removal is blocked.'); }
        $report=self::verify(true);
        if (($report['status']??'')!=='verified') { throw new \RuntimeException('Migration verification failed.'); }
        self::persist_report($report);return $report;
    }
    private static function persist_report($report) {
        update_option(self::REPORT,$report,false);
        if (get_option(self::REPORT)!==$report) { throw new \RuntimeException('Migration report was not persisted; plugin removal is blocked.'); }
    }
    public static function handle() {
        if (!current_user_can('manage_options')) { wp_die('Brak uprawnień.',403); }
        check_admin_referer('dxsm_rs2_migrate');
        try { self::run(); }
        catch (\Throwable $e) { wp_die(esc_html($e->getMessage())); }
        wp_safe_redirect(admin_url('admin.php?page=dxsm-social-meta&dxsm_view=settings'));exit;
    }
    public static function panel() {
        if (!current_user_can('manage_options')) { return; }
        $report=get_option(self::REPORT,array());
        echo '<h2>Migracja danych SEOPress</h2><p>Kopia ustawień, pól SEO, schematów i analiz w prywatnym archiwum tej strony. Klucze API, tokeny i licencje nie są kopiowane.</p>';
        if ($report) {
            $verified=self::verify();
            echo '<p><strong>Status: '.esc_html($verified['status']).'</strong></p>';
            if (($verified['status']??'')==='verified') {
                echo '<pre>'.esc_html(wp_json_encode($verified['counts'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)).'</pre>';
                echo '<p>SHA256: <code>'.esc_html($verified['digest']).'</code></p>';
            }
        }
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        wp_nonce_field('dxsm_rs2_migrate');echo '<input type="hidden" name="action" value="dxsm_rs2_migrate" />';
        submit_button($report?'Sprawdź przeniesione dane':'Przenieś dane SEO z SEOPress');echo '</form>';
    }
}
