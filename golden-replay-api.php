<?php
/**
 * Plugin Name: Golden Replay API
 * Plugin URI: https://github.com/eagle4life69/golden-replay-api
 * Description: Read-only REST API for Golden Replay episode data.
 * Version: 0.1.14
 * Author: Rhynes Media LLC
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: golden-replay-api
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'GRAPI_VERSION', '0.1.14' );
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

            $season_request = self::resolve_season_request( $parent, $season_slug );
            if ( ! $season_request ) {
                return new WP_Error( 'golden_replay_season_not_found', 'Season not found for the selected series.', array( 'status' => 404 ) );
            }

            if ( ! empty( $season_request['derived'] ) ) {
                if ( ! empty( $season_request['unknown_year'] ) ) {
                    $ids = array_values( array_filter( $ids, function( $id ) {
                        return null === self::episode_original_air_year( (int) $id );
                    } ) );
                    $season_response = self::derived_year_payload( $parent, null, count( $ids ), true );
                } else {
                    $requested_year = (int) $season_request['year'];
                    $ids = array_values( array_filter( $ids, function( $id ) use ( $requested_year ) {
                        return $requested_year === self::episode_original_air_year( (int) $id );
                    } ) );
                    $season_response = self::derived_year_payload( $parent, $requested_year, count( $ids ) );
                }
            } else {
                $season_term = $season_request['term'];
                $season_post_ids = get_objects_in_term( (int) $season_term->term_id, 'category' );
                if ( is_wp_error( $season_post_ids ) ) {
                    $season_post_ids = array();
                }
                $season_post_ids = array_flip( array_map( 'intval', $season_post_ids ) );
                $ids = array_values( array_filter( $ids, function( $id ) use ( $season_post_ids ) {
                    return isset( $season_post_ids[ (int) $id ] );
                } ) );
                $season_response = self::season_term_payload( $season_term, count( $ids ) );
            }
        }

        $order = 'desc' === strtolower( (string) $request->get_param( 'order' ) ) ? 'desc' : 'asc';
        $ids = self::sort_episode_ids( $ids, $sort_values, $order );

        $page = max( 1, absint( $request->get_param( 'page' ) ) );
        $per_page = min( 100, max( 1, absint( $request->get_param( 'per_page' ) ) ) );
        $total = count( $ids );
        $page_ids = array_slice( $ids, ( $page - 1 ) * $per_page, $per_page );
        $episodes = array();

        foreach ( $page_ids as $id ) {
            $post = get_post( $id );
            if ( ! $post || 'publish' !== $post->post_status ) { continue; }
            $payload = self::build_episode_payload( $post );
            if ( 'genre_matches' === $mode && ( empty( $payload['series']['key'] ) || $series_key !== $payload['series']['key'] ) ) { continue; }
            $episodes[] = $payload;
        }

        return rest_ensure_response( array(
            'source' => self::detect_source(),
            'genre' => array( 'name' => $genre['name'], 'slug' => $genre['slug'] ),
            'series' => array(
                'key' => $series_item['key'],
                'name' => $series_item['name'],
                'match_type' => $series_item['match_type'],
            ),
            'selection_mode' => $mode,
            'season' => $season_response,
            'pagination' => array(
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => $total ? (int) ceil( $total / $per_page ) : 0,
            ),
            'episodes' => $episodes,
        ) );
    }

    private static function get_or_build_series_catalog( $genre ) {
        $key = self::series_cache_key( $genre['slug'] );
        $cached = self::get_catalog_option( $key );
        if ( null === $cached ) {
            $data = self::build_series_catalog( $genre );
            self::set_catalog_option( $key, $data );
            return $data;
        }
        self::maybe_schedule_catalog_refresh( $cached );
        return $cached['data'];
    }

    private static function find_series_catalog_item( $catalog, $key ) {
        foreach ( $catalog as $item ) {
            if ( isset( $item['key'] ) && $key === $item['key'] ) { return $item; }
        }
        return null;
    }

    private static function get_or_build_episode_index( $genre ) {
        $key = self::episode_index_cache_key( $genre['slug'] );
        $cached = self::get_catalog_option( $key );
        if ( null === $cached ) {
            $series = self::build_series_catalog( $genre );
            self::set_catalog_option( self::series_cache_key( $genre['slug'] ), $series );
            $cached = self::get_catalog_option( $key );
        }
        if ( null === $cached ) { return array(); }
        self::maybe_schedule_catalog_refresh( $cached );
        return is_array( $cached['data'] ) ? $cached['data'] : array();
    }

    private static function get_all_series_episode_ids( $series, $matching ) {
        $tag_ids = array();
        if ( ! empty( $series['source_terms'] ) ) {
            foreach ( $series['source_terms'] as $t ) {
                if ( ! empty( $t['id'] ) ) { $tag_ids[] = (int) $t['id']; }
            }
        }
        $tag_ids = array_values( array_unique( $tag_ids ) );
        $ids = $matching;
        if ( $tag_ids ) {
            $q = new WP_Query( array(
                'post_type' => 'post',
                'post_status' => 'publish',
                'fields' => 'ids',
                'posts_per_page' => -1,
                'orderby' => 'ID',
                'order' => 'ASC',
                'ignore_sticky_posts' => true,
                'no_found_rows' => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'tax_query' => array( array(
                    'taxonomy' => 'post_tag',
                    'field' => 'term_id',
                    'terms' => $tag_ids,
                    'operator' => 'IN',
                ) ),
            ) );
            $ids = array_merge( $ids, $q->posts );
        }
        return array_values( array_unique( array_map( 'intval', $ids ) ) );
    }

    private static function sort_episode_ids( $ids, $sort_values, $order ) {
        $rows = array();
        foreach ( $ids as $id ) {
            $id = (int) $id;
            $key = (string) $id;
            if ( isset( $sort_values[ $key ] ) && is_array( $sort_values[ $key ] ) ) {
                $v = $sort_values[ $key ];
                $rows[] = array(
                    'id' => $id,
                    'date' => ! empty( $v['date'] ) ? $v['date'] : null,
                    'title' => isset( $v['title'] ) ? (string) $v['title'] : '',
                );
                continue;
            }

            $post = get_post( $id );
            if ( ! $post || 'publish' !== $post->post_status ) { continue; }
            $title = self::parse_title( get_the_title( $post ) );
            $content = self::parse_content( $post->post_content );
            $air = ! empty( $content['original_air_date'] ) ? $content['original_air_date'] : $title['original_air_date'];
            $rows[] = array( 'id' => $id, 'date' => $air, 'title' => $title['episode_title'] );
        }

        usort( $rows, function( $a, $b ) use ( $order ) {
            $a_missing = empty( $a['date'] );
            $b_missing = empty( $b['date'] );
            if ( $a_missing !== $b_missing ) { return $a_missing ? 1 : -1; }
            if ( ! $a_missing ) {
                $date_cmp = strcmp( $a['date'], $b['date'] );
                if ( 0 !== $date_cmp ) { return 'desc' === $order ? -$date_cmp : $date_cmp; }
            }
            $title_cmp = strcasecmp( $a['title'], $b['title'] );
            if ( 0 !== $title_cmp ) { return $title_cmp; }
            return $a['id'] <=> $b['id'];
        } );

        return array_values( array_map( function( $r ) { return (int) $r['id']; }, $rows ) );
    }

    private static function find_series_parent_category( $series_item, $ids ) {
        $checked = array();
        $limit = 100;
        $count = 0;

        foreach ( $ids as $id ) {
            if ( $count++ >= $limit ) { break; }
            $categories = get_the_category( (int) $id );
            foreach ( $categories as $cat ) {
                $cat_id = (int) $cat->term_id;
                if ( isset( $checked[ $cat_id ] ) ) { continue; }
                $checked[ $cat_id ] = true;
                if ( self::genre_from_term( $cat, 'category' ) || self::is_season_term( $cat ) ) { continue; }
                if ( self::category_has_season_children( $cat_id ) ) { return $cat; }
            }
        }

        $candidate_labels = array( $series_item['name'], $series_item['key'] );
        if ( ! empty( $series_item['source_terms'] ) ) {
            foreach ( $series_item['source_terms'] as $term ) {
                if ( ! empty( $term['name'] ) ) { $candidate_labels[] = $term['name']; }
                if ( ! empty( $term['slug'] ) ) { $candidate_labels[] = $term['slug']; }
            }
        }

        $normalized = array();
        foreach ( $candidate_labels as $label ) {
            $n = self::normalize_series_category_label( $label );
            if ( '' !== $n ) { $normalized[ $n ] = true; }
        }

        $terms = get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false ) );
        if ( is_wp_error( $terms ) || ! is_array( $terms ) ) { return null; }

        foreach ( $terms as $term ) {
            $name_key = self::normalize_series_category_label( $term->name );
            $slug_key = self::normalize_series_category_label( $term->slug );
            if ( ! isset( $normalized[ $name_key ] ) && ! isset( $normalized[ $slug_key ] ) ) { continue; }
            if ( self::category_has_season_children( (int) $term->term_id ) ) { return $term; }
        }

        return null;
    }

    private static function normalize_series_category_label( $value ) {
        $value = self::clean_series_name( $value );
        $value = preg_replace( '/^the\\s+/i', '', $value );
        return self::normalize_taxonomy_label( $value );
    }

    private static function category_has_season_children( $parent_id ) {
        $children = get_terms( array(
            'taxonomy' => 'category',
            'parent' => (int) $parent_id,
            'hide_empty' => false,
            'number' => 20,
        ) );
        if ( is_wp_error( $children ) || ! is_array( $children ) ) { return false; }
        foreach ( $children as $child ) {
            if ( self::is_season_term( $child ) ) { return true; }
        }
        return false;
    }

    private static function is_season_term( $term ) {
        if ( ! is_object( $term ) ) { return false; }
        return (bool) preg_match( '/(?:^|[-_\\s])season[-_\\s]*(\\d{1,2}|\\d{4})$/i', (string) $term->slug )
            || (bool) preg_match( '/(?:^|[-_\\s])season[-_\\s]*(\\d{1,2}|\\d{4})$/i', (string) $term->name );
    }

    private static function season_number_from_term( $term ) {
        foreach ( array( (string) $term->slug, (string) $term->name ) as $value ) {
            if ( preg_match( '/(?:^|[-_\\s])season[-_\\s]*(\\d{1,2}|\\d{4})$/i', $value, $m ) ) {
                return $m[1];
            }
        }
        return null;
    }

    private static function season_number_is_year_code( $season_number ) {
        if ( null === $season_number ) { return false; }
        if ( 4 === strlen( (string) $season_number ) ) { return true; }
        $n = (int) $season_number;
        return $n >= 20 && $n <= 80;
    }

    private static function episode_original_air_year( $post_id ) {
        $post = get_post( (int) $post_id );
        if ( ! $post || 'publish' !== $post->post_status ) { return null; }
        $content = self::parse_content( $post->post_content );
        $title = self::parse_title( get_the_title( $post ) );
        $air = ! empty( $content['original_air_date'] ) ? $content['original_air_date'] : $title['original_air_date'];
        if ( ! is_string( $air ) || ! preg_match( '/^(\\d{4})-\\d{2}-\\d{2}$/', $air, $m ) ) { return null; }
        return (int) $m[1];
    }

    private static function season_term_payload( $term, $episode_count = null ) {
        $season_number = self::season_number_from_term( $term );
        $year = null;
        $label = $term->name;

        if ( null !== $season_number ) {
            if ( '00' === $season_number || '0000' === $season_number ) {
                $label = 'Unknown';
            } elseif ( self::season_number_is_year_code( $season_number ) ) {
                $year = 4 === strlen( (string) $season_number ) ? (int) $season_number : 1900 + (int) $season_number;
                $label = (string) $year;
            }
        }

        return array(
            'id' => (int) $term->term_id,
            'key' => $term->slug,
            'slug' => $term->slug,
            'source_name' => $term->name,
            'season' => null === $season_number ? null : (int) $season_number,
            'year' => $year,
            'label' => $label,
            'episode_count' => null === $episode_count ? (int) $term->count : (int) $episode_count,
        );
    }

    private static function derived_year_payload( $parent, $year, $episode_count, $unknown = false ) {
        $label = $unknown ? 'Unknown' : (string) (int) $year;
        $slug = 'gr-year-' . ( $unknown ? 'unknown' : (string) (int) $year );
        $id = ( (int) $parent->term_id * 10000 ) + ( $unknown ? 0 : (int) $year );

        return array(
            'id' => $id,
            'key' => $slug,
            'slug' => $slug,
            'source_name' => $parent->name,
            'season' => 0,
            'year' => $unknown ? null : (int) $year,
            'label' => $label,
            'episode_count' => (int) $episode_count,
        );
    }

    private static function parent_uses_derived_years( $parent ) {
        $terms = get_terms( array(
            'taxonomy' => 'category',
            'parent' => (int) $parent->term_id,
            'hide_empty' => false,
        ) );
        if ( is_wp_error( $terms ) || ! is_array( $terms ) ) { return false; }

        foreach ( $terms as $term ) {
            if ( ! self::is_season_term( $term ) ) { continue; }
            $season_number = self::season_number_from_term( $term );
            if ( null === $season_number || '00' === $season_number || '0000' === $season_number ) { continue; }
            if ( ! self::season_number_is_year_code( $season_number ) ) { return true; }
        }
        return false;
    }

    private static function get_series_season_terms( $parent, $series_ids ) {
        if ( self::parent_uses_derived_years( $parent ) ) {
            $year_counts = array();
            $unknown_count = 0;

            foreach ( array_values( array_unique( array_map( 'intval', $series_ids ) ) ) as $id ) {
                $year = self::episode_original_air_year( $id );
                if ( null === $year ) {
                    $unknown_count++;
                    continue;
                }
                if ( ! isset( $year_counts[ $year ] ) ) { $year_counts[ $year ] = 0; }
                $year_counts[ $year ]++;
            }

            ksort( $year_counts, SORT_NUMERIC );
            $items = array();
            foreach ( $year_counts as $year => $count ) {
                $items[] = self::derived_year_payload( $parent, (int) $year, $count );
            }
            if ( $unknown_count > 0 ) {
                $items[] = self::derived_year_payload( $parent, null, $unknown_count, true );
            }
            return $items;
        }

        $terms = get_terms( array(
            'taxonomy' => 'category',
            'parent' => (int) $parent->term_id,
            'hide_empty' => false,
        ) );
        if ( is_wp_error( $terms ) || ! is_array( $terms ) ) { return array(); }

        $series_set = array_flip( array_map( 'intval', $series_ids ) );
        $items = array();

        foreach ( $terms as $term ) {
            if ( ! self::is_season_term( $term ) ) { continue; }
            $term_ids = get_objects_in_term( (int) $term->term_id, 'category' );
            if ( is_wp_error( $term_ids ) ) { continue; }
            $count = 0;
            foreach ( $term_ids as $id ) {
                if ( isset( $series_set[ (int) $id ] ) ) { $count++; }
            }
            if ( 0 === $count ) { continue; }
            $items[] = self::season_term_payload( $term, $count );
        }

        usort( $items, function( $a, $b ) {
            $a_unknown = null === $a['year'];
            $b_unknown = null === $b['year'];
            if ( $a_unknown !== $b_unknown ) { return $a_unknown ? 1 : -1; }
            if ( ! $a_unknown && $a['year'] !== $b['year'] ) { return $a['year'] <=> $b['year']; }
            return strcasecmp( $a['source_name'], $b['source_name'] );
        } );

        return $items;
    }

    private static function find_direct_season_term( $parent, $season_slug ) {
        $term = get_term_by( 'slug', sanitize_title( $season_slug ), 'category' );
        if ( ! $term || is_wp_error( $term ) ) { return null; }
        if ( (int) $term->parent !== (int) $parent->term_id ) { return null; }
        return self::is_season_term( $term ) ? $term : null;
    }

    private static function resolve_season_request( $parent, $season_slug ) {
        if ( preg_match( '/^gr-year-(\d{4}|unknown)$/', $season_slug, $m ) ) {
            return array(
                'derived' => true,
                'term' => null,
                'year' => 'unknown' === $m[1] ? null : (int) $m[1],
                'unknown_year' => 'unknown' === $m[1],
            );
        }

        $direct = self::find_direct_season_term( $parent, $season_slug );
        if ( $direct ) {
            return array( 'derived' => false, 'term' => $direct, 'year' => null, 'unknown_year' => false );
        }

        return null;
    }

    public static function handle_post_change( $post_id, $post, $update ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { return; }
        if ( ! $post || 'post' !== $post->post_type ) { return; }
        self::invalidate_catalog_generation();
    }

    public static function handle_deleted_post( $post_id, $post ) {
        if ( $post && 'post' === $post->post_type ) { self::invalidate_catalog_generation(); }
    }

    public static function handle_term_change( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ) {
        if ( 'category' !== $taxonomy && 'post_tag' !== $taxonomy ) { return; }
        if ( 'post' !== get_post_type( $object_id ) ) { return; }
        self::invalidate_catalog_generation();
    }

    private static function invalidate_catalog_generation() {
        $g = (int) get_option( 'grapi_catalog_generation', 1 );
        update_option( 'grapi_catalog_generation', $g + 1, false );
        self::schedule_catalog_refresh();
    }

    private static function current_catalog_generation() { return max( 1, (int) get_option( 'grapi_catalog_generation', 1 ) ); }
    private static function series_cache_key( $slug ) { return 'grapi_series_catalog_v4_' . md5( sanitize_title( $slug ) ); }
    private static function episode_index_cache_key( $slug ) { return 'grapi_episode_index_v3_' . md5( sanitize_title( $slug ) ); }

    private static function get_catalog_option( $key ) {
        $v = get_option( $key, null );
        if ( ! is_array( $v ) || ! array_key_exists( 'data', $v ) || empty( $v['built_at'] ) || empty( $v['generation'] ) ) { return null; }
        return $v;
    }

    private static function set_catalog_option( $key, $data ) {
        update_option( $key, array(
            'generation' => self::current_catalog_generation(),
            'built_at' => time(),
            'data' => $data,
        ), false );
    }

    private static function maybe_schedule_catalog_refresh( $cached ) {
        if ( (int) $cached['generation'] !== self::current_catalog_generation() || ( time() - (int) $cached['built_at'] ) >= self::CACHE_MAX_AGE ) {
            self::schedule_catalog_refresh();
        }
    }

    private static function schedule_catalog_refresh() {
        if ( ! wp_next_scheduled( 'grapi_rebuild_catalog_cache' ) ) {
            wp_schedule_single_event( time() + 15, 'grapi_rebuild_catalog_cache' );
        }
    }

    public static function rebuild_catalog_cache() {
        if ( get_transient( 'grapi_catalog_rebuild_lock' ) ) { return; }
        set_transient( 'grapi_catalog_rebuild_lock', 1, 10 * MINUTE_IN_SECONDS );
        self::set_catalog_option( 'grapi_genres_catalog_v2', self::build_genres_payload() );
        $seen = array();
        foreach ( self::genre_definitions() as $def ) {
            if ( isset( $seen[ $def['slug'] ] ) ) { continue; }
            $seen[ $def['slug'] ] = true;
            $genre = self::canonical_genre_definition( $def['slug'] );
            if ( ! $genre ) { continue; }
            self::set_catalog_option( self::series_cache_key( $genre['slug'] ), self::build_series_catalog( $genre ) );
        }
        delete_transient( 'grapi_catalog_rebuild_lock' );
    }

    private static function build_episode_payload( WP_Post $post ) {
        $title = self::parse_title( get_the_title( $post ) );
        $content = self::parse_content( $post->post_content );
        $enc = self::parse_enclosure( get_post_meta( $post->ID, 'enclosure', true ) );
        $series = self::build_series_from_show( $content['show'], $post );
        $primary = self::detect_primary_genre( $post );
        $genres = self::detect_episode_genres( $post, $primary );
        $air = ! empty( $content['original_air_date'] ) ? $content['original_air_date'] : $title['original_air_date'];

        return array(
            'post_id' => (int) $post->ID,
            'web_url' => add_query_arg( 'p', (int) $post->ID, home_url( '/' ) ),
            'pretty_url' => get_permalink( $post ),
            'title' => $title['episode_title'],
            'series' => $series,
            'publisher_feed' => self::detect_publisher_feed( $post ),
            'genre' => $primary,
            'primary_genre' => $primary,
            'episode_genres' => $genres,
            'source' => self::detect_source(),
            'original_air_date' => $air,
            'published_date' => get_post_time( DATE_ATOM, false, $post ),
            'modified_date' => get_post_modified_time( DATE_ATOM, false, $post ),
            'description' => ! empty( $content['description'] ) ? $content['description'] : null,
            'duration_seconds' => $enc['duration_seconds'],
            'duration_display' => $enc['duration_display'],
            'file_size_bytes' => $enc['file_size_bytes'],
            'file_size_display' => $enc['file_size_display'],
            'audio' => array(
                'provider' => $enc['provider'],
                'episode_id' => $enc['episode_id'],
                'stream_url' => $enc['stream_url'],
                'download_url' => $enc['download_url'],
            ),
            'credits' => $content['credits'],
            'availability' => array( 'status' => 'published', 'available' => true, 'scheduled_for' => null ),
        );
    }

    private static function parse_title( $raw ) {
        $title = html_entity_decode( wp_strip_all_tags( (string) $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $title = preg_replace( '/\\s+/u', ' ', trim( $title ) );
        $date = null;
        if ( preg_match( '/\\((\\d{2})-(\\d{2})-(\\d{2})\\)\\s*$/', $title, $m ) ) {
            $y = (int) $m[3];
            $y = $y >= 30 ? 1900 + $y : 2000 + $y;
            $date = sprintf( '%04d-%02d-%02d', $y, (int) $m[1], (int) $m[2] );
            $title = trim( preg_replace( '/\\s*\\(\\d{2}-\\d{2}-\\d{2}\\)\\s*$/', '', $title ) );
        }
        $parts = preg_split( '/\\s*[\\|\\x{2013}\\x{2014}]\\s*/u', $title, 2 );
        return array( 'episode_title' => ! empty( $parts[0] ) ? trim( $parts[0] ) : $title, 'original_air_date' => $date );
    }

    private static function parse_content( $content ) {
        $text = wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />' ), "\n", (string) $content ) );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( "/\\r\\n?|\\x{2028}|\\x{2029}/u", "\n", $text );
        $lines = array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ), 'strlen' ) );
        $r = array( 'description' => null, 'original_air_date' => null, 'show' => null, 'credits' => array() );

        // Existing show notes place an optional episode description before
        // "Original Air Date:". Capture that leading text without requiring
        // a visible "Description:" label in the WordPress post.
        foreach ( $lines as $index => $line ) {
            if ( 0 !== stripos( $line, 'Original Air Date:' ) ) { continue; }
            if ( $index > 0 ) {
                $description_lines = array_slice( $lines, 0, $index );
                if ( $description_lines && 0 !== stripos( $description_lines[0], 'Description:' ) ) {
                    $description = sanitize_textarea_field( implode( "\n", $description_lines ) );
                    if ( '' !== $description ) { $r['description'] = $description; }
                }
            }
            break;
        }

        $types = array(
            'Stars:' => 'star', 'Star:' => 'star', 'Special Guests:' => 'special_guest', 'Special Guest:' => 'special_guest',
            'Writer:' => 'writer', 'Writers:' => 'writer', 'Producer:' => 'producer', 'Producers:' => 'producer',
            'Director:' => 'director', 'Directors:' => 'director', 'Music:' => 'music', 'Announcer:' => 'announcer', 'Narrator:' => 'narrator',
        );
        $active = null;
        foreach ( $lines as $line ) {
            if ( 0 === stripos( $line, 'Description:' ) ) { $r['description'] = trim( substr( $line, strlen( 'Description:' ) ) ); $active = null; continue; }
            if ( 0 === stripos( $line, 'Original Air Date:' ) ) { $d = trim( substr( $line, strlen( 'Original Air Date:' ) ) ); $t = strtotime( $d ); if ( $t ) { $r['original_air_date'] = gmdate( 'Y-m-d', $t ); } $active = null; continue; }
            if ( 0 === stripos( $line, 'Show:' ) ) { $r['show'] = trim( substr( $line, strlen( 'Show:' ) ) ); $active = null; continue; }
            if ( isset( $types[ $line ] ) ) { $active = $types[ $line ]; continue; }
            if ( preg_match( '/^[A-Za-z][A-Za-z ]+:$/', $line ) ) { $active = null; continue; }
            if ( $active && preg_match( '/^[\\x{2022}\\-*]\\s*(.+)$/u', $line, $m ) ) {
                $c = self::parse_credit_line( $active, trim( $m[1] ) );
                if ( $c ) { $r['credits'][] = $c; }
            }
        }
        return $r;
    }

    private static function parse_credit_line( $type, $line ) {
        $line = trim( str_replace( '_', ' ', $line ) );
        if ( '' === $line || '.' === $line || '-' === $line ) { return null; }
        $name = $line;
        $role = null;
        if ( preg_match( '/^(.+?)\\s*\\(([^()]*)\\)\\s*$/u', $line, $m ) ) { $name = trim( $m[1] ); $role = trim( $m[2] ); }
        return array( 'type' => sanitize_key( $type ), 'name' => sanitize_text_field( $name ), 'role' => null !== $role && '' !== $role ? sanitize_text_field( $role ) : null );
    }

    private static function parse_enclosure( $meta ) {
        $r = array( 'provider' => null, 'episode_id' => null, 'stream_url' => null, 'download_url' => null, 'duration_seconds' => null, 'duration_display' => null, 'file_size_bytes' => null, 'file_size_display' => null );
        if ( ! is_string( $meta ) || '' === trim( $meta ) ) { return $r; }
        $lines = preg_split( '/\\r\\n|\\r|\\n/', $meta );
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line || false === strpos( $line, 'download.mp3' ) ) { continue; }
            $url = wp_http_validate_url( $line );
            if ( ! $url ) { continue; }
            $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
            if ( 'api.spreaker.com' !== $host ) { continue; }
            if ( preg_match( '#/episodes/(\\d+)/download\\.mp3(?:\\?.*)?$#', $url, $m ) ) {
                $r['provider'] = 'spreaker';
                $r['episode_id'] = $m[1];
                $r['stream_url'] = $url;
                $r['download_url'] = $url;
                break;
            }
        }
        if ( isset( $lines[1] ) && ctype_digit( trim( $lines[1] ) ) ) {
            $b = (int) trim( $lines[1] );
            if ( $b > 0 ) { $r['file_size_bytes'] = $b; $r['file_size_display'] = size_format( $b, 2 ); }
        }
        $extra = trim( (string) end( $lines ) );
        if ( is_serialized( $extra ) ) {
            $u = maybe_unserialize( $extra );
            if ( is_array( $u ) && ! empty( $u['duration'] ) ) {
                $s = self::duration_to_seconds( $u['duration'] );
                if ( null !== $s ) { $r['duration_seconds'] = $s; $r['duration_display'] = self::format_duration( $s ); }
            }
        }
        return $r;
    }

    private static function duration_to_seconds( $d ) {
        $d = trim( (string) $d );
        if ( '' === $d ) { return null; }
        if ( ctype_digit( $d ) ) { return (int) $d; }
        $p = array_map( 'intval', explode( ':', $d ) );
        if ( 2 === count( $p ) ) { return $p[0] * 60 + $p[1]; }
        if ( 3 === count( $p ) ) { return $p[0] * 3600 + $p[1] * 60 + $p[2]; }
        return null;
    }

    private static function format_duration( $s ) {
        $s = max( 0, (int) $s );
        $h = intdiv( $s, 3600 );
        $m = intdiv( $s % 3600, 60 );
        $x = $s % 60;
        return $h > 0 ? sprintf( '%d:%02d:%02d', $h, $m, $x ) : sprintf( '%d:%02d', $m, $x );
    }

    private static function clean_series_name( $show ) {
        $show = html_entity_decode( wp_strip_all_tags( (string) $show ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $show = preg_replace( '/[\\x{00A0}\\x{2000}-\\x{200B}\\x{202F}\\x{205F}\\x{3000}]+/u', ' ', $show );
        $show = preg_replace( '/\\s+/u', ' ', trim( $show ) );
        while ( 0 === stripos( $show, 'Show:' ) ) {
            $show = preg_replace( '/^Show:\\s*/i', '', $show, 1 );
            $show = preg_replace( '/\\s+/u', ' ', trim( $show ) );
        }
        return sanitize_text_field( $show );
    }

    private static function series_aliases() {
        return array(
            'lone-ranger' => array( 'name' => 'The Lone Ranger', 'key' => 'the-lone-ranger' ),
            'the-lone-ranger' => array( 'name' => 'The Lone Ranger', 'key' => 'the-lone-ranger' ),
            'wild-bill-hickok' => array( 'name' => 'Adventures of Wild Bill Hickok', 'key' => 'adventures-of-wild-bill-hickok' ),
            'adventures-of-wild-bill-hickok' => array( 'name' => 'Adventures of Wild Bill Hickok', 'key' => 'adventures-of-wild-bill-hickok' ),
            'grand-old-opry' => array( 'name' => 'Grand Ole Opry', 'key' => 'grand-ole-opry' ),
            'grand-ole-opry' => array( 'name' => 'Grand Ole Opry', 'key' => 'grand-ole-opry' ),
        );
    }

    private static function canonicalize_series( $show ) {
        $clean = self::clean_series_name( $show );
        if ( '' === $clean ) { return null; }
        $slug = sanitize_title( $clean );
        $aliases = self::series_aliases();
        if ( isset( $aliases[ $slug ] ) ) {
            return array( 'name' => $aliases[ $slug ]['name'], 'key' => $aliases[ $slug ]['key'], 'slug' => $aliases[ $slug ]['key'] );
        }
        return array( 'name' => $clean, 'key' => $slug, 'slug' => $slug );
    }

    private static function build_series_from_show( $show, WP_Post $post ) {
        $clean = self::clean_series_name( $show );
        $canonical = self::canonicalize_series( $clean );
        if ( ! $canonical ) { return null; }

        $source_id = null;
        $source_name = $clean;
        $source_slug = sanitize_title( $clean );
        $key = self::normalize_taxonomy_label( $clean );
        $canonical_key = self::normalize_taxonomy_label( $canonical['name'] );
        $tags = get_the_tags( $post->ID );
        if ( $tags ) {
            foreach ( $tags as $tag ) {
                $nk = self::normalize_taxonomy_label( $tag->name );
                $sk = self::normalize_taxonomy_label( $tag->slug );
                if ( $key === $nk || $key === $sk || $canonical_key === $nk || $canonical_key === $sk ) {
                    $source_id = (int) $tag->term_id;
                    $source_name = $tag->name;
                    $source_slug = $tag->slug;
                    break;
                }
            }
        }
        return array(
            'id' => $source_id,
            'key' => $canonical['key'],
            'name' => $canonical['name'],
            'slug' => $source_slug,
            'source_name' => $source_name,
            'source_slug' => $source_slug,
        );
    }

    private static function normalize_taxonomy_label( $v ) {
        $v = self::clean_series_name( $v );
        $v = str_replace( array( '_', '-' ), ' ', $v );
        $v = strtolower( $v );
        return (string) preg_replace( '/[^a-z0-9]+/', '', $v );
    }

    private static function genre_definitions() {
        return array(
            'western-podcast' => array( 'name' => 'Westerns', 'slug' => 'westerns' ),
            'western' => array( 'name' => 'Westerns', 'slug' => 'westerns' ),
            'westerns' => array( 'name' => 'Westerns', 'slug' => 'westerns' ),
            'mystery' => array( 'name' => 'Mystery', 'slug' => 'mystery' ),
            'drama' => array( 'name' => 'Drama', 'slug' => 'drama' ),
            'comedy' => array( 'name' => 'Comedy', 'slug' => 'comedy' ),
            'crime' => array( 'name' => 'Crime', 'slug' => 'crime' ),
            'detective' => array( 'name' => 'Detective', 'slug' => 'detective' ),
            'adventure' => array( 'name' => 'Adventure', 'slug' => 'adventure' ),
            'horror' => array( 'name' => 'Horror', 'slug' => 'horror' ),
            'sci-fi' => array( 'name' => 'Science Fiction', 'slug' => 'science-fiction' ),
            'science-fiction' => array( 'name' => 'Science Fiction', 'slug' => 'science-fiction' ),
        );
    }

    private static function canonical_genre_definition( $slug ) {
        foreach ( self::genre_definitions() as $term => $def ) {
            if ( $slug === $def['slug'] ) {
                return array( 'name' => $def['name'], 'slug' => $def['slug'], 'aliases' => self::genre_aliases( $def['slug'] ) );
            }
        }
        return null;
    }

    private static function genre_aliases( $slug ) {
        $a = array();
        foreach ( self::genre_definitions() as $term => $def ) {
            if ( $slug === $def['slug'] ) { $a[] = $term; }
        }
        return array_values( array_unique( $a ) );
    }

    private static function build_genres_payload() {
        $canonical = array();
        $genres = array();
        foreach ( self::genre_definitions() as $term => $def ) {
            $slug = $def['slug'];
            if ( ! isset( $canonical[ $slug ] ) ) { $canonical[ $slug ] = array( 'name' => $def['name'], 'slug' => $slug, 'aliases' => array() ); }
            $canonical[ $slug ]['aliases'][] = $term;
        }
        foreach ( $canonical as $g ) {
            $cats = self::get_genre_term_ids( 'category', $g['aliases'] );
            $tags = self::get_genre_term_ids( 'post_tag', $g['aliases'] );
            $primary = self::count_genre_posts( $cats, array() );
            $count = self::count_genre_posts( $cats, $tags );
            if ( ! $count ) { continue; }
            $genres[] = array( 'name' => $g['name'], 'slug' => $g['slug'], 'episode_count' => $count, 'primary_episode_count' => $primary );
        }
        return $genres;
    }

    private static function get_genre_term_ids( $taxonomy, $aliases ) {
        $ids = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => true, 'slug' => array_values( array_unique( $aliases ) ), 'fields' => 'ids' ) );
        return is_wp_error( $ids ) || ! is_array( $ids ) ? array() : array_values( array_map( 'intval', $ids ) );
    }

    private static function genre_tax_query( $genre, $categories = true, $tags = true ) {
        $parts = array();
        if ( $categories ) {
            $ids = self::get_genre_term_ids( 'category', $genre['aliases'] );
            if ( $ids ) { $parts[] = array( 'taxonomy' => 'category', 'field' => 'term_id', 'terms' => $ids, 'operator' => 'IN' ); }
        }
        if ( $tags ) {
            $ids = self::get_genre_term_ids( 'post_tag', $genre['aliases'] );
            if ( $ids ) { $parts[] = array( 'taxonomy' => 'post_tag', 'field' => 'term_id', 'terms' => $ids, 'operator' => 'IN' ); }
        }
        if ( count( $parts ) > 1 ) { return array_merge( array( 'relation' => 'OR' ), $parts ); }
        return $parts;
    }

    private static function count_genre_posts( $cats, $tags ) {
        $q = array();
        if ( $cats ) { $q[] = array( 'taxonomy' => 'category', 'field' => 'term_id', 'terms' => array_map( 'intval', $cats ), 'operator' => 'IN' ); }
        if ( $tags ) { $q[] = array( 'taxonomy' => 'post_tag', 'field' => 'term_id', 'terms' => array_map( 'intval', $tags ), 'operator' => 'IN' ); }
        if ( ! $q ) { return 0; }
        if ( count( $q ) > 1 ) { $q = array_merge( array( 'relation' => 'OR' ), $q ); }
        $query = new WP_Query( array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => 1,
            'orderby' => 'none',
            'ignore_sticky_posts' => true,
            'no_found_rows' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'tax_query' => $q,
        ) );
        return (int) $query->found_posts;
    }

    private static function build_series_catalog( $genre ) {
        $tax = self::genre_tax_query( $genre, true, true );
        if ( empty( $tax ) ) {
            self::set_catalog_option( self::episode_index_cache_key( $genre['slug'] ), array() );
            return array();
        }

        $query = new WP_Query( array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
            'update_post_meta_cache' => false,
            'tax_query' => $tax,
        ) );

        $series = array();
        $index = array();

        foreach ( $query->posts as $id ) {
            $post = get_post( $id );
            if ( ! $post ) { continue; }
            $content = self::parse_content( $post->post_content );
            $title = self::parse_title( get_the_title( $post ) );
            $air = ! empty( $content['original_air_date'] ) ? $content['original_air_date'] : $title['original_air_date'];
            $s = self::build_series_from_show( $content['show'], $post );
            if ( ! $s || empty( $s['key'] ) ) { continue; }

            $key = $s['key'];
            $primary = self::detect_primary_genre( $post );
            $is_primary = is_array( $primary ) && isset( $primary['slug'] ) && $genre['slug'] === $primary['slug'];

            if ( ! isset( $series[ $key ] ) ) {
                $series[ $key ] = array(
                    'id' => $s['id'],
                    'key' => $key,
                    'name' => $s['name'],
                    'slug' => $key,
                    'source_slug' => $s['source_slug'],
                    'source_terms' => array(),
                    'match_type' => $is_primary ? 'primary' : 'episode',
                    'matching_episode_count' => 0,
                    'primary_episode_count' => 0,
                );
            }

            $tk = (string) $s['id'] . '|' . $s['source_slug'];
            if ( ! isset( $series[ $key ]['source_terms'][ $tk ] ) ) {
                $series[ $key ]['source_terms'][ $tk ] = array( 'id' => $s['id'], 'name' => $s['source_name'], 'slug' => $s['source_slug'] );
            }

            $series[ $key ]['matching_episode_count']++;
            if ( $is_primary ) {
                $series[ $key ]['primary_episode_count']++;
                $series[ $key ]['match_type'] = 'primary';
            }
            if ( null === $series[ $key ]['id'] && null !== $s['id'] ) {
                $series[ $key ]['id'] = $s['id'];
                $series[ $key ]['source_slug'] = $s['source_slug'];
            }

            if ( ! isset( $index[ $key ] ) ) {
                $index[ $key ] = array( 'matching_post_ids' => array(), 'primary_post_ids' => array(), 'sort_values' => array() );
            }
            $index[ $key ]['matching_post_ids'][] = (int) $id;
            if ( $is_primary ) { $index[ $key ]['primary_post_ids'][] = (int) $id; }
            $index[ $key ]['sort_values'][ (string) $id ] = array( 'date' => $air, 'title' => $title['episode_title'] );
        }

        foreach ( $series as $key => &$item ) {
            $item['source_terms'] = array_values( $item['source_terms'] );
            if ( 'primary' === $item['match_type'] ) {
                $matching_ids = isset( $index[ $key ]['matching_post_ids'] ) ? $index[ $key ]['matching_post_ids'] : array();
                $item['matching_episode_count'] = count( self::get_all_series_episode_ids( $item, $matching_ids ) );
            }
        }
        unset( $item );
        uasort( $series, function( $a, $b ) { return strcasecmp( $a['name'], $b['name'] ); } );
        self::set_catalog_option( self::episode_index_cache_key( $genre['slug'] ), $index );
        return array_values( $series );
    }

    private static function genre_from_term( $term, $source ) {
        $defs = self::genre_definitions();
        $slug = strtolower( (string) $term->slug );
        if ( ! isset( $defs[ $slug ] ) ) { return null; }
        return array( 'id' => (int) $term->term_id, 'name' => $defs[ $slug ]['name'], 'slug' => $defs[ $slug ]['slug'], 'source' => $source );
    }

    private static function detect_primary_genre( WP_Post $post ) {
        foreach ( get_the_category( $post->ID ) as $cat ) {
            $g = self::genre_from_term( $cat, 'category' );
            if ( $g ) { return $g; }
        }
        return null;
    }

    private static function detect_episode_genres( WP_Post $post, $primary ) {
        $genres = array();
        $seen = array();
        if ( is_array( $primary ) && ! empty( $primary['slug'] ) ) {
            $genres[] = $primary;
            $seen[ $primary['slug'] ] = true;
        }
        $tags = get_the_tags( $post->ID );
        if ( $tags ) {
            foreach ( $tags as $tag ) {
                $g = self::genre_from_term( $tag, 'tag' );
                if ( ! $g || isset( $seen[ $g['slug'] ] ) ) { continue; }
                $genres[] = $g;
                $seen[ $g['slug'] ] = true;
            }
        }
        return $genres;
    }

    private static function detect_publisher_feed( WP_Post $post ) {
        foreach ( get_the_category( $post->ID ) as $cat ) {
            $slug = (string) $cat->slug;
            if ( preg_match( '/-season-\\d+$/', $slug ) || self::genre_from_term( $cat, 'category' ) ) { continue; }
            return array( 'id' => (int) $cat->term_id, 'name' => $cat->name, 'slug' => $cat->slug );
        }
        return null;
    }

    private static function detect_source() {
        $url = home_url( '/' );
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $host = preg_replace( '/^www\\./', '', $host );
        $known = array(
            'otrwesterns.com' => array( 'key' => 'otrwesterns', 'name' => 'Old Time Radio Westerns' ),
            'otnetcast.com' => array( 'key' => 'otnetcast', 'name' => 'Old Time Radio Netcast' ),
        );
        if ( isset( $known[ $host ] ) ) {
            $key = $known[ $host ]['key'];
            $name = $known[ $host ]['name'];
        } else {
            $key = sanitize_title( $host );
            $name = get_bloginfo( 'name' );
        }
        return array( 'key' => $key, 'name' => sanitize_text_field( $name ), 'site_url' => esc_url_raw( $url ) );
    }
}

Golden_Replay_API::init();
