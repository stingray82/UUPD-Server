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
    }

    /* -------------------------------------------------------------------------
     * CPT
     * ---------------------------------------------------------------------- */

    public function register_cpt() {
        register_post_type(
            'uupd_license',
            [
                'label'        => __( 'Licenses', 'uupd-server' ),
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
        <table class="form-table">

            <tr>
                <th><?php esc_html_e( 'Update', 'uupd-server' ); ?></th>
                <td>
                    <select id="uupd_update_id" name="uupd_update_id">
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
                    <select id="uupd_package_id" name="uupd_package_id">
                        <option value=""><?php esc_html_e( '— None / default —', 'uupd-server' ); ?></option>
                        <!-- JS will populate options based on selected update -->
                    </select>
                </td>
            </tr>

            <tr>
                <th><?php esc_html_e( 'User', 'uupd-server' ); ?></th>
                <td>
                    <select id="uupd_user_id" name="uupd_user_id">
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
                <th><?php esc_html_e( 'License key', 'uupd-server' ); ?></th>
                <td>
                    <input type="text"
                           name="uupd_license_key"
                           id="uupd_license_key"
                           value="<?php echo esc_attr( $license_key ); ?>"
                           class="regular-text">

                    <button type="button" class="button" id="uupd_generate_license_key">
                        <?php esc_html_e( 'Generate', 'uupd-server' ); ?>
                    </button>
                </td>
            </tr>

            <tr>
                <th><?php esc_html_e( 'Status', 'uupd-server' ); ?></th>
                <td>
                    <select name="uupd_status">
                        <option value="active"    <?php selected( $status, 'active' );    ?>><?php esc_html_e( 'Active', 'uupd-server' ); ?></option>
                        <option value="suspended" <?php selected( $status, 'suspended' ); ?>><?php esc_html_e( 'Suspended', 'uupd-server' ); ?></option>
                        <option value="expired"   <?php selected( $status, 'expired' );   ?>><?php esc_html_e( 'Expired', 'uupd-server' ); ?></option>
                    </select>
                </td>
            </tr>

            <tr>
                <th><?php esc_html_e( 'Activation limit', 'uupd-server' ); ?></th>
                <td>
                    <input type="number"
                           name="uupd_activation_limit"
                           value="<?php echo esc_attr( $activation_limit ); ?>">
                    <p class="description"><?php esc_html_e( '0 = use package default.', 'uupd-server' ); ?></p>
                </td>
            </tr>

            <tr>
                <th><?php esc_html_e( 'Expiry date', 'uupd-server' ); ?></th>
                <td>
                    <input type="date"
                           name="uupd_expiry"
                           value="<?php echo esc_attr( $expiry ); ?>">
                    <p class="description"><?php esc_html_e( 'Leave empty for lifetime license.', 'uupd-server' ); ?></p>
                </td>
            </tr>

            <tr>
                <th><?php esc_html_e( 'Order / Invoice ID', 'uupd-server' ); ?></th>
                <td>
                    <input type="text"
                           name="uupd_order_id"
                           value="<?php echo esc_attr( $order_id ); ?>"
                           class="regular-text">
                    <p class="description">
                        <?php esc_html_e( 'Reference to the order or invoice where this license was purchased (e.g. WooCommerce order number).', 'uupd-server' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th><?php esc_html_e( 'Used domains', 'uupd-server' ); ?></th>
                <td>
                    <textarea name="uupd_used_domains" rows="4" class="large-text"><?php
                        echo esc_textarea( implode( "\n", $used_domains ) );
                    ?></textarea>
                    <p class="description"><?php esc_html_e( 'One domain per line.', 'uupd-server' ); ?></p>
                </td>
            </tr>

        </table>

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
                update_post_meta( $post_id, $key, $val );
            }
        }

        // Used domains: one per line
        if ( isset( $_POST['uupd_used_domains'] ) ) {
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

            update_post_meta( $post_id, 'used_domains', $clean );
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
                    echo esc_html( $order_id );
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
