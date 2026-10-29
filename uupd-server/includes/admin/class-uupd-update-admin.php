<?php

/*The Update CPT
The Update meta box
Loader column + copy buttons
Saving meta
Admin scripts for media, sortable files, packages, etc
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the Update CPT, meta box, saving, loader column,
 * and all related admin UI.
 */
class UUPD_Update_Admin {

    const TOKEN_EXPIRY = 3600;

    public function register_hooks() {

        // CPT
        add_action( 'init', [ $this, 'register_cpt' ] );

        // Meta box
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'save_post_update', [ $this, 'save_meta' ], 10, 2 );

        // Loader + extra columns
        add_filter( 'manage_edit-update_columns', [ $this, 'columns' ] );
        add_action( 'manage_update_posts_custom_column', [ $this, 'render_column' ], 10, 2 );

        // Loader JS
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_loader_script' ] );

        // Complex media + sortable UI
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_ui' ] );
    }

    /*-------------------------------------------------------------------------
     * CPT
     *------------------------------------------------------------------------*/

    public function register_cpt() {

        register_post_type(
            'update',
            [
                'label'        => __( 'Updates', 'uupd-server' ),
                'labels'       => [
                    'name'          => __( 'Updates', 'uupd-server' ),
                    'singular_name' => __( 'Plugin', 'uupd-server' ),
                    'add_new'       => __( 'Add Plugin', 'uupd-server' ),
                    'add_new_item'  => __( 'Add Plugin', 'uupd-server' ),
                    'edit_item'     => __( 'Edit Plugin', 'uupd-server' ),
                    'new_item'      => __( 'New Plugin', 'uupd-server' ),
                    'search_items'  => __( 'Search Plugins', 'uupd-server' ),
                    'not_found'     => __( 'No plugins found.', 'uupd-server' ),
                ],
                'public'       => false,
                'show_ui'      => true,
                'show_in_menu' => 'uupd_server',
                'supports'     => [ 'title' ],
            ]
        );
    }

    /*-------------------------------------------------------------------------
     * META BOX
     *------------------------------------------------------------------------*/

    public function add_meta_box() {
        add_meta_box(
            'uupd_meta',
            __( 'Update Details', 'uupd-server' ),
            [ $this, 'render_meta_box' ],
            'update',
            'normal',
            'default'
        );
    }


    /**
     * Renders the entire large meta box
     */
    public function render_meta_box( $post ) {

        wp_nonce_field( 'uupd_save_update', 'uupd_nonce' );

        // Fetch all meta in one go
        $m = get_post_meta( $post->ID );

        // Basic fields
        $fields = [
            'slug', 'version', 'tested', 'homepage', 'remote_url',
        ];
        foreach ( $fields as $f ) {
            $$f = $m[ $f ][0] ?? '';
        }

        $min_wp  = $m['min_wp_version'][0] ?? '';
        $min_php = $m['min_php_version'][0] ?? '';

        $licensed_product = ! empty( $m['licensed_product'][0] );
        $license_prefix   = $m['license_prefix'][0] ?? '';

        // Loader options
        $loader_menu_location = $m['loader_menu_location'][0] ?? 'settings';
        $loader_menu_slug     = $m['loader_menu_slug'][0] ?? '';
        $loader_page_title    = $m['loader_page_title'][0] ?? '';
        $loader_menu_title    = $m['loader_menu_title'][0] ?? '';
        $loader_parent_slug   = $m['loader_parent_slug'][0] ?? '';
        $loader_inline_box    = ! empty( $m['loader_inline_box'][0] );

        // Packages + Files
        $packages = maybe_unserialize( $m['packages'][0] ?? [] );
        if ( ! is_array( $packages ) ) {
            $packages = [];
        }

        $files = maybe_unserialize( $m['files'][0] ?? [] );
        if ( ! is_array( $files ) ) {
            $files = [];
        }

        // Icons / Banners
        $sizes = [ 'icon_128', 'icon_256', 'banner_773', 'banner_1544' ];
        foreach ( $sizes as $s ) {
            ${$s . '_id'}  = intval( $m[ $s . '_id' ][0] ?? 0 );
            ${$s . '_url'} = esc_url( $m[ $s . '_url' ][0] ?? '' );
        }

        $changelog = $m['changelog_html'][0] ?? '';

        include UUPD_SERVER_DIR . 'includes/admin/views/update-meta-box.php';
    }


    /*-------------------------------------------------------------------------
     * SAVE META
     *------------------------------------------------------------------------*/

    public function save_meta( $post_id, $post ) {

        if ( ! isset( $_POST['uupd_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( $_POST['uupd_nonce'], 'uupd_save_update' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( $post->post_type !== 'update' ) {
            return;
        }

        // Basic text fields
        $simple = [
            'slug', 'version', 'tested', 'homepage', 'remote_url',
            'min_wp_version', 'min_php_version',
        ];

        foreach ( $simple as $f ) {
            if ( isset( $_POST[ "uupd_$f" ] ) ) {
                update_post_meta(
                    $post_id,
                    $f,
                    sanitize_text_field( wp_unslash( $_POST[ "uupd_$f" ] ) )
                );
            }
        }

        // License prefix + flag
        update_post_meta( $post_id, 'licensed_product', ! empty( $_POST['uupd_licensed_product'] ) ? 1 : 0 );

        if ( isset( $_POST['uupd_license_prefix'] ) ) {
            update_post_meta(
                $post_id,
                'license_prefix',
                sanitize_text_field( wp_unslash( $_POST['uupd_license_prefix'] ) )
            );
        }

        // Loader options
        $loader_fields = [
            'loader_menu_location',
            'loader_menu_slug',
            'loader_page_title',
            'loader_menu_title',
            'loader_parent_slug',
        ];

        foreach ( $loader_fields as $f ) {
            if ( isset( $_POST[ "uupd_$f" ] ) ) {
                update_post_meta(
                    $post_id,
                    $f,
                    sanitize_text_field( wp_unslash( $_POST[ "uupd_$f" ] ) )
                );
            }
        }

        update_post_meta(
            $post_id,
            'loader_inline_box',
            ! empty( $_POST['uupd_loader_inline_box'] ) ? 1 : 0
        );

        // Icons + banners
        $media_fields = [ 'icon_128', 'icon_256', 'banner_773', 'banner_1544' ];
        foreach ( $media_fields as $f ) {
            if ( isset( $_POST[ "uupd_{$f}_url" ] ) ) {
                update_post_meta(
                    $post_id,
                    $f . '_url',
                    esc_url_raw( wp_unslash( $_POST[ "uupd_{$f}_url" ] ) )
                );
            }
            if ( isset( $_POST[ "uupd_{$f}_id" ] ) ) {
                update_post_meta(
                    $post_id,
                    $f . '_id',
                    intval( $_POST[ "uupd_{$f}_id" ] )
                );
            }
        }

        // Changelog
        if ( isset( $_POST['uupd_changelog_html'] ) ) {
            update_post_meta(
                $post_id,
                'changelog_html',
                wp_kses_post( wp_unslash( $_POST['uupd_changelog_html'] ) )
            );
        }

        // Packages
        if ( isset( $_POST['uupd_packages'] ) && is_array( $_POST['uupd_packages'] ) ) {
            $clean = [];
            foreach ( $_POST['uupd_packages'] as $p ) {
                if ( empty( $p['id'] ) ) {
                    continue;
                }

                $clean[] = [
                    'id'               => sanitize_key( $p['id'] ),
                    'label'            => sanitize_text_field( $p['label'] ?? '' ),
                    'activation_limit' => intval( $p['activation_limit'] ?? 0 ),
                ];
            }
            update_post_meta( $post_id, 'packages', $clean );
        } else {
            delete_post_meta( $post_id, 'packages' );
        }

        // Files
        if ( isset( $_POST['uupd_files'] ) && is_array( $_POST['uupd_files'] ) ) {
            $out = [];
            foreach ( $_POST['uupd_files'] as $f ) {
                $id = intval( $f['id'] ?? 0 );
                if ( $id ) {
                    $out[] = [ 'id' => $id ];
                }
            }
            update_post_meta( $post_id, 'files', $out );
        } else {
            delete_post_meta( $post_id, 'files' );
        }

        update_post_meta( $post_id, 'last_updated', current_time( 'mysql' ) );
    }


    /*-------------------------------------------------------------------------
     * COLUMNS
     *------------------------------------------------------------------------*/

    public function columns( $cols ) {

        $new = [];

        foreach ( $cols as $key => $label ) {
            $new[ $key ] = $label;

            // Insert our custom columns right after the Title column.
            if ( 'title' === $key ) {
                $new['uupd_version']    = __( 'Version', 'uupd-server' );
                $new['uupd_tested']     = __( 'WP Tested to', 'uupd-server' );
                $new['uupd_licensed']   = __( 'Licensed?', 'uupd-server' );
                $new['uupd_loader']     = __( 'Loader', 'uupd-server' );
                $new['uupd_inline_box'] = __( 'Inline box', 'uupd-server' );
            }
        }

        // Fallback: if for some reason "title" wasn't in the original columns.
        if ( ! isset( $new['uupd_loader'] ) ) {
            $new['uupd_version']    = __( 'Version', 'uupd-server' );
            $new['uupd_tested']     = __( 'WP Tested to', 'uupd-server' );
            $new['uupd_licensed']   = __( 'Licensed?', 'uupd-server' );
            $new['uupd_loader']     = __( 'Loader', 'uupd-server' );
            $new['uupd_inline_box'] = __( 'Inline box', 'uupd-server' );
        }

        return $new;
    }


    protected function get_global_vendor() {
        $vendor = get_option( 'uupd_global_vendor', '' );
        $vendor = is_string( $vendor ) ? sanitize_key( $vendor ) : '';
        return '' !== $vendor ? $vendor : 'yourvendor';
    }

    protected function get_global_provider() {
        $provider = get_option( 'uupd_global_provider', '' );
        $provider = is_string( $provider ) ? sanitize_key( $provider ) : '';
        return $provider;
    }

    protected function build_vendor_slug_constant_base( $vendor, $slug ) {
        $vendor_base = sanitize_key( (string) $vendor );
        $vendor_base = strtoupper( str_replace( '-', '_', $vendor_base ) );
        $vendor_base = preg_replace( '/[^A-Z0-9_]+/', '_', $vendor_base );
        $vendor_base = preg_replace( '/_+/', '_', $vendor_base );
        $vendor_base = trim( $vendor_base, '_' );
        if ( '' === $vendor_base ) {
            $vendor_base = 'YOURVENDOR';
        }

        $slug_base = sanitize_key( (string) $slug );
        $slug_base = strtoupper( str_replace( '-', '_', $slug_base ) );
        $slug_base = preg_replace( '/[^A-Z0-9_]+/', '_', $slug_base );
        $slug_base = preg_replace( '/_+/', '_', $slug_base );
        $slug_base = trim( $slug_base, '_' );
        if ( '' === $slug_base ) {
            $slug_base = 'ITEM';
        }

        return 'UUPD_' . $vendor_base . '_' . $slug_base;
    }

	public function render_column( $col, $post_id ) {

	    // Simple info columns first
	    if ( 'uupd_version' === $col ) {
	        $version = get_post_meta( $post_id, 'version', true );
	        echo $version ? esc_html( $version ) : '&mdash;';
	        return;
	    }

	    if ( 'uupd_tested' === $col ) {
	        $tested = get_post_meta( $post_id, 'tested', true );
	        echo $tested ? esc_html( $tested ) : '&mdash;';
	        return;
	    }

	    if ( 'uupd_licensed' === $col ) {
	        $licensed = (bool) get_post_meta( $post_id, 'licensed_product', true );
	        if ( $licensed ) {
	            echo '<span class="dashicons dashicons-yes" aria-hidden="true"></span>';
	            echo '<span class="screen-reader-text">' . esc_html__( 'Licensed product', 'uupd-server' ) . '</span>';
	        } else {
	            echo '&mdash;';
	        }
	        return;
	    }

	    // Existing loader / inline box handling
	    if ( $col !== 'uupd_loader' && $col !== 'uupd_inline_box' ) {
	        return;
	    }

	    $slug = get_post_meta( $post_id, 'slug', true );
	    $slug = $slug ?: sanitize_title( get_post_field( 'post_name', $post_id ) );

	    $version  = get_post_meta( $post_id, 'version', true ) ?: '1.0.0';
	    $friendly = get_the_title( $post_id );
	    $server   = home_url();
	    $item_id  = $post_id;

	    // Loader config
	    $loader_menu_location = get_post_meta( $post_id, 'loader_menu_location', true ) ?: 'settings';
	    $loader_menu_slug     = get_post_meta( $post_id, 'loader_menu_slug', true ) ?: ( $slug . '-license' );
	    $loader_page_title    = get_post_meta( $post_id, 'loader_page_title', true ) ?: ( $friendly . ' License' );
	    $loader_menu_title    = get_post_meta( $post_id, 'loader_menu_title', true ) ?: __( 'License', 'uupd-server' );
	    $loader_parent_slug   = get_post_meta( $post_id, 'loader_parent_slug', true );
	    $inline_enabled       = (bool) get_post_meta( $post_id, 'loader_inline_box', true );

	    $vendor   = $this->get_global_vendor();
	    $provider = $this->get_global_provider();

	    // Build constants base
	    $const_base = $this->build_vendor_slug_constant_base( $vendor, $slug );

	    // Default generated menu slug
	    $default_menu_slug = $vendor . '-' . $slug;

	    // Convert menu location → literal
	    switch ( $loader_menu_location ) {
	        case 'top_level':
	            $parent = "'top_level'";
	            break;
	        case 'tools':
	            $parent = "'tools.php'";
	            break;
	        case 'custom_parent':
	            $parent = "'" . esc_attr( $loader_parent_slug ) . "'";
	            break;
	        case 'none':
	            $parent = "false";
	            break;
	        default:
	            $parent = "'options-general.php'";
	            break;
	    }

	    // Loader include paths from settings
	    $updater_path   = get_option( 'uupd_loader_updater_path', '/inc/updater.php' );
	    $licenseui_path = get_option( 'uupd_loader_license_ui_path', '/inc/class-uupd-license-ui.php' );

	    if ( $updater_path && $updater_path[0] !== '/' ) {
	        $updater_path = '/' . $updater_path;
	    }
	    if ( $licenseui_path && $licenseui_path[0] !== '/' ) {
	        $licenseui_path = '/' . $licenseui_path;
	    }

	    if ( $col === 'uupd_loader' ) {

	        $code =
	"define( '{$const_base}_VENDOR', '{$vendor}' );
	" .
	( $provider !== '' ? "define( '{$const_base}_PROVIDER', '{$provider}' );
	" : '' ) .
	"define( '{$const_base}_VERSION', '{$version}' );
	" .
	"define( '{$const_base}_MAIN_FILE', __FILE__ );
	" .
	"define( '{$const_base}_ITEM_NUMBER', {$item_id} );
	" .
	"define( '{$const_base}_FRIENDLY_NAME', '{$friendly}' );
	" .
	"define( '{$const_base}_SLUG', '{$slug}' );
	" .
	"define( '{$const_base}_SERVER_BASE', '{$server}' );
	" .
	"define( '{$const_base}_MENU_SLUG', {$const_base}_VENDOR . '-' . {$const_base}_SLUG );

	" .
	"add_action( 'plugins_loaded', function() {
	" .
	"    require_once __DIR__ . '{$updater_path}';
	" .
	"    require_once __DIR__ . '{$licenseui_path}';

	" .
	"    \\UUPD\\UI\\License\\V1\\UUPD_License_UI::register([
	" .
	"        'vendor'         => {$const_base}_VENDOR,
	" .
	"        'slug'           => {$const_base}_SLUG,
	" .
	"        'item_id'        => {$const_base}_ITEM_NUMBER,
	" .
	"        'license_server' => {$const_base}_SERVER_BASE,
	" .
	"        'metadata_base'  => {$const_base}_SERVER_BASE,
	" .
	"        'plugin_name'    => {$const_base}_FRIENDLY_NAME,
	" .
	"        'menu_parent'    => {$parent},
	" .
	"        'menu_slug'      => {$const_base}_MENU_SLUG,
	" .
	"        'page_title'     => '{$loader_page_title}',
	" .
	"        'menu_title'     => '{$loader_menu_title}',
	" .
	"        'capability'     => 'manage_options',
	" .
	"        'pass_token'     => false,
	" .
	"        'token_param'    => 'token',
	" .
	"    ]);

	" .
	"    \\UUPD\\V2\\UUPD_Updater_V2::register([
	" .
	"        'vendor'      => {$const_base}_VENDOR,
	" .
	"        'plugin_file' => plugin_basename( {$const_base}_MAIN_FILE ),
	" .
	"        'slug'        => {$const_base}_SLUG,
	" .
	"        'name'        => {$const_base}_FRIENDLY_NAME,
	" .
	"        'version'     => {$const_base}_VERSION,
	" .
	"        'server'      => '',
	" .
	"        'license_key_transport' => 'header',
	" .
	"    ]);
	" .
	"}, 1 );";

	        echo '<button class="button uupd-copy-loader" data-code="' . esc_attr( $code ) . '">' .
	            esc_html__( 'Copy loader', 'uupd-server' ) .
	            '</button>';
	        return;
	    }

	    if ( $col === 'uupd_inline_box' ) {

	        if ( ! $inline_enabled ) {
	            echo '&mdash;';
	            return;
	        }

	        $inline_code = "<?php \$inst = \\UUPD\\UI\\License\\V1\\UUPD_License_UI::get_instance( {$const_base}_VENDOR, {$const_base}_SLUG ); if ( \$inst ) { \$inst->render_page(); } ?>";

	        echo '<button class="button uupd-copy-loader" data-code="' . esc_attr( $inline_code ) . '">' .
	            esc_html__( 'Copy inline box', 'uupd-server' ) .
	            '</button>';
	    }
	}




    /*-------------------------------------------------------------------------
     * LOADER COPY BUTTON JS
     *------------------------------------------------------------------------*/

    public function enqueue_loader_script() {

        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'update' || $screen->base !== 'edit' ) {
            return;
        }

        $js = "
            jQuery(document).on('click', '.uupd-copy-loader', function(e){
                e.preventDefault();
                var code = jQuery(this).data('code');
                if (navigator.clipboard) {
                    navigator.clipboard.writeText(code).then(function(){
                        alert('Copied!');
                    }).catch(function(){ fallback(); });
                } else { fallback(); }
                function fallback() { window.prompt('Copy:', code); }
            });
        ";

        wp_add_inline_script( 'jquery', $js );
    }


    /*-------------------------------------------------------------------------
     * ADMIN MEDIA / SORTABLE UI (icons, banners, packages, files)
     *------------------------------------------------------------------------*/

    public function enqueue_admin_ui( $hook ) {
        // Only run on the Update edit screens
        if ( ! function_exists( 'get_current_screen' ) ) {
            return;
        }

        $screen = get_current_screen();

        // post.php = edit existing post, post-new.php = add new post
        if ( ! $screen || $screen->post_type !== 'update' ) {
            return;
        }
        if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) {
            return;
        }

        // Make sure these are loaded
        wp_enqueue_script( 'jquery' );
        wp_enqueue_media();
        wp_enqueue_script( 'jquery-ui-sortable' );

        // Load the JS view and attach as inline script
        ob_start();
        include UUPD_SERVER_DIR . 'includes/admin/views/update-meta-js.php';
        $script = ob_get_clean();

        if ( $script ) {
            wp_add_inline_script( 'jquery', $script );
        }
    }


}
