<?php
/**
 * Runs only when the user clicks "Delete" in the plugins list.
 * Removes all plugin database tables and options.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-tum-database.php';

TUM_Database::drop_tables();

delete_option( 'tum_db_version' );
delete_option( 'tum_flush_rewrite' );
