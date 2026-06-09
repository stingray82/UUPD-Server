<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Settings page for UUPD Server.
 *
 * - API Bearer key
 * - Loader include paths (updater + license UI)
 */
class UUPD_Settings_Page {

    /**
     * Called by UUPD_Core.
     */
    public function register_hooks() {
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
    }

    /**
     * Adds the settings page under Settings → UUPD Server.
     */
    public function add_settings_page() {

        add_options_page(
            __( 'UUPD Server', 'uupd-server' ),
            __( 'UUPD Server', 'uupd-server' ),
            'manage_options',
            'uupd-server',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Render & process the settings form.
     */
    public function render_settings_page() {

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Handle save
        if (
            isset( $_POST['uupd_settings_nonce'] )
            && wp_verify_nonce( $_POST['uupd_settings_nonce'], 'uupd_settings_save' )
        ) {
            // Bearer key
            if ( isset( $_POST['uupd_bearer_key'] ) ) {
                update_option(
                    'uupd_bearer_key',
                    sanitize_text_field( wp_unslash( $_POST['uupd_bearer_key'] ) )
                );
            }

            // Global vendor/provider
            if ( isset( $_POST['uupd_global_vendor'] ) ) {
                update_option(
                    'uupd_global_vendor',
                    sanitize_key( wp_unslash( $_POST['uupd_global_vendor'] ) )
                );
            }

            if ( isset( $_POST['uupd_global_provider'] ) ) {
                update_option(
                    'uupd_global_provider',
                    sanitize_key( wp_unslash( $_POST['uupd_global_provider'] ) )
                );
            }

            // Loader paths
            if ( isset( $_POST['uupd_loader_updater_path'] ) ) {
                $val = trim( wp_unslash( $_POST['uupd_loader_updater_path'] ) );
                update_option( 'uupd_loader_updater_path', sanitize_text_field( $val ) );
            }

            if ( isset( $_POST['uupd_loader_license_ui_path'] ) ) {
                $val = trim( wp_unslash( $_POST['uupd_loader_license_ui_path'] ) );
                update_option( 'uupd_loader_license_ui_path', sanitize_text_field( $val ) );
            }

            echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'uupd-server' ) . '</p></div>';
        }

        $bearer         = get_option( 'uupd_bearer_key', '' );
        $global_vendor  = get_option( 'uupd_global_vendor', '' );
        $global_provider = get_option( 'uupd_global_provider', '' );
        $updater_path   = get_option( 'uupd_loader_updater_path', '/inc/updater.php' );
        $licenseui_path = get_option( 'uupd_loader_license_ui_path', '/inc/class-uupd-license-ui.php' );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'UUPD Server Settings', 'uupd-server' ); ?></h1>

            <form method="post">
                <?php wp_nonce_field( 'uupd_settings_save', 'uupd_settings_nonce' ); ?>

                <table class="form-table" role="presentation">

                    <tr>
                        <th scope="row">
                            <label for="uupd_bearer_key">
                                <?php esc_html_e( 'API Bearer key', 'uupd-server' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text"
                                   id="uupd_bearer_key"
                                   name="uupd_bearer_key"
                                   value="<?php echo esc_attr( $bearer ); ?>"
                                   class="regular-text" />
                            <p class="description">
                                <?php esc_html_e( 'Use this in Authorization: Bearer &lt;key&gt; for server-side API calls (e.g. WooCommerce).', 'uupd-server' ); ?>
                            </p>
                        </td>
                    </tr>


                    <tr>
                        <th scope="row">
                            <label for="uupd_global_vendor">
                                <?php esc_html_e( 'Global vendor slug', 'uupd-server' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text"
                                   id="uupd_global_vendor"
                                   name="uupd_global_vendor"
                                   value="<?php echo esc_attr( $global_vendor ); ?>"
                                   class="regular-text code"
                                   placeholder="rup" />
                            <p class="description">
                                <?php esc_html_e( 'Used in generated loader constants and vendor-aware UI/updater registration.', 'uupd-server' ); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="uupd_global_provider">
                                <?php esc_html_e( 'Global provider slug', 'uupd-server' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text"
                                   id="uupd_global_provider"
                                   name="uupd_global_provider"
                                   value="<?php echo esc_attr( $global_provider ); ?>"
                                   class="regular-text code"
                                   placeholder="tdlab" />
                            <p class="description">
                                <?php esc_html_e( 'Optional provider constant for generated loaders.', 'uupd-server' ); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="uupd_loader_updater_path">
                                <?php esc_html_e( 'Updater include path', 'uupd-server' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text"
                                   id="uupd_loader_updater_path"
                                   name="uupd_loader_updater_path"
                                   value="<?php echo esc_attr( $updater_path ); ?>"
                                   class="regular-text code"
                                   placeholder="/inc/updater.php" />
                            <p class="description">
                                <?php esc_html_e( 'Relative path from the plugin main file to updater.php. Used in the generated loader snippet.', 'uupd-server' ); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="uupd_loader_license_ui_path">
                                <?php esc_html_e( 'License UI include path', 'uupd-server' ); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text"
                                   id="uupd_loader_license_ui_path"
                                   name="uupd_loader_license_ui_path"
                                   value="<?php echo esc_attr( $licenseui_path ); ?>"
                                   class="regular-text code"
                                   placeholder="/inc/class-uupd-license-ui.php" />
                            <p class="description">
                                <?php esc_html_e( 'Relative path from the plugin main file to class-uupd-license-ui.php. Used in the generated loader snippet.', 'uupd-server' ); ?>
                            </p>
                        </td>
                    </tr>

                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}
