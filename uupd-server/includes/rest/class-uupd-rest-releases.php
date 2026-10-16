<?php
/**
 * Authenticated release publishing for the native UUPD Server "update" CPT.
 * Application Passwords are supported through WordPress REST authentication.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class UUPD_REST_Releases {
    public function register_hooks() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes() {
        register_rest_route( 'uupd/v1', '/products/(?P<slug>[a-z0-9][a-z0-9-]*)/release', [
            'methods' => 'POST',
            'permission_callback' => [ $this, 'can_publish' ],
            'callback' => [ $this, 'publish' ],
        ] );
    }

    public function can_publish( WP_REST_Request $request ) {
        return current_user_can( 'manage_options' );
    }

    public function publish( WP_REST_Request $request ) {
        $slug = sanitize_key( (string) $request['slug'] );
        $posts = get_posts( [
            'post_type' => 'update', 'post_status' => 'any', 'meta_key' => 'slug',
            'meta_value' => $slug, 'posts_per_page' => 2,
        ] );
        if ( count( $posts ) !== 1 ) {
            return new WP_Error( 'uupd_release_not_found', 'Exactly one existing UUPD Update must match this software slug.', [ 'status' => 404 ] );
        }
        $post = $posts[0];
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return new WP_Error( 'uupd_release_forbidden', 'Not permitted to edit this update.', [ 'status' => 403 ] );
        }
        $version = sanitize_text_field( (string) $request->get_param( 'version' ) );
        if ( ! preg_match( '/^[0-9][A-Za-z0-9._+\-]*$/', $version ) || strlen( $version ) > 80 ) {
            return new WP_Error( 'uupd_release_version', 'A valid version is required.', [ 'status' => 400 ] );
        }
        $mode = sanitize_key( (string) ( $request->get_param( 'download_mode' ) ?: 'replace' ) );
        if ( ! in_array( $mode, [ 'replace', 'append' ], true ) ) {
            return new WP_Error( 'uupd_release_mode', 'download_mode must be replace or append.', [ 'status' => 400 ] );
        }
        $uploads = $request->get_file_params();
        $uploaded = isset( $uploads['package'] ) && is_array( $uploads['package'] ) && ! empty( $uploads['package']['tmp_name'] );
        $package_url = trim( (string) $request->get_param( 'package_url' ) );
        if ( $uploaded && $package_url ) {
            return new WP_Error( 'uupd_release_package', 'Provide either a ZIP upload or package_url, not both.', [ 'status' => 400 ] );
        }
        if ( ! $uploaded && ! $package_url ) {
            return new WP_Error( 'uupd_release_package', 'Provide package (multipart ZIP) or package_url.', [ 'status' => 400 ] );
        }
        if ( $package_url && ( ! wp_http_validate_url( $package_url ) || 0 !== strpos( $package_url, 'https://' ) ) ) {
            return new WP_Error( 'uupd_release_package_url', 'package_url must be a valid HTTPS URL.', [ 'status' => 400 ] );
        }
        $lock = 'uupd_release_lock_' . $post->ID;
        if ( get_transient( $lock ) ) {
            return new WP_Error( 'uupd_release_busy', 'A release for this product is already running.', [ 'status' => 409 ] );
        }
        set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );
        try {
            $attachment_id = 0;
            if ( $uploaded ) {
                $file = $uploads['package'];
                $zip_type = wp_check_filetype( (string) ( $file['name'] ?? '' ) );
                if ( ! empty( $file['error'] ) || 'zip' !== strtolower( (string) ( $zip_type['ext'] ?? '' ) ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
                    return new WP_Error( 'uupd_release_upload', 'A valid uploaded .zip file is required.', [ 'status' => 400 ] );
                }
                if ( ! class_exists( 'ZipArchive' ) ) {
                    return new WP_Error( 'uupd_release_zip', 'PHP ZipArchive is required to validate release packages.', [ 'status' => 503 ] );
                }
                $zip = new ZipArchive();
                $opened = $zip->open( $file['tmp_name'], ZipArchive::CHECKCONS );
                if ( $opened !== true || $zip->numFiles < 1 ) {
                    if ( $opened === true ) { $zip->close(); }
                    return new WP_Error( 'uupd_release_zip', 'Uploaded ZIP is empty or invalid.', [ 'status' => 400 ] );
                }
                $zip->close();
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';
                // Use WP's existing attachment system, as UUPD's download endpoint expects attachment IDs.
                $attachment_id = media_handle_upload( 'package', $post->ID, [], [ 'test_form' => false ] );
                if ( is_wp_error( $attachment_id ) ) { return $attachment_id; }
            }
            $old_files = get_post_meta( $post->ID, 'files', true );
            $old_files = is_array( $old_files ) ? $old_files : [];
            if ( $attachment_id ) {
                $files = [ [ 'id' => (int) $attachment_id ] ];
                if ( $mode === 'append' ) {
                    foreach ( $old_files as $entry ) {
                        $id = (int) ( $entry['id'] ?? 0 );
                        if ( $id > 0 && $id !== (int) $attachment_id ) { $files[] = [ 'id' => $id ]; }
                    }
                }
                update_post_meta( $post->ID, 'files', $files );
                delete_post_meta( $post->ID, 'file_id' );
                delete_post_meta( $post->ID, 'remote_url' );
            } else {
                update_post_meta( $post->ID, 'remote_url', esc_url_raw( $package_url, [ 'https' ] ) );
                // Keep archived attachments in append mode, but the existing UUPD server
                // prioritizes remote_url when resolving the current download.
                if ( $mode === 'replace' ) { delete_post_meta( $post->ID, 'files' ); delete_post_meta( $post->ID, 'file_id' ); }
            }
            update_post_meta( $post->ID, 'version', $version );
            $text_fields = [
                'requires_wp' => 'min_wp_version', 'tested_wp' => 'tested',
                'requires_php' => 'min_php_version', 'homepage' => 'homepage',
            ];
            foreach ( $text_fields as $input => $field ) {
                if ( $request->has_param( $input ) ) {
                    $value = (string) $request->get_param( $input );
                    update_post_meta( $post->ID, $field, $field === 'homepage' ? esc_url_raw( $value ) : sanitize_text_field( $value ) );
                }
            }
            if ( $request->has_param( 'changelog' ) ) {
                update_post_meta( $post->ID, 'changelog_html', wp_kses_post( (string) $request->get_param( 'changelog' ) ) );
            }
            foreach ( [ 'icon_128_url', 'icon_256_url', 'banner_773_url', 'banner_1544_url' ] as $field ) {
                if ( $request->has_param( $field ) ) {
                    update_post_meta( $post->ID, $field, esc_url_raw( (string) $request->get_param( $field ), [ 'http', 'https' ] ) );
                }
            }
            update_post_meta( $post->ID, 'last_updated', current_time( 'mysql' ) );
            return new WP_REST_Response( [
                'success' => true, 'id' => (int) $post->ID, 'slug' => $slug,
                'version' => $version, 'download_mode' => $mode,
                'file_count' => count( (array) get_post_meta( $post->ID, 'files', true ) ),
                'package' => true, 'last_updated' => get_post_meta( $post->ID, 'last_updated', true ),
            ], 200 );
        } finally {
            delete_transient( $lock );
        }
    }
}
