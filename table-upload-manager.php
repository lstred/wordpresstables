<?php
/**
 * Plugin Name: Table Upload Manager
 * Plugin URI:  https://github.com/
 * Description: Allows approved users to upload and display formatted data tables on the frontend with advanced formatting, filtering, sorting, and multi-user permission management.
 * Version:     1.0.0
 * Author:      Table Upload Manager
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: table-upload-manager
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ─── Plugin Constants ────────────────────────────────────────────────────────
define( 'TUM_VERSION',          '1.0.0' );
define( 'TUM_PLUGIN_DIR',       plugin_dir_path( __FILE__ ) );
define( 'TUM_PLUGIN_URL',       plugin_dir_url( __FILE__ ) );
define( 'TUM_PLUGIN_BASENAME',  plugin_basename( __FILE__ ) );
define( 'TUM_DB_VERSION',       '1.0' );
define( 'TUM_MAX_FILE_SIZE',    5 * 1024 * 1024 ); // 5 MB
define( 'TUM_MAX_ROWS',         10000 );

// ─── Core Class Includes ─────────────────────────────────────────────────────
require_once TUM_PLUGIN_DIR . 'includes/class-tum-activator.php';
require_once TUM_PLUGIN_DIR . 'includes/class-tum-database.php';
require_once TUM_PLUGIN_DIR . 'includes/class-tum-security.php';
require_once TUM_PLUGIN_DIR . 'includes/class-tum-file-parser.php';
require_once TUM_PLUGIN_DIR . 'includes/class-tum-user-manager.php';
require_once TUM_PLUGIN_DIR . 'includes/class-tum-table-manager.php';
require_once TUM_PLUGIN_DIR . 'includes/class-tum-ajax-handler.php';

if ( is_admin() ) {
    require_once TUM_PLUGIN_DIR . 'admin/class-tum-admin.php';
}

require_once TUM_PLUGIN_DIR . 'public/class-tum-public.php';

// ─── Activation / Deactivation Hooks ─────────────────────────────────────────
register_activation_hook( __FILE__, [ 'TUM_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'TUM_Activator', 'deactivate' ] );

// ─── Bootstrap ────────────────────────────────────────────────────────────────
function tum_init() {
    TUM_Ajax_Handler::get_instance();

    if ( is_admin() ) {
        TUM_Admin::get_instance();
    }

    TUM_Public::get_instance();
}
add_action( 'plugins_loaded', 'tum_init' );
