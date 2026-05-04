<?php
/**
 * Handles all direct database operations using prepared statements.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class TUM_Database {

    // ── Table name helpers ────────────────────────────────────────────────────
    public static function tables_table()       { global $wpdb; return $wpdb->prefix . 'tum_tables'; }
    public static function data_table()         { global $wpdb; return $wpdb->prefix . 'tum_table_data'; }
    public static function formatting_table()   { global $wpdb; return $wpdb->prefix . 'tum_formatting'; }
    public static function permissions_table()  { global $wpdb; return $wpdb->prefix . 'tum_permissions'; }
    public static function approved_table()     { global $wpdb; return $wpdb->prefix . 'tum_approved_users'; }

    // ── Schema creation ───────────────────────────────────────────────────────
    public static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = [];

        $sql[] = "CREATE TABLE " . self::approved_table() . " (
            id          bigint(20)   NOT NULL AUTO_INCREMENT,
            user_id     bigint(20)   NOT NULL,
            approved_by bigint(20)   NOT NULL,
            approved_at datetime     NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::tables_table() . " (
            id          bigint(20)    NOT NULL AUTO_INCREMENT,
            title       varchar(255)  NOT NULL,
            description text,
            owner_id    bigint(20)    NOT NULL,
            status      varchar(20)   NOT NULL DEFAULT 'active',
            sort_order  int(11)       NOT NULL DEFAULT 0,
            created_at  datetime      NOT NULL,
            updated_at  datetime      NOT NULL,
            PRIMARY KEY (id),
            KEY owner_id (owner_id),
            KEY status   (status)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::data_table() . " (
            id           bigint(20) NOT NULL AUTO_INCREMENT,
            table_id     bigint(20) NOT NULL,
            headers      longtext   NOT NULL,
            rows         longtext   NOT NULL,
            row_count    int(11)    NOT NULL DEFAULT 0,
            column_count int(11)    NOT NULL DEFAULT 0,
            uploaded_at  datetime   NOT NULL,
            uploaded_by  bigint(20) NOT NULL,
            PRIMARY KEY (id),
            KEY table_id (table_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::formatting_table() . " (
            id             bigint(20) NOT NULL AUTO_INCREMENT,
            table_id       bigint(20) NOT NULL,
            formatting_json longtext  NOT NULL,
            updated_at     datetime   NOT NULL,
            updated_by     bigint(20) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY table_id (table_id)
        ) $charset;";

        $sql[] = "CREATE TABLE " . self::permissions_table() . " (
            id         bigint(20) NOT NULL AUTO_INCREMENT,
            table_id   bigint(20) NOT NULL,
            user_id    bigint(20) NOT NULL,
            granted_by bigint(20) NOT NULL,
            granted_at datetime   NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY table_user (table_id, user_id)
        ) $charset;";

        foreach ( $sql as $query ) {
            dbDelta( $query );
        }
    }

    // ── Drop all plugin tables (called from uninstall.php) ────────────────────
    public static function drop_tables() {
        global $wpdb;
        $tables = [
            self::permissions_table(),
            self::formatting_table(),
            self::data_table(),
            self::tables_table(),
            self::approved_table(),
        ];
        foreach ( $tables as $table ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
        }
    }

    // ── Approved-user operations ──────────────────────────────────────────────
    public static function approve_user( int $user_id, int $approver_id ): bool {
        global $wpdb;
        $result = $wpdb->replace(
            self::approved_table(),
            [
                'user_id'     => $user_id,
                'approved_by' => $approver_id,
                'approved_at' => current_time( 'mysql', 1 ),
            ],
            [ '%d', '%d', '%s' ]
        );
        return $result !== false;
    }

    public static function unapprove_user( int $user_id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete( self::approved_table(), [ 'user_id' => $user_id ], [ '%d' ] );
    }

    public static function is_user_approved( int $user_id ): bool {
        global $wpdb;
        $count = $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM " . self::approved_table() . " WHERE user_id = %d", $user_id )
        );
        return (int) $count > 0;
    }

    public static function get_all_approved_users(): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            "SELECT * FROM " . self::approved_table() . " ORDER BY approved_at DESC"
        );
    }

    // ── Table CRUD ────────────────────────────────────────────────────────────
    public static function create_table( array $data ): ?int {
        global $wpdb;
        $result = $wpdb->insert(
            self::tables_table(),
            [
                'title'       => sanitize_text_field( $data['title'] ),
                'description' => sanitize_textarea_field( $data['description'] ?? '' ),
                'owner_id'    => (int) $data['owner_id'],
                'status'      => 'active',
                'sort_order'  => 0,
                'created_at'  => current_time( 'mysql', 1 ),
                'updated_at'  => current_time( 'mysql', 1 ),
            ],
            [ '%s', '%s', '%d', '%s', '%d', '%s', '%s' ]
        );
        return $result ? (int) $wpdb->insert_id : null;
    }

    public static function update_table_meta( int $table_id, array $data ): bool {
        global $wpdb;
        $fields = [ 'updated_at' => current_time( 'mysql', 1 ) ];
        $formats = [ '%s' ];
        if ( isset( $data['title'] ) ) {
            $fields['title']  = sanitize_text_field( $data['title'] );
            $formats[]        = '%s';
        }
        if ( isset( $data['description'] ) ) {
            $fields['description'] = sanitize_textarea_field( $data['description'] );
            $formats[]             = '%s';
        }
        return (bool) $wpdb->update( self::tables_table(), $fields, [ 'id' => $table_id ], $formats, [ '%d' ] );
    }

    public static function delete_table( int $table_id ): bool {
        global $wpdb;
        $wpdb->delete( self::permissions_table(), [ 'table_id' => $table_id ], [ '%d' ] );
        $wpdb->delete( self::formatting_table(),  [ 'table_id' => $table_id ], [ '%d' ] );
        $wpdb->delete( self::data_table(),        [ 'table_id' => $table_id ], [ '%d' ] );
        return (bool) $wpdb->delete( self::tables_table(), [ 'id' => $table_id ], [ '%d' ] );
    }

    public static function get_table( int $table_id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::tables_table() . " WHERE id = %d AND status = 'active'", $table_id )
        );
    }

    public static function get_all_tables(): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            "SELECT * FROM " . self::tables_table() . " WHERE status = 'active' ORDER BY sort_order ASC, created_at ASC"
        );
    }

    public static function get_tables_by_owner( int $owner_id ): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM " . self::tables_table() . " WHERE owner_id = %d AND status = 'active' ORDER BY sort_order ASC",
                $owner_id
            )
        );
    }

    // ── Table Data ────────────────────────────────────────────────────────────
    public static function save_table_data( int $table_id, array $headers, array $rows, int $uploader_id ): bool {
        global $wpdb;
        // Remove existing data record
        $wpdb->delete( self::data_table(), [ 'table_id' => $table_id ], [ '%d' ] );

        $result = $wpdb->insert(
            self::data_table(),
            [
                'table_id'     => $table_id,
                'headers'      => wp_json_encode( $headers ),
                'rows'         => wp_json_encode( $rows ),
                'row_count'    => count( $rows ),
                'column_count' => count( $headers ),
                'uploaded_at'  => current_time( 'mysql', 1 ),
                'uploaded_by'  => $uploader_id,
            ],
            [ '%d', '%s', '%s', '%d', '%d', '%s', '%d' ]
        );

        // Touch parent table
        $wpdb->update( self::tables_table(), [ 'updated_at' => current_time( 'mysql', 1 ) ], [ 'id' => $table_id ], [ '%s' ], [ '%d' ] );

        return $result !== false;
    }

    public static function get_table_data( int $table_id ): ?object {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM " . self::data_table() . " WHERE table_id = %d", $table_id )
        );
    }

    // ── Formatting ────────────────────────────────────────────────────────────
    public static function save_formatting( int $table_id, array $formatting, int $user_id ): bool {
        global $wpdb;
        $existing = $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM " . self::formatting_table() . " WHERE table_id = %d", $table_id )
        );

        if ( $existing ) {
            return (bool) $wpdb->update(
                self::formatting_table(),
                [
                    'formatting_json' => wp_json_encode( $formatting ),
                    'updated_at'      => current_time( 'mysql', 1 ),
                    'updated_by'      => $user_id,
                ],
                [ 'table_id' => $table_id ],
                [ '%s', '%s', '%d' ],
                [ '%d' ]
            );
        }

        return (bool) $wpdb->insert(
            self::formatting_table(),
            [
                'table_id'        => $table_id,
                'formatting_json' => wp_json_encode( $formatting ),
                'updated_at'      => current_time( 'mysql', 1 ),
                'updated_by'      => $user_id,
            ],
            [ '%d', '%s', '%s', '%d' ]
        );
    }

    public static function get_formatting( int $table_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT formatting_json FROM " . self::formatting_table() . " WHERE table_id = %d", $table_id )
        );
        if ( ! $row ) return null;
        $decoded = json_decode( $row->formatting_json, true );
        return is_array( $decoded ) ? $decoded : null;
    }

    // ── Table-level Permissions ───────────────────────────────────────────────
    public static function grant_permission( int $table_id, int $user_id, int $granter_id ): bool {
        global $wpdb;
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM " . self::permissions_table() . " WHERE table_id = %d AND user_id = %d",
                $table_id, $user_id
            )
        );
        if ( $existing ) return true;

        return (bool) $wpdb->insert(
            self::permissions_table(),
            [
                'table_id'   => $table_id,
                'user_id'    => $user_id,
                'granted_by' => $granter_id,
                'granted_at' => current_time( 'mysql', 1 ),
            ],
            [ '%d', '%d', '%d', '%s' ]
        );
    }

    public static function revoke_permission( int $table_id, int $user_id ): bool {
        global $wpdb;
        return (bool) $wpdb->delete(
            self::permissions_table(),
            [ 'table_id' => $table_id, 'user_id' => $user_id ],
            [ '%d', '%d' ]
        );
    }

    public static function get_table_permissions( int $table_id ): array {
        global $wpdb;
        return (array) $wpdb->get_results(
            $wpdb->prepare( "SELECT * FROM " . self::permissions_table() . " WHERE table_id = %d", $table_id )
        );
    }

    public static function has_table_permission( int $table_id, int $user_id ): bool {
        global $wpdb;
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM " . self::permissions_table() . " WHERE table_id = %d AND user_id = %d",
                $table_id, $user_id
            )
        );
        return (int) $count > 0;
    }

    // ── Admin stats ───────────────────────────────────────────────────────────
    public static function get_stats(): array {
        global $wpdb;
        return [
            'total_tables'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::tables_table() . " WHERE status = 'active'" ),
            'approved_users'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . self::approved_table() ),
            'total_rows'      => (int) $wpdb->get_var( "SELECT SUM(row_count) FROM " . self::data_table() ),
        ];
    }
}
