<?php
/**
 * High-level user management (approval, search, display helpers).
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class TUM_User_Manager {

    /**
     * Approve a WordPress user to upload tables.
     */
    public static function approve( int $user_id ): bool {
        if ( ! get_userdata( $user_id ) ) return false;
        return TUM_Database::approve_user( $user_id, get_current_user_id() );
    }

    public static function unapprove( int $user_id ): bool {
        return TUM_Database::unapprove_user( $user_id );
    }

    /**
     * Returns all WP users with the tum_approved flag merged in.
     */
    public static function get_all_users_with_status(): array {
        $wp_users = get_users( [ 'fields' => [ 'ID', 'user_login', 'user_email', 'display_name' ] ] );
        $approved  = array_column( TUM_Database::get_all_approved_users(), null, 'user_id' );

        $result = [];
        foreach ( $wp_users as $u ) {
            $result[] = [
                'id'           => (int) $u->ID,
                'login'        => $u->user_login,
                'email'        => $u->user_email,
                'display_name' => $u->display_name,
                'is_admin'     => user_can( (int) $u->ID, 'manage_options' ),
                'is_approved'  => isset( $approved[ $u->ID ] ),
                'approved_at'  => isset( $approved[ $u->ID ] ) ? $approved[ $u->ID ]->approved_at : null,
            ];
        }
        return $result;
    }

    /**
     * Search users by login, email, or display name for the permission picker.
     * Returns only approved (or admin) users so owners can grant meaningful access.
     */
    public static function search_approvable_users( string $term ): array {
        $users = get_users( [
            'search'         => '*' . esc_attr( $term ) . '*',
            'search_columns' => [ 'user_login', 'user_email', 'display_name' ],
            'fields'         => [ 'ID', 'user_login', 'user_email', 'display_name' ],
            'number'         => 20,
        ] );

        $result = [];
        foreach ( $users as $u ) {
            $result[] = [
                'id'           => (int) $u->ID,
                'login'        => $u->user_login,
                'email'        => $u->user_email,
                'display_name' => $u->display_name,
            ];
        }
        return $result;
    }

    /**
     * Returns minimal public info for a user ID.
     */
    public static function get_user_display( int $user_id ): array {
        $u = get_userdata( $user_id );
        if ( ! $u ) return [ 'id' => $user_id, 'display_name' => 'Unknown', 'email' => '' ];
        return [
            'id'           => $user_id,
            'display_name' => $u->display_name,
            'email'        => $u->user_email,
            'login'        => $u->user_login,
        ];
    }
}
