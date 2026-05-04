<?php
/**
 * Registers and handles all wp_ajax_* endpoints.
 * Every action verifies nonce + login + appropriate permissions.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class TUM_Ajax_Handler {

    private static ?self $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $public_actions = [
            'tum_get_tables',
            'tum_get_table',
            'tum_create_table',
            'tum_upload_file',
            'tum_save_formatting',
            'tum_delete_table',
            'tum_grant_permission',
            'tum_revoke_permission',
            'tum_get_permissions',
            'tum_search_users',
            // Admin-only
            'tum_admin_approve_user',
            'tum_admin_unapprove_user',
            'tum_admin_get_users',
            'tum_admin_delete_table',
        ];

        foreach ( $public_actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ $this, 'dispatch' ] );
        }
    }

    // ── Dispatcher ────────────────────────────────────────────────────────────
    public function dispatch(): void {
        $action = sanitize_key( $_REQUEST['action'] ?? '' );
        $method = str_replace( 'tum_', 'handle_', $action );

        if ( method_exists( $this, $method ) ) {
            $this->$method();
        } else {
            wp_send_json_error( [ 'message' => 'Unknown action.' ], 400 );
        }
    }

    // ── Helper ────────────────────────────────────────────────────────────────
    private function nonce(): string {
        return sanitize_text_field( wp_unslash( $_REQUEST['nonce'] ?? '' ) );
    }

    private function post_int( string $key ): int {
        return (int) ( $_POST[ $key ] ?? 0 );
    }

    private function post_str( string $key ): string {
        return sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  Public / Frontend handlers
    // ═══════════════════════════════════════════════════════════════════════════

    /** GET all table metadata (lightweight, for tab nav). */
    protected function handle_get_tables(): void {
        TUM_Security::verify_nonce( $this->nonce() );
        TUM_Security::require_logged_in();

        wp_send_json_success( TUM_Table_Manager::get_all_table_meta() );
    }

    /** GET full payload for one table (includes row data). */
    protected function handle_get_table(): void {
        TUM_Security::verify_nonce( $this->nonce() );
        TUM_Security::require_logged_in();

        $table_id = $this->post_int( 'table_id' );
        $payload  = TUM_Table_Manager::get_frontend_payload( $table_id );

        if ( ! $payload ) {
            wp_send_json_error( [ 'message' => __( 'Table not found.', 'table-upload-manager' ) ], 404 );
        }

        wp_send_json_success( $payload );
    }

    /** POST create a new table (approved users only). */
    protected function handle_create_table(): void {
        TUM_Security::verify_nonce( $this->nonce() );
        TUM_Security::require_logged_in();
        TUM_Security::require_approved();

        $title       = $this->post_str( 'title' );
        $description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );

        if ( empty( $title ) ) {
            wp_send_json_error( [ 'message' => __( 'Title is required.', 'table-upload-manager' ) ], 422 );
        }

        $result = TUM_Table_Manager::create( $title, $description );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'table_id' => $result, 'message' => __( 'Table created.', 'table-upload-manager' ) ] );
    }

    /** POST upload & parse a file, then store the data. */
    protected function handle_upload_file(): void {
        TUM_Security::verify_nonce( $this->nonce() );
        TUM_Security::require_logged_in();

        $table_id = $this->post_int( 'table_id' );
        TUM_Security::require_table_edit_permission( $table_id );

        if ( empty( $_FILES['tum_file'] ) ) {
            wp_send_json_error( [ 'message' => __( 'No file received.', 'table-upload-manager' ) ], 400 );
        }

        $result = TUM_Table_Manager::upload_data( $table_id, $_FILES['tum_file'] );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        // Return headers + first N rows for preview (don't send all rows in response)
        $preview_rows = array_slice( $result['rows'], 0, 100 );
        wp_send_json_success( [
            'headers'      => $result['headers'],
            'preview_rows' => $preview_rows,
            'row_count'    => $result['row_count'],
            'column_count' => $result['column_count'],
            'message'      => sprintf(
                __( 'Uploaded %d rows and %d columns.', 'table-upload-manager' ),
                $result['row_count'],
                $result['column_count']
            ),
        ] );
    }

    /** POST save formatting JSON for a table. */
    protected function handle_save_formatting(): void {
        TUM_Security::verify_nonce( $this->nonce() );
        TUM_Security::require_logged_in();

        $table_id    = $this->post_int( 'table_id' );
        TUM_Security::require_table_edit_permission( $table_id );

        $raw_json = wp_unslash( $_POST['formatting'] ?? '' );
        $raw      = TUM_Security::decode_json_input( $raw_json );

        if ( null === $raw ) {
            wp_send_json_error( [ 'message' => __( 'Invalid formatting data.', 'table-upload-manager' ) ], 422 );
        }

        $clean = TUM_Security::sanitize_formatting( $raw );
        $saved = TUM_Database::save_formatting( $table_id, $clean, get_current_user_id() );

        if ( ! $saved ) {
            wp_send_json_error( [ 'message' => __( 'Could not save formatting.', 'table-upload-manager' ) ] );
        }

        wp_send_json_success( [ 'formatting' => $clean, 'message' => __( 'Formatting saved.', 'table-upload-manager' ) ] );
    }

    /** POST delete a table (owner or admin only). */
    protected function handle_delete_table(): void {
        TUM_Security::verify_nonce( $this->nonce() );
        TUM_Security::require_logged_in();

        $table_id = $this->post_int( 'table_id' );
        TUM_Security::require_table_owner( $table_id );

        $deleted = TUM_Database::delete_table( $table_id );
        if ( ! $deleted ) {
            wp_send_json_error( [ 'message' => __( 'Could not delete table.', 'table-upload-manager' ) ] );
        }

        wp_send_json_success( [ 'message' => __( 'Table deleted.', 'table-upload-manager' ) ] );
    }

    /** POST grant edit permission on a table to another user (owner only). */
    protected function handle_grant_permission(): void {
        TUM_Security::verify_nonce( $this->nonce() );
        TUM_Security::require_logged_in();

        $table_id  = $this->post_int( 'table_id' );
        $target_id = $this->post_int( 'user_id' );

        TUM_Security::require_table_owner( $table_id );

        // Target must be a real WP user
        if ( ! get_userdata( $target_id ) ) {
            wp_send_json_error( [ 'message' => __( 'User not found.', 'table-upload-manager' ) ], 404 );
        }

        // Prevent granting to self (owner already has access)
        if ( $target_id === get_current_user_id() ) {
            wp_send_json_error( [ 'message' => __( 'You already own this table.', 'table-upload-manager' ) ], 422 );
        }

        $result = TUM_Database::grant_permission( $table_id, $target_id, get_current_user_id() );
        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( 'Could not grant permission.', 'table-upload-manager' ) ] );
        }

        wp_send_json_success( [
            'user'    => TUM_User_Manager::get_user_display( $target_id ),
            'message' => __( 'Permission granted.', 'table-upload-manager' ),
        ] );
    }

    /** POST revoke edit permission (owner only). */
    protected function handle_revoke_permission(): void {
        TUM_Security::verify_nonce( $this->nonce() );
        TUM_Security::require_logged_in();

        $table_id  = $this->post_int( 'table_id' );
        $target_id = $this->post_int( 'user_id' );

        TUM_Security::require_table_owner( $table_id );

        $result = TUM_Database::revoke_permission( $table_id, $target_id );
        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( 'Could not revoke permission.', 'table-upload-manager' ) ] );
        }

        wp_send_json_success( [ 'message' => __( 'Permission revoked.', 'table-upload-manager' ) ] );
    }

    /** GET permission list for a table (owner only). */
    protected function handle_get_permissions(): void {
        TUM_Security::verify_nonce( $this->nonce() );
        TUM_Security::require_logged_in();

        $table_id = $this->post_int( 'table_id' );
        TUM_Security::require_table_owner( $table_id );

        $perms = TUM_Database::get_table_permissions( $table_id );
        $data  = array_map( function ( $p ) {
            return TUM_User_Manager::get_user_display( (int) $p->user_id );
        }, $perms );

        wp_send_json_success( $data );
    }

    /** GET search users for permission picker. */
    protected function handle_search_users(): void {
        TUM_Security::verify_nonce( $this->nonce() );
        TUM_Security::require_logged_in();

        $term  = sanitize_text_field( wp_unslash( $_GET['term'] ?? $_POST['term'] ?? '' ) );
        $users = TUM_User_Manager::search_approvable_users( $term );

        wp_send_json_success( $users );
    }

    // ═══════════════════════════════════════════════════════════════════════════
    //  Admin-only handlers
    // ═══════════════════════════════════════════════════════════════════════════

    protected function handle_admin_approve_user(): void {
        TUM_Security::verify_nonce( $this->nonce() );
        TUM_Security::require_logged_in();
        TUM_Security::require_admin();

        $user_id = $this->post_int( 'user_id' );
        $result  = TUM_User_Manager::approve( $user_id );

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( 'Could not approve user.', 'table-upload-manager' ) ] );
        }

        wp_send_json_success( [
            'user'    => TUM_User_Manager::get_user_display( $user_id ),
            'message' => __( 'User approved.', 'table-upload-manager' ),
        ] );
    }

    protected function handle_admin_unapprove_user(): void {
        TUM_Security::verify_nonce( $this->nonce() );
        TUM_Security::require_logged_in();
        TUM_Security::require_admin();

        $user_id = $this->post_int( 'user_id' );
        $result  = TUM_User_Manager::unapprove( $user_id );

        if ( ! $result ) {
            wp_send_json_error( [ 'message' => __( 'Could not unapprove user.', 'table-upload-manager' ) ] );
        }

        wp_send_json_success( [ 'message' => __( 'User unapproved.', 'table-upload-manager' ) ] );
    }

    protected function handle_admin_get_users(): void {
        TUM_Security::verify_nonce( $this->nonce() );
        TUM_Security::require_logged_in();
        TUM_Security::require_admin();

        wp_send_json_success( TUM_User_Manager::get_all_users_with_status() );
    }

    protected function handle_admin_delete_table(): void {
        TUM_Security::verify_nonce( $this->nonce() );
        TUM_Security::require_logged_in();
        TUM_Security::require_admin();

        $table_id = $this->post_int( 'table_id' );
        $deleted  = TUM_Database::delete_table( $table_id );

        if ( ! $deleted ) {
            wp_send_json_error( [ 'message' => __( 'Could not delete table.', 'table-upload-manager' ) ] );
        }

        wp_send_json_success( [ 'message' => __( 'Table deleted.', 'table-upload-manager' ) ] );
    }
}
