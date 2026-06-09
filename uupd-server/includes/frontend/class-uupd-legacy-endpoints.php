<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Legacy frontend endpoints:
 *
 * - ?action=get_metadata
 * - ?action=download
 *
 * These remain so old plugins using query-string endpoints continue working.
 */
class UUPD_Legacy_Endpoints {

    const TOKEN_EXPIRY = 3600; // 1 hour

    /**
     * Hooked into template_redirect by the core router.
     */
    public function register_hooks() {
        add_action( 'template_redirect', [ $this, 'route' ] );
    }

    /**
     * Routes query variables to handlers.
     */
    public function route() {
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';

        if ( $action === 'get_metadata' ) {
            $this->output_metadata();
            exit;
        }

        if ( $action === 'download' ) {
            $this->serve_download();
            exit;
        }
    }

    /* -------------------------------------------------------------------------
     * METADATA ENDPOINT
     * ---------------------------------------------------------------------- */

    private function output_metadata() {

        $slug = isset( $_GET['slug'] )
            ? sanitize_text_field( wp_unslash( $_GET['slug'] ) )
            : '';

        $key  = isset( $_GET['key'] )
            ? sanitize_text_field( wp_unslash( $_GET['key'] ) )
            : '';

        // Determine domain
        if ( ! empty( $_GET['domain'] ) ) {
            $domain = sanitize_text_field( wp_unslash( $_GET['domain'] ) );
        } elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
            $domain = wp_parse_url( wp_unslash( $_SERVER['HTTP_REFERER'] ), PHP_URL_HOST );
        } else {
            $domain = isset( $_SERVER['HTTP_HOST'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
                : '';
        }

        // Find the update by slug
        $posts = get_posts([
            'post_type'   => 'update',
            'meta_key'    => 'slug',
            'meta_value'  => $slug,
            'numberposts' => 1,
        ]);

        if ( ! $posts ) {
            wp_send_json_error( 'Invalid slug', 404 );
        }

        $post = $posts[0];

        // Build metadata array
        $meta = [];
        foreach ( [
            'slug',
            'version',
            'tested',
            'homepage',
            'changelog_html',
            'last_updated',
            'min_wp_version',
            'min_php_version'
        ] as $f ) {
            $meta[ $f ] = get_post_meta( $post->ID, $f, true ) ?: '';
        }

        // Icons
        $meta['icons'] = [
            '1x' => get_post_meta( $post->ID, 'icon_128_url', true )
                ?: get_post_meta( $post->ID, 'icon_256_url', true ),
            '2x' => get_post_meta( $post->ID, 'icon_256_url', true ),
        ];

        // Banners
        $meta['banners'] = [
            'low'  => get_post_meta( $post->ID, 'banner_773_url', true )
                ?: get_post_meta( $post->ID, 'banner_1544_url', true ),
            'high' => get_post_meta( $post->ID, 'banner_1544_url', true ),
        ];

        // Check if licensed product but no key provided
        $licensed_product = (bool) get_post_meta( $post->ID, 'licensed_product', true );

        if ( $licensed_product && empty( $key ) ) {
            $meta['license_required'] = true;
            $meta['license_status']   = 'missing';
            $meta['download_url']     = '';
            wp_send_json( $meta );
        }

        // Get primary file (first in "files" array)
        $files   = get_post_meta( $post->ID, 'files', true );
        $file_id = 0;

        if ( is_array( $files ) && ! empty( $files[0]['id'] ) ) {
            $file_id = intval( $files[0]['id'] );
        } else {
            $file_id = intval( get_post_meta( $post->ID, 'file_id', true ) );
        }

        $remote = get_post_meta( $post->ID, 'remote_url', true );

        if ( $remote ) {
            $meta['download_url'] = esc_url_raw( $remote );
        } elseif ( $file_id ) {
            // Generate signed URL
            $expires = time() + self::TOKEN_EXPIRY;
            $data    = "{$slug}|{$file_id}|{$expires}";
            $token   = hash_hmac( 'sha256', $data, AUTH_KEY );

            $meta['download_url'] = esc_url_raw(
                add_query_arg(
                    [
                        'action'  => 'download',
                        'slug'    => $slug,
                        'expires' => $expires,
                        'token'   => $token,
                        'key'     => $key,
                        'domain'  => $domain,
                    ],
                    site_url( '/' )
                )
            );
        } else {
            $meta['download_url'] = '';
        }

        wp_send_json( $meta );
    }

    /* -------------------------------------------------------------------------
     * DOWNLOAD ENDPOINT
     * ---------------------------------------------------------------------- */

    private function serve_download() {

        // Required query params
        if (
            ! isset( $_GET['slug'], $_GET['expires'], $_GET['token'] )
        ) {
            wp_die( 'Invalid download link', 400 );
        }

        $slug    = sanitize_key( wp_unslash( $_GET['slug'] ) );
        $expires = intval( wp_unslash( $_GET['expires'] ) );
        $token   = sanitize_text_field( wp_unslash( $_GET['token'] ) );

        if ( empty( $slug ) || $expires <= 0 || empty( $token ) ) {
            wp_die( 'Invalid download link', 400 );
        }

        // Reject future timestamps (anti-manipulation)
        if ( $expires > ( time() + WEEK_IN_SECONDS ) ) {
            wp_die( 'Invalid expiry timestamp', 400 );
        }

        // Expired link
        if ( time() > $expires ) {
            wp_die( 'Download link expired', 403 );
        }

        // Look up update by slug
        $posts = get_posts([
            'post_type'      => 'update',
            'meta_key'       => 'slug',
            'meta_value'     => $slug,
            'posts_per_page' => 1,
            'no_found_rows'  => true,
        ]);

        if ( empty( $posts ) ) {
            wp_die( 'Invalid slug', 404 );
        }

        $post   = $posts[0];
        $remote = get_post_meta( $post->ID, 'remote_url', true );

        // If remote URL is set, redirect immediately
        if ( ! empty( $remote ) ) {
            $remote = esc_url_raw( $remote, ['http', 'https'] );
            if ( ! $remote ) {
                wp_die( 'Invalid remote URL', 500 );
            }
            wp_redirect( $remote );
            exit;
        }

        // Get attached file ID
        $files   = get_post_meta( $post->ID, 'files', true );
        $file_id = 0;

        if ( is_array( $files ) && ! empty( $files[0]['id'] ) ) {
            $file_id = intval( $files[0]['id'] );
        } else {
            $file_id = intval( get_post_meta( $post->ID, 'file_id', true ) );
        }

        if ( ! $file_id ) {
            wp_die( 'No file available', 404 );
        }

        // Validate token
        $data     = "{$slug}|{$file_id}|{$expires}";
        $expected = hash_hmac( 'sha256', $data, AUTH_KEY );

        if ( ! hash_equals( $expected, $token ) ) {
            wp_die( 'Invalid token', 403 );
        }

        // Physical file path
        $file = get_attached_file( $file_id );
        if ( ! $file || ! file_exists( $file ) ) {
            wp_die( 'File not found', 404 );
        }

        // Detect MIME type.
        $mime      = wp_check_filetype( $file );
        $mime_type = ! empty( $mime['type'] ) ? $mime['type'] : 'application/octet-stream';

        // Build a "public" download name that hides the random suffix.
        // Example stored name: my-plugin-1.2.3-ABCDEF123456789.zip
        // We want:             my-plugin-1.2.3.zip
        $basename       = basename( $file );
        $download_name  = $basename;

        // If we see "-{15 alnum chars}" before the extension, strip that bit.
        if ( preg_match( '/^(.*)-[A-Za-z0-9]{15}(\.[^.]+)$/', $basename, $m ) ) {
            $download_name = $m[1] . $m[2];
        }

        if ( ob_get_length() ) {
            ob_end_clean();
        }

        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow', true );
        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: ' . $mime_type );
        header( 'Content-Disposition: attachment; filename="' . $download_name . '"' );
        header( 'Content-Transfer-Encoding: binary' );
        header( 'Content-Length: ' . filesize( $file ) );

        // Stream the file.
        $handle = fopen( $file, 'rb' );
        if ( $handle === false ) {
            wp_die( 'Could not read file', 500 );
        }

        while ( ! feof( $handle ) ) {
            echo fread( $handle, 8192 );
            flush();
        }

        fclose( $handle );
        exit;
    }
}
