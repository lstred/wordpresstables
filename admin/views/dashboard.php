<?php
/**
 * Admin Dashboard view.
 *
 * @var array $stats
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$stats = TUM_Database::get_stats();
?>
<div id="tum-admin" class="wrap">
    <h1 class="tum-admin-heading">
        <span class="dashicons dashicons-grid-view"></span>
        <?php esc_html_e( 'Table Upload Manager', 'table-upload-manager' ); ?>
    </h1>

    <div class="tum-stat-cards">
        <div class="tum-stat-card">
            <div class="tum-stat-icon dashicons dashicons-media-spreadsheet"></div>
            <div class="tum-stat-value"><?php echo esc_html( $stats['total_tables'] ); ?></div>
            <div class="tum-stat-label"><?php esc_html_e( 'Active Tables', 'table-upload-manager' ); ?></div>
        </div>
        <div class="tum-stat-card">
            <div class="tum-stat-icon dashicons dashicons-groups"></div>
            <div class="tum-stat-value"><?php echo esc_html( $stats['approved_users'] ); ?></div>
            <div class="tum-stat-label"><?php esc_html_e( 'Approved Users', 'table-upload-manager' ); ?></div>
        </div>
        <div class="tum-stat-card">
            <div class="tum-stat-icon dashicons dashicons-editor-table"></div>
            <div class="tum-stat-value"><?php echo esc_html( number_format( (int) $stats['total_rows'] ) ); ?></div>
            <div class="tum-stat-label"><?php esc_html_e( 'Total Rows Stored', 'table-upload-manager' ); ?></div>
        </div>
    </div>

    <div class="tum-quick-links">
        <h2><?php esc_html_e( 'Quick Actions', 'table-upload-manager' ); ?></h2>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=tum-users' ) ); ?>" class="button button-primary">
            <?php esc_html_e( '+ Approve User', 'table-upload-manager' ); ?>
        </a>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=tum-tables' ) ); ?>" class="button">
            <?php esc_html_e( 'View All Tables', 'table-upload-manager' ); ?>
        </a>
    </div>

    <div class="tum-shortcode-box">
        <h2><?php esc_html_e( 'Shortcode', 'table-upload-manager' ); ?></h2>
        <p><?php esc_html_e( 'Paste this shortcode on any page or post to display the table manager interface.', 'table-upload-manager' ); ?></p>
        <code class="tum-shortcode-code">[table_manager]</code>
        <button class="button tum-copy-shortcode" data-copy="[table_manager]">
            <?php esc_html_e( 'Copy', 'table-upload-manager' ); ?>
        </button>
    </div>
</div>
