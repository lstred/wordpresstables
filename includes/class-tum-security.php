<?php
/**
 * Security utilities: nonce helpers, permission checks, input sanitization.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class TUM_Security {

    const NONCE_ACTION = 'tum_nonce';

    // ── Nonce helpers ─────────────────────────────────────────────────────────
    public static function create_nonce(): string {
        return wp_create_nonce( self::NONCE_ACTION );
    }

    /**
     * Verify nonce and bail with wp_die on failure.
     * Exits the current request – safe for AJAX handlers.
     */
    public static function verify_nonce( string $nonce ): void {
        if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
            wp_send_json_error( [ 'message' => __( 'Security check failed.', 'table-upload-manager' ) ], 403 );
        }
    }

    // ── Authentication/authorisation ──────────────────────────────────────────
    public static function require_logged_in(): void {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'You must be logged in.', 'table-upload-manager' ) ], 401 );
        }
    }

    public static function require_admin(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'table-upload-manager' ) ], 403 );
        }
    }

    /**
     * Returns true when the current user may upload / edit tables.
     */
    public static function current_user_is_approved(): bool {
        if ( ! is_user_logged_in() ) return false;
        if ( current_user_can( 'manage_options' ) ) return true;
        return TUM_Database::is_user_approved( get_current_user_id() );
    }

    public static function require_approved(): void {
        if ( ! self::current_user_is_approved() ) {
            wp_send_json_error( [ 'message' => __( 'You are not approved to perform this action.', 'table-upload-manager' ) ], 403 );
        }
    }

    /**
     * Checks whether the current user can edit a specific table
     * (owner, approved editor, or admin).
     */
    public static function current_user_can_edit_table( int $table_id ): bool {
        if ( ! is_user_logged_in() ) return false;
        $uid = get_current_user_id();
        if ( current_user_can( 'manage_options' ) ) return true;
        $table = TUM_Database::get_table( $table_id );
        if ( $table && (int) $table->owner_id === $uid ) return true;
        return TUM_Database::has_table_permission( $table_id, $uid );
    }

    public static function require_table_edit_permission( int $table_id ): void {
        if ( ! self::current_user_can_edit_table( $table_id ) ) {
            wp_send_json_error( [ 'message' => __( 'You do not have permission to edit this table.', 'table-upload-manager' ) ], 403 );
        }
    }

    /**
     * Only the table owner can manage permissions for a table.
     */
    public static function current_user_is_table_owner( int $table_id ): bool {
        if ( ! is_user_logged_in() ) return false;
        if ( current_user_can( 'manage_options' ) ) return true;
        $table = TUM_Database::get_table( $table_id );
        return $table && (int) $table->owner_id === get_current_user_id();
    }

    public static function require_table_owner( int $table_id ): void {
        if ( ! self::current_user_is_table_owner( $table_id ) ) {
            wp_send_json_error( [ 'message' => __( 'Only the table owner can manage permissions.', 'table-upload-manager' ) ], 403 );
        }
    }

    // ── Input sanitization helpers ────────────────────────────────────────────
    public static function sanitize_int( $value ): int {
        return (int) $value;
    }

    public static function sanitize_string( $value ): string {
        return sanitize_text_field( (string) $value );
    }

    /**
     * Sanitize an array of strings (table cell content).
     */
    public static function sanitize_cell_value( string $value ): string {
        // Strip tags, trim, limit length
        return mb_substr( wp_strip_all_tags( $value ), 0, 1000 );
    }

    /**
     * Recursively sanitize all string values in a 2-D array (table rows).
     */
    public static function sanitize_rows( array $rows ): array {
        $clean = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) continue;
            $clean_row = [];
            foreach ( $row as $cell ) {
                $clean_row[] = self::sanitize_cell_value( (string) $cell );
            }
            $clean[] = $clean_row;
        }
        return $clean;
    }

    /**
     * Sanitize header names.
     */
    public static function sanitize_headers( array $headers ): array {
        return array_map( function ( $h ) {
            return mb_substr( wp_strip_all_tags( (string) $h ), 0, 255 );
        }, $headers );
    }

    /**
     * Validate and decode a JSON string into an array.
     * Returns null on failure.
     */
    public static function decode_json_input( string $json ): ?array {
        $decoded = json_decode( wp_unslash( $json ), true );
        return ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) ? $decoded : null;
    }

    /**
     * Sanitize formatting configuration JSON.
     * Whitelists top-level keys and validates nested structure.
     */
    public static function sanitize_formatting( array $raw ): array {
        $allowed_color_schemes = [ 'ocean-blue', 'forest-green', 'royal-purple', 'sunset-orange', 'midnight-dark', 'clean-white', 'custom' ];
        $allowed_border_styles = [ 'full', 'horizontal', 'none' ];
        $allowed_filter_types  = [ 'none', 'text', 'select', 'number-range', 'date-range' ];

        $fmt = [];

        $fmt['colorScheme'] = in_array( $raw['colorScheme'] ?? '', $allowed_color_schemes, true )
            ? $raw['colorScheme']
            : 'ocean-blue';

        // Custom colors
        $fmt['colors'] = [];
        $color_keys = [ 'headerBg', 'headerText', 'rowBg', 'altRowBg', 'borderColor', 'hoverBg', 'accentColor' ];
        foreach ( $color_keys as $key ) {
            $color = preg_replace( '/[^#a-fA-F0-9]/', '', $raw['colors'][ $key ] ?? '' );
            $fmt['colors'][ $key ] = $color ?: '';
        }

        // Typography
        $fmt['typography'] = [
            'fontSize'         => max( 10, min( 24, (int) ( $raw['typography']['fontSize'] ?? 14 ) ) ),
            'headerFontWeight' => in_array( $raw['typography']['headerFontWeight'] ?? '', [ '400', '600', '700', 'bold' ], true )
                                    ? $raw['typography']['headerFontWeight'] : '600',
            'textAlign'        => in_array( $raw['typography']['textAlign'] ?? '', [ 'left', 'center', 'right' ], true )
                                    ? $raw['typography']['textAlign'] : 'left',
        ];

        // Layout
        $fmt['layout'] = [
            'stripedRows'  => ! empty( $raw['layout']['stripedRows'] ),
            'hoverEffect'  => ! empty( $raw['layout']['hoverEffect'] ),
            'borderStyle'  => in_array( $raw['layout']['borderStyle'] ?? '', $allowed_border_styles, true )
                                ? $raw['layout']['borderStyle'] : 'full',
            'compactMode'  => ! empty( $raw['layout']['compactMode'] ),
            'frozenHeader' => ! empty( $raw['layout']['frozenHeader'] ),
            'pagination'   => ! empty( $raw['layout']['pagination'] ),
            'rowsPerPage'  => in_array( (int) ( $raw['layout']['rowsPerPage'] ?? 25 ), [ 10, 25, 50, 100 ], true )
                                ? (int) $raw['layout']['rowsPerPage'] : 25,
        ];

        // Column visibility (array of bool, keyed by column index)
        $fmt['columnVisibility'] = [];
        if ( isset( $raw['columnVisibility'] ) && is_array( $raw['columnVisibility'] ) ) {
            foreach ( $raw['columnVisibility'] as $idx => $visible ) {
                $fmt['columnVisibility'][ (int) $idx ] = (bool) $visible;
            }
        }

        // Filters per column
        $fmt['filters'] = [ 'enabled' => ! empty( $raw['filters']['enabled'] ), 'columns' => [] ];
        if ( isset( $raw['filters']['columns'] ) && is_array( $raw['filters']['columns'] ) ) {
            foreach ( $raw['filters']['columns'] as $idx => $col_cfg ) {
                $type = in_array( $col_cfg['type'] ?? '', $allowed_filter_types, true ) ? $col_cfg['type'] : 'none';
                $fmt['filters']['columns'][ (int) $idx ] = [
                    'enabled'     => ! empty( $col_cfg['enabled'] ),
                    'type'        => $type,
                    'placeholder' => mb_substr( wp_strip_all_tags( $col_cfg['placeholder'] ?? '' ), 0, 100 ),
                ];
            }
        }

        // Sort enabled columns
        $fmt['sortable'] = [];
        if ( isset( $raw['sortable'] ) && is_array( $raw['sortable'] ) ) {
            foreach ( $raw['sortable'] as $idx ) {
                $fmt['sortable'][] = (int) $idx;
            }
        }

        return $fmt;
    }

    /**
     * Validate an uploaded file (MIME + extension + size).
     * Returns WP_Error on failure, true on success.
     */
    public static function validate_upload( array $file ) {
        $allowed_ext   = [ 'csv', 'tsv', 'xlsx' ];
        $allowed_mimes = [
            'text/csv',
            'text/plain',
            'text/tab-separated-values',
            'application/csv',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/octet-stream',
        ];

        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return new WP_Error( 'upload_error', __( 'File upload failed.', 'table-upload-manager' ) );
        }

        if ( $file['size'] > TUM_MAX_FILE_SIZE ) {
            return new WP_Error( 'file_too_large', __( 'File exceeds the 5 MB limit.', 'table-upload-manager' ) );
        }

        $ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
        if ( ! in_array( $ext, $allowed_ext, true ) ) {
            return new WP_Error( 'invalid_type', __( 'Only CSV, TSV, and XLSX files are accepted.', 'table-upload-manager' ) );
        }

        // Check real MIME via finfo when available
        if ( function_exists( 'finfo_open' ) ) {
            $finfo     = finfo_open( FILEINFO_MIME_TYPE );
            $real_mime = finfo_file( $finfo, $file['tmp_name'] );
            finfo_close( $finfo );
            if ( ! in_array( $real_mime, $allowed_mimes, true ) ) {
                return new WP_Error( 'invalid_mime', __( 'File MIME type is not allowed.', 'table-upload-manager' ) );
            }
        }

        return true;
    }
}
