<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles the main UUPD Server admin menu and submenu.
 */
class UUPD_Admin_Menu {

    public function register_hooks() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
    }

    /**
     * Register top-level & settings submenu.
     */
    public function register_menu() {

        // Top-level menu for Updates + Licenses CPTs
        add_menu_page(
            __( 'UUPD Server', 'uupd-server' ),
            __( 'UUPD Server', 'uupd-server' ),
            'manage_options',
            'uupd_server',
            [ $this, 'render_main_page' ],
            'dashicons-update-alt',
            26
        );

        // Settings submenu
        add_submenu_page(
            'uupd_server',
            __( 'Settings', 'uupd-server' ),
            __( 'Settings', 'uupd-server' ),
            'manage_options',
            'uupd_server_settings',
            [ $this, 'render_settings_redirect' ]
        );
    }

    /**
     * When clicking the top-level "UUPD Server" menu item,
     * redirect to the Updates list table.
     */
    public function render_main_page() {
        wp_safe_redirect( admin_url( 'edit.php?post_type=update' ) );
        exit;
    }

    /**
     * Redirect submenu click to the proper settings page.
     */
    public function render_settings_redirect() {
        wp_safe_redirect( admin_url( 'options-general.php?page=uupd-server' ) );
        exit;
    }
}
