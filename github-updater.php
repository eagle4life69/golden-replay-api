<?php
/**
 * Native GitHub updater for Golden Replay API.
 * Checks the latest published GitHub Release.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function grapi_github_get_latest_release() {
    $cache_key = 'grapi_github_latest_release';
    $cached    = get_transient( $cache_key );

    if ( false !== $cached ) {
        return $cached;
    }

    $response = wp_remote_get(
        'https://api.github.com/repos/eagle4life69/golden-replay-api/releases/latest',
        array(
            'timeout' => 10,
            'headers' => array(
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'Golden-Replay-API/' . GRAPI_VERSION,
            ),
        )
    );

    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
        return false;
    }

    $data = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( empty( $data['tag_name'] ) || empty( $data['zipball_url'] ) ) {
        return false;
    }

    $release = array(
        'version' => ltrim( trim( $data['tag_name'] ), 'vV' ),
        'package' => esc_url_raw( $data['zipball_url'] ),
        'url'     => ! empty( $data['html_url'] )
            ? esc_url_raw( $data['html_url'] )
            : 'https://github.com/eagle4life69/golden-replay-api/releases',
        'body'    => ! empty( $data['body'] ) ? (string) $data['body'] : '',
    );

    set_transient( $cache_key, $release, 15 * MINUTE_IN_SECONDS );

    return $release;
}

function grapi_github_build_update_object( $release, $plugin_file ) {
    $update                  = new stdClass();
    $update->id              = 'https://github.com/eagle4life69/golden-replay-api';
    $update->slug            = 'golden-replay-api';
    $update->plugin          = $plugin_file;
    $update->new_version     = $release['version'];
    $update->url             = $release['url'];
    $update->package         = $release['package'];
    $update->requires_php    = '7.2';

    return $update;
}

function grapi_github_check_for_update( $transient ) {
    if ( ! is_object( $transient ) ) {
        $transient = new stdClass();
    }

    if ( empty( $transient->response ) || ! is_array( $transient->response ) ) {
        $transient->response = array();
    }

    if ( empty( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
        $transient->no_update = array();
    }

    $plugin_file = plugin_basename( GRAPI_PLUGIN_FILE );
    $release     = grapi_github_get_latest_release();

    if ( ! $release ) {
        return $transient;
    }

    $update = grapi_github_build_update_object( $release, $plugin_file );

    if ( version_compare( GRAPI_VERSION, $release['version'], '<' ) ) {
        $transient->response[ $plugin_file ] = $update;
        unset( $transient->no_update[ $plugin_file ] );
    } else {
        $transient->no_update[ $plugin_file ] = $update;
        unset( $transient->response[ $plugin_file ] );
    }

    return $transient;
}
add_filter( 'site_transient_update_plugins', 'grapi_github_check_for_update' );

function grapi_github_plugin_information( $result, $action, $args ) {
    if ( 'plugin_information' !== $action || empty( $args->slug ) || 'golden-replay-api' !== $args->slug ) {
        return $result;
    }

    $release = grapi_github_get_latest_release();

    $info                = new stdClass();
    $info->name          = 'Golden Replay API';
    $info->slug          = 'golden-replay-api';
    $info->version       = $release ? $release['version'] : GRAPI_VERSION;
    $info->author        = '<a href="https://github.com/eagle4life69">Andrew Rhynes</a>';
    $info->homepage      = 'https://github.com/eagle4life69/golden-replay-api';
    $info->requires_php  = '7.2';
    $info->download_link = $release ? $release['package'] : '';
    $info->sections      = array(
        'description' => 'Provides a secure, read-only REST API for Golden Replay episode data.',
        'changelog'   => $release && ! empty( $release['body'] )
            ? wpautop( esc_html( $release['body'] ) )
            : 'See the GitHub release notes for the current changelog.',
    );

    return $info;
}
add_filter( 'plugins_api', 'grapi_github_plugin_information', 20, 3 );

function grapi_github_fix_source_folder( $source, $remote_source, $upgrader, $hook_extra ) {
    if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== plugin_basename( GRAPI_PLUGIN_FILE ) ) {
        return $source;
    }

    // Keep the extracted update package in the same plugin directory name that
    // WordPress is currently using. Changing the directory changes the plugin
    // basename, which can make WordPress treat an update as a different plugin
    // and lose activation/auto-update state.
    $installed_directory = basename( dirname( GRAPI_PLUGIN_FILE ) );

    if ( '' === $installed_directory || '.' === $installed_directory ) {
        $installed_directory = 'golden-replay-api';
    }

    $desired_source = trailingslashit( $remote_source ) . trailingslashit( $installed_directory );

    if ( untrailingslashit( $source ) === untrailingslashit( $desired_source ) ) {
        return $source;
    }

    if ( file_exists( $desired_source ) ) {
        return $source;
    }

    if ( @rename( untrailingslashit( $source ), untrailingslashit( $desired_source ) ) ) {
        return $desired_source;
    }

    return new WP_Error(
        'grapi_github_rename_failed',
        'Unable to prepare the GitHub update package.'
    );
}
add_filter( 'upgrader_source_selection', 'grapi_github_fix_source_folder', 10, 4 );

function grapi_github_clear_update_cache( $upgrader, $options ) {
    if (
        ! empty( $options['action'] ) && 'update' === $options['action'] &&
        ! empty( $options['type'] ) && 'plugin' === $options['type']
    ) {
        delete_transient( 'grapi_github_latest_release' );
    }
}
add_action( 'upgrader_process_complete', 'grapi_github_clear_update_cache', 10, 2 );

// Load admin-only API settings and diagnostics. Keeping these tools outside the
// public REST implementation prevents diagnostic enclosure data from being
// exposed through Golden Replay API endpoints.
$grapi_admin_settings = plugin_dir_path( __FILE__ ) . 'admin-settings.php';
if ( file_exists( $grapi_admin_settings ) ) {
    require_once $grapi_admin_settings;
}
