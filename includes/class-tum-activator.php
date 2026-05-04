<?php
/**
 * Fired during plugin activation and deactivation.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class TUM_Activator {

    public static function activate() {
        TUM_Database::create_tables();
        update_option( 'tum_db_version', TUM_DB_VERSION );
        update_option( 'tum_flush_rewrite', 1 );
    }

    public static function deactivate() {
        // Intentionally light – tables are preserved on deactivate.
        // Tables are only dropped on full uninstall (uninstall.php).
    }
}
