<?php
/**
 * ------------------------------------------------------------------
 * Helpers for UUPD "simple license" shortcodes
 * ------------------------------------------------------------------
 */

/**
 * Normalise a page value to a full URL.
 * Accepts full URL or path.
 */
function uupd_simple_normalize_page_url( $page ) {
    $page = trim( (string) $page );
    if ( '' === $page ) {
        return '';
    }
    if ( preg_match( '#^https?://#i', $page ) ) {
        return $page;
    }
    return home_url( '/' . ltrim( $page, '/' ) );
}

/**
 * Get all licenses for a given user (as WP_Post objects).
 */
function uupd_simple_get_user_licenses( $user_id ) {
    $args = array(
        'post_type'      => 'uupd_license',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => array(
            array(
                'key'   => 'user_id',
                'value' => (int) $user_id,
                'type'  => 'NUMERIC',
            ),
        ),
    );

    return get_posts( $args );
}

/**
 * Get current user’s UUPD license post by ID or license key.
 */
function uupd_simple_get_current_user_license( $license_id = 0, $license_key = '' ) {
    if ( ! is_user_logged_in() ) {
        return null;
    }

    $user_id = get_current_user_id();
    $args    = array(
        'post_type'      => 'uupd_license',
        'posts_per_page' => 1,
        'post_status'    => 'any',
        'meta_query'     => array(
            array(
                'key'   => 'user_id',
                'value' => $user_id,
            ),
        ),
    );

    if ( $license_id ) {
        $args['p'] = $license_id;
    } elseif ( $license_key ) {
        $args['meta_query'][] = array(
            'key'   => 'license_key',
            'value' => $license_key,
        );
    } else {
        return null;
    }

    $query = new WP_Query( $args );
    if ( $query->have_posts() ) {
        return $query->posts[0];
    }

    return null;
}

/**
 * Build a signed UUPD download URL for the *latest* file of an update.
 *
 * Mirrors the logic used in UUPD_Server::output_metadata()/serve_download().
 */
function uupd_simple_build_download_url( $update_id, $license_key = '' ) {
    $update_id = (int) $update_id;
    if ( ! $update_id ) {
        return '';
    }

    $slug = get_post_meta( $update_id, 'slug', true );
    if ( ! $slug ) {
        return '';
    }

    // Remote redirect takes priority, exactly like the server.
    $remote = get_post_meta( $update_id, 'remote_url', true );
    if ( $remote ) {
        $remote = esc_url_raw( $remote );
        return $remote ? $remote : '';
    }

    // Determine the first file id (top of list).
    $files   = get_post_meta( $update_id, 'files', true );
    $file_id = 0;

    if ( is_array( $files ) && ! empty( $files[0]['id'] ) ) {
        $file_id = (int) $files[0]['id'];
    } else {
        $file_id = (int) get_post_meta( $update_id, 'file_id', true ); // legacy fallback
    }

    if ( ! $file_id ) {
        return '';
    }

    // Same expiry + token logic as UUPD_Server, with safe fallback.
    if ( class_exists( 'UUPD_Server' ) && defined( 'UUPD_Server::TOKEN_EXPIRY' ) ) {
        $ttl = UUPD_Server::TOKEN_EXPIRY;
    } else {
        $ttl = 3600;
    }

    $expires = time() + $ttl;
    $data    = "{$slug}|{$file_id}|{$expires}";
    $token   = hash_hmac( 'sha256', $data, AUTH_KEY );

    // Domain: use site host.
    $host   = wp_parse_url( home_url(), PHP_URL_HOST );
    $domain = $host ? $host : '';

    $url = add_query_arg(
        array(
            'action'  => 'download',
            'slug'    => $slug,
            'expires' => $expires,
            'token'   => $token,
            'key'     => $license_key,
            'domain'  => $domain,
        ),
        site_url( '/' )
    );

    return esc_url( $url );
}

/**
 * ------------------------------------------------------------------
 * [uupd_simple_license_activations]
 *
 * Shows and manages activations (domains) for a single license.
 * URL params: ?license=POST_ID or ?license_key=KEY
 * ------------------------------------------------------------------
 */
function uupd_simple_license_activations_shortcode( $atts ) {

    $atts = shortcode_atts(
        array(
            'quiet_if_missing' => 'no',
        ),
        $atts,
        'uupd_simple_license_activations'
    );

    $quiet_if_missing = in_array(
        strtolower( (string) $atts['quiet_if_missing'] ),
        array( 'yes', 'true', '1' ),
        true
    );

    if ( ! is_user_logged_in() ) {
        return '<p>You must be logged in to view license activations.</p>';
    }

    $license_id  = isset( $_GET['license'] ) ? (int) $_GET['license'] : 0;
    $license_key = isset( $_GET['license_key'] ) ? sanitize_text_field( wp_unslash( $_GET['license_key'] ) ) : '';

    $has_param = ( $license_id || $license_key );

    // Find the license for the current user.
    $license_post = uupd_simple_get_current_user_license( $license_id, $license_key );

    if ( ! $license_post ) {
        if ( ! $has_param && $quiet_if_missing ) {
            return '';
        }
        return '<p>License not found for your account.</p>';
    }

    $license_id  = $license_post->ID;
    $license_key = (string) get_post_meta( $license_id, 'license_key', true );
    $update_id   = (int) get_post_meta( $license_id, 'update_id', true );
    $update_slug = $update_id ? (string) get_post_meta( $update_id, 'slug', true ) : '';

    $notices        = array();
    $errors         = array();
    $refresh_domains = false;

    if ( ! $update_slug ) {
        $errors[] = 'Configuration error: this license has no associated update slug.';
    }

    // ------------------------------------------------------------
    // Handle REMOVE activation (GET + nonce)
    // ------------------------------------------------------------
    if (
        empty( $errors )
        && isset( $_GET['uupd_remove_activation'], $_GET['_wpnonce'] )
    ) {
        $domain_raw = wp_unslash( $_GET['uupd_remove_activation'] );
        $domain     = sanitize_text_field( $domain_raw );

        $nonce_ok = wp_verify_nonce(
            wp_unslash( $_GET['_wpnonce'] ),
            'uupd_remove_activation_' . $license_id . '_' . $domain
        );

        if ( ! $nonce_ok ) {
            $errors[] = 'Security check failed when removing activation.';
        } elseif ( '' !== $domain ) {
            $endpoint = rest_url( 'uupd/v1/license/deactivate' );
            $response = wp_remote_post(
                $endpoint,
                array(
                    'timeout' => 10,
                    'body'    => array(
                        'license_key' => $license_key,
                        'domain'      => $domain,
                    ),
                )
            );

            if ( is_wp_error( $response ) ) {
                $errors[] = 'Could not contact the license server to remove activation.';
            } else {
                $code = wp_remote_retrieve_response_code( $response );
                $body = json_decode( wp_remote_retrieve_body( $response ), true );

                if ( 200 !== $code || empty( $body['success'] ) ) {
                    $msg      = isset( $body['message'] ) ? $body['message'] : 'Unknown error.';
                    $errors[] = 'Failed to remove activation: ' . esc_html( $msg );
                } else {
                    // Clear cache so we see the updated meta in THIS request.
                    clean_post_cache( $license_id );
                    $refresh_domains = true;
                    $notices[]       = 'Activation removed: ' . esc_html( $domain );
                }
            }
        }
    }

    // ------------------------------------------------------------
    // Handle ADD activation (POST + nonce)
    // ------------------------------------------------------------
    if (
        empty( $errors )
        && isset( $_POST['uupd_add_activation_domain'], $_POST['uupd_activation_nonce'] )
    ) {
        $nonce_ok = wp_verify_nonce(
            wp_unslash( $_POST['uupd_activation_nonce'] ),
            'uupd_add_activation_' . $license_id
        );

        if ( ! $nonce_ok ) {
            $errors[] = 'Security check failed when adding activation.';
        } else {
            $domain_raw = wp_unslash( $_POST['uupd_add_activation_domain'] );
            $domain     = sanitize_text_field( $domain_raw );

            if ( '' === $domain ) {
                $errors[] = 'Please enter a domain name.';
            } elseif ( ! empty( $update_slug ) ) {
                $endpoint = rest_url( 'uupd/v1/license/activate' );
                $response = wp_remote_post(
                    $endpoint,
                    array(
                        'timeout' => 10,
                        'body'    => array(
                            'license_key' => $license_key,
                            'slug'        => $update_slug,
                            'domain'      => $domain,
                        ),
                    )
                );

                if ( is_wp_error( $response ) ) {
                    $errors[] = 'Could not contact the license server to add activation.';
                } else {
                    $code = wp_remote_retrieve_response_code( $response );
                    $body = json_decode( wp_remote_retrieve_body( $response ), true );

                    if ( 200 !== $code || empty( $body['success'] ) ) {
                        $msg      = isset( $body['message'] ) ? $body['message'] : 'Unknown error.';
                        $errors[] = 'Failed to add activation: ' . esc_html( $msg );
                    } else {
                        clean_post_cache( $license_id );
                        $refresh_domains = true;
                        $notices[]       = 'Activation added: ' . esc_html( $domain );
                    }
                }
            }
        }
    }

    // Re-read used_domains (and get fresh data if we changed anything).
    if ( $refresh_domains ) {
        clean_post_cache( $license_id );
    }

    $used_domains = get_post_meta( $license_id, 'used_domains', true );
    if ( ! is_array( $used_domains ) ) {
        $used_domains = array();
    }

    // Activation limit (for display only).
    $limit_meta  = (int) get_post_meta( $license_id, 'activation_limit', true );
    $current     = count( $used_domains );
    $limit_label = $limit_meta > 0
        ? sprintf( '%d / %d', $current, $limit_meta )
        : sprintf( '%d / ∞', $current );

    $base_url = get_permalink();

    ob_start();
    ?>
    <div class="gpd-account gpd-license-activations-only">
        <section class="gpd-card gpd-license-activations">
            <header class="gpd-card__header">
                <h1 class="gpd-page-title">License Activations</h1>
            </header>
            <div class="gpd-card__body">

                <?php if ( ! empty( $errors ) ) : ?>
                    <div class="gpd-empty" style="color:#b91c1c;">
                        <?php foreach ( $errors as $msg ) : ?>
                            <p><?php echo esc_html( $msg ); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $notices ) ) : ?>
                    <div class="gpd-empty" style="color:#166534;">
                        <?php foreach ( $notices as $msg ) : ?>
                            <p><?php echo esc_html( $msg ); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <p>
                    <strong>License key:</strong>
                    <code class="gpd-pill gpd-copy-on-click"
                          data-gpd-copy="<?php echo esc_attr( $license_key ); ?>">
                        <?php echo esc_html( $license_key ); ?>
                    </code>
                </p>

                <p>
                    <strong>Activations:</strong>
                    <?php echo esc_html( $limit_label ); ?>
                </p>

                <?php if ( empty( $used_domains ) ) : ?>
                    <p class="gpd-empty">No activations yet.</p>
                <?php else : ?>
                    <ul class="gpd-list gpd-list--activations">
                        <?php foreach ( $used_domains as $domain ) :
                            $remove_url = wp_nonce_url(
                                add_query_arg(
                                    array(
                                        'uupd_remove_activation' => rawurlencode( $domain ),
                                        'license'                => (int) $license_id,
                                    ),
                                    $base_url
                                ),
                                'uupd_remove_activation_' . $license_id . '_' . $domain
                            );
                            ?>
                            <li class="gpd-list__item gpd-activation">
                                <div class="gpd-activation__info">
                                    <span class="gpd-activation__domain"><?php echo esc_html( $domain ); ?></span>
                                </div>
                                <a class="gpd-link gpd-link--danger gpd-activation__remove"
                                   href="<?php echo esc_url( $remove_url ); ?>"
                                   onclick="return confirm('Remove this activation?');">
                                    Remove
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>

                <hr />

                <form method="post" class="gpd-add-activation-form">
                    <h2 class="gpd-section-title">Add Activation</h2>
                    <p>
                        <label for="uupd_add_activation_domain">
                            Domain (e.g. <code>example.com</code>)
                        </label><br />
                        <input type="text"
                               id="uupd_add_activation_domain"
                               name="uupd_add_activation_domain"
                               class="regular-text"
                               placeholder="example.com" />
                    </p>
                    <?php wp_nonce_field( 'uupd_add_activation_' . $license_id, 'uupd_activation_nonce' ); ?>
                    <p>
                        <button type="submit" class="gpd-button gpd-button--primary">
                            Add Activation
                        </button>
                    </p>
                </form>
            </div>
        </section>
    </div>
    <?php

    // Click-to-copy for this page (shared with others).
    if ( ! defined( 'GPD_COPY_SCRIPT_PRINTED' ) ) {
        define( 'GPD_COPY_SCRIPT_PRINTED', true );
        ?>
        <script>
        document.addEventListener('click', function (e) {
            var pill = e.target.closest('.gpd-copy-on-click');
            if (!pill) return;
            var key = pill.getAttribute('data-gpd-copy');
            if (!key || !navigator.clipboard) return;

            var originalText = pill.textContent;
            navigator.clipboard.writeText(key).then(function () {
                pill.textContent = 'Copied!';
                setTimeout(function () {
                    pill.textContent = originalText;
                }, 1500);
            }).catch(function () {});
        });
        </script>
        <?php
    }

    return ob_get_clean();
}
add_shortcode( 'uupd_simple_license_activations', 'uupd_simple_license_activations_shortcode' );


/**
 * ------------------------------------------------------------------
 * [uupd_simple_license_purchases]
 *
 * Simple table of licenses ("purchases") for the current user.
 * ------------------------------------------------------------------
 */
function uupd_simple_license_purchases_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) {
        return '<p>You must be logged in to view your purchases.</p>';
    }

    $user_id = get_current_user_id();

    $atts = shortcode_atts(
        array(
            'confirmation_page' => '',
            'activations_page'  => '',
        ),
        $atts,
        'uupd_simple_license_purchases'
    );

    $confirmation_base = uupd_simple_normalize_page_url( $atts['confirmation_page'] );
    $activations_base  = uupd_simple_normalize_page_url( $atts['activations_page'] );

    $licenses = uupd_simple_get_user_licenses( $user_id );

    if ( empty( $licenses ) ) {
        return '<p>No licenses (purchases) found for your account.</p>';
    }

    ob_start();
    ?>
    <div class="uupd-account uupd-purchases">
        <section class="uupd-card uupd-purchases-card">
            <header class="uupd-card__header">
                <h1 class="uupd-page-title">My Licenses / Purchases</h1>
            </header>

            <div class="uupd-card__body">
                <table class="uupd-table uupd-table--purchases">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Product</th>
                            <th>Details</th>
                            <th>Invoice</th>
                            <th>License / Activations</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $licenses as $license_post ) :
                            $license_id   = $license_post->ID;
                            $update_id    = (int) get_post_meta( $license_id, 'update_id', true );
                            $license_key  = (string) get_post_meta( $license_id, 'license_key', true );
                            $status       = (string) get_post_meta( $license_id, 'status', true );
                            $date         = mysql2date( get_option( 'date_format' ), $license_post->post_date );

                            $update_post  = $update_id ? get_post( $update_id ) : null;
                            $product_name = $update_post ? get_the_title( $update_post ) : __( 'Unknown product', 'uupd-server' );

                            // Confirmation link (license summary/download).
                            $details_html = '&mdash;';
                            if ( $confirmation_base ) {
                                $details_url  = add_query_arg( 'license', $license_id, $confirmation_base );
                                $details_html = sprintf(
                                    '<a href="%s">Details &amp; Download</a>',
                                    esc_url( $details_url )
                                );
                            }

                            // Activations link.
                            $activations_html = '&mdash;';
                            if ( $activations_base ) {
                                $act_url          = add_query_arg( 'license', $license_id, $activations_base );
                                $activations_html = sprintf(
                                    '<a href="%s">View Activations</a>',
                                    esc_url( $act_url )
                                );
                            }

                            // Invoice is not wired yet → stub.
                            $invoice_html = '&mdash;';
                            ?>
                            <tr>
                                <td><?php echo esc_html( $date ); ?></td>
                                <td>
                                    <?php echo esc_html( $product_name ); ?>
                                    <?php if ( $status ) : ?>
                                        <span class="uupd-badge uupd-badge--status uupd-badge--<?php echo esc_attr( $status ); ?>">
                                            <?php echo esc_html( ucfirst( $status ) ); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo $details_html; // phpcs:ignore ?></td>
                                <td><?php echo $invoice_html; // phpcs:ignore ?></td>
                                <td>
                                    <code class="uupd-pill uupd-copy-on-click"
                                          data-uupd-copy="<?php echo esc_attr( $license_key ); ?>">
                                        <?php echo esc_html( $license_key ); ?>
                                    </code>
                                    <?php if ( $activations_html ) : ?>
                                        <br />
                                        <?php echo $activations_html; // phpcs:ignore ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <?php

    // Copy script for this table.
    if ( ! defined( 'UUPD_SIMPLE_COPY_SCRIPT_PRINTED' ) ) {
        define( 'UUPD_SIMPLE_COPY_SCRIPT_PRINTED', true );
        ?>
        <script>
        document.addEventListener('click', function (e) {
            var pill = e.target.closest('.uupd-copy-on-click');
            if (!pill) return;
            var key = pill.getAttribute('data-uupd-copy');
            if (!key || !navigator.clipboard) return;

            var originalText = pill.textContent;
            navigator.clipboard.writeText(key).then(function () {
                pill.textContent = 'Copied!';
                setTimeout(function () {
                    pill.textContent = originalText;
                }, 1500);
            }).catch(function () {});
        });
        </script>
        <?php
    }

    return ob_get_clean();
}
add_shortcode( 'uupd_simple_license_purchases', 'uupd_simple_license_purchases_shortcode' );


/**
 * ------------------------------------------------------------------
 * [uupd_simple_purchase_confirmation]
 *
 * Confirmation-style view for a single UUPD license.
 * ------------------------------------------------------------------
 */
function uupd_simple_purchase_confirmation_shortcode( $atts ) {
    if ( ! is_user_logged_in() ) {
        return '<p>You must be logged in to view this license.</p>';
    }

    $atts = shortcode_atts(
        array(
            'activations_page' => '', // page with [uupd_simple_license_activations]
        ),
        $atts,
        'uupd_simple_purchase_confirmation'
    );

    $activations_base = uupd_simple_normalize_page_url( $atts['activations_page'] );

    // Find license from query.
    $license_id_q  = isset( $_GET['license'] ) ? (int) $_GET['license'] : 0;
    $license_key_q = isset( $_GET['license_key'] ) ? sanitize_text_field( wp_unslash( $_GET['license_key'] ) ) : '';

    if ( ! $license_id_q && ! $license_key_q ) {
        return '<p>License not specified.</p>';
    }

    $license_post = uupd_simple_get_current_user_license( $license_id_q, $license_key_q );
    if ( ! $license_post ) {
        return '<p>License not found for your account.</p>';
    }

    $license_id   = $license_post->ID;
    $license_meta = get_post_meta( $license_id );

    $license_key  = isset( $license_meta['license_key'][0] ) ? $license_meta['license_key'][0] : '';
    $status       = isset( $license_meta['status'][0] ) ? $license_meta['status'][0] : 'active';
    $update_id    = isset( $license_meta['update_id'][0] ) ? (int) $license_meta['update_id'][0] : 0;
    $expiry_raw   = isset( $license_meta['expiry'][0] ) ? $license_meta['expiry'][0] : '';
    $limit_meta   = isset( $license_meta['activation_limit'][0] ) ? (int) $license_meta['activation_limit'][0] : 0;
    $used_domains = isset( $license_meta['used_domains'][0] ) ? maybe_unserialize( $license_meta['used_domains'][0] ) : array();

    if ( ! is_array( $used_domains ) ) {
        $used_domains = array();
    }

    // Product/update info.
    $update_post  = $update_id ? get_post( $update_id ) : null;
    $product_name = $update_post ? get_the_title( $update_post ) : __( 'Unknown product', 'uupd-server' );
    $version      = $update_id ? get_post_meta( $update_id, 'version', true ) : '';

    // Status / expiry / activations.
    $status_label = ucfirst( $status );

    if ( '' === $expiry_raw ) {
        $expiry_label = __( 'Never', 'uupd-server' );
    } else {
        $ts = strtotime( $expiry_raw );
        $expiry_label = $ts
            ? mysql2date( get_option( 'date_format' ), gmdate( 'Y-m-d H:i:s', $ts ) )
            : esc_html( $expiry_raw );
    }

    $current_acts = count( $used_domains );
    $act_label    = $limit_meta > 0
        ? sprintf( '%d / %d', $current_acts, $limit_meta )
        : sprintf( '%d / ∞', $current_acts );

    // “Order” fields – placeholders for now.
    $order_number     = '—';
    $order_status     = $status_label;
    $payment_method   = '—';
    $subtotal_label   = '&mdash;';
    $discount_label   = '&mdash;';
    $total_label      = '$0.00';
    $order_date_label = mysql2date( get_option( 'date_format' ), $license_post->post_date );

    // Activations URL (manage link).
    $activations_url = '';
    if ( $activations_base ) {
        $activations_url = esc_url(
            add_query_arg( 'license', $license_id, $activations_base )
        );
    }

    // Download URL.
    $download_url   = $update_id ? uupd_simple_build_download_url( $update_id, $license_key ) : '';
    $download_label = $version
        ? sprintf( __( 'Download Latest Version (%s)', 'uupd-server' ), $version )
        : __( 'Download Latest Version', 'uupd-server' );

    ob_start();
    ?>
    <div class="gpd-account gpd-purchase-confirmation">
        <!-- Order / License summary -->
        <section class="gpd-card gpd-order-summary">
            <header class="gpd-card__header">
                <h1 class="gpd-page-title"><?php esc_html_e( 'License Summary', 'uupd-server' ); ?></h1>
            </header>
            <div class="gpd-card__body">
                <dl class="gpd-definition-list gpd-definition-list--order">

                    <div class="gpd-definition-list__row">
                        <dt><?php esc_html_e( 'Product', 'uupd-server' ); ?>:</dt>
                        <dd><?php echo esc_html( $product_name ); ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt><?php esc_html_e( 'Order / Invoice', 'uupd-server' ); ?>:</dt>
                        <dd><?php echo $order_number ? esc_html( $order_number ) : '&mdash;'; ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt><?php esc_html_e( 'Order Status', 'uupd-server' ); ?>:</dt>
                        <dd><?php echo esc_html( $order_status ); ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt><?php esc_html_e( 'Payment Method', 'uupd-server' ); ?>:</dt>
                        <dd><?php echo $payment_method ? esc_html( $payment_method ) : '&mdash;'; ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt><?php esc_html_e( 'Date', 'uupd-server' ); ?>:</dt>
                        <dd><?php echo esc_html( $order_date_label ); ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt><?php esc_html_e( 'Subtotal', 'uupd-server' ); ?>:</dt>
                        <dd><?php echo wp_kses_post( $subtotal_label ); ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt><?php esc_html_e( 'Discount', 'uupd-server' ); ?>:</dt>
                        <dd><?php echo wp_kses_post( $discount_label ); ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt><?php esc_html_e( 'Total', 'uupd-server' ); ?>:</dt>
                        <dd><?php echo wp_kses_post( $total_label ); ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt><?php esc_html_e( 'License Key', 'uupd-server' ); ?>:</dt>
                        <dd>
                            <code class="gpd-pill gpd-pill--wide gpd-copy-on-click"
                                  data-gpd-copy="<?php echo esc_attr( $license_key ); ?>">
                                <?php echo esc_html( $license_key ); ?>
                            </code>
                        </dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt><?php esc_html_e( 'Expiry', 'uupd-server' ); ?>:</dt>
                        <dd><?php echo esc_html( $expiry_label ); ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt><?php esc_html_e( 'Activations', 'uupd-server' ); ?>:</dt>
                        <dd><?php echo esc_html( $act_label ); ?></dd>
                    </div>

                    <div class="gpd-definition-list__row">
                        <dt><?php esc_html_e( 'Manage Activations', 'uupd-server' ); ?>:</dt>
                        <dd>
                            <?php if ( $activations_url ) : ?>
                                <a href="<?php echo $activations_url; ?>" class="gpd-link">
                                    <?php esc_html_e( 'View / Manage', 'uupd-server' ); ?>
                                </a>
                            <?php else : ?>
                                &mdash;
                            <?php endif; ?>
                        </dd>
                    </div>
                </dl>
            </div>
        </section>

        <!-- Products / Downloads -->
        <section class="gpd-card gpd-order-products">
            <header class="gpd-card__header">
                <h2 class="gpd-section-title gpd-section-title--products">
                    <?php esc_html_e( 'Download', 'uupd-server' ); ?>
                </h2>
            </header>
            <div class="gpd-card__body">
                <?php if ( ! $download_url ) : ?>
                    <p class="gpd-empty">
                        <?php esc_html_e( 'No downloadable file is currently available for this product.', 'uupd-server' ); ?>
                    </p>
                <?php else : ?>
                    <p>
                        <?php esc_html_e( 'You can download the latest version of this product using the button below:', 'uupd-server' ); ?>
                    </p>
                    <p>
                        <a class="gpd-button gpd-button--primary"
                           href="<?php echo esc_url( $download_url ); ?>">
                            <?php echo esc_html( $download_label ); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <?php
    // Click-to-copy handler – only print once.
    if ( ! defined( 'GPD_COPY_SCRIPT_PRINTED' ) ) {
        define( 'GPD_COPY_SCRIPT_PRINTED', true );
        ?>
        <script>
        document.addEventListener('click', function (e) {
            var pill = e.target.closest('.gpd-copy-on-click');
            if (!pill) return;
            var key = pill.getAttribute('data-gpd-copy');
            if (!key || !navigator.clipboard) return;

            var originalText = pill.textContent;
            navigator.clipboard.writeText(key).then(function () {
                pill.textContent = 'Copied!';
                setTimeout(function () {
                    pill.textContent = originalText;
                }, 1500);
            }).catch(function () {});
        });
        </script>
        <?php
    }

    return ob_get_clean();
}
add_shortcode( 'uupd_simple_purchase_confirmation', 'uupd_simple_purchase_confirmation_shortcode' );


/**
 * Enqueue default styles for the uupd_simple_* shortcodes.
 */
add_action( 'wp_enqueue_scripts', 'uupd_simple_enqueue_default_styles' );

function uupd_simple_enqueue_default_styles() {

    $enabled = apply_filters( 'uupd_simple_enable_default_styles', true );
    if ( ! $enabled ) {
        return;
    }

    $should_enqueue = false;

    if ( is_singular() ) {
        global $post;

        if ( $post instanceof WP_Post ) {
            $shortcodes_to_check = array(
                'uupd_simple_license_purchases',
                'uupd_simple_purchase_confirmation',
                'uupd_simple_license_activations',
            );

            foreach ( $shortcodes_to_check as $tag ) {
                if ( has_shortcode( $post->post_content, $tag ) ) {
                    $should_enqueue = true;
                    break;
                }
            }
        }
    }

    $should_enqueue = apply_filters( 'uupd_simple_should_enqueue_styles', $should_enqueue );

    if ( ! $should_enqueue ) {
        return;
    }

    $css = <<<CSS
/* Layout container – make it wider and give side padding */
.gpd-account,
.uupd-account {
    max-width: 1120px;
    margin: 0 auto;
    padding: 0 1.5rem;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}

/* Card */
.gpd-card,
.uupd-card {
    background: #fff;
    border-radius: 14px;
    padding: 1.9rem 2.1rem;
    box-shadow: 0 10px 25px rgba(15,23,42,.06);
    margin-bottom: 2rem;
}

/* Headers */
.gpd-card__header,
.uupd-card__header {
    margin-bottom: 1.25rem;
}

.gpd-page-title,
.uupd-page-title {
    font-size: 1.7rem;
    font-weight: 600;
    margin: 0;
}

.gpd-section-title,
.uupd-section-title {
    font-size: 1.15rem;
    font-weight: 600;
    margin: 0 0 0.5rem;
}

/* Pills / badges / buttons */
.gpd-pill,
.uupd-pill {
    display: inline-block;
    padding: 0.25rem 0.9rem;
    border-radius: 999px;
    background: #0f172a;
    color: #fff;
    font-size: 0.85rem;
}

.gpd-pill--wide,
.uupd-pill--wide {
    padding-inline: 1.1rem;
}

.gpd-badge,
.uupd-badge {
    display: inline-block;
    padding: 0.2rem 0.7rem;
    border-radius: 999px;
    font-size: 0.78rem;
    background: #e5e7eb;
    color: #111827;
    margin-left: 0.5rem;
}

.gpd-badge--status,
.uupd-badge--status {
    background: #dcfce7;
    color: #166534;
}

.gpd-button,
.uupd-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.45rem 1.1rem;
    border-radius: 999px;
    font-size: 0.95rem;
    text-decoration: none;
    border: none;
    cursor: pointer;
}

.gpd-button--primary,
.uupd-button--primary {
    background: #111827;
    color: #fff;
}

.gpd-link,
.uupd-link {
    color: #111827;
    text-decoration: underline;
    text-underline-offset: 2px;
    font-size: 0.9rem;
}

.gpd-link--danger,
.uupd-link--danger {
    color: #b91c1c;
}

/* Definition list */
.gpd-definition-list,
.uupd-definition-list {
    margin: 0;
}

.gpd-definition-list__row,
.uupd-definition-list__row {
    display: flex;
    justify-content: space-between;
    padding: 0.55rem 0;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.98rem;
}

.gpd-definition-list__row:last-child,
.uupd-definition-list__row:last-child {
    border-bottom: none;
}

.gpd-definition-list__row dt,
.uupd-definition-list__row dt {
    font-weight: 500;
    color: #6b7280;
}

.gpd-definition-list__row dd,
.uupd-definition-list__row dd {
    margin: 0;
    text-align: right;
}

/* License-key special row */
.gpd-definition-list__row--license,
.uupd-definition-list__row--license {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.gpd-definition-list__row--license dd,
.uupd-definition-list__row--license dd {
    width: 100%;
    text-align: left;
}

/* List of licenses / activations */
.gpd-list,
.uupd-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.gpd-list__item,
.uupd-list__item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.65rem 0;
    border-bottom: 1px solid #e5e7eb;
    font-size: 0.98rem;
}

.gpd-list__item:last-child,
.uupd-list__item:last-child {
    border-bottom: none;
}

/* Empty text */
.gpd-empty,
.uupd-empty {
    margin: 0.3rem 0;
    font-size: 0.9rem;
    color: #6b7280;
}

/* Purchases table */
.gpd-table--purchases th,
.gpd-table--purchases td,
.uupd-table--purchases th,
.uupd-table--purchases td {
    padding: 0.6rem 1rem;
}

.gpd-table--purchases th:nth-child(3),
.gpd-table--purchases th:nth-child(4),
.gpd-table--purchases th:nth-child(5),
.gpd-table--purchases td:nth-child(3),
.gpd-table--purchases td:nth-child(4),
.gpd-table--purchases td:nth-child(5),
.uupd-table--purchases th:nth-child(3),
.uupd-table--purchases th:nth-child(4),
.uupd-table--purchases th:nth-child(5),
.uupd-table--purchases td:nth-child(3),
.uupd-table--purchases td:nth-child(4),
.uupd-table--purchases td:nth-child(5) {
    white-space: nowrap;
    padding-right: 1.5rem;
}

/* Products heading – match Order Summary */
.gpd-card__header .gpd-section-title--products,
.gpd-card__header .gpd-section-title.gpd-section-title--products {
    font-size: 1.7rem;
    font-weight: 600;
    margin-bottom: 1rem;
}

/* Nicer copied state */
.gpd-pill--copied {
    background: #16a34a;
}
CSS;

    $css = apply_filters( 'uupd_simple_default_styles', $css );

    $handle = 'uupd-simple-shortcodes';
    wp_register_style( $handle, false, array(), null );
    wp_enqueue_style( $handle );
    wp_add_inline_style( $handle, $css );
}
