<?php
/**
 * Plugin Name: Golden Replay API
 * Plugin URI: https://github.com/eagle4life69/golden-replay-api
 * Description: Read-only REST API for Golden Replay episode data.
 * Version: 0.1.5
 * Author: Rhynes Media LLC
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: golden-replay-api
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'GRAPI_VERSION', '0.1.5' );
define( 'GRAPI_PLUGIN_FILE', __FILE__ );

$grapi_updater = plugin_dir_path( __FILE__ ) . 'github-updater.php';
if ( file_exists( $grapi_updater ) ) { require_once $grapi_updater; }

final class Golden_Replay_API {
    const VERSION = GRAPI_VERSION;
    const NAMESPACE = 'golden-replay/v1';

    public static function init() { add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) ); }

    public static function register_routes() {
        register_rest_route( self::NAMESPACE, '/episode/(?P<id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'get_episode' ),
            'permission_callback' => '__return_true',
            'args' => array( 'id' => array(
                'description' => 'WordPress post ID for the episode.',
                'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint',
                'validate_callback' => function ( $param ) { return is_numeric( $param ) && (int) $param > 0; },
            ) ),
        ) );
        register_rest_route( self::NAMESPACE, '/genres', array(
            'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_genres' ), 'permission_callback' => '__return_true',
        ) );
        register_rest_route( self::NAMESPACE, '/series', array(
            'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'get_series' ), 'permission_callback' => '__return_true',
            'args' => array( 'genre' => array(
                'description' => 'Canonical Golden Replay genre slug.', 'type' => 'string', 'required' => true,
                'sanitize_callback' => 'sanitize_title',
                'validate_callback' => function ( $param ) { return null !== self::canonical_genre_definition( sanitize_title( $param ) ); },
            ) ),
        ) );
    }

    public static function get_episode( WP_REST_Request $request ) {
        $post = get_post( absint( $request->get_param( 'id' ) ) );
        if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
            return new WP_Error( 'golden_replay_episode_not_found', 'Episode not found.', array( 'status' => 404 ) );
        }
        return rest_ensure_response( self::build_episode_payload( $post ) );
    }

    public static function get_genres() {
        $cache_key = 'grapi_genres_v1';
        $genres = get_transient( $cache_key );
        if ( false === $genres ) { $genres = self::build_genres_payload(); set_transient( $cache_key, $genres, 5 * MINUTE_IN_SECONDS ); }
        return rest_ensure_response( array( 'source' => self::detect_source(), 'genres' => $genres ) );
    }

    public static function get_series( WP_REST_Request $request ) {
        $genre_slug = sanitize_title( $request->get_param( 'genre' ) );
        $genre = self::canonical_genre_definition( $genre_slug );
        if ( ! $genre ) { return new WP_Error( 'golden_replay_invalid_genre', 'Invalid genre.', array( 'status' => 400 ) ); }
        $cache_key = 'grapi_series_v1_' . md5( $genre_slug );
        $series = get_transient( $cache_key );
        if ( false === $series ) { $series = self::build_series_catalog( $genre ); set_transient( $cache_key, $series, 5 * MINUTE_IN_SECONDS ); }
        return rest_ensure_response( array(
            'source' => self::detect_source(),
            'genre' => array( 'name' => $genre['name'], 'slug' => $genre['slug'] ),
            'series' => $series,
        ) );
    }

    private static function build_episode_payload( WP_Post $post ) {
        $title_data = self::parse_title( get_the_title( $post ) );
        $content_data = self::parse_content( $post->post_content );
        $enclosure_data = self::parse_enclosure( get_post_meta( $post->ID, 'enclosure', true ) );
        $series_data = self::build_series_from_show( $content_data['show'], $post );
        $primary_genre_data = self::detect_primary_genre( $post );
        $episode_genres = self::detect_episode_genres( $post, $primary_genre_data );
        $original_air_date = ! empty( $content_data['original_air_date'] ) ? $content_data['original_air_date'] : $title_data['original_air_date'];
        return array(
            'post_id'=>(int)$post->ID, 'web_url'=>add_query_arg('p',(int)$post->ID,home_url('/')), 'pretty_url'=>get_permalink($post),
            'title'=>$title_data['episode_title'], 'series'=>$series_data, 'publisher_feed'=>self::detect_publisher_feed($post),
            'genre'=>$primary_genre_data, 'primary_genre'=>$primary_genre_data, 'episode_genres'=>$episode_genres, 'source'=>self::detect_source(),
            'original_air_date'=>$original_air_date, 'published_date'=>get_post_time(DATE_ATOM,false,$post), 'modified_date'=>get_post_modified_time(DATE_ATOM,false,$post),
            'description'=>!empty($content_data['description'])?$content_data['description']:null,
            'duration_seconds'=>$enclosure_data['duration_seconds'], 'duration_display'=>$enclosure_data['duration_display'],
            'file_size_bytes'=>$enclosure_data['file_size_bytes'], 'file_size_display'=>$enclosure_data['file_size_display'],
            'audio'=>array('provider'=>$enclosure_data['provider'],'episode_id'=>$enclosure_data['episode_id'],'stream_url'=>$enclosure_data['stream_url'],'download_url'=>$enclosure_data['download_url']),
            'credits'=>$content_data['credits'], 'availability'=>array('status'=>'published','available'=>true,'scheduled_for'=>null),
        );
    }

    private static function parse_title( $raw_title ) {
        $title=html_entity_decode(wp_strip_all_tags((string)$raw_title),ENT_QUOTES|ENT_HTML5,'UTF-8'); $title=preg_replace('/\s+/u',' ',trim($title)); $date=null;
        if(preg_match('/\((\d{2})-(\d{2})-(\d{2})\)\s*$/',$title,$m)){ $y=(int)$m[3]; $y=$y>=30?1900+$y:2000+$y; $date=sprintf('%04d-%02d-%02d',$y,(int)$m[1],(int)$m[2]); $title=trim(preg_replace('/\s*\(\d{2}-\d{2}-\d{2}\)\s*$/','',$title)); }
        $parts=preg_split('/\s*[\|\x{2013}\x{2014}]\s*/u',$title,2); return array('episode_title'=>!empty($parts[0])?trim($parts[0]):$title,'original_air_date'=>$date);
    }

    private static function parse_content( $content ) {
        $text=wp_strip_all_tags(str_replace(array('<br>','<br/>','<br />'),"\n",(string)$content)); $text=html_entity_decode($text,ENT_QUOTES|ENT_HTML5,'UTF-8'); $text=preg_replace("/\r\n?|\x{2028}|\x{2029}/u","\n",$text); $lines=array_values(array_filter(array_map('trim',explode("\n",$text)),'strlen'));
        $r=array('description'=>null,'original_air_date'=>null,'show'=>null,'credits'=>array());
        $types=array('Stars:'=>'star','Star:'=>'star','Special Guests:'=>'special_guest','Special Guest:'=>'special_guest','Writer:'=>'writer','Writers:'=>'writer','Producer:'=>'producer','Producers:'=>'producer','Director:'=>'director','Directors:'=>'director','Music:'=>'music','Announcer:'=>'announcer','Narrator:'=>'narrator'); $active=null;
        foreach($lines as $line){
            if(0===stripos($line,'Description:')){$r['description']=trim(substr($line,strlen('Description:')));$active=null;continue;}
            if(0===stripos($line,'Original Air Date:')){$d=trim(substr($line,strlen('Original Air Date:')));$t=strtotime($d);if($t){$r['original_air_date']=gmdate('Y-m-d',$t);}$active=null;continue;}
            if(0===stripos($line,'Show:')){$r['show']=trim(substr($line,strlen('Show:')));$active=null;continue;}
            if(isset($types[$line])){$active=$types[$line];continue;} if(preg_match('/^[A-Za-z][A-Za-z ]+:$/',$line)){$active=null;continue;}
            if($active&&preg_match('/^[\x{2022}\-*]\s*(.+)$/u',$line,$m)){ $c=self::parse_credit_line($active,trim($m[1])); if($c){$r['credits'][]=$c;} }
        } return $r;
    }

    private static function parse_credit_line($type,$line){$line=trim(str_replace('_',' ',$line));if(''===$line||'.'===$line||'-'===$line)return null;$name=$line;$role=null;if(preg_match('/^(.+?)\s*\(([^()]*)\)\s*$/u',$line,$m)){$name=trim($m[1]);$role=trim($m[2]);}return array('type'=>sanitize_key($type),'name'=>sanitize_text_field($name),'role'=>null!==$role&&''!==$role?sanitize_text_field($role):null);}

    private static function parse_enclosure($meta){
        $r=array('provider'=>null,'episode_id'=>null,'stream_url'=>null,'download_url'=>null,'duration_seconds'=>null,'duration_display'=>null,'file_size_bytes'=>null,'file_size_display'=>null); if(!is_string($meta)||''===trim($meta))return $r; $lines=preg_split('/\r\n|\r|\n/',$meta);
        foreach($lines as $line){$line=trim($line);if(''===$line||false===strpos($line,'download.mp3'))continue;$url=wp_http_validate_url($line);if(!$url)continue;$host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));if('api.spreaker.com'!==$host)continue;if(preg_match('#/episodes/(\d+)/download\.mp3(?:\?.*)?$#',$url,$m)){$r['provider']='spreaker';$r['episode_id']=$m[1];$r['stream_url']=$url;$r['download_url']=$url;break;}}
        if(isset($lines[1])&&ctype_digit(trim($lines[1]))){$b=(int)trim($lines[1]);if($b>0){$r['file_size_bytes']=$b;$r['file_size_display']=size_format($b,2);}}
        $extra=trim((string)end($lines));if(is_serialized($extra)){$u=maybe_unserialize($extra);if(is_array($u)&&!empty($u['duration'])){$s=self::duration_to_seconds($u['duration']);if(null!==$s){$r['duration_seconds']=$s;$r['duration_display']=self::format_duration($s);}}}return $r;
    }

    private static function duration_to_seconds($d){$d=trim((string)$d);if(''===$d)return null;if(ctype_digit($d))return(int)$d;$p=array_map('intval',explode(':',$d));if(2===count($p))return$p[0]*60+$p[1];if(3===count($p))return$p[0]*3600+$p[1]*60+$p[2];return null;}
    private static function format_duration($s){$s=max(0,(int)$s);$h=intdiv($s,3600);$m=intdiv($s%3600,60);$x=$s%60;return$h>0?sprintf('%d:%02d:%02d',$h,$m,$x):sprintf('%d:%02d',$m,$x);}

    private static function build_series_from_show($show,WP_Post $post){$show=sanitize_text_field(trim((string)$show));if(''===$show)return null;$key=self::normalize_taxonomy_label($show);$tags=get_the_tags($post->ID);if($tags){foreach($tags as $tag){if($key===self::normalize_taxonomy_label($tag->name)||$key===self::normalize_taxonomy_label($tag->slug))return array('id'=>(int)$tag->term_id,'name'=>$show,'slug'=>$tag->slug);}}return array('id'=>null,'name'=>$show,'slug'=>sanitize_title($show));}
    private static function normalize_taxonomy_label($v){$v=html_entity_decode(wp_strip_all_tags((string)$v),ENT_QUOTES|ENT_HTML5,'UTF-8');$v=str_replace(array('_','-'),' ',$v);$v=strtolower($v);return(string)preg_replace('/[^a-z0-9]+/','',$v);}

    private static function genre_definitions(){return array(
        'western-podcast'=>array('name'=>'Westerns','slug'=>'westerns'),'western'=>array('name'=>'Westerns','slug'=>'westerns'),'westerns'=>array('name'=>'Westerns','slug'=>'westerns'),
        'mystery'=>array('name'=>'Mystery','slug'=>'mystery'),'drama'=>array('name'=>'Drama','slug'=>'drama'),'comedy'=>array('name'=>'Comedy','slug'=>'comedy'),'crime'=>array('name'=>'Crime','slug'=>'crime'),'detective'=>array('name'=>'Detective','slug'=>'detective'),'adventure'=>array('name'=>'Adventure','slug'=>'adventure'),'horror'=>array('name'=>'Horror','slug'=>'horror'),'sci-fi'=>array('name'=>'Science Fiction','slug'=>'science-fiction'),'science-fiction'=>array('name'=>'Science Fiction','slug'=>'science-fiction'),
    );}

    private static function canonical_genre_definition($canonical_slug){foreach(self::genre_definitions() as $term_slug=>$def){if($canonical_slug===$def['slug']){return array('name'=>$def['name'],'slug'=>$def['slug'],'aliases'=>self::genre_aliases($def['slug']));}}return null;}
    private static function genre_aliases($canonical_slug){$aliases=array();foreach(self::genre_definitions() as $term_slug=>$def){if($canonical_slug===$def['slug'])$aliases[]=$term_slug;}return array_values(array_unique($aliases));}

    private static function build_genres_payload(){ $canonical=array();$genres=array();foreach(self::genre_definitions() as $term_slug=>$def){$slug=$def['slug'];if(!isset($canonical[$slug]))$canonical[$slug]=array('name'=>$def['name'],'slug'=>$slug,'aliases'=>array());$canonical[$slug]['aliases'][]=$term_slug;}foreach($canonical as $g){$cats=self::get_genre_term_ids('category',$g['aliases']);$tags=self::get_genre_term_ids('post_tag',$g['aliases']);$primary=self::count_genre_posts($cats,array());$count=self::count_genre_posts($cats,$tags);if(0===$count)continue;$genres[]=array('name'=>$g['name'],'slug'=>$g['slug'],'episode_count'=>$count,'primary_episode_count'=>$primary);}return$genres; }
    private static function get_genre_term_ids($taxonomy,$aliases){$ids=get_terms(array('taxonomy'=>$taxonomy,'hide_empty'=>true,'slug'=>array_values(array_unique($aliases)),'fields'=>'ids'));return is_wp_error($ids)||!is_array($ids)?array():array_values(array_map('intval',$ids));}
    private static function genre_tax_query($genre,$categories=true,$tags=true){$parts=array();$aliases=$genre['aliases'];if($categories){$ids=self::get_genre_term_ids('category',$aliases);if($ids)$parts[]=array('taxonomy'=>'category','field'=>'term_id','terms'=>$ids,'operator'=>'IN');}if($tags){$ids=self::get_genre_term_ids('post_tag',$aliases);if($ids)$parts[]=array('taxonomy'=>'post_tag','field'=>'term_id','terms'=>$ids,'operator'=>'IN');}if(count($parts)>1)return array_merge(array('relation'=>'OR'),$parts);return$parts;}
    private static function count_genre_posts($category_ids,$tag_ids){$q=array();if($category_ids)$q[]=array('taxonomy'=>'category','field'=>'term_id','terms'=>array_values(array_map('intval',$category_ids)),'operator'=>'IN');if($tag_ids)$q[]=array('taxonomy'=>'post_tag','field'=>'term_id','terms'=>array_values(array_map('intval',$tag_ids)),'operator'=>'IN');if(!$q)return 0;if(count($q)>1)$q=array_merge(array('relation'=>'OR'),$q);$query=new WP_Query(array('post_type'=>'post','post_status'=>'publish','fields'=>'ids','posts_per_page'=>1,'orderby'=>'none','ignore_sticky_posts'=>true,'no_found_rows'=>false,'update_post_meta_cache'=>false,'update_post_term_cache'=>false,'tax_query'=>$q));return(int)$query->found_posts;}

    private static function build_series_catalog($genre){
        $tax_query=self::genre_tax_query($genre,true,true); if(empty($tax_query))return array();
        $query=new WP_Query(array('post_type'=>'post','post_status'=>'publish','fields'=>'ids','posts_per_page'=>-1,'orderby'=>'ID','order'=>'ASC','ignore_sticky_posts'=>true,'no_found_rows'=>true,'update_post_meta_cache'=>false,'tax_query'=>$tax_query));
        $series=array();
        foreach($query->posts as $post_id){$post=get_post($post_id);if(!$post)continue;$content=self::parse_content($post->post_content);$s=self::build_series_from_show($content['show'],$post);if(!$s||empty($s['name']))continue;$key=self::normalize_taxonomy_label($s['name']);if(''===$key)continue;$primary=self::detect_primary_genre($post);$is_primary=is_array($primary)&&isset($primary['slug'])&&$genre['slug']===$primary['slug'];
            if(!isset($series[$key]))$series[$key]=array('id'=>$s['id'],'name'=>$s['name'],'slug'=>sanitize_title($s['name']),'source_slug'=>$s['slug'],'match_type'=>$is_primary?'primary':'episode','matching_episode_count'=>0,'primary_episode_count'=>0);
            $series[$key]['matching_episode_count']++; if($is_primary){$series[$key]['primary_episode_count']++;$series[$key]['match_type']='primary';} if(null===$series[$key]['id']&&null!==$s['id'])$series[$key]['id']=$s['id'];
        }
        uasort($series,function($a,$b){return strcasecmp($a['name'],$b['name']);}); return array_values($series);
    }

    private static function genre_from_term($term,$source){$defs=self::genre_definitions();$slug=strtolower((string)$term->slug);if(!isset($defs[$slug]))return null;return array('id'=>(int)$term->term_id,'name'=>$defs[$slug]['name'],'slug'=>$defs[$slug]['slug'],'source'=>$source);}
    private static function detect_primary_genre(WP_Post $post){foreach(get_the_category($post->ID) as $cat){$g=self::genre_from_term($cat,'category');if($g)return$g;}return null;}
    private static function detect_episode_genres(WP_Post $post,$primary){$genres=array();$seen=array();if(is_array($primary)&&!empty($primary['slug'])){$genres[]=$primary;$seen[$primary['slug']]=true;}$tags=get_the_tags($post->ID);if($tags){foreach($tags as $tag){$g=self::genre_from_term($tag,'tag');if(!$g||isset($seen[$g['slug']]))continue;$genres[]=$g;$seen[$g['slug']]=true;}}return$genres;}
    private static function detect_publisher_feed(WP_Post $post){foreach(get_the_category($post->ID) as $cat){$slug=(string)$cat->slug;if(preg_match('/-season-\d+$/',$slug)||self::genre_from_term($cat,'category'))continue;return array('id'=>(int)$cat->term_id,'name'=>$cat->name,'slug'=>$cat->slug);}return null;}
    private static function detect_source(){$url=home_url('/');$host=strtolower((string)wp_parse_url($url,PHP_URL_HOST));$host=preg_replace('/^www\./','',$host);$known=array('otrwesterns.com'=>array('key'=>'otrwesterns','name'=>'Old Time Radio Westerns'),'otnetcast.com'=>array('key'=>'otnetcast','name'=>'Old Time Radio Netcast'));if(isset($known[$host])){$key=$known[$host]['key'];$name=$known[$host]['name'];}else{$key=sanitize_title($host);$name=get_bloginfo('name');}return array('key'=>$key,'name'=>sanitize_text_field($name),'site_url'=>esc_url_raw($url));}
}
Golden_Replay_API::init();
