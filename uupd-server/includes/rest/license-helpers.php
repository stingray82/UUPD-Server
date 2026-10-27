<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Keep a short, private history without storing keys, domains or request payloads. */
function uupd_record_license_interaction( $license_id, $event, $details = array() ) {
    $license_id = absint( $license_id );
    if ( ! $license_id || '1' !== (string) get_option( 'uupd_license_history_enabled', '0' ) ) {
        return;
    }
    $allowed = array( 'order_id', 'subscription_id', 'reason', 'result', 'status', 'expiry', 'source' );
    $safe = array();
    foreach ( $allowed as $key ) {
        if ( isset( $details[ $key ] ) && is_scalar( $details[ $key ] ) ) {
            $safe[ $key ] = substr( sanitize_text_field( (string) $details[ $key ] ), 0, 120 );
        }
    }
    $history = get_post_meta( $license_id, '_uupd_interaction_history', true );
    $history = is_array( $history ) ? $history : array();
    $history[] = array( 'at' => current_time( 'mysql' ), 'event' => sanitize_key( $event ), 'details' => $safe );
    update_post_meta( $license_id, '_uupd_interaction_history', array_slice( $history, -20 ) );
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

/** Effective feature flags: old licences inherit the site defaults. */
function uupd_license_feature_enabled( $license_id, $feature ) {
    if ( ! in_array( $feature, array( 'rotate', 'children' ), true ) ) {
        return false;
    }
    $parent_id = absint( get_post_meta( $license_id, 'uupd_parent_license_id', true ) );
    if ( $parent_id ) {
        return $parent_id !== absint( $license_id ) && 'rotate' === $feature && uupd_license_feature_enabled( $parent_id, 'rotate' );
    }
    $meta_key = 'uupd_allow_' . $feature;
    $value = metadata_exists( 'post', $license_id, $meta_key )
        ? get_post_meta( $license_id, $meta_key, true )
        : get_option( $meta_key, 'rotate' === $feature ? '1' : '0' );
    return '1' === (string) $value;
}

/** Return the master of a child, or the licence itself for a master. */
function uupd_license_master_id( $license_id ) {
    $parent_id = absint( get_post_meta( $license_id, 'uupd_parent_license_id', true ) );
    return $parent_id ?: absint( $license_id );
}

/** The master's package limit is used when its explicit limit is zero. */
function uupd_license_master_limit( $master_id ) {
    $limit = absint( get_post_meta( $master_id, 'activation_limit', true ) );
    if ( $limit ) { return $limit; }
    $update_id = absint( get_post_meta( $master_id, 'update_id', true ) );
    $package_id = get_post_meta( $master_id, 'package_id', true );
    $packages = get_post_meta( $update_id, 'packages', true );
    if ( is_array( $packages ) ) {
        foreach ( $packages as $package ) {
            if ( isset( $package['id'] ) && $package['id'] === $package_id ) {
                return absint( $package['activation_limit'] ?? 0 );
            }
        }
    }
    return 0; // Unlimited, as in the existing licence API.
}

function uupd_license_child_ids( $master_id ) {
    return get_posts( array(
        'post_type' => 'uupd_license', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => -1,
        'meta_key' => 'uupd_parent_license_id', 'meta_value' => absint( $master_id ),
    ) );
}

/** Missing limits inherit the store setting. -1 means unlimited child records. */
function uupd_license_max_children( $master_id ) {
    $value = metadata_exists( 'post', $master_id, 'uupd_max_children' )
        ? get_post_meta( $master_id, 'uupd_max_children', true )
        : get_option( 'uupd_max_children', 'auto' );
    $master_limit = uupd_license_master_limit( $master_id );
    if ( 'auto' === (string) $value || '' === (string) $value ) {
        return $master_limit ?: -1;
    }
    if ( 'unlimited' === (string) $value || '-2' === (string) $value ) {
        return $master_limit ?: -1;
    }
    $count = max( 0, absint( $value ) );
    return $master_limit ? min( $count, $master_limit ) : $count;
}

function uupd_license_child_limit_reached( $master_id ) {
    $maximum = uupd_license_max_children( $master_id );
    return -1 !== $maximum && count( uupd_license_child_ids( $master_id ) ) >= $maximum;
}

function uupd_child_count_label( $master_id ) {
    $maximum = uupd_license_max_children( $master_id );
    return count( uupd_license_child_ids( $master_id ) ) . ' / ' . ( -1 === $maximum ? __( 'Unlimited', 'uupd-server' ) : $maximum );
}

/** Active domains consume one slot each across the master and its live children. */
function uupd_license_family_usage( $master_id ) {
    $ids = array_merge( array( absint( $master_id ) ), uupd_license_child_ids( $master_id ) );
    $total = 0;
    foreach ( $ids as $id ) {
        if ( $id !== absint( $master_id ) && ! uupd_license_is_usable( $id ) ) { continue; }
        $domains = get_post_meta( $id, 'used_domains', true );
        $total += is_array( $domains ) ? count( $domains ) : 0;
    }
    return $total;
}

function uupd_child_activation_limit( $child_id ) {
    // Older children used a copy of the master's limit; keep that stored value.
    $stored = absint( get_post_meta( $child_id, 'activation_limit', true ) );
    if ( $stored ) { return $stored; }
    $domains = get_post_meta( $child_id, 'used_domains', true );
    return max( 1, is_array( $domains ) ? count( $domains ) : 0 );
}

function uupd_sanitize_child_note( $note ) {
    $note = sanitize_textarea_field( $note );
    return function_exists( 'mb_substr' ) ? mb_substr( $note, 0, 500 ) : substr( $note, 0, 500 );
}

/** A child is usable only while its master licence is usable. */
function uupd_license_is_usable( $license_id ) {
    $license = get_post( $license_id );
    if ( ! $license || 'uupd_license' !== $license->post_type || 'publish' !== $license->post_status ) {
        return false;
    }
    if ( 'active' !== get_post_meta( $license_id, 'status', true ) ) {
        return false;
    }
    $parent_id = absint( get_post_meta( $license_id, 'uupd_parent_license_id', true ) );
    // A child's term follows its master, including later renewals.
    $expiry = get_post_meta( $parent_id ?: $license_id, 'expiry', true );
    if ( $expiry && strtotime( $expiry . ' 23:59:59' ) < time() ) {
        return false;
    }
    return ! $parent_id || ( $parent_id !== $license_id && uupd_license_is_usable( $parent_id ) );
}

/** Rotate in place: all links by post ID survive, while the old key stops working. */
function uupd_rotate_license( $license_id, $admin_override = false ) {
    $license_id = absint( $license_id );
    if ( 'uupd_license' !== get_post_type( $license_id ) || ( ! $admin_override && ( ! uupd_license_feature_enabled( $license_id, 'rotate' ) || ! uupd_license_is_usable( $license_id ) ) ) ) {
        return new WP_Error( 'uupd_rotation_disabled', __( 'Key rotation is unavailable for this licence.', 'uupd-server' ) );
    }
    $update_id = absint( get_post_meta( $license_id, 'update_id', true ) );
    for ( $attempt = 0; $attempt < 5; $attempt++ ) {
        $key = uupd_generate_license_key( $update_id, 35 );
        if ( ! uupd_get_license_by_key( $key ) ) {
            update_post_meta( $license_id, 'license_key', $key );
            wp_update_post( array( 'ID' => $license_id, 'post_title' => $key, 'post_name' => sanitize_title( $key ) ) );
            return $key;
        }
    }
    return new WP_Error( 'uupd_key_collision', __( 'Could not generate a unique licence key.', 'uupd-server' ) );
}

/** Create a subordinate key with a per-child cap inside the master's shared pool. */
function uupd_create_child_license( $parent_id, $limit = 1, $note = '', $admin_override = false ) {
    $parent_id = absint( $parent_id );
    if ( get_post_meta( $parent_id, 'uupd_parent_license_id', true ) || ( ! $admin_override && ! uupd_license_feature_enabled( $parent_id, 'children' ) ) || ! uupd_license_is_usable( $parent_id ) ) {
        return new WP_Error( 'uupd_children_disabled', __( 'Child licences are unavailable for this licence.', 'uupd-server' ) );
    }
    if ( uupd_license_child_limit_reached( $parent_id ) ) {
        return new WP_Error( 'uupd_child_count_limit', __( 'This licence has reached its child key limit.', 'uupd-server' ) );
    }
    $limit = absint( $limit );
    $master_limit = uupd_license_master_limit( $parent_id );
    if ( ! $limit || ( $master_limit && $limit > $master_limit ) ) {
        return new WP_Error( 'uupd_invalid_child_limit', __( 'Choose a child activation limit within the master limit.', 'uupd-server' ) );
    }
    $child_id = uupd_create_license( array(
        'update_id'        => absint( get_post_meta( $parent_id, 'update_id', true ) ),
        'package_id'       => get_post_meta( $parent_id, 'package_id', true ),
        'user_id'          => absint( get_post_meta( $parent_id, 'user_id', true ) ),
        'activation_limit' => $limit,
        'expiry'           => get_post_meta( $parent_id, 'expiry', true ),
        'order_id'         => get_post_meta( $parent_id, 'order_id', true ),
        'allow_rotate'     => uupd_license_feature_enabled( $parent_id, 'rotate' ) ? '1' : '0',
        'allow_children'   => '0',
    ) );
    if ( ! is_wp_error( $child_id ) ) {
        update_post_meta( $child_id, 'uupd_parent_license_id', $parent_id );
        update_post_meta( $child_id, 'uupd_child_note', uupd_sanitize_child_note( $note ) );
    }
    return $child_id;
}

function uupd_update_child_license( $child_id, $limit, $note ) {
    $child_id = absint( $child_id );
    $master_id = absint( get_post_meta( $child_id, 'uupd_parent_license_id', true ) );
    if ( ! $master_id || ! get_post( $child_id ) || 'active' !== get_post_meta( $child_id, 'status', true ) ) {
        return new WP_Error( 'uupd_invalid_child', __( 'Child key is unavailable.', 'uupd-server' ) );
    }
    $limit = absint( $limit );
    $master_limit = uupd_license_master_limit( $master_id );
    $domains = get_post_meta( $child_id, 'used_domains', true );
    $used = is_array( $domains ) ? count( $domains ) : 0;
    if ( ! $limit || $limit < $used || ( $master_limit && $limit > $master_limit ) ) {
        return new WP_Error( 'uupd_invalid_child_limit', __( 'The limit must cover existing activations and fit within the master limit.', 'uupd-server' ) );
    }
    update_post_meta( $child_id, 'activation_limit', $limit );
    update_post_meta( $child_id, 'uupd_child_note', uupd_sanitize_child_note( $note ) );
    return true;
}

function uupd_revoke_child_license( $child_id ) {
    $child_id = absint( $child_id );
    if ( ! get_post_meta( $child_id, 'uupd_parent_license_id', true ) || ! get_post( $child_id ) ) {
        return new WP_Error( 'uupd_invalid_child', __( 'Child key not found.', 'uupd-server' ) );
    }
    update_post_meta( $child_id, 'status', 'revoked' );
    update_post_meta( $child_id, 'used_domains', array() );
    return true;
}

function uupd_revoke_all_child_licenses( $master_id ) {
    $revoked = 0;
    foreach ( uupd_license_child_ids( $master_id ) as $child_id ) {
        if ( 'revoked' !== get_post_meta( $child_id, 'status', true ) && true === uupd_revoke_child_license( $child_id ) ) {
            $revoked++;
        }
    }
    return $revoked;
}

function uupd_delete_child_license( $child_id ) {
    $child_id = absint( $child_id );
    if ( ! get_post_meta( $child_id, 'uupd_parent_license_id', true ) || ! get_post( $child_id ) ) {
        return new WP_Error( 'uupd_invalid_child', __( 'Child key not found.', 'uupd-server' ) );
    }
    return wp_delete_post( $child_id, true ) ? true : new WP_Error( 'uupd_child_delete_failed', __( 'Could not delete child key.', 'uupd-server' ) );
}

add_action( 'before_delete_post', function ( $post_id ) {
    if ( 'uupd_license' !== get_post_type( $post_id ) || get_post_meta( $post_id, 'uupd_parent_license_id', true ) ) { return; }
    foreach ( uupd_license_child_ids( $post_id ) as $child_id ) {
        wp_delete_post( $child_id, true );
    }
} );

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
            'allow_rotate'     => null,
            'allow_children'   => null,
            'max_children'     => null,
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
    uupd_record_license_interaction( $post_id, 'created', array( 'order_id' => $order_id, 'source' => 'api' ) );
    foreach ( array( 'rotate', 'children' ) as $feature ) {
        if ( null !== $args[ 'allow_' . $feature ] ) {
            update_post_meta( $post_id, 'uupd_allow_' . $feature, $args[ 'allow_' . $feature ] ? '1' : '0' );
        }
    }
    if ( null !== $args['max_children'] ) {
        update_post_meta( $post_id, 'uupd_max_children', '-2' === (string) $args['max_children'] ? 'unlimited' : absint( $args['max_children'] ) );
    }

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
    if ( ! get_post_meta( $license_id, 'uupd_parent_license_id', true ) ) {
        $children = get_posts( array(
            'post_type' => 'uupd_license', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1,
            'meta_key' => 'uupd_parent_license_id', 'meta_value' => $license_id,
        ) );
        foreach ( $children as $child_id ) {
            foreach ( array( 'expiry' ) as $field ) {
                if ( in_array( $field, $updated_fields, true ) ) {
                    update_post_meta( $child_id, $field, get_post_meta( $license_id, $field, true ) );
                }
            }
        }
    }

    uupd_record_license_interaction( $license_id, 'updated', array( 'reason' => implode( ', ', $updated_fields ), 'status' => get_post_meta( $license_id, 'status', true ), 'expiry' => get_post_meta( $license_id, 'expiry', true ), 'source' => 'api' ) );
    return array(
        'success'        => true,
        'message'        => __( 'License details updated successfully.', 'uupd-server' ),
        'license_id'     => $license_id,
        'updated_fields' => $updated_fields,
    );
}

