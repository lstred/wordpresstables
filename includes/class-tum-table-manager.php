<?php
/**
 * High-level table business logic (combines DB + security + parsing).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class TUM_Table_Manager {

    /**
     * Default formatting applied to every new table.
     */
    public static function default_formatting(): array {
        return [
            'colorScheme'      => 'ocean-blue',
            'colors'           => [
                'headerBg'    => '#1e3a5f',
                'headerText'  => '#ffffff',
                'rowBg'       => '#ffffff',
                'altRowBg'    => '#f0f4f8',
                'borderColor' => '#dee2e6',
                'hoverBg'     => '#e8f0fe',
                'accentColor' => '#1e3a5f',
            ],
            'typography'       => [
                'fontSize'         => 14,
                'headerFontWeight' => '600',
                'textAlign'        => 'left',
            ],
            'layout'           => [
                'stripedRows'  => true,
                'hoverEffect'  => true,
                'borderStyle'  => 'full',
                'compactMode'  => false,
                'frozenHeader' => true,
                'pagination'   => true,
                'rowsPerPage'  => 25,
            ],
            'columnVisibility' => [],
            'filters'          => [
                'enabled' => false,
                'columns' => [],
            ],
            'sortable'         => [],
        ];
    }

    /**
     * Create a new table record (without data).
     * Returns the new table ID or WP_Error.
     */
    public static function create( string $title, string $description = '' ) {
        if ( ! TUM_Security::current_user_is_approved() ) {
            return new WP_Error( 'not_approved', __( 'You are not approved to create tables.', 'table-upload-manager' ) );
        }

        $table_id = TUM_Database::create_table( [
            'title'       => $title,
            'description' => $description,
            'owner_id'    => get_current_user_id(),
        ] );

        if ( ! $table_id ) {
            return new WP_Error( 'db_error', __( 'Could not create table.', 'table-upload-manager' ) );
        }

        // Seed with default formatting
        TUM_Database::save_formatting( $table_id, self::default_formatting(), get_current_user_id() );

        return $table_id;
    }

    /**
     * Upload, parse, and store file data for a given table.
     * Preserves any existing formatting.
     */
    public static function upload_data( int $table_id, array $file ) {
        $validation = TUM_Security::validate_upload( $file );
        if ( is_wp_error( $validation ) ) return $validation;

        $ext    = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        $parsed = TUM_File_Parser::parse( $file['tmp_name'], $ext );
        if ( is_wp_error( $parsed ) ) return $parsed;

        $headers = TUM_Security::sanitize_headers( $parsed['headers'] );
        $rows    = TUM_Security::sanitize_rows( $parsed['rows'] );

        $saved = TUM_Database::save_table_data( $table_id, $headers, $rows, get_current_user_id() );
        if ( ! $saved ) {
            return new WP_Error(
                'db_error',
                __( 'Upload failed — the data could not be saved to the database. Please try again. If the issue persists, ask your administrator to check the server error log.', 'table-upload-manager' )
            );
        }

        return [
            'headers'      => $headers,
            'rows'         => $rows,
            'row_count'    => count( $rows ),
            'column_count' => count( $headers ),
        ];
    }

    /**
     * Get complete table payload for the frontend (meta + data + formatting + permissions).
     */
    public static function get_frontend_payload( int $table_id ): ?array {
        $table = TUM_Database::get_table( $table_id );
        if ( ! $table ) return null;

        $data_row   = TUM_Database::get_table_data( $table_id );
        $formatting = TUM_Database::get_formatting( $table_id ) ?? self::default_formatting();
        $uid        = get_current_user_id();

        $can_edit   = TUM_Security::current_user_can_edit_table( $table_id );
        $is_owner   = TUM_Security::current_user_is_table_owner( $table_id );

        // Build permission list for table owners
        $permissions = [];
        if ( $is_owner ) {
            foreach ( TUM_Database::get_table_permissions( $table_id ) as $perm ) {
                $permissions[] = TUM_User_Manager::get_user_display( (int) $perm->user_id );
            }
        }

        $owner_info = TUM_User_Manager::get_user_display( (int) $table->owner_id );

        $payload = [
            'id'          => (int) $table->id,
            'title'       => esc_html( $table->title ),
            'description' => esc_html( $table->description ),
            'owner'       => $owner_info,
            'created_at'  => $table->created_at,
            'updated_at'  => $table->updated_at,
            'can_edit'    => $can_edit,
            'is_owner'    => $is_owner,
            'permissions' => $permissions,
            'formatting'  => $formatting,
            'has_data'    => (bool) $data_row,
        ];

        if ( $data_row ) {
            $payload['headers']      = json_decode( $data_row->headers, true ) ?? [];
            $payload['rows']         = json_decode( $data_row->rows,    true ) ?? [];
            $payload['row_count']    = (int) $data_row->row_count;
            $payload['column_count'] = (int) $data_row->column_count;
            $payload['uploaded_at']  = $data_row->uploaded_at;
        }

        return $payload;
    }

    /**
     * Returns lightweight metadata for all active tables (no row data).
     */
    public static function get_all_table_meta(): array {
        $tables = TUM_Database::get_all_tables();
        $result = [];
        foreach ( $tables as $t ) {
            $data_row = TUM_Database::get_table_data( (int) $t->id );
            $result[] = [
                'id'          => (int) $t->id,
                'title'       => esc_html( $t->title ),
                'description' => esc_html( $t->description ?? '' ),
                'owner_id'    => (int) $t->owner_id,
                'updated_at'  => $t->updated_at,
                'has_data'    => (bool) $data_row,
                'row_count'   => $data_row ? (int) $data_row->row_count : 0,
                'can_edit'    => TUM_Security::current_user_can_edit_table( (int) $t->id ),
                'is_owner'    => TUM_Security::current_user_is_table_owner( (int) $t->id ),
            ];
        }
        return $result;
    }
}
