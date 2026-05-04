<?php
/**
 * Admin: Approved Users view.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
$all_users = TUM_User_Manager::get_all_users_with_status();
?>
<div id="tum-admin" class="wrap">
    <h1 class="tum-admin-heading">
        <span class="dashicons dashicons-groups"></span>
        <?php esc_html_e( 'Approved Users', 'table-upload-manager' ); ?>
    </h1>

    <p class="description">
        <?php esc_html_e( 'Users listed below with "Approved" status can upload and manage tables from the frontend. Administrators always have full access.', 'table-upload-manager' ); ?>
    </p>

    <div class="tum-user-search-wrap">
        <input type="text" id="tum-user-filter" placeholder="<?php esc_attr_e( 'Filter users…', 'table-upload-manager' ); ?>" class="regular-text">
    </div>

    <table class="wp-list-table widefat fixed striped tum-admin-table" id="tum-users-table">
        <thead>
            <tr>
                <th><?php esc_html_e( 'Display Name', 'table-upload-manager' ); ?></th>
                <th><?php esc_html_e( 'Username', 'table-upload-manager' ); ?></th>
                <th><?php esc_html_e( 'Email', 'table-upload-manager' ); ?></th>
                <th><?php esc_html_e( 'Role', 'table-upload-manager' ); ?></th>
                <th><?php esc_html_e( 'Status', 'table-upload-manager' ); ?></th>
                <th><?php esc_html_e( 'Action', 'table-upload-manager' ); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ( $all_users as $user ) : ?>
            <tr data-user-id="<?php echo esc_attr( $user['id'] ); ?>"
                data-search="<?php echo esc_attr( strtolower( $user['display_name'] . ' ' . $user['login'] . ' ' . $user['email'] ) ); ?>">
                <td><?php echo esc_html( $user['display_name'] ); ?></td>
                <td><code><?php echo esc_html( $user['login'] ); ?></code></td>
                <td><?php echo esc_html( $user['email'] ); ?></td>
                <td>
                    <?php if ( $user['is_admin'] ) : ?>
                        <span class="tum-badge tum-badge-admin"><?php esc_html_e( 'Administrator', 'table-upload-manager' ); ?></span>
                    <?php else : ?>
                        <span class="tum-badge tum-badge-user"><?php esc_html_e( 'Subscriber', 'table-upload-manager' ); ?></span>
                    <?php endif; ?>
                </td>
                <td class="tum-status-cell">
                    <?php if ( $user['is_admin'] ) : ?>
                        <span class="tum-badge tum-badge-admin"><?php esc_html_e( 'Always Approved', 'table-upload-manager' ); ?></span>
                    <?php elseif ( $user['is_approved'] ) : ?>
                        <span class="tum-badge tum-badge-approved"><?php esc_html_e( 'Approved', 'table-upload-manager' ); ?></span>
                    <?php else : ?>
                        <span class="tum-badge tum-badge-pending"><?php esc_html_e( 'Not Approved', 'table-upload-manager' ); ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ( ! $user['is_admin'] ) : ?>
                        <?php if ( $user['is_approved'] ) : ?>
                            <button class="button tum-unapprove-btn" data-user-id="<?php echo esc_attr( $user['id'] ); ?>">
                                <?php esc_html_e( 'Revoke Access', 'table-upload-manager' ); ?>
                            </button>
                        <?php else : ?>
                            <button class="button button-primary tum-approve-btn" data-user-id="<?php echo esc_attr( $user['id'] ); ?>">
                                <?php esc_html_e( 'Approve', 'table-upload-manager' ); ?>
                            </button>
                        <?php endif; ?>
                    <?php else : ?>
                        <em class="tum-na">&mdash;</em>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
