<?php
/**
 * Admin-only settings and diagnostics for Golden Replay API.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function grapi_admin_register_settings_page() {
    add_options_page(
        'Golden Replay API',
        'Golden Replay API',
        'manage_options',
        'golden-replay-api',
        'grapi_admin_render_settings_page'
    );
}
add_action( 'admin_menu', 'grapi_admin_register_settings_page' );

function grapi_admin_resolve_post_id( $value ) {
    $value = trim( (string) $value );

    if ( '' === $value ) {
        return 0;
    }

    if ( ctype_digit( $value ) ) {
        return absint( $value );
    }

    $url = esc_url_raw( $value );
    if ( ! $url ) {
        return 0;
    }

    return absint( url_to_postid( $url ) );
}

function grapi_admin_render_enclosure_value( $raw_value, $index ) {
    $lines = preg_split( '/\r\n|\r|\n/', (string) $raw_value );
    $url = isset( $lines[0] ) ? trim( $lines[0] ) : '';
    $length = isset( $lines[1] ) ? trim( $lines[1] ) : '';
    $type = isset( $lines[2] ) ? trim( $lines[2] ) : '';
    ?>
    <div style="margin:16px 0;padding:16px;border:1px solid #c3c4c7;background:#fff;max-width:1000px;">
        <h3 style="margin-top:0;">Enclosure <?php echo esc_html( (string) ( $index + 1 ) ); ?></h3>
        <table class="widefat striped" style="max-width:100%;">
            <tbody>
                <tr>
                    <th style="width:140px;">URL</th>
                    <td><code style="word-break:break-all;white-space:normal;"><?php echo esc_html( $url ); ?></code></td>
                </tr>
                <tr>
                    <th>Length</th>
                    <td><?php echo '' !== $length ? esc_html( $length ) : '<em>Not provided</em>'; ?></td>
                </tr>
                <tr>
                    <th>Type</th>
                    <td><?php echo '' !== $type ? esc_html( $type ) : '<em>Not provided</em>'; ?></td>
                </tr>
            </tbody>
        </table>
        <details style="margin-top:12px;">
            <summary>Raw enclosure metadata</summary>
            <pre style="white-space:pre-wrap;word-break:break-all;background:#f6f7f7;padding:12px;"><?php echo esc_html( (string) $raw_value ); ?></pre>
        </details>
    </div>
    <?php
}

function grapi_admin_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to access this page.', 'golden-replay-api' ) );
    }

    $lookup_value = '';
    $post_id = 0;
    $lookup_attempted = false;
    $error = '';
    $enclosures = array();
    $post = null;

    if ( isset( $_POST['grapi_enclosure_lookup_submit'] ) ) {
        check_admin_referer( 'grapi_enclosure_lookup', 'grapi_enclosure_lookup_nonce' );
        $lookup_attempted = true;
        $lookup_value = isset( $_POST['grapi_post_lookup'] )
            ? sanitize_text_field( wp_unslash( $_POST['grapi_post_lookup'] ) )
            : '';
        $post_id = grapi_admin_resolve_post_id( $lookup_value );

        if ( ! $post_id ) {
            $error = 'No WordPress post could be resolved from that post ID or URL.';
        } else {
            $post = get_post( $post_id );
            if ( ! $post || 'post' !== $post->post_type ) {
                $error = 'The resolved item is not a WordPress post.';
            } else {
                // false is intentional: return every enclosure meta value for this post.
                $enclosures = get_post_meta( $post_id, 'enclosure', false );
            }
        }
    }
    ?>
    <div class="wrap">
        <h1>Golden Replay API</h1>
        <p>Administrative tools for the Golden Replay API. These diagnostics are rendered only inside WordPress admin and are not added to the public REST API.</p>

        <hr>

        <h2>Enclosure Inspector</h2>
        <p>Enter a WordPress post ID or full episode URL to inspect every <code>enclosure</code> metadata value stored on that post.</p>

        <form method="post">
            <?php wp_nonce_field( 'grapi_enclosure_lookup', 'grapi_enclosure_lookup_nonce' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="grapi_post_lookup">Post ID or URL</label></th>
                    <td>
                        <input
                            name="grapi_post_lookup"
                            id="grapi_post_lookup"
                            type="text"
                            class="regular-text"
                            value="<?php echo esc_attr( $lookup_value ); ?>"
                            placeholder="1234 or https://www.example.com/episode/"
                        >
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Inspect Enclosures', 'primary', 'grapi_enclosure_lookup_submit' ); ?>
        </form>

        <?php if ( $lookup_attempted && $error ) : ?>
            <div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
        <?php elseif ( $lookup_attempted && $post ) : ?>
            <h2>Results</h2>
            <p>
                <strong>Post:</strong> <?php echo esc_html( get_the_title( $post ) ); ?><br>
                <strong>Post ID:</strong> <?php echo esc_html( (string) $post_id ); ?><br>
                <strong>Enclosures found:</strong> <?php echo esc_html( (string) count( $enclosures ) ); ?>
            </p>

            <?php if ( empty( $enclosures ) ) : ?>
                <div class="notice notice-warning inline"><p>No <code>enclosure</code> metadata values were found for this post.</p></div>
            <?php else : ?>
                <?php foreach ( $enclosures as $index => $raw_value ) : ?>
                    <?php grapi_admin_render_enclosure_value( $raw_value, $index ); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <?php
}
