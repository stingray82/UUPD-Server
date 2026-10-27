<?php
/**
 * Plugin Name:     UUPD Server
 * Description:     Provides a custom post type and secure file download or remote redirect for Universal Updater (UUPD_Updater), with licensing, multiple packages, and multiple files per update.
 * Version:         2.2.0
 * Author:          Stingray82
 * Text Domain:     uupd-server
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'UUPD_SERVER_FILE', __FILE__ );
define( 'UUPD_SERVER_DIR', plugin_dir_path( __FILE__ ) );
define( 'UUPD_SERVER_URL', plugin_dir_url( __FILE__ ) );

// -----------------------------------------------------------------------------
// Core + class includes
// -----------------------------------------------------------------------------

require_once UUPD_SERVER_DIR . 'includes/class-uupd-core.php';

// Admin modules
require_once UUPD_SERVER_DIR . 'includes/admin/class-uupd-admin-menu.php';
require_once UUPD_SERVER_DIR . 'includes/admin/class-uupd-update-admin.php';
require_once UUPD_SERVER_DIR . 'includes/admin/class-uupd-license-admin.php';

// REST API
require_once UUPD_SERVER_DIR . 'includes/rest/class-uupd-rest-licenses.php';
require_once UUPD_SERVER_DIR . 'includes/rest/class-uupd-rest-releases.php';

// Helpers Suspend / Create / Deactivate
require_once UUPD_SERVER_DIR . 'includes/rest/license-helpers.php';

// Frontend legacy endpoints (?action=get_metadata / download)
require_once UUPD_SERVER_DIR . 'includes/frontend/class-uupd-legacy-endpoints.php';

// Frontend ShortCodes
require_once UUPD_SERVER_DIR . 'includes/frontend/class-licence-server-shortcodes.php';
require_once UUPD_SERVER_DIR . 'includes/frontend/class-uupd-license-keys.php';


// Settings page
require_once UUPD_SERVER_DIR . 'includes/settings/class-uupd-settings-page.php';

// Misc procedural hooks (random filename filter)
require_once UUPD_SERVER_DIR . 'includes/misc/randomize-media.php';

// -----------------------------------------------------------------------------
// Bootstrap
// -----------------------------------------------------------------------------

add_action( 'plugins_loaded', function () {

    // Simple container that lets each module attach its own hooks.
    $core = new UUPD_Core();

    // Admin UI
    $core->add_module( new UUPD_Admin_Menu() );
    $core->add_module( new UUPD_Update_Admin() );
    $core->add_module( new UUPD_License_Admin() );

    // REST + legacy endpoints
    $core->add_module( new UUPD_REST_Licenses() );
    $core->add_module( new UUPD_REST_Releases() );
    $core->add_module( new UUPD_Legacy_Endpoints() );

    // Settings
    $core->add_module( new UUPD_Settings_Page() );

    // Let every module register its actions/filters.
    $core->init();
} );
