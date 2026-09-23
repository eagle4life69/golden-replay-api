<?php
/**
 * Plugin Name: Golden Replay API
 * Plugin URI: https://github.com/eagle4life69/golden-replay-api
 * Description: Read-only REST API for Golden Replay episode data.
 * Version: 0.1.20
 * Author: Rhynes Media LLC
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: golden-replay-api
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'GRAPI_VERSION', '0.1.20' );
define( 'GRAPI_PLUGIN_FILE', __FILE__ );

$grapi_updater = plugin_dir_path( __FILE__ ) . 'github-updater.php';
if ( file_exists( $grapi_updater ) ) { require_once $grapi_updater; }

final class Golden_Replay_API {
    const VERSION = GRAPI_VERSION;
    const NAMESPACE = 'golden-replay/v1';
    const CACHE_MAX_AGE = 86400;

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        add_action( 'save_post', array( __CLASS__, 'handle_post_change' ), 10, 3 );
        add_action( 'deleted_post', array( __CLASS__, 'handle_deleted_post' ), 10, 2 );
        add_action( 'set_object_terms', array( __CLASS__, 'handle_term_change' ), 10, 6 );
        add_action( 'grapi_rebuild_catalog_cache', array( __CLASS__, 'rebuild_catalog_cache' ) );
    }

    public static function register_routes() {
        register_rest_route( self::NAMESPACE, '/episode/(?P<id>\\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'get_episode' ),
            'permission_callback' => '__return_true',
            'args' => array(
                'id' => array(
                    'type' => 'integer',
                    'required' => true,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function( $p ) { return is_numeric( $p ) && (int) $p > 0; },
                ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/genres', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'get_genres' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( self::NAMESPACE, '/latest', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'get_latest_episode' ),
            'permission_callback' => '__return_true',
            'args' => array(
                'genre' => array(
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_title',
                    'validate_callback' => function( $p ) { return null !== self::canonical_genre_definition( sanitize_title( $p ) ); },
                ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/series', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'get_series' ),
            'permission_callback' => '__return_true',
            'args' => array(
                'genre' => array(
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_title',
                    'validate_callback' => function( $p ) { return null !== self::canonical_genre_definition( sanitize_title( $p ) ); },
                ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/seasons', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'get_seasons' ),
            'permission_callback' => '__return_true',
            'args' => array(
                'genre' => array(
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_title',
                    'validate_callback' => function( $p ) { return null !== self::canonical_genre_definition( sanitize_title( $p ) ); },
                ),
                'series' => array(
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_title',
                    'validate_callback' => function( $p ) { return '' !== sanitize_title( $p ); },
                ),
            ),
        ) );

        register_rest_route( self::NAMESPACE, '/episodes', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array( __CLASS__, 'get_episodes' ),
            'permission_callback' => '__return_true',
            'args' => array(
                'genre' => array(
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_title',
                    'validate_callback' => function( $p ) { return null !== self::canonical_genre_definition( sanitize_title( $p ) ); },
                ),
                'series' => array(
                    'type' => 'string',
                    'required' => true,
                    'sanitize_callback' => 'sanitize_title',
                    'validate_callback' => function( $p ) { return '' !== sanitize_title( $p ); },
                ),
                'season' => array(
                    'type' => 'string',
                    'required' => false,
                    'sanitize_callback' => 'sanitize_title',
                    'validate_callback' => function( $p ) { return '' !== sanitize_title( $p ); },
                ),
                'page' => array(
                    'type' => 'integer',
                    'required' => false,
                    'default' => 1,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function( $p ) { return is_numeric( $p ) && (int) $p >= 1; },
                ),
                'per_page' => array(
                    'type' => 'integer',
                    'required' => false,
                    'default' => 25,
                    'sanitize_callback' => 'absint',
                    'validate_callback' => function( $p ) { return is_numeric( $p ) && (int) $p >= 1 && (int) $p <= 100; },
                ),
                'order' => array(
                    'type' => 'string',
                    'required' => false,
                    'default' => 'asc',
                    'sanitize_callback' => function( $p ) { return strtolower( sanitize_text_field( $p ) ); },
                    'validate_callback' => function( $p ) { return in_array( strtolower( (string) $p ), array( 'asc', 'desc' ), true ); },
                ),
            ),
        ) );
    }

    public static function get_episode( WP_REST_Request $request ) {
        $post = get_post( absint( $request->get_param( 'id' ) ) );
        if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
            return new WP_Error( 'golden_replay_episode_not_found', 'Episode not found.', array( 'status' => 404 ) );
        }
        return rest_ensure_response( self::build_episode_payload( $post ) );
    }

    public static function get_latest_episode( WP_REST_Request $request ) {
        $genre = self::canonical_genre_definition( sanitize_title( $request->get_param( 'genre' ) ) );
        if ( ! $genre ) {
            return new WP_Error( 'golden_replay_invalid_genre', 'Invalid genre.', array( 'status' => 400 ) );
        }

        $catalog = self::get_or_build_series_catalog( $genre );
        $index = self::get_or_build_episode_index( $genre );
        $ids = array();

        foreach ( $catalog as $series_item ) {
            if ( empty( $series_item['key'] ) ) { continue; }

            $series_key = $series_item['key'];
            $series_index = isset( $index[ $series_key ] ) && is_array( $index[ $series_key ] ) ? $index[ $series_key ] : array();
            $matching = isset( $series_index['matching_post_ids'] ) && is_array( $series_index['matching_post_ids'] ) ? $series_index['matching_post_ids'] : array();

            if ( isset( $series_item['match_type'] ) && 'primary' === $series_item['match_type'] ) {
                $series_ids = self::get_all_series_episode_ids( $series_item, $matching );
            } else {
                $series_ids = $matching;
            }

            $ids = array_merge( $ids, $series_ids );
        }

        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        if ( empty( $ids ) ) {
            return new WP_Error( 'golden_replay_no_episodes', 'No published episodes were found for this genre.', array( 'status' => 404 ) );
        }

        $latest_ids = get_posts( array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'post__in' => $ids,
            'posts_per_page' => 1,
            'orderby' => array( 'date' => 'DESC', 'ID' => 'DESC' ),
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
            'fields' => 'ids',
        ) );

        if ( empty( $latest_ids ) ) {
            return new WP_Error( 'golden_replay_no_episodes', 'No published episodes were found for this genre.', array( 'status' => 404 ) );
        }

        $post = get_post( (int) $latest_ids[0] );
        if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
            return new WP_Error( 'golden_replay_episode_not_found', 'Latest episode could not be loaded.', array( 'status' => 404 ) );
        }

        return rest_ensure_response( array(
            'source' => self::detect_source(),
            'genre' => array( 'name' => $genre['name'], 'slug' => $genre['slug'] ),
            'episode' => self::build_episode_payload( $post ),
        ) );
    }

    public static function get_genres() {
        $cached = self::get_catalog_option( 'grapi_genres_catalog_v2' );
        if ( null === $cached ) {
            $genres = self::build_genres_payload();
            self::set_catalog_option( 'grapi_genres_catalog_v2', $genres );
        } else {
            $genres = $cached['data'];
            self::maybe_schedule_catalog_refresh( $cached );
        }
        return rest_ensure_response( array( 'source' => self::detect_source(), 'genres' => $genres ) );
    }

    public static function get_series( WP_REST_Request $request ) {
        $genre = self::canonical_genre_definition( sanitize_title( $request->get_param( 'genre' ) ) );
        if ( ! $genre ) {
            return new WP_Error( 'golden_replay_invalid_genre', 'Invalid genre.', array( 'status' => 400 ) );
        }
        return rest_ensure_response( array(
            'source' => self::detect_source(),
            'genre' => array( 'name' => $genre['name'], 'slug' => $genre['slug'] ),
            'series' => self::get_or_build_series_catalog( $genre ),
        ) );
    }

    public static function get_seasons( WP_REST_Request $request ) {
        $genre = self::canonical_genre_definition( sanitize_title( $request->get_param( 'genre' ) ) );
        $series_key = sanitize_title( $request->get_param( 'series' ) );

        if ( ! $genre ) {
            return new WP_Error( 'golden_replay_invalid_genre', 'Invalid genre.', array( 'status' => 400 ) );
        }

        $catalog = self::get_or_build_series_catalog( $genre );
        $series_item = self::find_series_catalog_item( $catalog, $series_key );
        if ( ! $series_item ) {
            return new WP_Error( 'golden_replay_series_not_found', 'Series not found for the selected genre.', array( 'status' => 404 ) );
        }

        $index = self::get_or_build_episode_index( $genre );
        $series_index = isset( $index[ $series_key ] ) && is_array( $index[ $series_key ] ) ? $index[ $series_key ] : array();
        $matching = isset( $series_index['matching_post_ids'] ) ? $series_index['matching_post_ids'] : array();
        $mode = 'primary' === $series_item['match_type'] ? 'all_series' : 'genre_matches';
        $ids = 'all_series' === $mode ? self::get_all_series_episode_ids( $series_item, $matching ) : $matching;
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

        $parent = self::find_series_parent_category( $series_item, $ids );
        $seasons = $parent ? self::get_series_season_terms( $parent, $ids ) : array();

        return rest_ensure_response( array(
            'source' => self::detect_source(),
            'genre' => array( 'name' => $genre['name'], 'slug' => $genre['slug'] ),
            'series' => array(
                'key' => $series_item['key'],
                'name' => $series_item['name'],
                'match_type' => $series_item['match_type'],
            ),
            'selection_mode' => $mode,
            'season_parent' => $parent ? array(
                'id' => (int) $parent->term_id,
                'name' => $parent->name,
                'slug' => $parent->slug,
            ) : null,
            'seasons' => $seasons,
        ) );
    }

    public static function get_episodes( WP_REST_Request $request ) {
        $genre = self::canonical_genre_definition( sanitize_title( $request->get_param( 'genre' ) ) );
        $series_key = sanitize_title( $request->get_param( 'series' ) );

        if ( ! $genre ) {
            return new WP_Error( 'golden_replay_invalid_genre', 'Invalid genre.', array( 'status' => 400 ) );
        }

        $catalog = self::get_or_build_series_catalog( $genre );
        $series_item = self::find_series_catalog_item( $catalog, $series_key );
        if ( ! $series_item ) {
            return new WP_Error( 'golden_replay_series_not_found', 'Series not found for the selected genre.', array( 'status' => 404 ) );
        }

        $index = self::get_or_build_episode_index( $genre );
        $series_index = isset( $index[ $series_key ] ) && is_array( $index[ $series_key ] ) ? $index[ $series_key ] : array();
        $matching = isset( $series_index['matching_post_ids'] ) ? $series_index['matching_post_ids'] : array();
        $sort_values = isset( $series_index['sort_values'] ) && is_array( $series_index['sort_values'] ) ? $series_index['sort_values'] : array();

        $mode = 'primary' === $series_item['match_type'] ? 'all_series' : 'genre_matches';
        $ids = 'all_series' === $mode ? self::get_all_series_episode_ids( $series_item, $matching ) : $matching;
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );

        $season_response = null;
        $season_slug = sanitize_title( (string) $request->get_param( 'season' ) );
        if ( '' !== $season_slug ) {
            $parent = self::find_series_parent_category( $series_item, $ids );
            if ( ! $parent ) {
                return new WP_Error( 'golden_replay_season_parent_not_found', 'No season categories were found for this series.', array( 'status' => 404 ) );
            }
            $season = get_term_by( 'slug', $season_slug, 'category' );
            if ( ! $season || is_wp_error( $season ) || (int) $season->parent !== (int) $parent->term_id ) {
                return new WP_Error( 'golden_replay_season_not_found', 'Season not found for this series.', array( 'status' => 404 ) );
            }
            $season_ids = get_objects_in_term( (int) $season->term_id, 'category' );
            if ( is_wp_error( $season_ids ) ) { $season_ids = array(); }
            $season_ids = array_map( 'intval', $season_ids );
            $ids = array_values( array_intersect( $ids, $season_ids ) );
            $season_response = array( 'id' => (int) $season->term_id, 'name' => $season->name, 'slug' => $season->slug );
        }

        $order = 'desc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'desc' : 'asc';
        usort( $ids, function( $a, $b ) use ( $sort_values, $order ) {
            $a_sort = isset( $sort_values[ $a ] ) ? $sort_values[ $a ] : '';
            $b_sort = isset( $sort_values[ $b ] ) ? $sort_values[ $b ] : '';
            $cmp = strcmp( $a_sort, $b_sort );
            if ( 0 === $cmp ) { $cmp = $a <=> $b; }
            return 'desc' === $order ? -$cmp : $cmp;
        } );

        $page = max( 1, absint( $request->get_param( 'page' ) ) );
        $per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
        $total = count( $ids );
        $pages = max( 1, (int) ceil( $total / $per_page ) );
        $paged_ids = array_slice( $ids, ( $page - 1 ) * $per_page, $per_page );
        $episodes = array();
        foreach ( $paged_ids as $id ) {
            $post = get_post( $id );
            if ( $post && 'publish' === $post->post_status ) { $episodes[] = self::build_episode_payload( $post ); }
        }

        return rest_ensure_response( array(
            'source' => self::detect_source(),
            'genre' => array( 'name' => $genre['name'], 'slug' => $genre['slug'] ),
            'series' => array( 'key' => $series_item['key'], 'name' => $series_item['name'], 'match_type' => $series_item['match_type'] ),
            'selection_mode' => $mode,
            'season' => $season_response,
            'pagination' => array( 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'pages' => $pages ),
            'episodes' => $episodes,
        ) );
    }

    private static function canonical_genres() {
        return array(
            'western' => array(
                'name' => 'Western',
                'slug' => 'western',
                'aliases' => array( 'westerns', 'western-stories' ),
            ),
            'mystery' => array(
                'name' => 'Mystery',
                'slug' => 'mystery',
                'aliases' => array( 'mystery', 'mysteries' ),
            ),
            'drama' => array(
                'name' => 'Drama',
                'slug' => 'drama',
                'aliases' => array( 'drama', 'dramas' ),
            ),
            'comedy' => array(
                'name' => 'Comedy',
                'slug' => 'comedy',
                'aliases' => array( 'comedy', 'comedies' ),
            ),
            'science-fiction' => array(
                'name' => 'Science Fiction',
                'slug' => 'science-fiction',
                'aliases' => array( 'science-fiction', 'sci-fi', 'scifi' ),
            ),
            'detective' => array(
                'name' => 'Detective',
                'slug' => 'detective',
                'aliases' => array( 'detective', 'detectives', 'crime' ),
            ),
            'adventure' => array(
                'name' => 'Adventure',
                'slug' => 'adventure',
                'aliases' => array( 'adventure', 'adventures' ),
            ),
        );
    }

    private static function canonical_genre_definition( $slug ) {
        $slug = sanitize_title( $slug );
        foreach ( self::canonical_genres() as $genre ) {
            if ( $slug === $genre['slug'] || in_array( $slug, $genre['aliases'], true ) ) { return $genre; }
        }
        return null;
    }

    private static function get_or_build_series_catalog( $genre ) {
        $key = 'grapi_series_catalog_v3_' . $genre['slug'];
        $cached = self::get_catalog_option( $key );
        if ( null === $cached ) {
            $data = self::build_series_catalog( $genre );
            self::set_catalog_option( $key, $data );
            return $data;
        }
        self::maybe_schedule_catalog_refresh( $cached );
        return $cached['data'];
    }

    private static function get_or_build_episode_index( $genre ) {
        $key = 'grapi_episode_index_v3_' . $genre['slug'];
        $cached = self::get_catalog_option( $key );
        if ( null === $cached ) {
            $data = self::build_episode_index( $genre );
            self::set_catalog_option( $key, $data );
            return $data;
        }
        self::maybe_schedule_catalog_refresh( $cached );
        return $cached['data'];
    }

    private static function get_catalog_option( $key ) {
        $value = get_option( $key );
        if ( ! is_array( $value ) || ! array_key_exists( 'data', $value ) || empty( $value['built_at'] ) ) { return null; }
        return $value;
    }

    private static function set_catalog_option( $key, $data ) {
        update_option( $key, array( 'built_at' => time(), 'data' => $data ), false );
    }

    private static function maybe_schedule_catalog_refresh( $cached ) {
        if ( empty( $cached['built_at'] ) || ( time() - (int) $cached['built_at'] ) < self::CACHE_MAX_AGE ) { return; }
        if ( ! wp_next_scheduled( 'grapi_rebuild_catalog_cache' ) ) {
            wp_schedule_single_event( time() + 5, 'grapi_rebuild_catalog_cache' );
        }
    }

    public static function rebuild_catalog_cache() {
        self::clear_catalog_cache();
        self::build_genres_payload();
        foreach ( self::canonical_genres() as $genre ) {
            $series = self::build_series_catalog( $genre );
            self::set_catalog_option( 'grapi_series_catalog_v3_' . $genre['slug'], $series );
            $index = self::build_episode_index( $genre );
            self::set_catalog_option( 'grapi_episode_index_v3_' . $genre['slug'], $index );
        }
    }

    private static function clear_catalog_cache() {
        delete_option( 'grapi_genres_catalog_v2' );
        foreach ( self::canonical_genres() as $genre ) {
            delete_option( 'grapi_series_catalog_v3_' . $genre['slug'] );
            delete_option( 'grapi_episode_index_v3_' . $genre['slug'] );
        }
    }

    public static function handle_post_change( $post_id, $post, $update ) {
        if ( wp_is_post_revision( $post_id ) || 'post' !== $post->post_type ) { return; }
        self::clear_catalog_cache();
    }

    public static function handle_deleted_post( $post_id, $post ) {
        if ( $post && 'post' === $post->post_type ) { self::clear_catalog_cache(); }
    }

    public static function handle_term_change( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
        if ( in_array( $taxonomy, array( 'category', 'post_tag' ), true ) ) { self::clear_catalog_cache(); }
    }

    private static function build_genres_payload() {
        $out = array();
        foreach ( self::canonical_genres() as $genre ) {
            $catalog = self::build_series_catalog( $genre );
            $count = 0;
            foreach ( $catalog as $series ) { $count += isset( $series['episode_count'] ) ? (int) $series['episode_count'] : 0; }
            if ( $count > 0 ) {
                $out[] = array( 'name' => $genre['name'], 'slug' => $genre['slug'], 'series_count' => count( $catalog ), 'episode_count' => $count );
            }
        }
        self::set_catalog_option( 'grapi_genres_catalog_v2', $out );
        return $out;
    }

    private static function build_series_catalog( $genre ) {
        $posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
        $series = array();
        foreach ( $posts as $post_id ) {
            $match = self::match_post_to_genre( $post_id, $genre );
            if ( ! $match ) { continue; }
            $series_info = self::detect_series( $post_id, $match );
            if ( ! $series_info ) { continue; }
            $key = $series_info['key'];
            if ( ! isset( $series[$key] ) ) {
                $series[$key] = array( 'key' => $key, 'name' => $series_info['name'], 'match_type' => $match['type'], 'episode_count' => 0 );
            }
            $series[$key]['episode_count']++;
            if ( 'primary' === $match['type'] ) { $series[$key]['match_type'] = 'primary'; }
        }
        uasort( $series, function( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
        return array_values( $series );
    }

    private static function build_episode_index( $genre ) {
        $posts = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true ) );
        $index = array();
        foreach ( $posts as $post_id ) {
            $match = self::match_post_to_genre( $post_id, $genre );
            if ( ! $match ) { continue; }
            $series = self::detect_series( $post_id, $match );
            if ( ! $series ) { continue; }
            $key = $series['key'];
            if ( ! isset( $index[$key] ) ) { $index[$key] = array( 'matching_post_ids' => array(), 'sort_values' => array() ); }
            $index[$key]['matching_post_ids'][] = (int) $post_id;
            $post = get_post( $post_id );
            $index[$key]['sort_values'][$post_id] = self::episode_sort_value( $post );
        }
        return $index;
    }

    private static function match_post_to_genre( $post_id, $genre ) {
        $cats = wp_get_post_categories( $post_id, array( 'fields' => 'all' ) );
        $tags = wp_get_post_tags( $post_id );
        foreach ( $cats as $cat ) {
            if ( self::term_matches_genre( $cat, $genre ) ) { return array( 'type' => 'primary', 'term' => $cat ); }
        }
        foreach ( $tags as $tag ) {
            if ( self::term_matches_genre( $tag, $genre ) ) { return array( 'type' => 'secondary', 'term' => $tag ); }
        }
        return null;
    }

    private static function term_matches_genre( $term, $genre ) {
        $slug = sanitize_title( $term->slug );
        $name = sanitize_title( $term->name );
        return $slug === $genre['slug'] || $name === $genre['slug'] || in_array( $slug, $genre['aliases'], true ) || in_array( $name, $genre['aliases'], true );
    }

    private static function detect_series( $post_id, $genre_match ) {
        $cats = wp_get_post_categories( $post_id, array( 'fields' => 'all' ) );
        $matched_id = isset( $genre_match['term']->term_id ) ? (int) $genre_match['term']->term_id : 0;
        $candidates = array();
        foreach ( $cats as $cat ) {
            if ( (int) $cat->term_id === $matched_id ) { continue; }
            if ( 0 !== (int) $cat->parent ) { $candidates[] = $cat; }
        }
        if ( empty( $candidates ) ) {
            foreach ( $cats as $cat ) {
                if ( (int) $cat->term_id !== $matched_id ) { $candidates[] = $cat; }
            }
        }
        if ( empty( $candidates ) ) { return null; }
        usort( $candidates, function( $a, $b ) { return (int) $b->parent <=> (int) $a->parent; } );
        $cat = $candidates[0];
        $name = $cat->name;
        $key = sanitize_title( $cat->slug ? $cat->slug : $name );
        return array( 'key' => $key, 'name' => $name, 'category_id' => (int) $cat->term_id );
    }

    private static function get_all_series_episode_ids( $series_item, $fallback ) {
        $term = get_term_by( 'slug', $series_item['key'], 'category' );
        if ( ! $term || is_wp_error( $term ) ) { return $fallback; }
        $ids = get_objects_in_term( (int) $term->term_id, 'category' );
        if ( is_wp_error( $ids ) ) { return $fallback; }
        return array_values( array_unique( array_map( 'intval', $ids ) ) );
    }

    private static function find_series_catalog_item( $catalog, $key ) {
        foreach ( $catalog as $item ) { if ( isset( $item['key'] ) && $item['key'] === $key ) { return $item; } }
        return null;
    }

    private static function find_series_parent_category( $series_item, $ids ) {
        $term = get_term_by( 'slug', $series_item['key'], 'category' );
        if ( $term && ! is_wp_error( $term ) ) { return $term; }
        foreach ( $ids as $id ) {
            $cats = wp_get_post_categories( $id, array( 'fields' => 'all' ) );
            foreach ( $cats as $cat ) { if ( sanitize_title( $cat->slug ) === $series_item['key'] ) { return $cat; } }
        }
        return null;
    }

    private static function get_series_season_terms( $parent, $ids ) {
        $children = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'parent' => (int) $parent->term_id ) );
        if ( is_wp_error( $children ) ) { return array(); }
        $out = array();
        foreach ( $children as $child ) {
            $objects = get_objects_in_term( (int) $child->term_id, 'category' );
            if ( is_wp_error( $objects ) ) { continue; }
            $count = count( array_intersect( array_map( 'intval', $objects ), $ids ) );
            if ( $count ) { $out[] = array( 'id' => (int) $child->term_id, 'name' => $child->name, 'slug' => $child->slug, 'episode_count' => $count ); }
        }
        usort( $out, function( $a, $b ) { return strnatcasecmp( $a['name'], $b['name'] ); } );
        return $out;
    }

    private static function episode_sort_value( $post ) {
        $date = self::original_air_date( $post );
        if ( $date ) { return $date . '-' . sprintf( '%010d', $post->ID ); }
        return get_post_time( 'Y-m-d-H-i-s', false, $post ) . '-' . sprintf( '%010d', $post->ID );
    }

    private static function build_episode_payload( $post ) {
        $cats = wp_get_post_categories( $post->ID, array( 'fields' => 'all' ) );
        $tags = wp_get_post_tags( $post->ID );
        $genres = array();
        foreach ( self::canonical_genres() as $genre ) {
            $match = self::match_post_to_genre( $post->ID, $genre );
            if ( $match ) { $genres[] = array( 'name' => $genre['name'], 'slug' => $genre['slug'], 'source' => 'primary' === $match['type'] ? 'category' : 'tag' ); }
        }
        $primary_genre = null;
        foreach ( $genres as $genre ) { if ( 'category' === $genre['source'] ) { $primary_genre = $genre; break; } }
        if ( ! $primary_genre && ! empty( $genres ) ) { $primary_genre = $genres[0]; }
        $series = null;
        if ( $primary_genre ) {
            $definition = self::canonical_genre_definition( $primary_genre['slug'] );
            $match = $definition ? self::match_post_to_genre( $post->ID, $definition ) : null;
            $series = $match ? self::detect_series( $post->ID, $match ) : null;
        }
        $publisher_feed = self::detect_publisher_feed( $post, $cats );
        $enclosures = self::get_enclosure_candidates( $post );
        return array(
            'post_id' => (int) $post->ID,
            'web_url' => get_permalink( $post ),
            'pretty_url' => get_permalink( $post ),
            'title' => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
            'series' => $series ? array( 'id' => $series['category_id'], 'name' => $series['name'], 'slug' => $series['key'] ) : null,
            'publisher_feed' => $publisher_feed,
            'genre' => $primary_genre,
            'primary_genre' => $primary_genre,
            'episode_genres' => $genres,
            'source' => self::detect_source(),
            'original_air_date' => self::original_air_date( $post ),
            'published_date' => get_post_time( DATE_ATOM, false, $post ),
            'modified_date' => get_post_modified_time( DATE_ATOM, false, $post ),
            'description' => self::description_for_post( $post ),
            'enclosures' => $enclosures,
        );
    }

    private static function detect_publisher_feed( $post, $cats ) {
        foreach ( $cats as $cat ) {
            if ( 0 === (int) $cat->parent ) { continue; }
            $parent = get_term( (int) $cat->parent, 'category' );
            if ( $parent && ! is_wp_error( $parent ) && 0 === (int) $parent->parent ) {
                return array( 'id' => (int) $cat->term_id, 'name' => $cat->name, 'slug' => $cat->slug );
            }
        }
        return null;
    }

    private static function original_air_date( $post ) {
        $candidates = array(
            get_post_meta( $post->ID, 'original_air_date', true ),
            get_post_meta( $post->ID, '_original_air_date', true ),
            get_post_meta( $post->ID, 'air_date', true ),
        );
        foreach ( $candidates as $value ) {
            if ( ! is_string( $value ) || '' === trim( $value ) ) { continue; }
            $value = trim( $value );
            if ( preg_match( '/^(19|20)\\d{2}-\\d{2}-\\d{2}$/', $value ) ) { return $value; }
            $ts = strtotime( $value );
            if ( $ts ) { return gmdate( 'Y-m-d', $ts ); }
        }
        if ( preg_match( '/(?:^|[^0-9])((?:19|20)\\d{2})[-_\\/. ](0?[1-9]|1[0-2])[-_\\/. ](0?[1-9]|[12]\\d|3[01])(?:[^0-9]|$)/', $post->post_title, $m ) ) {
            return sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
        }
        return null;
    }

    private static function description_for_post( $post ) {
        $excerpt = trim( wp_strip_all_tags( $post->post_excerpt ) );
        if ( '' !== $excerpt ) { return $excerpt; }
        $content = trim( wp_strip_all_tags( strip_shortcodes( $post->post_content ) ) );
        return $content;
    }

    private static function detect_source() {
        return array( 'key' => sanitize_title( get_bloginfo( 'name' ) ), 'name' => get_bloginfo( 'name' ), 'site_url' => home_url( '/' ) );
    }

    private static function get_enclosure_candidates( $post ) {
        $items = array();
        $raw = get_post_meta( $post->ID, 'enclosure', false );
        foreach ( $raw as $entry ) {
            $lines = preg_split( '/\\r\\n|\\r|\\n/', (string) $entry );
            if ( empty( $lines[0] ) || ! filter_var( trim( $lines[0] ), FILTER_VALIDATE_URL ) ) { continue; }
            $items[] = array( 'url' => esc_url_raw( trim( $lines[0] ) ), 'length' => isset( $lines[1] ) ? (int) $lines[1] : null, 'type' => isset( $lines[2] ) ? trim( $lines[2] ) : null, 'source' => 'wordpress_enclosure' );
        }
        return $items;
    }
}

Golden_Replay_API::init();
