<?php
/**
 * Plugin Name: Golden Replay API
 * Plugin URI: https://github.com/eagle4life69/golden-replay-api
 * Description: Read-only REST API for Golden Replay episode data.
 * Version: 0.1.4
 * Author: Rhynes Media LLC
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: golden-replay-api
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GRAPI_VERSION', '0.1.4' );
define( 'GRAPI_PLUGIN_FILE', __FILE__ );

$grapi_updater = plugin_dir_path( __FILE__ ) . 'github-updater.php';
if ( file_exists( $grapi_updater ) ) {
    require_once $grapi_updater;
}

final class Golden_Replay_API {
    const VERSION   = GRAPI_VERSION;
    const NAMESPACE = 'golden-replay/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    public static function register_routes() {
        register_rest_route(
            self::NAMESPACE,
            '/episode/(?P<id>\d+)',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'get_episode' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'id' => array(
                        'description'       => 'WordPress post ID for the episode.',
                        'type'              => 'integer',
                        'required'          => true,
                        'sanitize_callback' => 'absint',
                        'validate_callback' => function ( $param ) {
                            return is_numeric( $param ) && (int) $param > 0;
                        },
                    ),
                ),
            )
        );

        register_rest_route(
            self::NAMESPACE,
            '/genres',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( __CLASS__, 'get_genres' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    public static function get_episode( WP_REST_Request $request ) {
        $post_id = absint( $request->get_param( 'id' ) );
        $post    = get_post( $post_id );

        if ( ! $post || 'post' !== $post->post_type || 'publish' !== $post->post_status ) {
            return new WP_Error(
                'golden_replay_episode_not_found',
                'Episode not found.',
                array( 'status' => 404 )
            );
        }

        return rest_ensure_response( self::build_episode_payload( $post ) );
    }

    public static function get_genres() {
        $cache_key = 'grapi_genres_v1';
        $genres    = get_transient( $cache_key );

        if ( false === $genres ) {
            $genres = self::build_genres_payload();
            set_transient( $cache_key, $genres, 5 * MINUTE_IN_SECONDS );
        }

        return rest_ensure_response(
            array(
                'source' => self::detect_source(),
                'genres' => $genres,
            )
        );
    }

    private static function build_episode_payload( WP_Post $post ) {
        $title_data          = self::parse_title( get_the_title( $post ) );
        $content_data        = self::parse_content( $post->post_content );
        $enclosure_data      = self::parse_enclosure( get_post_meta( $post->ID, 'enclosure', true ) );
        $series_data         = self::build_series_from_show( $content_data['show'], $post );
        $primary_genre_data  = self::detect_primary_genre( $post );
        $episode_genres      = self::detect_episode_genres( $post, $primary_genre_data );
        $publisher_feed_data = self::detect_publisher_feed( $post );
        $source_data         = self::detect_source();

        $original_air_date = ! empty( $content_data['original_air_date'] )
            ? $content_data['original_air_date']
            : $title_data['original_air_date'];

        return array(
            'post_id'           => (int) $post->ID,
            'web_url'           => add_query_arg( 'p', (int) $post->ID, home_url( '/' ) ),
            'pretty_url'        => get_permalink( $post ),
            'title'             => $title_data['episode_title'],
            'series'            => $series_data,
            'publisher_feed'    => $publisher_feed_data,
            'genre'             => $primary_genre_data,
            'primary_genre'     => $primary_genre_data,
            'episode_genres'    => $episode_genres,
            'source'            => $source_data,
            'original_air_date' => $original_air_date,
            'published_date'    => get_post_time( DATE_ATOM, false, $post ),
            'modified_date'     => get_post_modified_time( DATE_ATOM, false, $post ),
            'description'       => ! empty( $content_data['description'] ) ? $content_data['description'] : null,
            'duration_seconds'  => $enclosure_data['duration_seconds'],
            'duration_display'  => $enclosure_data['duration_display'],
            'file_size_bytes'   => $enclosure_data['file_size_bytes'],
            'file_size_display' => $enclosure_data['file_size_display'],
            'audio'             => array(
                'provider'     => $enclosure_data['provider'],
                'episode_id'   => $enclosure_data['episode_id'],
                'stream_url'   => $enclosure_data['stream_url'],
                'download_url' => $enclosure_data['download_url'],
            ),
            'credits'           => $content_data['credits'],
            'availability'      => array(
                'status'        => 'published',
                'available'     => true,
                'scheduled_for' => null,
            ),
        );
    }

    private static function parse_title( $raw_title ) {
        $title = html_entity_decode( wp_strip_all_tags( (string) $raw_title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $title = preg_replace( '/\s+/u', ' ', trim( $title ) );

        $original_air_date = null;
        if ( preg_match( '/\((\d{2})-(\d{2})-(\d{2})\)\s*$/', $title, $m ) ) {
            $year = (int) $m[3];
            $year = $year >= 30 ? 1900 + $year : 2000 + $year;
            $original_air_date = sprintf( '%04d-%02d-%02d', $year, (int) $m[1], (int) $m[2] );
            $title = trim( preg_replace( '/\s*\(\d{2}-\d{2}-\d{2}\)\s*$/', '', $title ) );
        }

        $parts = preg_split( '/\s*[\|\x{2013}\x{2014}]\s*/u', $title, 2 );

        return array(
            'episode_title'     => ! empty( $parts[0] ) ? trim( $parts[0] ) : $title,
            'original_air_date' => $original_air_date,
        );
    }

    private static function parse_content( $content ) {
        $text = wp_strip_all_tags( str_replace( array( '<br>', '<br/>', '<br />' ), "\n", (string) $content ) );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = preg_replace( "/\r\n?|\x{2028}|\x{2029}/u", "\n", $text );
        $lines = array_values( array_filter( array_map( 'trim', explode( "\n", $text ) ), 'strlen' ) );

        $result = array(
            'description'       => null,
            'original_air_date' => null,
            'show'              => null,
            'credits'           => array(),
        );

        $credit_types = array(
            'Stars:'          => 'star',
            'Star:'           => 'star',
            'Special Guests:' => 'special_guest',
            'Special Guest:'  => 'special_guest',
            'Writer:'         => 'writer',
            'Writers:'        => 'writer',
            'Producer:'       => 'producer',
            'Producers:'      => 'producer',
            'Director:'       => 'director',
            'Directors:'      => 'director',
            'Music:'          => 'music',
            'Announcer:'      => 'announcer',
            'Narrator:'       => 'narrator',
        );

        $active_credit_type = null;

        foreach ( $lines as $line ) {
            if ( 0 === stripos( $line, 'Description:' ) ) {
                $result['description'] = trim( substr( $line, strlen( 'Description:' ) ) );
                $active_credit_type = null;
                continue;
            }

            if ( 0 === stripos( $line, 'Original Air Date:' ) ) {
                $date_text = trim( substr( $line, strlen( 'Original Air Date:' ) ) );
                $timestamp = strtotime( $date_text );
                if ( $timestamp ) {
                    $result['original_air_date'] = gmdate( 'Y-m-d', $timestamp );
                }
                $active_credit_type = null;
                continue;
            }

            if ( 0 === stripos( $line, 'Show:' ) ) {
                $result['show'] = trim( substr( $line, strlen( 'Show:' ) ) );
                $active_credit_type = null;
                continue;
            }

            if ( isset( $credit_types[ $line ] ) ) {
                $active_credit_type = $credit_types[ $line ];
                continue;
            }

            if ( preg_match( '/^[A-Za-z][A-Za-z ]+:$/', $line ) ) {
                $active_credit_type = null;
                continue;
            }

            if ( $active_credit_type && preg_match( '/^[\x{2022}\-*]\s*(.+)$/u', $line, $m ) ) {
                $credit = self::parse_credit_line( $active_credit_type, trim( $m[1] ) );
                if ( $credit ) {
                    $result['credits'][] = $credit;
                }
            }
        }

        return $result;
    }

    private static function parse_credit_line( $type, $line ) {
        $line = trim( str_replace( '_', ' ', $line ) );
        if ( '' === $line || '.' === $line || '-' === $line ) {
            return null;
        }

        $name = $line;
        $role = null;

        if ( preg_match( '/^(.+?)\s*\(([^()]*)\)\s*$/u', $line, $m ) ) {
            $name = trim( $m[1] );
            $role = trim( $m[2] );
        }

        return array(
            'type' => sanitize_key( $type ),
            'name' => sanitize_text_field( $name ),
            'role' => null !== $role && '' !== $role ? sanitize_text_field( $role ) : null,
        );
    }

    private static function parse_enclosure( $meta ) {
        $result = array(
            'provider'          => null,
            'episode_id'        => null,
            'stream_url'        => null,
            'download_url'      => null,
            'duration_seconds'  => null,
            'duration_display'  => null,
            'file_size_bytes'   => null,
            'file_size_display' => null,
        );

        if ( ! is_string( $meta ) || '' === trim( $meta ) ) {
            return $result;
        }

        $lines = preg_split( '/\r\n|\r|\n/', $meta );

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( '' === $line || false === strpos( $line, 'download.mp3' ) ) {
                continue;
            }

            $validated_url = wp_http_validate_url( $line );
            if ( ! $validated_url ) {
                continue;
            }

            $host = strtolower( (string) wp_parse_url( $validated_url, PHP_URL_HOST ) );
            if ( 'api.spreaker.com' !== $host ) {
                continue;
            }

            if ( preg_match( '#/episodes/(\d+)/download\.mp3(?:\?.*)?$#', $validated_url, $m ) ) {
                $result['provider']     = 'spreaker';
                $result['episode_id']   = $m[1];
                $result['stream_url']   = $validated_url;
                $result['download_url'] = $validated_url;
                break;
            }
        }

        if ( isset( $lines[1] ) && ctype_digit( trim( $lines[1] ) ) ) {
            $bytes = (int) trim( $lines[1] );
            if ( $bytes > 0 ) {
                $result['file_size_bytes']   = $bytes;
                $result['file_size_display'] = size_format( $bytes, 2 );
            }
        }

        $extra = trim( (string) end( $lines ) );
        if ( is_serialized( $extra ) ) {
            $unserialized = maybe_unserialize( $extra );
            if ( is_array( $unserialized ) && ! empty( $unserialized['duration'] ) ) {
                $seconds = self::duration_to_seconds( $unserialized['duration'] );
                if ( null !== $seconds ) {
                    $result['duration_seconds'] = $seconds;
                    $result['duration_display'] = self::format_duration( $seconds );
                }
            }
        }

        return $result;
    }

    private static function duration_to_seconds( $duration ) {
        $duration = trim( (string) $duration );
        if ( '' === $duration ) {
            return null;
        }

        if ( ctype_digit( $duration ) ) {
            return (int) $duration;
        }

        $parts = array_map( 'intval', explode( ':', $duration ) );
        if ( 2 === count( $parts ) ) {
            return ( $parts[0] * 60 ) + $parts[1];
        }
        if ( 3 === count( $parts ) ) {
            return ( $parts[0] * 3600 ) + ( $parts[1] * 60 ) + $parts[2];
        }

        return null;
    }

    private static function format_duration( $seconds ) {
        $seconds = max( 0, (int) $seconds );
        $hours   = intdiv( $seconds, 3600 );
        $minutes = intdiv( $seconds % 3600, 60 );
        $secs    = $seconds % 60;

        return $hours > 0
            ? sprintf( '%d:%02d:%02d', $hours, $minutes, $secs )
            : sprintf( '%d:%02d', $minutes, $secs );
    }

    private static function build_series_from_show( $show, WP_Post $post ) {
        $show = sanitize_text_field( trim( (string) $show ) );
        if ( '' === $show ) {
            return null;
        }

        $show_key = self::normalize_taxonomy_label( $show );
        $tags     = get_the_tags( $post->ID );

        if ( $tags ) {
            foreach ( $tags as $tag ) {
                $tag_name_key = self::normalize_taxonomy_label( $tag->name );
                $tag_slug_key = self::normalize_taxonomy_label( $tag->slug );

                if ( $show_key === $tag_name_key || $show_key === $tag_slug_key ) {
                    return array(
                        'id'   => (int) $tag->term_id,
                        'name' => $show,
                        'slug' => $tag->slug,
                    );
                }
            }
        }

        return array(
            'id'   => null,
            'name' => $show,
            'slug' => sanitize_title( $show ),
        );
    }

    private static function normalize_taxonomy_label( $value ) {
        $value = html_entity_decode( wp_strip_all_tags( (string) $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $value = str_replace( array( '_', '-' ), ' ', $value );
        $value = strtolower( $value );
        $value = preg_replace( '/[^a-z0-9]+/', '', $value );

        return (string) $value;
    }

    private static function genre_definitions() {
        return array(
            'western-podcast' => array( 'name' => 'Westerns', 'slug' => 'westerns' ),
            'western'         => array( 'name' => 'Westerns', 'slug' => 'westerns' ),
            'westerns'        => array( 'name' => 'Westerns', 'slug' => 'westerns' ),
            'mystery'         => array( 'name' => 'Mystery', 'slug' => 'mystery' ),
            'drama'           => array( 'name' => 'Drama', 'slug' => 'drama' ),
            'comedy'          => array( 'name' => 'Comedy', 'slug' => 'comedy' ),
            'crime'           => array( 'name' => 'Crime', 'slug' => 'crime' ),
            'detective'       => array( 'name' => 'Detective', 'slug' => 'detective' ),
            'adventure'       => array( 'name' => 'Adventure', 'slug' => 'adventure' ),
            'horror'          => array( 'name' => 'Horror', 'slug' => 'horror' ),
            'sci-fi'          => array( 'name' => 'Science Fiction', 'slug' => 'science-fiction' ),
            'science-fiction' => array( 'name' => 'Science Fiction', 'slug' => 'science-fiction' ),
        );
    }

    private static function build_genres_payload() {
        $definitions = self::genre_definitions();
        $canonical   = array();
        $genres      = array();

        foreach ( $definitions as $term_slug => $definition ) {
            $canonical_slug = $definition['slug'];

            if ( ! isset( $canonical[ $canonical_slug ] ) ) {
                $canonical[ $canonical_slug ] = array(
                    'name'    => $definition['name'],
                    'slug'    => $canonical_slug,
                    'aliases' => array(),
                );
            }

            $canonical[ $canonical_slug ]['aliases'][] = $term_slug;
        }

        foreach ( $canonical as $genre ) {
            $category_ids = self::get_genre_term_ids( 'category', $genre['aliases'] );
            $tag_ids      = self::get_genre_term_ids( 'post_tag', $genre['aliases'] );

            $primary_episode_count = self::count_genre_posts( $category_ids, array() );
            $episode_count         = self::count_genre_posts( $category_ids, $tag_ids );

            if ( 0 === $episode_count ) {
                continue;
            }

            $genres[] = array(
                'name'                  => $genre['name'],
                'slug'                  => $genre['slug'],
                'episode_count'         => $episode_count,
                'primary_episode_count' => $primary_episode_count,
            );
        }

        return $genres;
    }

    private static function get_genre_term_ids( $taxonomy, $aliases ) {
        $term_ids = get_terms(
            array(
                'taxonomy'   => $taxonomy,
                'hide_empty' => true,
                'slug'       => array_values( array_unique( $aliases ) ),
                'fields'     => 'ids',
            )
        );

        if ( is_wp_error( $term_ids ) || ! is_array( $term_ids ) ) {
            return array();
        }

        return array_values( array_map( 'intval', $term_ids ) );
    }

    private static function count_genre_posts( $category_ids, $tag_ids ) {
        $tax_query = array();

        if ( ! empty( $category_ids ) ) {
            $tax_query[] = array(
                'taxonomy' => 'category',
                'field'    => 'term_id',
                'terms'    => array_values( array_map( 'intval', $category_ids ) ),
                'operator' => 'IN',
            );
        }

        if ( ! empty( $tag_ids ) ) {
            $tax_query[] = array(
                'taxonomy' => 'post_tag',
                'field'    => 'term_id',
                'terms'    => array_values( array_map( 'intval', $tag_ids ) ),
                'operator' => 'IN',
            );
        }

        if ( empty( $tax_query ) ) {
            return 0;
        }

        if ( count( $tax_query ) > 1 ) {
            $tax_query = array_merge( array( 'relation' => 'OR' ), $tax_query );
        }

        $query = new WP_Query(
            array(
                'post_type'              => 'post',
                'post_status'            => 'publish',
                'fields'                 => 'ids',
                'posts_per_page'         => 1,
                'orderby'                => 'none',
                'ignore_sticky_posts'    => true,
                'no_found_rows'          => false,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'tax_query'              => $tax_query,
            )
        );

        return (int) $query->found_posts;
    }

    private static function genre_from_term( $term, $source ) {
        $definitions = self::genre_definitions();
        $slug        = strtolower( (string) $term->slug );

        if ( ! isset( $definitions[ $slug ] ) ) {
            return null;
        }

        return array(
            'id'     => (int) $term->term_id,
            'name'   => $definitions[ $slug ]['name'],
            'slug'   => $definitions[ $slug ]['slug'],
            'source' => $source,
        );
    }

    private static function detect_primary_genre( WP_Post $post ) {
        $categories = get_the_category( $post->ID );

        foreach ( $categories as $category ) {
            $genre = self::genre_from_term( $category, 'category' );
            if ( $genre ) {
                return $genre;
            }
        }

        return null;
    }

    private static function detect_episode_genres( WP_Post $post, $primary_genre ) {
        $genres = array();
        $seen   = array();

        if ( is_array( $primary_genre ) && ! empty( $primary_genre['slug'] ) ) {
            $genres[] = $primary_genre;
            $seen[ $primary_genre['slug'] ] = true;
        }

        $tags = get_the_tags( $post->ID );
        if ( $tags ) {
            foreach ( $tags as $tag ) {
                $genre = self::genre_from_term( $tag, 'tag' );
                if ( ! $genre || isset( $seen[ $genre['slug'] ] ) ) {
                    continue;
                }

                $genres[] = $genre;
                $seen[ $genre['slug'] ] = true;
            }
        }

        return $genres;
    }

    private static function detect_publisher_feed( WP_Post $post ) {
        $categories = get_the_category( $post->ID );

        foreach ( $categories as $category ) {
            $slug = (string) $category->slug;

            if ( preg_match( '/-season-\d+$/', $slug ) || self::genre_from_term( $category, 'category' ) ) {
                continue;
            }

            return array(
                'id'   => (int) $category->term_id,
                'name' => $category->name,
                'slug' => $category->slug,
            );
        }

        return null;
    }

    private static function detect_source() {
        $site_url = home_url( '/' );
        $host     = strtolower( (string) wp_parse_url( $site_url, PHP_URL_HOST ) );
        $host     = preg_replace( '/^www\./', '', $host );

        $known_sources = array(
            'otrwesterns.com' => array(
                'key'  => 'otrwesterns',
                'name' => 'Old Time Radio Westerns',
            ),
            'otnetcast.com' => array(
                'key'  => 'otnetcast',
                'name' => 'Old Time Radio Netcast',
            ),
        );

        if ( isset( $known_sources[ $host ] ) ) {
            $key  = $known_sources[ $host ]['key'];
            $name = $known_sources[ $host ]['name'];
        } else {
            $key  = sanitize_title( $host );
            $name = get_bloginfo( 'name' );
        }

        return array(
            'key'      => $key,
            'name'     => sanitize_text_field( $name ),
            'site_url' => esc_url_raw( $site_url ),
        );
    }
}

Golden_Replay_API::init();
