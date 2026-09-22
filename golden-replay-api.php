
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
