<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Utility: find a license post by its license_key meta.
 *
 * @param string $license_key
 * @return WP_Post|false
 */
function uupd_get_license_by_key( $license_key ) {

    $license_key = trim( (string) $license_key );
    if ( $license_key === '' ) {
        return false;
    }

    $posts = get_posts(
        [
            'post_type'   => 'uupd_license',
            'numberposts' => 1,
            'meta_key'    => 'license_key',
            'meta_value'  => $license_key,
        ]
    );

    if ( empty( $posts ) ) {
        return false;
    }

    return $posts[0];
}

/**
 * Programmatically create a license.
 *
 * @param array $args {
 *   @type int    $update_id        Required. ID of the Update post.
 *   @type string $package_id       Optional package short ID.
 *   @type int    $user_id          Optional user ID to link.
 *   @type string $license_key      Optional custom license key.
 *   @type int    $activation_limit Optional per-license activation limit.
 *   @type string $expiry           Optional Y-m-d date string. Empty = lifetime.
 *   @type string $order_id         Optional order / invoice reference.
 * }
 * @return int|WP_Error License post ID on success.
 */
function uupd_create_license( array $args ) {

    $args = wp_parse_args(
        $args,
        [
            'update_id'        => 0,
            'package_id'       => '',
            'user_id'          => 0,
            'license_key'      => '',
            'activation_limit' => 0,
            'expiry'           => '',
            'order_id'         => '',
        ]
    );

    $update_id        = (int) $args['update_id'];
    $package_id       = (string) $args['package_id'];
    $user_id          = (int) $args['user_id'];
    $license_key      = (string) $args['license_key'];
    $activation_limit = (int) $args['activation_limit'];
    $expiry           = (string) $args['expiry'];
    $order_id         = (string) $args['order_id'];

    // Validate update.
    if ( ! $update_id ) {
        return new WP_Error( 'uupd_missing_update_id', __( 'Missing update_id for license creation.', 'uupd-server' ) );
    }

    $update_post = get_post( $update_id );
    if ( ! $update_post || $update_post->post_type !== 'update' ) {
        return new WP_Error( 'uupd_invalid_update_id', __( 'Invalid update_id for license creation.', 'uupd-server' ) );
    }

    // Generate license key if needed.
    if ( $license_key === '' ) {
        if ( function_exists( 'uupd_generate_license_key' ) ) {
            $license_key = uupd_generate_license_key( $update_id, 35 );
        } else {
            // Fallback – same approach as uupd_generate_license_key().
            $prefix = (string) get_post_meta( $update_id, 'license_prefix', true );
            $prefix = is_string( $prefix ) ? $prefix : '';

            $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
            $max   = strlen( $chars ) - 1;
            $rand  = '';

            for ( $i = 0; $i < 35; $i++ ) {
                $rand .= $chars[ random_int( 0, $max ) ];
            }

            $license_key = $prefix . $rand;
        }
    }

    // Insert license post.
    $post_id = wp_insert_post(
        [
            'post_type'   => 'uupd_license',
            'post_status' => 'publish',
            'post_title'  => $license_key,
        ],
        true
    );

    if ( is_wp_error( $post_id ) ) {
        return $post_id;
    }

    // Meta fields.
    update_post_meta( $post_id, 'update_id',        $update_id );
    update_post_meta( $post_id, 'package_id',       $package_id );
    update_post_meta( $post_id, 'user_id',          $user_id );
    update_post_meta( $post_id, 'license_key',      $license_key );
    update_post_meta( $post_id, 'status',           'active' );
    update_post_meta( $post_id, 'activation_limit', $activation_limit );
    update_post_meta( $post_id, 'expiry',           $expiry );
    update_post_meta( $post_id, 'order_id',         $order_id );

    // Ensure used_domains is an array.
    $used_domains = get_post_meta( $post_id, 'used_domains', true );
    if ( ! is_array( $used_domains ) ) {
        update_post_meta( $post_id, 'used_domains', [] );
    }

    return $post_id;
}

/**
 * Suspend a license by its key.
 *
 * @param string $license_key
 * @return bool|WP_Error True on success.
 */
function uupd_suspend_license( $license_key ) {

    $license = uupd_get_license_by_key( $license_key );
    if ( ! $license ) {
        return new WP_Error( 'uupd_license_not_found', __( 'License not found.', 'uupd-server' ) );
    }

    update_post_meta( $license->ID, 'status', 'suspended' );

    return true;
}

/**
 * Remove a single domain from a license's used_domains list.
 *
 * @param string $license_key
 * @param string $domain
 * @return bool|WP_Error True on success.
 */
function uupd_deactivate_license_for_domain( $license_key, $domain ) {

    $license = uupd_get_license_by_key( $license_key );
    if ( ! $license ) {
        return new WP_Error( 'uupd_license_not_found', __( 'License not found.', 'uupd-server' ) );
    }

    $domain = trim( (string) $domain );
    if ( $domain === '' ) {
        return new WP_Error( 'uupd_invalid_domain', __( 'Invalid domain.', 'uupd-server' ) );
    }

    $used_domains = get_post_meta( $license->ID, 'used_domains', true );
    if ( ! is_array( $used_domains ) ) {
        $used_domains = [];
    }

    // Normalise + remove.
    $cleaned = [];
    foreach ( $used_domains as $d ) {
        $d = trim( (string) $d );
        if ( $d === '' ) {
            continue;
        }
        if ( strcasecmp( $d, $domain ) === 0 ) {
            // Skip this one → effectively removed.
            continue;
        }
        $cleaned[] = $d;
    }

    update_post_meta( $license->ID, 'used_domains', $cleaned );

    return true;
}


/**
 * Programmatically update a UUPD licence.
 *
 * @param int   $license_id  Licence post ID.
 * @param array $update_data {
 *   @type string $status           Optional. New licence status.
 *   @type string $expiry           Optional. Expiry date Y-m-d. Empty = lifetime.
 *   @type string $expiry_date      Optional alias for expiry.
 *   @type int    $activation_limit Optional. Activation limit. 0 = unlimited.
 *   @type string $package_id       Optional. Package short ID.
 *   @type int    $user_id          Optional. Linked user ID.
 *   @type string $order_id         Optional. Order/invoice reference.
 * }
 * @return array|WP_Error
 */
function uupd_update_license( $license_id, array $update_data ) {

    $license_id = absint( $license_id );

    if ( ! $license_id ) {
        return new WP_Error(
            'uupd_missing_license_id',
            __( 'Missing license_id for license update.', 'uupd-server' )
        );
    }

    $license = get_post( $license_id );

    if ( ! $license || $license->post_type !== 'uupd_license' ) {
        return new WP_Error(
            'uupd_invalid_license_id',
            __( 'Invalid license_id for license update.', 'uupd-server' )
        );
    }

    $allowed_statuses = array(
        'active',
        'inactive',
        'expired',
        'suspended',
        'cancelled',
        'refunded',
    );

    $updated_fields = array();

    if ( array_key_exists( 'status', $update_data ) ) {
        $status = sanitize_key( $update_data['status'] );

        if ( ! in_array( $status, $allowed_statuses, true ) ) {
            return new WP_Error(
                'uupd_invalid_license_status',
                __( 'Invalid license status.', 'uupd-server' )
            );
        }

        update_post_meta( $license_id, 'status', $status );
        $updated_fields[] = 'status';
    }

    if ( array_key_exists( 'expiry_date', $update_data ) && ! array_key_exists( 'expiry', $update_data ) ) {
        $update_data['expiry'] = $update_data['expiry_date'];
    }

    if ( array_key_exists( 'expiry', $update_data ) ) {
        $expiry = trim( (string) $update_data['expiry'] );

        if ( $expiry !== '' ) {
            $timestamp = strtotime( $expiry );

            if ( false === $timestamp ) {
                return new WP_Error(
                    'uupd_invalid_expiry',
                    __( 'Invalid expiry date.', 'uupd-server' )
                );
            }

            $expiry = gmdate( 'Y-m-d', $timestamp );
        }

        update_post_meta( $license_id, 'expiry', $expiry );
        $updated_fields[] = 'expiry';
    }

    if ( array_key_exists( 'activation_limit', $update_data ) ) {
        update_post_meta(
            $license_id,
            'activation_limit',
            absint( $update_data['activation_limit'] )
        );

        $updated_fields[] = 'activation_limit';
    }

    if ( array_key_exists( 'package_id', $update_data ) ) {
        update_post_meta(
            $license_id,
            'package_id',
            sanitize_text_field( $update_data['package_id'] )
        );

        $updated_fields[] = 'package_id';
    }

    if ( array_key_exists( 'user_id', $update_data ) ) {
        update_post_meta(
            $license_id,
            'user_id',
            absint( $update_data['user_id'] )
        );

        $updated_fields[] = 'user_id';
    }

    if ( array_key_exists( 'order_id', $update_data ) ) {
        update_post_meta(
            $license_id,
            'order_id',
            sanitize_text_field( $update_data['order_id'] )
        );

        $updated_fields[] = 'order_id';
    }

    update_post_meta( $license_id, 'updated_at', current_time( 'mysql' ) );

    return array(
        'success'        => true,
        'message'        => __( 'License details updated successfully.', 'uupd-server' ),
        'license_id'     => $license_id,
        'updated_fields' => $updated_fields,
    );
}

