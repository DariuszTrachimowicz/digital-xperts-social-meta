<?php
// Moduł pełnego SEO z wariantu RS2. Nazwy danych (dxsm_rs2_*, _dxsm_rs2_*) zostają z pierwszego wdrożenia, bo leżą już w bazie RS2.
namespace DigitalXperts\SocialMeta\FullSeo;
defined('ABSPATH') || exit;

final class Schema {
    private static function collect_types($data,&$types) {
        if (!is_array($data)) { return; }
        if (isset($data['@type'])) {
            foreach ((array)$data['@type'] as $type) { if (is_string($type)) { $types[]=preg_replace('~^https?://schema\.org[/#]~i','',$type); } }
        }
        foreach ($data as $value) { if (is_array($value)) { self::collect_types($value,$types); } }
    }

    private static function existing_types($head) {
        $types=array();
        preg_match_all('~<script\b(?:"[^"]*"|\'[^\']*\'|[^\'">])*>(.*?)</script\s*>~is',$head,$matches,PREG_SET_ORDER);
        foreach ($matches as $match) {
            $tag=new \WP_HTML_Tag_Processor($match[0]);
            if (!$tag->next_tag('SCRIPT')) { continue; }
            $mime=$tag->get_attribute('type');
            if (is_string($mime) && strtolower(trim($mime))==='application/ld+json') {
                self::collect_types(json_decode($match[1],true),$types);
            }
        }
        return $types;
    }

    public static function complete_head($head,$context) {
        if (!SEO::enabled() || !$context) { return $head; }
        $settings=SEO::settings();$business=$settings['business'];
        if ($business['name']==='') { return $head; }
        $types=self::existing_types($head);$nodes=array();$base=home_url('/');$post=get_queried_object();
        if (is_front_page()) {
            if (!array_intersect($types,array('Organization','LocalBusiness','Corporation'))) {
                $organization=array('@type'=>'LocalBusiness','@id'=>$base.'#organization','name'=>$business['name'],'url'=>$base);
                foreach (array('telephone','email','logo') as $field) { if ($business[$field]!=='') { $organization[$field]=$business[$field]; } }
                $address=array('@type'=>'PostalAddress');
                foreach (array('street'=>'streetAddress','city'=>'addressLocality','region'=>'addressRegion','postal_code'=>'postalCode','country'=>'addressCountry') as $key=>$field) {
                    if ($business[$key]!=='') { $address[$field]=$business[$key]; }
                }
                if (count($address)>1) { $organization['address']=$address; }
                $nodes[]=$organization;
            }
            if (!in_array('WebSite',$types,true)) {
                $nodes[]=array('@type'=>'WebSite','@id'=>$base.'#website','url'=>$base,'name'=>$context['site_name'],
                    'inLanguage'=>str_replace('_','-',$context['locale']),'publisher'=>array('@id'=>$base.'#organization'));
            }
        }
        if (SEO::public_post($post) && in_array($post->post_type,$settings['service_post_types'],true) && !in_array('Service',$types,true)) {
            $node=array('@type'=>'Service','@id'=>get_permalink($post).'#service','url'=>get_permalink($post),
                'name'=>$post->post_title,'description'=>$context['description'],'provider'=>array('@id'=>$base.'#organization'));
            $manual=Migration::post_meta($post->ID,'_seopress_pro_schemas_manual',true);
            if (is_string($manual) && is_serialized($manual)) { $manual=unserialize($manual,array('allowed_classes'=>false)); }
            foreach (is_array($manual) ? $manual : array() as $entry) {
                if (!is_array($entry) || ($entry['_seopress_pro_rich_snippets_type']??'')!=='services') { continue; }
                foreach (array('name'=>'name','type'=>'serviceType','description'=>'description','area'=>'areaServed') as $key=>$field) {
                    $value=SEO::text($entry['_seopress_pro_rich_snippets_service_'.$key]??'');
                    if ($value!=='') { $node[$field]=$value; }
                }
                break;
            }
            $nodes[]=$node;
        }
        if (SEO::public_post($post) && $post->post_type==='post' && !array_intersect($types,array('Article','BlogPosting','NewsArticle'))) {
            $nodes[]=array('@type'=>'Article','@id'=>get_permalink($post).'#article','url'=>get_permalink($post),
                // Kontekst ma już zdekodowany, zwykły tekst; ponowne dekodowanie zmieniłoby dosłowne encje.
                'headline'=>$context['title'],'description'=>$context['description'],
                'inLanguage'=>str_replace('_','-',$context['locale']), 'datePublished'=>get_post_time(DATE_W3C,true,$post),
                'dateModified'=>get_post_modified_time(DATE_W3C,true,$post),'publisher'=>array('@id'=>$base.'#organization'),
                'author'=>array('@type'=>'Person','name'=>get_the_author_meta('display_name',$post->post_author)));
        }
        if (!$nodes) { return $head; }
        $json=wp_json_encode(array('@context'=>'https://schema.org','@graph'=>$nodes),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT);
        return $head."\n".'<script type="application/ld+json" id="dxsm-schema">'.$json.'</script>'."\n";
    }
}
