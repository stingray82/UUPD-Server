<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API endpoints for licensing.
 *
 * Routes:
 *  - POST /uupd/v1/license/check
 *  - POST /uupd/v1/license/activate
 *  - POST /uupd/v1/license/deactivate
 *  - POST /uupd/v1/license           (Bearer-protected create)
 *  - POST /uupd/v1/license/update    (Bearer-protected update)
 *  - POST /uupd/v1/license/suspend   (Bearer-protected suspend)
 */
class UUPD_REST_Licenses {

    /**
     * Hook entrypoint called by UUPD_Core.
     */
    public function register_hooks() {
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }

    /* -------------------------------------------------------------------------
     * Route registration
     * ---------------------------------------------------------------------- */

    public function register_rest_routes() {

        // Public endpoints (client side)
        register_rest_route(
            'uupd/v1',
            '/license/check',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'rest_license_check' ],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'uupd/v1',
            '/license/activate',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'rest_license_activate' ],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'uupd/v1',
            '/license/deactivate',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'rest_license_deactivate' ],
                'permission_callback' => '__return_true',
            ]
        );

        // Server-to-server endpoints (Bearer protected)
        register_rest_route(
            'uupd/v1',
            '/license',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'rest_license_create' ],
                'permission_callback' => function () {
                    return $this->verify_bearer();
                },
            ]
        );

        register_rest_route(
            'uupd/v1',
            '/license/update',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'rest_license_update' ],
                'permission_callback' => function () {
                    return $this->verify_bearer();
                },
            ]
        );

        register_rest_route(
            'uupd/v1',
            '/license/suspend',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'rest_license_suspend' ],
                'permission_callback' => function () {
                    return $this->verify_bearer();
                },
            ]
        );
    }

    /* -------------------------------------------------------------------------
     * Internal helpers
     * ---------------------------------------------------------------------- */

    /**
     * Verify Authorization: Bearer <key> against stored option.
     */
    private function verify_bearer() {

        // Try to get headers as array.
        $headers = [];
        if ( function_exists( 'getallheaders' ) ) {
            $headers = getallheaders();
        }

        $auth = '';

        if ( isset( $headers['Authorization'] ) ) {
            $auth = $headers['Authorization'];
        } elseif ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
            $auth = wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
        }

        if ( ! $auth || stripos( $auth, 'Bearer ' ) !== 0 ) {
            return false;
        }

        $key    = trim( substr( $auth, 7 ) );
        $stored = get_option( 'uupd_bearer_key', '' );

        return (bool) ( $stored && hash_equals( $stored, $key ) );
    }

    /**
     * Find a license post by its license_key meta.
     *
     * @param string $key
     * @return WP_Post|null
     */
    private function get_license_by_key( $key ) {

        $posts = get_posts(
            [
                'post_type'   => 'uupd_license',
                'meta_key'    => 'license_key',
                'meta_value'  => $key,
                'numberposts' => 1,
            ]
        );

        return $posts ? $posts[0] : null;
    }

    /**
     * Check if a license is expired (based on date and status).
     * Will auto-update status to 'expired' if date passed.
     *
     * @param int $license_id
     * @return bool
     */
    private function license_is_expired( $license_id ) {

        $status = get_post_meta( $license_id, 'status', true );
        if ( $status === 'expired' ) {
            return true;
        }

        $parent_id = absint( get_post_meta( $license_id, 'uupd_parent_license_id', true ) );
        $expiry = get_post_meta( $parent_id ?: $license_id, 'expiry', true );
        if ( empty( $expiry ) ) {
            return false; // lifetime
        }

        $ts = strtotime( $expiry . ' 23:59:59' );
        if ( ! $ts ) {
            return false; // invalid date, fail-open
        }

        if ( time() > $ts ) {
            if ( ! $parent_id && $status === 'active' ) {
                update_post_meta( $license_id, 'status', 'expired' );
            }
            return true;
        }

        return false;
    }

    /**
     * Generate a random license key with optional prefix from the update.
     *
     * @param int $update_id
     * @param int $length
     * @return string
     */
    private function generate_license_key( $update_id = 0, $length = 35 ) {

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

    /* -------------------------------------------------------------------------
     * /license/check
     * ---------------------------------------------------------------------- */

    public function rest_license_check( WP_REST_Request $req ) {

        $key    = sanitize_text_field( $req->get_param( 'license_key' ) );
        $slug   = sanitize_text_field( $req->get_param( 'slug' ) );
        $domain = sanitize_text_field( $req->get_param( 'domain' ) );

        if ( ! $key || ! $slug ) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => 'Missing license_key or slug',
                ],
                400
            );
        }

        $license = $this->get_license_by_key( $key );
        if ( ! $license ) {
            return [
                'success' => false,
                'message' => 'License not found',
            ];
        }

        $status = get_post_meta( $license->ID, 'status', true );

        // Explicit expired
        if ( $status === 'expired' ) {
            uupd_record_license_interaction( $license->ID, 'check_rejected', array( 'reason' => 'expired', 'source' => 'rest' ) );
            return [
                'success' => false,
                'message' => 'License expired',
                'status'  => 'expired',
            ];
        }

        if ( $status !== 'active' || ! uupd_license_is_usable( $license->ID ) ) {
            uupd_record_license_interaction( $license->ID, 'check_rejected', array( 'reason' => 'inactive', 'status' => $status, 'source' => 'rest' ) );
            return [
                'success' => false,
                'message' => 'License not active',
                'status'  => $status,
            ];
        }

        // Date-based expiry
        if ( $this->license_is_expired( $license->ID ) ) {
            uupd_record_license_interaction( $license->ID, 'check_rejected', array( 'reason' => 'date_expired', 'source' => 'rest' ) );
            return [
                'success' => false,
                'message' => 'License expired',
                'status'  => 'expired',
            ];
        }

        $master_id   = uupd_license_master_id( $license->ID );
        $update_id   = intval( get_post_meta( $master_id, 'update_id', true ) );
        $update_slug = get_post_meta( $update_id, 'slug', true );

        if ( $slug !== $update_slug ) {
            uupd_record_license_interaction( $license->ID, 'check_rejected', array( 'reason' => 'product_mismatch', 'source' => 'rest' ) );
            return [
                'success' => false,
                'message' => 'License not valid for this product',
            ];
        }

        $master_limit = uupd_license_master_limit( $master_id );
        $limit = $master_id === $license->ID ? $master_limit : uupd_child_activation_limit( $license->ID );

        $used_domains = get_post_meta( $license->ID, 'used_domains', true );
        if ( ! is_array( $used_domains ) ) {
            $used_domains = [];
        }

        // Child checks for a named site must use an active child connection.
        if ( $master_id !== $license->ID && $domain && ! in_array( $domain, $used_domains, true ) ) {
            uupd_record_license_interaction( $license->ID, 'check_rejected', array( 'reason' => 'site_inactive', 'source' => 'rest' ) );
            return [
                'success' => false,
                'message' => 'Site not activated for this child key',
                'domain_active' => false,
            ];
        }

        $activations = count( $used_domains );

        uupd_record_license_interaction( $license->ID, 'check_succeeded', array( 'result' => 'valid', 'source' => 'rest' ) );
        return [
            'success'          => true,
            'status'           => $status,
            'activations'      => $activations,
            'activation_limit' => $limit,
            'master_activations' => uupd_license_family_usage( $master_id ),
            'master_activation_limit' => $master_limit,
            'domain_active'    => (bool) ( $domain && in_array( $domain, $used_domains, true ) ),
        ];
    }

    /* -------------------------------------------------------------------------
     * /license/activate
     * ---------------------------------------------------------------------- */

    public function rest_license_activate( WP_REST_Request $req ) {

        $key    = sanitize_text_field( $req->get_param( 'license_key' ) );
        $slug   = sanitize_text_field( $req->get_param( 'slug' ) );
        $domain = sanitize_text_field( $req->get_param( 'domain' ) );

        if ( ! $key || ! $slug || ! $domain ) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => 'Missing license_key, slug or domain',
                ],
                400
            );
        }

        $license = $this->get_license_by_key( $key );
        if ( ! $license ) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => 'License not found',
                ],
                404
            );
        }

        $status = get_post_meta( $license->ID, 'status', true );

        if ( $status === 'expired' ) {
            return [
                'success' => false,
                'message' => 'License expired',
            ];
        }

        if ( $status !== 'active' || ! uupd_license_is_usable( $license->ID ) ) {
            return [
                'success' => false,
                'message' => 'License not active',
            ];
        }

        if ( $this->license_is_expired( $license->ID ) ) {
            return [
                'success' => false,
                'message' => 'License expired',
            ];
        }

        $master_id   = uupd_license_master_id( $license->ID );
        $update_id   = intval( get_post_meta( $master_id, 'update_id', true ) );
        $update_slug = get_post_meta( $update_id, 'slug', true );

        if ( $slug !== $update_slug ) {
            return [
                'success' => false,
                'message' => 'License not valid for this product',
            ];
        }

        $used_domains = get_post_meta( $license->ID, 'used_domains', true );
        if ( ! is_array( $used_domains ) ) {
            $used_domains = [];
        }

        if ( in_array( $domain, $used_domains, true ) ) {
            return [
                'success'     => true,
                'message'     => 'Already activated',
                'activations' => count( $used_domains ),
            ];
        }

        $master_limit = uupd_license_master_limit( $master_id );
        $limit = $master_id === $license->ID ? $master_limit : uupd_child_activation_limit( $license->ID );

        if ( $limit && count( $used_domains ) >= $limit ) {
            return [
                'success' => false,
                'message' => 'Activation limit reached',
            ];
        }
        if ( $master_limit && uupd_license_family_usage( $master_id ) >= $master_limit ) {
            return [ 'success' => false, 'message' => 'Master activation limit reached' ];
        }

        $used_domains[] = $domain;
        update_post_meta( $license->ID, 'used_domains', $used_domains );
        uupd_record_license_interaction( $license->ID, 'activated', array( 'result' => 'success', 'source' => 'rest' ) );

        return [
            'success'     => true,
            'message'     => 'Activated',
            'activations' => count( $used_domains ),
        ];
    }

    /* -------------------------------------------------------------------------
     * /license/deactivate
     * ---------------------------------------------------------------------- */

    public function rest_license_deactivate( WP_REST_Request $req ) {

        $key    = sanitize_text_field( $req->get_param( 'license_key' ) );
        $domain = sanitize_text_field( $req->get_param( 'domain' ) );

        if ( ! $key || ! $domain ) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => 'Missing license_key or domain',
                ],
                400
            );
        }

        $license = $this->get_license_by_key( $key );
        if ( ! $license ) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => 'License not found',
                ],
                404
            );
        }

        $used_domains = get_post_meta( $license->ID, 'used_domains', true );
        if ( ! is_array( $used_domains ) ) {
            $used_domains = [];
        }

        $used_domains = array_values( array_diff( $used_domains, [ $domain ] ) );
        update_post_meta( $license->ID, 'used_domains', $used_domains );
        uupd_record_license_interaction( $license->ID, 'deactivated', array( 'result' => 'success', 'source' => 'rest' ) );

        return [
            'success'     => true,
            'message'     => 'Deactivated',
            'activations' => count( $used_domains ),
        ];
    }

    /* -------------------------------------------------------------------------
     * /license  (create, bearer-protected)
     * ---------------------------------------------------------------------- */

    /**
     * POST /uupd/v1/license
     *
     * Params:
     * - update_id        (required)
     * - package_id
     * - user_id
     * - license_key      (optional, auto-generated if empty)
     * - activation_limit
     * - expiry           (YYYY-MM-DD or empty)
     * - order_id         (optional, store order/invoice reference)
     */
    public function rest_license_create( WP_REST_Request $req ) {

        $update_id   = intval( $req->get_param( 'update_id' ) );
        $package_id  = sanitize_text_field( $req->get_param( 'package_id' ) );
        $user_id     = intval( $req->get_param( 'user_id' ) );
        $license_key = sanitize_text_field( $req->get_param( 'license_key' ) );
        $limit       = intval( $req->get_param( 'activation_limit' ) );
        $expiry      = sanitize_text_field( $req->get_param( 'expiry' ) );
        $order_id    = sanitize_text_field( $req->get_param( 'order_id' ) ); // NEW

        if ( ! $update_id ) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => 'Missing update_id',
                ],
                400
            );
        }

        if ( empty( $license_key ) ) {
            $license_key = $this->generate_license_key( $update_id, 35 );
        }

        $post_id = wp_insert_post(
            [
                'post_type'   => 'uupd_license',
                'post_status' => 'publish',
                'post_title'  => $license_key,
            ],
            true
        );

        if ( is_wp_error( $post_id ) ) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => $post_id->get_error_message(),
                ],
                500
            );
        }

        update_post_meta( $post_id, 'update_id',        $update_id );
        update_post_meta( $post_id, 'package_id',       $package_id );
        update_post_meta( $post_id, 'user_id',          $user_id );
        update_post_meta( $post_id, 'license_key',      $license_key );
        update_post_meta( $post_id, 'status',           'active' );
        update_post_meta( $post_id, 'activation_limit', $limit );
        update_post_meta( $post_id, 'expiry',           $expiry );
        update_post_meta( $post_id, 'order_id',         $order_id ); // NEW
        uupd_record_license_interaction( $post_id, 'created', array( 'order_id' => $order_id, 'source' => 'rest' ) );

        return [
            'success'     => true,
            'license_key' => $license_key,
            'license_id'  => $post_id,
        ];
    }

    /* -------------------------------------------------------------------------
     * /license/update  (update, bearer-protected)
     * ---------------------------------------------------------------------- */

    /**
     * POST /uupd/v1/license/update
     *
     * Params:
     * - license_id       (required unless license_key supplied)
     * - license_key      (optional alias lookup when license_id is omitted)
     * - status           (optional; active, inactive, expired, suspended, cancelled, refunded)
     * - expiry           (optional; YYYY-MM-DD or empty for lifetime)
     * - expiry_date      (optional alias for expiry)
     * - activation_limit (optional; 0 = unlimited)
     * - package_id       (optional package short ID)
     * - user_id          (optional linked user ID)
     * - order_id         (optional order/invoice reference)
     *
     * Uses uupd_update_license() when available, so REST updates stay aligned
     * with the programmatic licence helper.
     */
    public function rest_license_update( WP_REST_Request $req ) {

        $license_id  = intval( $req->get_param( 'license_id' ) );
        $license_key = sanitize_text_field( $req->get_param( 'license_key' ) );

        if ( ! $license_id && $license_key ) {
            $license = $this->get_license_by_key( $license_key );
            if ( ! $license ) {
                return new WP_REST_Response(
                    [
                        'success' => false,
                        'message' => 'License not found',
                    ],
                    404
                );
            }

            $license_id = intval( $license->ID );
        }

        if ( ! $license_id ) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => 'Missing license_id or license_key',
                ],
                400
            );
        }

        if ( ! function_exists( 'uupd_update_license' ) ) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => 'License update helper is unavailable',
                ],
                500
            );
        }

        $supported_fields = [
            'status',
            'expiry',
            'expiry_date',
            'activation_limit',
            'package_id',
            'user_id',
            'order_id',
        ];

        $update_data = [];

        foreach ( $supported_fields as $field ) {
            if ( null !== $req->get_param( $field ) ) {
                $update_data[ $field ] = $req->get_param( $field );
            }
        }

        if ( empty( $update_data ) ) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => 'No supported license update fields supplied',
                ],
                400
            );
        }

        $result = uupd_update_license( $license_id, $update_data );

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => $result->get_error_message(),
                    'code'    => $result->get_error_code(),
                ],
                400
            );
        }

        return $result;
    }

    /* -------------------------------------------------------------------------
     * /license/suspend  (bearer-protected)
     * ---------------------------------------------------------------------- */

    /**
     * POST /uupd/v1/license/suspend
     *
     * Params:
     * - license_key
     */
    public function rest_license_suspend( WP_REST_Request $req ) {

        $key = sanitize_text_field( $req->get_param( 'license_key' ) );

        if ( ! $key ) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => 'Missing license_key',
                ],
                400
            );
        }

        $license = $this->get_license_by_key( $key );
        if ( ! $license ) {
            return new WP_REST_Response(
                [
                    'success' => false,
                    'message' => 'License not found',
                ],
                404
            );
        }

        update_post_meta( $license->ID, 'status', 'suspended' );
        uupd_record_license_interaction( $license->ID, 'suspended', array( 'source' => 'rest' ) );

        return [
            'success' => true,
            'message' => 'License suspended',
        ];
    }
}
