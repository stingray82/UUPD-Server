<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the uupd_license CPT admin UI:
 * - CPT registration
 * - License meta box + save
 * - List table columns
 */
class UUPD_License_Admin {

    /**
     * Called by UUPD_Core.
     */
    public function register_hooks() {

        // CPT
        add_action( 'init', [ $this, 'register_cpt' ] );

        // Meta box + save
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'save_post_uupd_license', [ $this, 'save_meta' ], 10, 2 );

        // List table columns
        add_filter( 'manage_edit-uupd_license_columns', [ $this, 'filter_columns' ] );
        add_action( 'manage_uupd_license_posts_custom_column', [ $this, 'render_column' ], 10, 2 );

        // List table JS (for copy buttons)
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_list_js' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_editor_css' ] );
        add_action( 'pre_get_posts', [ $this, 'hide_children_from_list' ] );
        add_action( 'restrict_manage_posts', [ $this, 'render_list_filters' ], 10, 2 );
        add_action( 'pre_get_posts', [ $this, 'filter_license_list' ] );
    }

    /* -------------------------------------------------------------------------
     * CPT
     * ---------------------------------------------------------------------- */

    public function register_cpt() {
        register_post_type(
            'uupd_license',
            [
                'label'        => __( 'Licenses', 'uupd-server' ),
                'labels'       => [
                    'name'          => __( 'Licences', 'uupd-server' ),
                    'singular_name' => __( 'Licence', 'uupd-server' ),
                    'add_new'       => __( 'Add Licence', 'uupd-server' ),
                    'add_new_item'  => __( 'Add Licence', 'uupd-server' ),
                    'edit_item'     => __( 'Edit Licence', 'uupd-server' ),
                    'new_item'      => __( 'New Licence', 'uupd-server' ),
                    'search_items'  => __( 'Search Licences', 'uupd-server' ),
                    'not_found'     => __( 'No licences found.', 'uupd-server' ),
                ],
                'public'       => false,
                'show_ui'      => true,
                'supports'     => [ '' ],        // effectively hides title/editor
                'show_in_menu' => 'uupd_server',
            ]
        );
        }

    /* -------------------------------------------------------------------------
     * META BOX
     * ---------------------------------------------------------------------- */

    public function add_meta_box() {
        add_meta_box(
            'uupd_license_meta',
            __( 'License details', 'uupd-server' ),
            [ $this, 'render_meta_box' ],
            'uupd_license',
            'normal',
            'default'
        );
    }

    public function hide_children_from_list( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() || 'uupd_license' !== $query->get( 'post_type' ) ) { return; }
        $meta_query = (array) $query->get( 'meta_query' );
        $meta_query[] = [ 'key' => 'uupd_parent_license_id', 'compare' => 'NOT EXISTS' ];
        $query->set( 'meta_query', $meta_query );
    }

    /** Filters for the main admin licence list only. */
    public function render_list_filters( $post_type, $which = 'top' ) {
        if ( 'uupd_license' !== $post_type || 'top' !== $which ) { return; }

        $user     = isset( $_GET['uupd_filter_user'] ) ? sanitize_user( wp_unslash( $_GET['uupd_filter_user'] ) ) : '';
        $order    = isset( $_GET['uupd_filter_order'] ) ? sanitize_text_field( wp_unslash( $_GET['uupd_filter_order'] ) ) : '';
        $download = isset( $_GET['uupd_filter_download'] ) ? absint( $_GET['uupd_filter_download'] ) : 0;
        $from     = isset( $_GET['uupd_filter_expiry_from'] ) ? sanitize_text_field( wp_unslash( $_GET['uupd_filter_expiry_from'] ) ) : '';
        $to       = isset( $_GET['uupd_filter_expiry_to'] ) ? sanitize_text_field( wp_unslash( $_GET['uupd_filter_expiry_to'] ) ) : '';

        printf( '<label class="screen-reader-text" for="uupd-filter-user">%s</label><input id="uupd-filter-user" type="text" name="uupd_filter_user" value="%s" placeholder="%s">', esc_html__( 'Username', 'uupd-server' ), esc_attr( $user ), esc_attr__( 'Username', 'uupd-server' ) );
        printf( '<label class="screen-reader-text" for="uupd-filter-order">%s</label><input id="uupd-filter-order" type="text" name="uupd_filter_order" value="%s" placeholder="%s">', esc_html__( 'Order / invoice', 'uupd-server' ), esc_attr( $order ), esc_attr__( 'Order / invoice', 'uupd-server' ) );
        printf( '<label class="screen-reader-text" for="uupd-filter-expiry-from">%s</label><input id="uupd-filter-expiry-from" type="date" name="uupd_filter_expiry_from" value="%s" title="%s">', esc_html__( 'Expiry from', 'uupd-server' ), esc_attr( $from ), esc_attr__( 'Expiry from', 'uupd-server' ) );
        printf( '<label class="screen-reader-text" for="uupd-filter-expiry-to">%s</label><input id="uupd-filter-expiry-to" type="date" name="uupd_filter_expiry_to" value="%s" title="%s">', esc_html__( 'Expiry to', 'uupd-server' ), esc_attr( $to ), esc_attr__( 'Expiry to', 'uupd-server' ) );
        printf( '<label class="screen-reader-text" for="uupd-filter-download">%s</label><select id="uupd-filter-download" name="uupd_filter_download"><option value="">%s</option>', esc_html__( 'Download', 'uupd-server' ), esc_html__( 'All downloads', 'uupd-server' ) );
        foreach ( get_posts( [ 'post_type' => 'update', 'post_status' => [ 'publish', 'private', 'draft' ], 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => true ] ) as $item ) {
            printf( '<option value="%d"%s>%s</option>', absint( $item->ID ), selected( $download, $item->ID, false ), esc_html( $item->post_title ) );
        }
        echo '</select>';
        printf( '<a class="button" href="%s">%s</a>', esc_url( admin_url( 'edit.php?post_type=uupd_license' ) ), esc_html__( 'Clear filters', 'uupd-server' ) );
    }

    public function filter_license_list( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() || 'uupd_license' !== $query->get( 'post_type' ) ) { return; }
        $meta_query = (array) $query->get( 'meta_query' );

        if ( isset( $_GET['uupd_filter_user'] ) && '' !== trim( (string) wp_unslash( $_GET['uupd_filter_user'] ) ) ) {
            $user = get_user_by( 'login', sanitize_user( wp_unslash( $_GET['uupd_filter_user'] ) ) );
            if ( ! $user ) { $query->set( 'post__in', [ 0 ] ); }
            else { $meta_query[] = [ 'key' => 'user_id', 'value' => (int) $user->ID ]; }
        }
        if ( isset( $_GET['uupd_filter_order'] ) && '' !== trim( (string) wp_unslash( $_GET['uupd_filter_order'] ) ) ) {
            $meta_query[] = [ 'key' => 'order_id', 'value' => sanitize_text_field( wp_unslash( $_GET['uupd_filter_order'] ) ) ];
        }
        if ( ! empty( $_GET['uupd_filter_download'] ) ) {
            $meta_query[] = [ 'key' => 'update_id', 'value' => absint( $_GET['uupd_filter_download'] ) ];
        }
        foreach ( [ 'uupd_filter_expiry_from' => '>=', 'uupd_filter_expiry_to' => '<=' ] as $key => $compare ) {
            if ( empty( $_GET[ $key ] ) ) { continue; }
            $date = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
            if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) { continue; }
            [ $year, $month, $day ] = array_map( 'intval', explode( '-', $date ) );
            if ( checkdate( $month, $day, $year ) ) {
                $meta_query[] = [ 'key' => 'expiry', 'value' => $date, 'compare' => $compare, 'type' => 'DATE' ];
            }
        }
        $query->set( 'meta_query', $meta_query );
    }

    public function enqueue_editor_css() {
        $screen = get_current_screen();
        if ( $screen && 'uupd_license' === $screen->post_type ) {
            wp_enqueue_style( 'uupd-license-editor', UUPD_SERVER_URL . 'assets/admin-license.css', [], filemtime( UUPD_SERVER_DIR . 'assets/admin-license.css' ) );
            wp_enqueue_script( 'uupd-license-editor', UUPD_SERVER_URL . 'assets/admin-license.js', [], filemtime( UUPD_SERVER_DIR . 'assets/admin-license.js' ), true );
        }
    }

    public function render_meta_box( $post ) {

        wp_nonce_field( 'uupd_license_save', 'uupd_license_nonce' );

        $m = get_post_meta( $post->ID );

        $update_id        = isset( $m['update_id'][0] )        ? intval( $m['update_id'][0] )        : 0;
        $package_id       = isset( $m['package_id'][0] )       ? $m['package_id'][0]                  : '';
        $user_id          = isset( $m['user_id'][0] )          ? intval( $m['user_id'][0] )          : 0;
        $license_key      = isset( $m['license_key'][0] )      ? $m['license_key'][0]                : '';
        $status           = isset( $m['status'][0] )           ? $m['status'][0]                     : 'active';
        $activation_limit = isset( $m['activation_limit'][0] ) ? intval( $m['activation_limit'][0] ) : 0;
        $expiry           = isset( $m['expiry'][0] )           ? $m['expiry'][0]                     : '';
        $order_id         = isset( $m['order_id'][0] )         ? $m['order_id'][0]                   : '';
        $used_domains     = isset( $m['used_domains'][0] )     ? maybe_unserialize( $m['used_domains'][0] ) : [];
        $parent_id = absint( get_post_meta( $post->ID, 'uupd_parent_license_id', true ) );

        if ( ! is_array( $used_domains ) ) {
            $used_domains = [];
        }

        // Fetch updates for dropdown
        $updates = get_posts(
            [
                'post_type'   => 'update',
                'numberposts' => -1,
                'orderby'     => 'title',
                'order'       => 'ASC',
            ]
        );

        // Fetch users for dropdown
        $users = get_users(
            [
                'orderby' => 'display_name',
                'order'   => 'ASC',
                'fields'  => [ 'ID', 'display_name', 'user_email' ],
            ]
        );

        // Build packages map: update_id => [ [id, label], ... ]
        $packages_by_update = [];
        foreach ( $updates as $u ) {
            $pkgs = get_post_meta( $u->ID, 'packages', true );
            if ( is_array( $pkgs ) ) {
                $packages_by_update[ $u->ID ] = array_values(
                    array_map(
                        function ( $p ) {
                            return [
                                'id'    => $p['id'] ?? '',
                                'label' => $p['label'] ?? '',
                            ];
                        },
                        $pkgs
                    )
                );
            } else {
                $packages_by_update[ $u->ID ] = [];
            }
        }

        ?>
        <?php if ( get_post_meta( $post->ID, 'uupd_domain_limit_error', true ) ) : delete_post_meta( $post->ID, 'uupd_domain_limit_error' ); ?>
            <div class="notice notice-error"><p><?php esc_html_e( 'Domains were not changed because they would exceed the master activation limit.', 'uupd-server' ); ?></p></div>
        <?php endif; ?>
        <?php if ( isset( $_GET['uupd_key_result'], $_GET['uupd_key_license'] ) && absint( $_GET['uupd_key_license'] ) === $post->ID ) : ?>
            <div class="notice <?php echo in_array( sanitize_key( wp_unslash( $_GET['uupd_key_result'] ) ), array( 'success', 'rotated', 'child_created', 'child_updated', 'child_revoked', 'child_deleted', 'child_deactivated' ), true ) ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html( uupd_key_result_message( sanitize_key( wp_unslash( $_GET['uupd_key_result'] ) ) ) ); ?></p></div>
        <?php endif; ?>
        <div class="uupd-admin-shell">
            <section class="uupd-admin-panel uupd-admin-key-panel" aria-labelledby="uupd-admin-key-heading">
                <div class="uupd-admin-panel-heading"><div><span class="uupd-admin-eyebrow"><?php esc_html_e( 'Licence', 'uupd-server' ); ?> #<?php echo esc_html( $post->ID ); ?></span><h2 id="uupd-admin-key-heading"><?php esc_html_e( 'Licence key', 'uupd-server' ); ?></h2><p><?php esc_html_e( 'Manage the main key and permissions for this licence.', 'uupd-server' ); ?></p></div><span class="uupd-admin-badge <?php echo uupd_license_is_usable( $post->ID ) ? 'is-active' : 'is-inactive'; ?>"><?php echo esc_html( uupd_license_is_usable( $post->ID ) ? __( 'Active', 'uupd-server' ) : __( 'Inactive', 'uupd-server' ) ); ?></span></div>
                <?php if ( $parent_id ) : ?><p class="uupd-admin-parent"><?php esc_html_e( 'Child of', 'uupd-server' ); ?> <a href="<?php echo esc_url( get_edit_post_link( $parent_id ) ); ?>"><?php echo esc_html( '#' . $parent_id ); ?></a></p><?php endif; ?>
                <div class="uupd-admin-key-row"><label for="uupd_license_key"><?php esc_html_e( 'Current key', 'uupd-server' ); ?></label><div class="uupd-admin-key-actions"><input type="text" name="uupd_license_key" id="uupd_license_key" value="<?php echo esc_attr( $license_key ); ?>" class="regular-text code" <?php if ( $license_key ) : ?>readonly<?php endif; ?>><?php if ( '' === trim( (string) $license_key ) ) : ?><button type="button" class="button" id="uupd_generate_license_key"><?php esc_html_e( 'Generate key', 'uupd-server' ); ?></button><?php elseif ( $post->ID && 'auto-draft' !== $post->post_status ) : echo uupd_license_key_admin_button( $post->ID, 'rotate', __( 'Rotate key', 'uupd-server' ) ); endif; ?></div><p class="description"><?php echo esc_html( $license_key ? __( 'Rotation invalidates the old key immediately. Copy the new key to the customer.', 'uupd-server' ) : __( 'Generate a key, then save this licence.', 'uupd-server' ) ); ?></p></div>
                <div class="uupd-admin-policy">
                    <?php if ( $parent_id ) : ?><p class="description"><?php esc_html_e( 'Customer rotation and licence status follow the master licence.', 'uupd-server' ); ?></p><?php else : ?><label><?php esc_html_e( 'Allow customers to rotate', 'uupd-server' ); ?><select name="uupd_allow_rotate"><option value="inherit" <?php selected( ! metadata_exists( 'post', $post->ID, 'uupd_allow_rotate' ) ); ?>><?php esc_html_e( 'Use site default', 'uupd-server' ); ?></option><option value="1" <?php selected( get_post_meta( $post->ID, 'uupd_allow_rotate', true ), '1' ); ?>><?php esc_html_e( 'Yes', 'uupd-server' ); ?></option><option value="0" <?php selected( metadata_exists( 'post', $post->ID, 'uupd_allow_rotate' ) && '0' === get_post_meta( $post->ID, 'uupd_allow_rotate', true ) ); ?>><?php esc_html_e( 'No', 'uupd-server' ); ?></option></select><span class="description"><?php esc_html_e( 'This controls the customer dashboard only. Administrators can always rotate a key.', 'uupd-server' ); ?></span></label><?php endif; ?>
                </div>
            </section>
            <?php if ( ! $parent_id ) : ?><section class="uupd-admin-panel uupd-admin-children" aria-labelledby="uupd-admin-children-heading"><div class="uupd-admin-panel-heading"><div><span class="uupd-admin-eyebrow"><?php esc_html_e( 'Under this key', 'uupd-server' ); ?></span><h2 id="uupd-admin-children-heading"><?php esc_html_e( 'Child keys', 'uupd-server' ); ?></h2><p><?php esc_html_e( 'Set access and a child count here. All child activations share the master limit.', 'uupd-server' ); ?></p></div></div>
                <div class="uupd-child-policy">
                    <label class="uupd-child-policy__field"><span><?php esc_html_e( 'Allow customers to create child keys', 'uupd-server' ); ?></span><select name="uupd_allow_children" id="uupd_allow_children"><option value="inherit" <?php selected( ! metadata_exists( 'post', $post->ID, 'uupd_allow_children' ) ); ?>><?php esc_html_e( 'Use site default', 'uupd-server' ); ?></option><option value="1" <?php selected( get_post_meta( $post->ID, 'uupd_allow_children', true ), '1' ); ?>><?php esc_html_e( 'Yes', 'uupd-server' ); ?></option><option value="0" <?php selected( metadata_exists( 'post', $post->ID, 'uupd_allow_children' ) && '0' === get_post_meta( $post->ID, 'uupd_allow_children', true ) ); ?>><?php esc_html_e( 'No', 'uupd-server' ); ?></option></select></label>
                    <div class="uupd-child-policy__field"><label for="uupd_max_children"><?php esc_html_e( 'Maximum child keys', 'uupd-server' ); ?></label><input type="number" id="uupd_max_children" name="uupd_max_children" min="0" step="1" <?php if ( uupd_license_master_limit( $post->ID ) ) : ?>max="<?php echo esc_attr( uupd_license_master_limit( $post->ID ) ); ?>"<?php endif; ?> value="<?php echo esc_attr( metadata_exists( 'post', $post->ID, 'uupd_max_children' ) && is_numeric( get_post_meta( $post->ID, 'uupd_max_children', true ) ) ? get_post_meta( $post->ID, 'uupd_max_children', true ) : '' ); ?>" placeholder="<?php esc_attr_e( 'Site default', 'uupd-server' ); ?>"><p class="description"><?php esc_html_e( 'Blank uses the site default. For a finite licence, the number cannot exceed its activation limit.', 'uupd-server' ); ?></p><label class="uupd-admin-checkbox"><input type="checkbox" name="uupd_max_children_unlimited" value="1" <?php checked( get_post_meta( $post->ID, 'uupd_max_children', true ), 'unlimited' ); ?> <?php disabled( uupd_license_master_limit( $post->ID ) > 0 ); ?>><?php esc_html_e( 'Unlimited child keys', 'uupd-server' ); ?></label><?php if ( uupd_license_master_limit( $post->ID ) ) : ?><p class="description"><?php esc_html_e( 'Available when the master has unlimited activations.', 'uupd-server' ); ?></p><?php endif; ?></div>
                </div>
                <div class="uupd-child-disable-choice" hidden><strong><?php esc_html_e( 'Disabling child creation', 'uupd-server' ); ?></strong><p><?php esc_html_e( 'Existing child keys will be revoked and their activations released when you save.', 'uupd-server' ); ?></p><label><input type="checkbox" name="uupd_keep_children_active" value="1"> <?php esc_html_e( 'Keep existing child keys active instead', 'uupd-server' ); ?></label></div>
                <?php echo uupd_child_admin_controls( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></section><?php endif; ?>
            <section class="uupd-admin-panel uupd-admin-details" aria-labelledby="uupd-admin-details-heading"><div class="uupd-admin-panel-heading"><div><span class="uupd-admin-eyebrow"><?php esc_html_e( 'Configuration', 'uupd-server' ); ?></span><h2 id="uupd-admin-details-heading"><?php esc_html_e( 'Licence details', 'uupd-server' ); ?></h2></div></div>
        <table class="form-table">
            <tr>
                <th><?php esc_html_e( 'Update', 'uupd-server' ); ?></th>
                <td>
                    <select id="uupd_update_id" name="uupd_update_id" <?php disabled( $parent_id > 0 ); ?>>
                        <option value="0"><?php esc_html_e( '— Select update —', 'uupd-server' ); ?></option>
                        <?php foreach ( $updates as $u ) : ?>
                            <option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( $update_id, $u->ID ); ?>>
                                <?php echo esc_html( $u->post_title . ' (ID ' . $u->ID . ')' ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <tr>
                <th><?php esc_html_e( 'Package', 'uupd-server' ); ?></th>
                <td>
                    <select id="uupd_package_id" name="uupd_package_id" <?php disabled( $parent_id > 0 ); ?>>
                        <option value=""><?php esc_html_e( '— None / default —', 'uupd-server' ); ?></option>
                        <!-- JS will populate options based on selected update -->
                    </select>
                </td>
            </tr>

            <tr>
                <th><?php esc_html_e( 'User', 'uupd-server' ); ?></th>
                <td>
                    <select id="uupd_user_id" name="uupd_user_id" <?php disabled( $parent_id > 0 ); ?>>
                        <option value="0"><?php esc_html_e( '— No user —', 'uupd-server' ); ?></option>
                        <?php foreach ( $users as $u ) : ?>
                            <option value="<?php echo esc_attr( $u->ID ); ?>" <?php selected( $user_id, $u->ID ); ?>>
                                <?php
                                echo esc_html(
                                    $u->display_name .
                                    ( $u->user_email ? ' <' . $u->user_email . '>' : '' )
                                );
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>

            <tr>
                <th><?php esc_html_e( 'Status', 'uupd-server' ); ?></th>
                <td>
                    <select name="uupd_status" <?php disabled( $parent_id > 0 ); ?>>
                        <option value="active"    <?php selected( $status, 'active' );    ?>><?php esc_html_e( 'Active', 'uupd-server' ); ?></option>
                        <option value="suspended" <?php selected( $status, 'suspended' ); ?>><?php esc_html_e( 'Suspended', 'uupd-server' ); ?></option>
                        <option value="expired"   <?php selected( $status, 'expired' );   ?>><?php esc_html_e( 'Expired', 'uupd-server' ); ?></option>
                        <?php if ( 'revoked' === $status ) : ?><option value="revoked" selected><?php esc_html_e( 'Revoked', 'uupd-server' ); ?></option><?php endif; ?>
                    </select>
                </td>
            </tr>

            <tr>
                <th><?php esc_html_e( 'Activation limit', 'uupd-server' ); ?></th>
                <td>
                    <input type="number"
                           name="uupd_activation_limit"
                           value="<?php echo esc_attr( $activation_limit ); ?>" <?php disabled( $parent_id > 0 ); ?>>
                    <p class="description"><?php echo esc_html( $parent_id ? __( 'Edit this child limit on the master licence.', 'uupd-server' ) : __( '0 = use package default.', 'uupd-server' ) ); ?></p>
                </td>
            </tr>

            <tr>
                <th><?php esc_html_e( 'Expiry date', 'uupd-server' ); ?></th>
                <td>
                    <input type="date"
                           name="uupd_expiry"
                           value="<?php echo esc_attr( $expiry ); ?>" <?php disabled( $parent_id > 0 ); ?>>
                    <p class="description"><?php esc_html_e( 'Leave empty for lifetime license.', 'uupd-server' ); ?></p>
                </td>
            </tr>

            <tr>
                <th><?php esc_html_e( 'Order / Invoice ID', 'uupd-server' ); ?></th>
                <td>
                    <input type="text"
                           name="uupd_order_id"
                           value="<?php echo esc_attr( $order_id ); ?>"
                           class="regular-text" <?php disabled( $parent_id > 0 ); ?>>
                    <p class="description">
                        <?php esc_html_e( 'Reference to the order or invoice where this license was purchased (e.g. WooCommerce order number).', 'uupd-server' ); ?>
                    </p>
                </td>
            </tr>

            <?php if ( '1' === (string) get_option( 'uupd_license_history_enabled', '0' ) ) : ?>
            <tr><th><?php esc_html_e( 'Interaction log', 'uupd-server' ); ?></th><td><details><summary><?php esc_html_e( 'View logs', 'uupd-server' ); ?></summary><?php $history = get_post_meta( $post->ID, '_uupd_interaction_history', true ); $history = is_array( $history ) ? array_reverse( $history ) : array(); ?><?php if ( $history ) : ?><ol><?php foreach ( $history as $entry ) : ?><li><strong><?php echo esc_html( $entry['at'] ?? '' ); ?> — <?php echo esc_html( $entry['event'] ?? '' ); ?></strong><?php if ( ! empty( $entry['details'] ) && is_array( $entry['details'] ) ) : ?>: <?php echo esc_html( wp_json_encode( $entry['details'] ) ); ?><?php endif; ?></li><?php endforeach; ?></ol><?php else : ?><p><?php esc_html_e( 'No interactions recorded yet.', 'uupd-server' ); ?></p><?php endif; ?></details></td></tr>
            <?php endif; ?>
            <tr>
                <th><?php esc_html_e( 'Used domains', 'uupd-server' ); ?></th>
                <td>
                    <textarea name="uupd_used_domains" rows="4" class="large-text" <?php disabled( $parent_id > 0 ); ?>><?php
                        echo esc_textarea( implode( "\n", $used_domains ) );
                    ?></textarea>
                    <p class="description"><?php esc_html_e( 'One domain per line.', 'uupd-server' ); ?></p>
                </td>
            </tr>

        </table></section></div>

        <?php
        // Prepare data & nonce for the inline JS.
        $packages_json = wp_json_encode( $packages_by_update );
        $current_pkg   = esc_js( $package_id );
        $nonce         = wp_create_nonce( 'uupd_license_meta' );
        ?>

        <script>
        (function($){

            window.uupdLicPackages = <?php echo $packages_json; ?> || {};

            function refillPackages() {
                var updateId = $('#uupd_update_id').val();
                var pkgSel   = $('#uupd_package_id');
                var map      = window.uupdLicPackages || {};
                var current  = '<?php echo $current_pkg; ?>';

                pkgSel.empty();
                pkgSel.append(
                    $('<option>', {
                        value: '',
                        text: '<?php echo esc_js( '— None / default —' ); ?>'
                    })
                );

                if (map[updateId]) {
                    map[updateId].forEach(function(p){
                        if (!p.id) return;
                        var text = p.label ? (p.label + ' (' + p.id + ')') : p.id;
                        var opt  = $('<option>', { value: p.id, text: text });
                        pkgSel.append(opt);
                    });
                }

                if (current) {
                    pkgSel.val(current);
                }
            }

            $(document).ready(function(){
                var url = new URL(window.location.href);
                if (url.searchParams.has('uupd_key_result')) {
                    url.searchParams.delete('uupd_key_result');
                    url.searchParams.delete('uupd_key_license');
                    window.history.replaceState(null, '', url.pathname + url.search + url.hash);
                }
                var childSelect = document.getElementById('uupd_allow_children');
                var disableChoice = document.querySelector('.uupd-child-disable-choice');
                if (childSelect && disableChoice) {
                    var childrenWereEnabled = <?php echo wp_json_encode( uupd_license_feature_enabled( $post->ID, 'children' ) ); ?>;
                    var siteChildrenEnabled = <?php echo wp_json_encode( '1' === (string) get_option( 'uupd_allow_children', '0' ) ); ?>;
                    var existingChildren = <?php echo wp_json_encode( count( uupd_license_child_ids( $post->ID ) ) ); ?>;
                    function syncDisableChoice() {
                        var willBeEnabled = childSelect.value === '1' || (childSelect.value === 'inherit' && siteChildrenEnabled);
                        disableChoice.hidden = !(existingChildren && childrenWereEnabled && !willBeEnabled);
                    }
                    childSelect.addEventListener('change', syncDisableChoice);
                    syncDisableChoice();
                }
                var unlimitedChildren = document.querySelector('[name="uupd_max_children_unlimited"]');
                var childMaximum = document.getElementById('uupd_max_children');
                if (unlimitedChildren && childMaximum) {
                    function syncUnlimitedChildren() { childMaximum.disabled = unlimitedChildren.checked; }
                    unlimitedChildren.addEventListener('change', syncUnlimitedChildren);
                    syncUnlimitedChildren();
                }
                $('#uupd_update_id').on('change', refillPackages);
                refillPackages();

                // Generate license key via AJAX
                $('#uupd_generate_license_key').on('click', function(e){
                    e.preventDefault();

                    var updateId = $('#uupd_update_id').val() || 0;

                    $.post(ajaxurl, {
                        action: 'uupd_generate_license_key',
                        nonce:  '<?php echo esc_js( $nonce ); ?>',
                        update_id: updateId
                    }).done(function(resp){
                        if (resp && resp.success && resp.data && resp.data.license_key) {
                            $('#uupd_license_key').val(resp.data.license_key);
                        } else {
                            alert('Could not generate license key.');
                        }
                    }).fail(function(){
                        alert('Could not generate license key.');
                    });
                });
            });

        })(jQuery);
        </script>
        <?php
    }

    /* -------------------------------------------------------------------------
     * SAVE META
     * ---------------------------------------------------------------------- */

    public function save_meta( $post_id, $post ) {

        if ( ! isset( $_POST['uupd_license_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['uupd_license_nonce'], 'uupd_license_save' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        if ( $post->post_type !== 'uupd_license' ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) { return; }
        $children_were_enabled = uupd_license_feature_enabled( $post_id, 'children' );
        foreach ( array( 'rotate', 'children' ) as $feature ) {
            $field = 'uupd_allow_' . $feature;
            if ( isset( $_POST[ $field ] ) ) {
                $value = sanitize_key( wp_unslash( $_POST[ $field ] ) );
                if ( 'inherit' === $value ) { delete_post_meta( $post_id, $field ); }
                elseif ( in_array( $value, array( '0', '1' ), true ) ) { update_post_meta( $post_id, $field, $value ); }
            }
        }
        if ( ! get_post_meta( $post_id, 'uupd_parent_license_id', true ) && ( isset( $_POST['uupd_max_children'] ) || isset( $_POST['uupd_max_children_unlimited'] ) ) ) {
            $max_children = isset( $_POST['uupd_max_children'] ) ? sanitize_text_field( wp_unslash( $_POST['uupd_max_children'] ) ) : '';
            if ( isset( $_POST['uupd_max_children_unlimited'] ) && ! uupd_license_master_limit( $post_id ) ) { update_post_meta( $post_id, 'uupd_max_children', 'unlimited' ); }
            elseif ( '' === $max_children || 'inherit' === $max_children ) { delete_post_meta( $post_id, 'uupd_max_children' ); }
            elseif ( ctype_digit( $max_children ) ) {
                update_post_meta( $post_id, 'uupd_max_children', absint( $max_children ) );
            }
        }

        $fields = [
            'update_id'        => 'intval',
            'package_id'       => 'sanitize_text_field',
            'user_id'          => 'intval',
            'license_key'      => 'sanitize_text_field',
            'status'           => 'sanitize_text_field',
            'activation_limit' => 'intval',
            'expiry'           => 'sanitize_text_field',
            'order_id'         => 'sanitize_text_field',
        ];

        foreach ( $fields as $key => $cb ) {
            $form_key = 'uupd_' . $key;
            if ( isset( $_POST[ $form_key ] ) ) {
                $val = wp_unslash( $_POST[ $form_key ] );
                if ( 'intval' === $cb ) {
                    $val = intval( $val );
                } else {
                    $val = call_user_func( $cb, $val );
                }
                if ( get_post_meta( $post_id, 'uupd_parent_license_id', true ) && in_array( $key, [ 'update_id', 'package_id', 'user_id', 'activation_limit', 'expiry', 'order_id' ], true ) ) { continue; }
                update_post_meta( $post_id, $key, $val );
            }
        }
        if ( ! get_post_meta( $post_id, 'uupd_parent_license_id', true ) ) {
            $effective_activation_limit = uupd_license_master_limit( $post_id );
            $stored_child_limit = get_post_meta( $post_id, 'uupd_max_children', true );
            if ( $effective_activation_limit && ( 'unlimited' === $stored_child_limit || ( is_numeric( $stored_child_limit ) && (int) $stored_child_limit > $effective_activation_limit ) ) ) {
                update_post_meta( $post_id, 'uupd_max_children', $effective_activation_limit );
            }
            if ( $children_were_enabled && ! uupd_license_feature_enabled( $post_id, 'children' ) && ! isset( $_POST['uupd_keep_children_active'] ) ) {
                uupd_revoke_all_child_licenses( $post_id );
            }
            $children = get_posts( [ 'post_type' => 'uupd_license', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1, 'meta_key' => 'uupd_parent_license_id', 'meta_value' => $post_id ] );
            foreach ( $children as $child_id ) {
                foreach ( [ 'expiry' ] as $field ) {
                    update_post_meta( $child_id, $field, get_post_meta( $post_id, $field, true ) );
                }
            }
        }

        // Used domains: one per line
        if ( ! get_post_meta( $post_id, 'uupd_parent_license_id', true ) && isset( $_POST['uupd_used_domains'] ) ) {
            $raw   = wp_unslash( $_POST['uupd_used_domains'] );
            $lines = preg_split( '/\r\n|\r|\n/', $raw );
            $clean = [];

            foreach ( $lines as $line ) {
                $d = trim( $line );
                if ( $d === '' ) {
                    continue;
                }
                $clean[] = sanitize_text_field( $d );
            }

            $limit = uupd_license_master_limit( $post_id );
            $old = get_post_meta( $post_id, 'used_domains', true );
            $old_count = is_array( $old ) ? count( $old ) : 0;
            if ( $limit && uupd_license_family_usage( $post_id ) - $old_count + count( $clean ) > $limit ) {
                update_post_meta( $post_id, 'uupd_domain_limit_error', '1' );
            } else {
                update_post_meta( $post_id, 'used_domains', $clean );
            }
        }

        // Keep post title in sync with license key
        $license_key = get_post_meta( $post_id, 'license_key', true );
        if ( $license_key && $post->post_title !== $license_key ) {
            wp_update_post(
                [
                    'ID'         => $post_id,
                    'post_title' => $license_key,
                    'post_name'  => sanitize_title( $license_key ),
                ]
            );
        }
    }

    /* -------------------------------------------------------------------------
     * LIST TABLE COLUMNS
     * ---------------------------------------------------------------------- */

    /**
     * Define columns for the Licenses list.
     */
    public function filter_columns( $columns ) {

        // Preserve checkbox and date
        $new = [];

        $new['cb']                 = $columns['cb'] ?? '<input type="checkbox" />';

        // Title column = licence key
        $new['title']              = __( 'Licence Key', 'uupd-server' );
        $new['uupd_copy']          = __( 'Copy', 'uupd-server' ); // NEW copy column
        $new['uupd_update']        = __( 'Download', 'uupd-server' );
        $new['uupd_user']          = __( 'User', 'uupd-server' );
        $new['uupd_status']        = __( 'Status', 'uupd-server' );
        $new['uupd_activation']    = __( 'Activation Limit', 'uupd-server' );
        $new['uupd_expiry']        = __( 'Expiry Date', 'uupd-server' );
        $new['uupd_order_id']      = __( 'Order / Invoice', 'uupd-server' );
        $new['date']               = $columns['date'] ?? __( 'Date', 'uupd-server' );

        return $new;
    }

    /**
     * Render custom column content.
     */
    public function render_column( $column, $post_id ) {

        switch ( $column ) {

            case 'uupd_copy':
                $key = get_post_meta( $post_id, 'license_key', true );
                if ( ! $key ) {
                    $key = get_the_title( $post_id );
                }

                if ( ! $key ) {
                    echo '&mdash;';
                    break;
                }

                printf(
                    '<button type="button" class="button button-small uupd-copy-key" data-key="%s">%s</button>',
                    esc_attr( $key ),
                    esc_html__( 'Copy', 'uupd-server' )
                );
                break;

            case 'uupd_update':
                $update_id = intval( get_post_meta( $post_id, 'update_id', true ) );
                if ( ! $update_id ) {
                    echo '&mdash;';
                    break;
                }

                $title = get_the_title( $update_id );
                if ( ! $title ) {
                    echo '&mdash;';
                    break;
                }

                $link = get_edit_post_link( $update_id );
                if ( $link ) {
                    printf(
                        '<a href="%s">%s</a>',
                        esc_url( $link ),
                        esc_html( $title )
                    );
                } else {
                    echo esc_html( $title );
                }
                break;

            case 'uupd_user':
                $user_id = intval( get_post_meta( $post_id, 'user_id', true ) );
                if ( ! $user_id ) {
                    echo '&mdash;';
                    break;
                }

                $user = get_user_by( 'id', $user_id );
                if ( ! $user ) {
                    echo '&mdash;';
                    break;
                }

                $name = $user->display_name ?: $user->user_login;
                $link = get_edit_user_link( $user_id );

                if ( $link ) {
                    printf(
                        '<a href="%s">%s</a>',
                        esc_url( $link ),
                        esc_html( $name )
                    );
                } else {
                    echo esc_html( $name );
                }
                break;

            case 'uupd_status':
                $status = get_post_meta( $post_id, 'status', true ) ?: 'active';
                echo esc_html( ucfirst( $status ) );
                break;

            case 'uupd_activation':
                $limit = intval( get_post_meta( $post_id, 'activation_limit', true ) );
                if ( ! $limit ) {
                    echo '&infin;';
                } else {
                    echo esc_html( $limit );
                }
                break;

            case 'uupd_expiry':
                $expiry = get_post_meta( $post_id, 'expiry', true );
                if ( ! $expiry ) {
                    esc_html_e( 'Lifetime', 'uupd-server' );
                } else {
                    $ts = strtotime( $expiry );
                    if ( $ts ) {
                        echo esc_html( date_i18n( get_option( 'date_format' ), $ts ) );
                    } else {
                        echo esc_html( $expiry );
                    }
                }
                break;

            case 'uupd_order_id':
                $order_id = get_post_meta( $post_id, 'order_id', true );
                if ( $order_id ) {
                    echo wp_kses_post( apply_filters( 'uupd_license_order_reference_html', esc_html( $order_id ), $post_id, $order_id ) );
                } else {
                    echo '&mdash;';
                }
                break;
        }
    }

    /* -------------------------------------------------------------------------
     * LIST TABLE JS (copy buttons)
     * ---------------------------------------------------------------------- */

    public function enqueue_list_js( $hook ) {

        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'uupd_license' || $screen->base !== 'edit' ) {
            return;
        }

        wp_enqueue_script( 'jquery' );

        $js = <<<JS
jQuery(function($){
    $(document).on('click', '.uupd-copy-key', function(e){
        e.preventDefault();
        var key = $(this).data('key');

        function fallbackCopy() {
            window.prompt('Copy the license key:', key);
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(key).then(function(){
                alert('License key copied to clipboard.');
            }).catch(function(){
                fallbackCopy();
            });
        } else {
            fallbackCopy();
        }
    });
});
JS;

        wp_add_inline_script( 'jquery', $js );
    }
}
