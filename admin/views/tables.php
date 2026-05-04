<?php
/**
 * Admin: All Tables view.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$tables = TUM_Database::get_all_tables();
?>
<div id="tum-admin" class="wrap">
    <h1 class="tum-admin-heading">
        <span class="dashicons dashicons-editor-table"></span>
        <?php esc_html_e( 'All Tables', 'table-upload-manager' ); ?>
    </h1>

    <?php if ( empty( $tables ) ) : ?>
        <div class="tum-empty-state">
            <span class="dashicons dashicons-media-spreadsheet"></span>
            <p><?php esc_html_e( 'No tables have been created yet. Approved users can create tables via the frontend shortcode.', 'table-upload-manager' ); ?></p>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped tum-admin-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ID', 'table-upload-manager' ); ?></th>
                    <th><?php esc_html_e( 'Title', 'table-upload-manager' ); ?></th>
                    <th><?php esc_html_e( 'Owner', 'table-upload-manager' ); ?></th>
                    <th><?php esc_html_e( 'Rows', 'table-upload-manager' ); ?></th>
                    <th><?php esc_html_e( 'Last Updated', 'table-upload-manager' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'table-upload-manager' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $tables as $table ) :
                $data_row   = TUM_Database::get_table_data( (int) $table->id );
                $owner      = TUM_User_Manager::get_user_display( (int) $table->owner_id );
            ?>
                <tr>
                    <td><?php echo esc_html( $table->id ); ?></td>
                    <td><strong><?php echo esc_html( $table->title ); ?></strong>
                        <?php if ( $table->description ) : ?>
                            <p class="description"><?php echo esc_html( $table->description ); ?></p>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $owner['display_name'] ); ?><br>
                        <small><?php echo esc_html( $owner['email'] ); ?></small>
                    </td>
                    <td><?php echo $data_row ? esc_html( number_format( (int) $data_row->row_count ) ) : '<em>—</em>'; ?></td>
                    <td><?php echo esc_html( $table->updated_at ); ?></td>
                    <td>
                        <button class="button tum-admin-delete-table"
                                data-table-id="<?php echo esc_attr( $table->id ); ?>"
                                data-title="<?php echo esc_attr( $table->title ); ?>">
                            <?php esc_html_e( 'Delete', 'table-upload-manager' ); ?>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
