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

    // Metadata may be cached by an unchanged UUPD client for six hours.
    // The gateway must outlive that cache; authorization is still rechecked at download.
    const TOKEN_EXPIRY = 24 * HOUR_IN_SECONDS;

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

        $key  = $this->get_metadata_license_key();

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
        if ( $licensed_product ) {
            $license = uupd_get_license_by_key( $key );
            if ( ! $license || absint( get_post_meta( uupd_license_master_id( $license->ID ), 'update_id', true ) ) !== $post->ID || ! uupd_license_is_usable( $license->ID ) || ! self::license_domain_active( $license->ID, $domain ) ) {
                $meta['license_required'] = true;
                $meta['license_status'] = 'inactive';
                $meta['download_url'] = '';
                wp_send_json( $meta );
            }
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

        if ( $remote || $file_id ) {
            // Generate signed URL
            $expires = time() + self::TOKEN_EXPIRY;
            // Remote packages bind the signature to their configured URL.
            // Local packages retain the legacy file-ID signature unchanged.
            $resource = $remote ? 'remote:' . hash( 'sha256', (string) $remote ) : (string) $file_id;
            $data    = "{$slug}|{$resource}|{$expires}";
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

    /** Read the v2.2 Bearer credential, falling back to the legacy query key. */
    private function get_metadata_license_key() {
        $authorization = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) : '';
        if ( '' === $authorization && function_exists( 'getallheaders' ) ) {
            $headers = getallheaders();
            $authorization = is_array( $headers ) && isset( $headers['Authorization'] ) ? $headers['Authorization'] : '';
        }
        if ( preg_match( '/^Bearer\s+(.+)$/i', trim( (string) $authorization ), $matches ) ) {
            return sanitize_text_field( trim( $matches[1] ) );
        }
        $allow_query_key = defined( 'UUPD_ALLOW_QUERY_LICENSE_KEY' )
            ? (bool) UUPD_ALLOW_QUERY_LICENSE_KEY
            : ( '1' === (string) get_option( 'uupd_allow_query_license_key', '1' ) );
        if ( ! $allow_query_key ) {
            return '';
        }
        return isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
    }

    /** Verify the specific domain was activated for this licence (not just its master). */
    private static function license_domain_active( $license_id, $domain ) {
        $domain = self::canonical_domain( $domain );
        if ( '' === $domain ) { return false; }
        $activated = get_post_meta( $license_id, 'used_domains', true );
        if ( ! is_array( $activated ) ) { return false; }
        foreach ( $activated as $site ) {
            if ( self::canonical_domain( $site ) === $domain ) { return true; }
        }
        return false;
    }

    /** Supports the existing activation API's URL-like domain values. */
    private static function canonical_domain( $value ) {
        $value = strtolower( trim( (string) $value ) );
        if ( '' === $value ) { return ''; }
        $url = preg_match( '~^https?://~', $value ) ? $value : 'https://' . $value;
        $host = wp_parse_url( $url, PHP_URL_HOST );
        $port = wp_parse_url( $url, PHP_URL_PORT );
        return $host ? rtrim( strtolower( $host ), '.' ) . ( $port ? ':' . (int) $port : '' ) : '';
    }

    private static function valid_https_destination( $url ) {
        $parts = wp_parse_url( (string) $url );
        return is_array( $parts ) && ( $parts['scheme'] ?? '' ) === 'https'
            && ! empty( $parts['host'] ) && empty( $parts['user'] ) && empty( $parts['pass'] )
            && (bool) wp_http_validate_url( $url );
    }

    /**
     * CDN delivery runs only AFTER gateway HMAC, expiry and licence checks.
     * Use Bunny token authentication (URL must point to a token-protected pull zone),
     * a presigned R2 URL supplied by an integration filter, or a legacy HTTPS URL.
     */
    private static function cdn_destination( $remote, $slug, $product_id ) {
        $remote = esc_url_raw( $remote, [ 'https' ] );
        if ( ! self::valid_https_destination( $remote ) ) {
            return new WP_Error( 'uupd_cdn_invalid', 'Invalid HTTPS remote package URL.' );
        }
        $mode = defined( 'UUPD_CDN_MODE' ) ? strtolower( (string) UUPD_CDN_MODE ) : 'legacy';
        if ( 'bunny' === $mode ) {
            $key = defined( 'UUPD_BUNNY_TOKEN_KEY' ) ? (string) UUPD_BUNNY_TOKEN_KEY : '';
            $host = defined( 'UUPD_BUNNY_HOST' ) ? strtolower( (string) UUPD_BUNNY_HOST ) : '';
            if ( '' === $key || '' === $host || strtolower( (string) wp_parse_url( $remote, PHP_URL_HOST ) ) !== $host ) {
                return new WP_Error( 'uupd_bunny_config', 'Bunny token authentication requires the configured signing key and matching CDN host.' );
            }
            // For Bunny query-token signing, we require a URL without pre-existing query parameters.
            if ( wp_parse_url( $remote, PHP_URL_QUERY ) ) {
                return new WP_Error( 'uupd_bunny_query', 'Bunny source URLs must not have query parameters.' );
            }
            $path = (string) wp_parse_url( $remote, PHP_URL_PATH );
            if ( '' === $path ) { return new WP_Error( 'uupd_bunny_path', 'Missing Bunny package path.' ); }
            $expires = time() + 10 * MINUTE_IN_SECONDS;
            $signature = hash_hmac( 'sha256', $path . $expires, $key, true );
            $token = 'HS256-' . rtrim( strtr( base64_encode( $signature ), '+/', '-_' ), '=' );
            return add_query_arg( [ 'token' => $token, 'expires' => $expires ], $remote );
        }
        if ( 'cloudflare_r2' === $mode ) {
            // R2 requires AWS SigV4, not a homemade token. Integration must return
            // an actual short-lived presigned GET URL at download time.
            $signed = apply_filters( 'uupd/server/r2_presigned_get_url', '', $remote, $slug, $product_id, 600 );
            return self::valid_https_destination( $signed ) ? $signed : new WP_Error( 'uupd_r2_signer_missing', 'R2 signer is not configured or returned an invalid HTTPS URL.' );
        }
        if ( 'legacy' !== $mode ) {
            return new WP_Error( 'uupd_cdn_mode', 'Unknown UUPD CDN mode.' );
        }
        return $remote; // Existing behaviour; public origin URLs are not protected.
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
        if ( get_post_meta( $post->ID, 'licensed_product', true ) ) {
            $key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
            $license = uupd_get_license_by_key( $key );
            $domain = isset( $_GET['domain'] ) ? sanitize_text_field( wp_unslash( $_GET['domain'] ) ) : '';
            if ( ! $license || absint( get_post_meta( uupd_license_master_id( $license->ID ), 'update_id', true ) ) !== $post->ID || ! uupd_license_is_usable( $license->ID ) || ! self::license_domain_active( $license->ID, $domain ) ) {
                wp_die( 'Licence inactive', 403 );
            }
        }
        // Resolve the same resource identity used when metadata signed the URL.
        $remote = get_post_meta( $post->ID, 'remote_url', true );
        $files   = get_post_meta( $post->ID, 'files', true );
        $file_id = ( is_array( $files ) && ! empty( $files[0]['id'] ) )
            ? intval( $files[0]['id'] )
            : intval( get_post_meta( $post->ID, 'file_id', true ) );
        if ( ! $remote && ! $file_id ) {
            wp_die( 'No file available', 404 );
        }
        $resource = $remote ? 'remote:' . hash( 'sha256', (string) $remote ) : (string) $file_id;
        $expected = hash_hmac( 'sha256', "{$slug}|{$resource}|{$expires}", AUTH_KEY );
        if ( ! hash_equals( $expected, $token ) ) {
            wp_die( 'Invalid token', 403 );
        }
        if ( $remote ) {
            $destination = self::cdn_destination( $remote, $slug, $post->ID );
            if ( is_wp_error( $destination ) ) {
                wp_die( esc_html( $destination->get_error_message() ), 'Delivery configuration error', [ 'response' => 503 ] );
            }
            // Restrict to HTTPS. Do not leak a private origin URL in update metadata.
            if ( ! self::valid_https_destination( $destination ) ) {
                wp_die( 'Invalid delivery destination', 'Delivery configuration error', [ 'response' => 503 ] );
            }
            nocache_headers();
            wp_redirect( $destination, 302, 'UUPD Server' );
            exit;
        }

        // Physical file path
        $file = get_attached_file( $file_id );
        if ( ! $file || ! file_exists( $file ) ) {
            wp_die( 'File not found', 404 );
        }

        // Optional Nginx X-Accel-Redirect: private server-side file transfer instead of PHP streaming.
        // Requires a matching Nginx internal location configured by the operator.
        if ( defined( 'UUPD_X_ACCEL_ROOT' ) && defined( 'UUPD_X_ACCEL_URI' ) ) {
            $root = realpath( (string) UUPD_X_ACCEL_ROOT );
            $real = realpath( $file );
            $uri = (string) UUPD_X_ACCEL_URI;
            if ( $root && $real && 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) && preg_match( '~^/[a-zA-Z0-9/_-]+/$~', $uri ) ) {
                $relative = substr( $real, strlen( $root ) + 1 );
                $segments = array_map( 'rawurlencode', explode( DIRECTORY_SEPARATOR, $relative ) );
                nocache_headers();
                header( 'X-Accel-Redirect: ' . $uri . implode( '/', $segments ) );
                header( 'Content-Type: application/zip' );
                header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
                exit;
            }
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
