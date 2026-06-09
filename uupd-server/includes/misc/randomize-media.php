<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* -----------------------------------------------------------------------------
 * RANDOMISE MEDIA FILENAMES FOR UPDATE CPT (ZIP FILES ONLY)
 * -------------------------------------------------------------------------- */

/**
 * Append a 15-char random suffix only when:
 * - uploading from an 'update' CPT edit screen, AND
 * - the uploaded file is a .zip
 */
add_filter( 'wp_handle_upload_prefilter', 'uupd_randomize_media_filename' );

function uupd_randomize_media_filename( $file ) {

    // Only act on .zip files
    $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
    if ( $ext !== 'zip' ) {
        return $file;
    }

    // Try the AJAX post_id first (media modal upload from a post screen)
    $post_id = ! empty( $_REQUEST['post_id'] ) ? (int) $_REQUEST['post_id'] : 0;

    // Fallback: parse the post ID out of the referer URL (media modal iframe)
    if ( ! $post_id ) {
        $ref = wp_get_referer();
        if ( $ref && preg_match( '/post\.php\?post=(\d+)/', $ref, $matches ) ) {
            $post_id = (int) $matches[1];
        }
    }

    // Only randomize when editing an 'update' post
    if ( ! $post_id || 'update' !== get_post_type( $post_id ) ) {
        return $file;
    }

    // Generate and append the 15-char suffix
    $name = pathinfo( $file['name'], PATHINFO_FILENAME );
    $rand = substr( wp_generate_password( 15, false, false ), 0, 15 );

    $file['name'] = sanitize_file_name( "{$name}-{$rand}.{$ext}" );

    return $file;
}

/* -----------------------------------------------------------------------------
 * AJAX: GENERATE LICENSE KEY (for License meta box "Generate" button)
 * -------------------------------------------------------------------------- */

add_action( 'wp_ajax_uupd_generate_license_key', 'uupd_ajax_generate_license_key' );

function uupd_ajax_generate_license_key() {

    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( [ 'message' => 'Permission denied' ], 403 );
    }

    check_ajax_referer( 'uupd_license_meta', 'nonce' );

    $update_id = isset( $_POST['update_id'] ) ? intval( $_POST['update_id'] ) : 0;

    $key = uupd_generate_license_key( $update_id, 35 );

    wp_send_json_success( [ 'license_key' => $key ] );
}

/**
 * Generate a random license key with optional prefix taken
 * from the update post meta 'license_prefix'.
 *
 * @param int $update_id
 * @param int $length
 * @return string
 */
function uupd_generate_license_key( $update_id = 0, $length = 35 ) {

    $prefix = '';
    if ( $update_id ) {
        $prefix = get_post_meta( $update_id, 'license_prefix', true );
    }

    $prefix = is_string( $prefix ) ? $prefix : '';

    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $max   = strlen( $chars ) - 1;
    $key   = '';

    for ( $i = 0; $i < $length; $i++ ) {
        $key .= $chars[ random_int( 0, $max ) ];
    }

    return $prefix . $key;
}
