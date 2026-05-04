<?php
/**
 * Parses uploaded CSV, TSV, and XLSX files into a uniform array structure.
 * Files are consumed immediately – never stored on disk after parsing.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class TUM_File_Parser {

    /**
     * Main entry point.
     *
     * @param  string $tmp_path  Path to the uploaded temp file.
     * @param  string $ext       'csv' | 'tsv' | 'xlsx'
     * @return array|WP_Error    [ 'headers' => [], 'rows' => [] ]
     */
    public static function parse( string $tmp_path, string $ext ) {
        switch ( $ext ) {
            case 'csv':
                return self::parse_dsv( $tmp_path, ',' );
            case 'tsv':
                return self::parse_dsv( $tmp_path, "\t" );
            case 'xlsx':
                return self::parse_xlsx( $tmp_path );
            default:
                return new WP_Error( 'unsupported', __( 'Unsupported file type.', 'table-upload-manager' ) );
        }
    }

    // ── DSV (CSV / TSV) ───────────────────────────────────────────────────────
    private static function parse_dsv( string $path, string $delimiter ) {
        $handle = fopen( $path, 'r' );
        if ( ! $handle ) {
            return new WP_Error( 'read_error', __( 'Could not read file.', 'table-upload-manager' ) );
        }

        // Detect and strip BOM
        $bom = fread( $handle, 3 );
        if ( $bom !== "\xEF\xBB\xBF" ) {
            rewind( $handle );
        }

        $all_rows = [];
        $row_count = 0;
        while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
            $all_rows[] = $row;
            $row_count++;
            if ( $row_count > TUM_MAX_ROWS + 1 ) {
                fclose( $handle );
                return new WP_Error( 'too_many_rows',
                    sprintf( __( 'File exceeds the %d row limit.', 'table-upload-manager' ), TUM_MAX_ROWS )
                );
            }
        }
        fclose( $handle );

        if ( empty( $all_rows ) ) {
            return new WP_Error( 'empty_file', __( 'The file appears to be empty.', 'table-upload-manager' ) );
        }

        $headers = array_map( 'strval', array_shift( $all_rows ) );

        // Normalize: ensure every row has the same column count as headers
        $col_count = count( $headers );
        $rows = [];
        foreach ( $all_rows as $row ) {
            // Pad or trim
            while ( count( $row ) < $col_count ) $row[] = '';
            $rows[] = array_slice( $row, 0, $col_count );
        }

        return [ 'headers' => $headers, 'rows' => $rows ];
    }

    // ── XLSX (Office Open XML) ────────────────────────────────────────────────
    private static function parse_xlsx( string $path ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'no_zip', __( 'ZipArchive extension is not available.', 'table-upload-manager' ) );
        }

        $zip = new ZipArchive();
        if ( $zip->open( $path ) !== true ) {
            return new WP_Error( 'bad_zip', __( 'Could not open XLSX file.', 'table-upload-manager' ) );
        }

        // Read shared strings
        $shared_strings = [];
        $ss_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
        if ( $ss_xml ) {
            $shared_strings = self::parse_shared_strings( $ss_xml );
        }

        // Read first worksheet
        $sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
        $zip->close();

        if ( ! $sheet_xml ) {
            return new WP_Error( 'no_sheet', __( 'No worksheet found in XLSX file.', 'table-upload-manager' ) );
        }

        $all_rows = self::parse_sheet( $sheet_xml, $shared_strings );
        if ( is_wp_error( $all_rows ) ) return $all_rows;

        if ( count( $all_rows ) > TUM_MAX_ROWS + 1 ) {
            return new WP_Error( 'too_many_rows',
                sprintf( __( 'File exceeds the %d row limit.', 'table-upload-manager' ), TUM_MAX_ROWS )
            );
        }

        if ( empty( $all_rows ) ) {
            return new WP_Error( 'empty_file', __( 'The worksheet appears to be empty.', 'table-upload-manager' ) );
        }

        $headers = array_map( 'strval', array_shift( $all_rows ) );
        $col_count = count( $headers );

        $rows = [];
        foreach ( $all_rows as $row ) {
            while ( count( $row ) < $col_count ) $row[] = '';
            $rows[] = array_slice( $row, 0, $col_count );
        }

        return [ 'headers' => $headers, 'rows' => $rows ];
    }

    private static function parse_shared_strings( string $xml ): array {
        $strings = [];
        $doc = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING );
        if ( ! $doc ) return $strings;

        foreach ( $doc->si as $si ) {
            if ( isset( $si->t ) ) {
                $strings[] = (string) $si->t;
            } elseif ( isset( $si->r ) ) {
                $text = '';
                foreach ( $si->r as $r ) {
                    $text .= (string) $r->t;
                }
                $strings[] = $text;
            } else {
                $strings[] = '';
            }
        }

        return $strings;
    }

    private static function parse_sheet( string $xml, array $shared_strings ) {
        $doc = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING );
        if ( ! $doc ) {
            return new WP_Error( 'xml_error', __( 'Could not parse worksheet XML.', 'table-upload-manager' ) );
        }

        $rows       = [];
        $max_col    = 0;

        foreach ( $doc->sheetData->row as $row_el ) {
            $row_idx  = (int) $row_el['r'] - 1;
            $row_data = [];

            foreach ( $row_el->c as $cell ) {
                $col_idx = self::col_ref_to_index( (string) $cell['r'] );
                $type    = (string) $cell['t'];
                $value   = isset( $cell->v ) ? (string) $cell->v : '';

                if ( $type === 's' ) {
                    $value = $shared_strings[ (int) $value ] ?? '';
                } elseif ( $type === 'b' ) {
                    $value = $value ? 'TRUE' : 'FALSE';
                } elseif ( $type === 'str' || $type === 'inlineStr' ) {
                    $value = isset( $cell->is->t ) ? (string) $cell->is->t : $value;
                }

                $row_data[ $col_idx ] = $value;
                if ( $col_idx > $max_col ) $max_col = $col_idx;
            }

            $rows[ $row_idx ] = $row_data;
        }

        ksort( $rows );

        // Normalise to sequential indexed arrays
        $result = [];
        foreach ( $rows as $row_data ) {
            $filled = [];
            for ( $i = 0; $i <= $max_col; $i++ ) {
                $filled[] = $row_data[ $i ] ?? '';
            }
            $result[] = $filled;
        }

        return $result;
    }

    /**
     * Convert a cell reference like "AB12" to a zero-based column index.
     */
    private static function col_ref_to_index( string $cell_ref ): int {
        preg_match( '/^([A-Z]+)/i', strtoupper( $cell_ref ), $m );
        if ( empty( $m[1] ) ) return 0;
        $letters = $m[1];
        $index   = 0;
        for ( $i = 0, $len = strlen( $letters ); $i < $len; $i++ ) {
            $index = $index * 26 + ( ord( $letters[ $i ] ) - ord( 'A' ) + 1 );
        }
        return $index - 1;
    }
}
