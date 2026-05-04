<?php
/**
 * Registers WordPress admin menus and enqueues admin assets.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class TUM_Admin {

    private static ?self $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_filter( 'plugin_action_links_' . TUM_PLUGIN_BASENAME, [ $this, 'plugin_action_links' ] );
    }

    public function register_menu(): void {
        add_menu_page(
            __( 'Table Manager', 'table-upload-manager' ),
            __( 'Table Manager', 'table-upload-manager' ),
            'manage_options',
            'table-upload-manager',
            [ $this, 'render_dashboard' ],
            'dashicons-grid-view',
            30
        );

        add_submenu_page(
            'table-upload-manager',
            __( 'Dashboard', 'table-upload-manager' ),
            __( 'Dashboard', 'table-upload-manager' ),
            'manage_options',
            'table-upload-manager',
            [ $this, 'render_dashboard' ]
        );

        add_submenu_page(
            'table-upload-manager',
            __( 'Approved Users', 'table-upload-manager' ),
            __( 'Approved Users', 'table-upload-manager' ),
            'manage_options',
            'tum-users',
            [ $this, 'render_users' ]
        );

        add_submenu_page(
            'table-upload-manager',
            __( 'All Tables', 'table-upload-manager' ),
            __( 'All Tables', 'table-upload-manager' ),
            'manage_options',
            'tum-tables',
            [ $this, 'render_tables' ]
        );
    }

    public function enqueue_assets( string $hook ): void {
        $tum_pages = [
            'toplevel_page_table-upload-manager',
            'table-manager_page_tum-users',
            'table-manager_page_tum-tables',
        ];

        if ( ! in_array( $hook, $tum_pages, true ) ) return;

        wp_enqueue_style(
            'tum-admin',
            TUM_PLUGIN_URL . 'admin/css/admin.css',
            [],
            TUM_VERSION
        );

        wp_enqueue_script(
            'tum-admin',
            TUM_PLUGIN_URL . 'admin/js/admin.js',
            [ 'jquery' ],
            TUM_VERSION,
            true
        );

        wp_localize_script( 'tum-admin', 'tumAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => TUM_Security::create_nonce(),
            'strings' => [
                'confirmDelete'   => __( 'Are you sure you want to delete this item? This cannot be undone.', 'table-upload-manager' ),
                'confirmUnapprove'=> __( 'Remove upload access for this user?', 'table-upload-manager' ),
                'saving'          => __( 'Saving…', 'table-upload-manager' ),
                'saved'           => __( 'Saved!', 'table-upload-manager' ),
                'error'           => __( 'An error occurred. Please try again.', 'table-upload-manager' ),
            ],
        ] );
    }

    public function render_dashboard(): void {
        require TUM_PLUGIN_DIR . 'admin/views/dashboard.php';
    }

    public function render_users(): void {
        require TUM_PLUGIN_DIR . 'admin/views/users.php';
    }

    public function render_tables(): void {
        require TUM_PLUGIN_DIR . 'admin/views/tables.php';
    }

    public function plugin_action_links( array $links ): array {
        $custom = [
            '<a href="' . esc_url( admin_url( 'admin.php?page=table-upload-manager' ) ) . '">' . __( 'Settings', 'table-upload-manager' ) . '</a>',
        ];
        return array_merge( $custom, $links );
    }
}
