<?php
/**
 * Registers the [table_manager] shortcode and enqueues public assets.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class TUM_Public {

    private static ?self $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( 'table_manager', [ $this, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'maybe_enqueue' ] );
    }

    /**
     * Enqueue on every frontend page – the JS only activates when the
     * shortcode wrapper is present in the DOM.
     */
    public function maybe_enqueue(): void {
        if ( is_admin() ) return;

        wp_enqueue_style(
            'tum-public',
            TUM_PLUGIN_URL . 'public/css/public.css',
            [],
            TUM_VERSION
        );

        wp_enqueue_script(
            'tum-public',
            TUM_PLUGIN_URL . 'public/js/public.js',
            [ 'jquery' ],
            TUM_VERSION,
            true
        );

        $current_user = wp_get_current_user();

        wp_localize_script( 'tum-public', 'tumData', [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => TUM_Security::create_nonce(),
            'isLoggedIn'  => is_user_logged_in(),
            'isApproved'  => TUM_Security::current_user_is_approved(),
            'currentUser' => is_user_logged_in() ? [
                'id'           => $current_user->ID,
                'display_name' => $current_user->display_name,
                'email'        => $current_user->user_email,
            ] : null,
            'maxFileSize' => TUM_MAX_FILE_SIZE,
            'maxRows'     => TUM_MAX_ROWS,
            'strings'     => [
                'uploadDrop'        => __( 'Drop your file here or click to browse', 'table-upload-manager' ),
                'uploadHint'        => __( 'Supported: CSV, TSV, XLSX — Max 5 MB', 'table-upload-manager' ),
                'uploading'         => __( 'Uploading…', 'table-upload-manager' ),
                'saving'            => __( 'Saving…', 'table-upload-manager' ),
                'saved'             => __( 'Saved!', 'table-upload-manager' ),
                'confirmDelete'     => __( 'Delete this table? This cannot be undone.', 'table-upload-manager' ),
                'confirmRevoke'     => __( 'Remove edit access for this user?', 'table-upload-manager' ),
                'noData'            => __( 'No data has been uploaded yet.', 'table-upload-manager' ),
                'loginRequired'     => __( 'You must be logged in to view tables.', 'table-upload-manager' ),
                'notApproved'       => __( 'Your account has not been approved to upload tables. Please contact an administrator.', 'table-upload-manager' ),
                'previewOf'         => __( 'Preview — first 100 rows shown', 'table-upload-manager' ),
                'rowCount'          => __( 'rows', 'table-upload-manager' ),
                'colCount'          => __( 'columns', 'table-upload-manager' ),
                'filterPlaceholder' => __( 'Filter…', 'table-upload-manager' ),
                'searchUser'        => __( 'Search by name or email…', 'table-upload-manager' ),
                'noResults'         => __( 'No matching records found.', 'table-upload-manager' ),
                'page'              => __( 'Page', 'table-upload-manager' ),
                'of'                => __( 'of', 'table-upload-manager' ),
                'prev'              => __( '‹ Prev', 'table-upload-manager' ),
                'next'              => __( 'Next ›', 'table-upload-manager' ),
                'error'             => __( 'An error occurred. Please try again.', 'table-upload-manager' ),
                'fileTooLarge'      => __( 'File is too large. Maximum size is 5 MB.', 'table-upload-manager' ),
                'invalidType'       => __( 'Invalid file type. Please use CSV, TSV, or XLSX.', 'table-upload-manager' ),
            ],
            'colorSchemes' => [
                'ocean-blue'    => [ 'label' => __( 'Ocean Blue',    'table-upload-manager' ), 'headerBg' => '#1e3a5f', 'headerText' => '#ffffff', 'rowBg' => '#ffffff', 'altRowBg' => '#f0f4f8', 'borderColor' => '#c7d7e8', 'hoverBg' => '#e0eaf5', 'accentColor' => '#2563eb' ],
                'forest-green'  => [ 'label' => __( 'Forest Green',  'table-upload-manager' ), 'headerBg' => '#14532d', 'headerText' => '#ffffff', 'rowBg' => '#ffffff', 'altRowBg' => '#f0fdf4', 'borderColor' => '#bbf7d0', 'hoverBg' => '#d1fae5', 'accentColor' => '#16a34a' ],
                'royal-purple'  => [ 'label' => __( 'Royal Purple',  'table-upload-manager' ), 'headerBg' => '#4c1d95', 'headerText' => '#ffffff', 'rowBg' => '#ffffff', 'altRowBg' => '#faf5ff', 'borderColor' => '#ddd6fe', 'hoverBg' => '#ede9fe', 'accentColor' => '#7c3aed' ],
                'sunset-orange' => [ 'label' => __( 'Sunset Orange', 'table-upload-manager' ), 'headerBg' => '#7c2d12', 'headerText' => '#ffffff', 'rowBg' => '#ffffff', 'altRowBg' => '#fff7ed', 'borderColor' => '#fed7aa', 'hoverBg' => '#ffedd5', 'accentColor' => '#ea580c' ],
                'midnight-dark' => [ 'label' => __( 'Midnight Dark', 'table-upload-manager' ), 'headerBg' => '#111827', 'headerText' => '#f9fafb', 'rowBg' => '#1f2937', 'altRowBg' => '#111827', 'borderColor' => '#374151', 'hoverBg' => '#374151', 'accentColor' => '#60a5fa' ],
                'clean-white'   => [ 'label' => __( 'Clean White',   'table-upload-manager' ), 'headerBg' => '#f8fafc', 'headerText' => '#1e293b', 'rowBg' => '#ffffff', 'altRowBg' => '#f8fafc', 'borderColor' => '#e2e8f0', 'hoverBg' => '#f1f5f9', 'accentColor' => '#0f172a' ],
            ],
        ] );
    }

    public function render_shortcode( array $atts = [] ): string {
        if ( ! is_user_logged_in() ) {
            return '<div class="tum-app tum-not-logged-in"><p>' . esc_html__( 'Please log in to view the table manager.', 'table-upload-manager' ) . '</p></div>';
        }

        ob_start();
        require TUM_PLUGIN_DIR . 'public/views/main.php';
        return ob_get_clean();
    }
}
