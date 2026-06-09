<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Mailing Lists admin views (read-only, v1).
 *
 *   - Index: browse the organisation's mailing lists with subscriber counts.
 *   - Detail (?list_id=…): a list's subscribers, each matched against WP core users
 *     by email. The "WP users not on this list" pool (which will drive add-to-list)
 *     lands in the next increment.
 *
 * Server-rendered with query params (page/list_id/paged/s) — bookmarkable, no JS
 * controller needed. Suits the small org sizes here (<1k subscribers typical).
 */
class Hail_Mail_Connect_Lists {

    /** Rows shown per page in the detail tabs. */
    const PER_PAGE = 25;

    public function __construct() {
        // WP-user membership changes post here, then redirect back (no resubmit).
        add_action( 'admin_post_hail_mail_save_members', array( $this, 'handle_member_save' ) );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'hail-mail-connect' ) );
        }

        $api = Hail_Mail_Connect::instance()->api;

        echo '<div class="wrap hmc">';

        if ( ! $api->is_connected() ) {
            $this->header( __( 'Hail Mail — Mailing Lists', 'hail-mail-connect' ) );
            echo '<div class="notice notice-warning"><p>';
            printf(
                /* translators: %s: settings page URL */
                wp_kses_post( __( 'Not connected to Hail. <a href="%s">Open Settings</a> to connect.', 'hail-mail-connect' ) ),
                esc_url( admin_url( 'admin.php?page=hail-mail-connect-settings' ) )
            );
            echo '</p></div></div>';
            return;
        }

        $list_id = isset( $_GET['list_id'] ) ? sanitize_text_field( wp_unslash( $_GET['list_id'] ) ) : '';

        if ( '' !== $list_id ) {
            $this->render_list_detail( $api, $list_id );
        } else {
            $this->render_lists( $api );
        }

        echo '</div>';
    }

    /* ------------------------------------------------------------------ *
     * Header
     * ------------------------------------------------------------------ */

    /**
     * Page header: Hail icon + title, with optional right-side actions
     * (back link, "Open in Hail Mail" deep link).
     *
     * @param string $title
     * @param array  $args  back_url, hail_url
     */
    private function header( $title, $args = array() ) {
        echo '<div class="hmc-header">';
        echo $this->icon_svg(); // phpcs:ignore WordPress.Security.EscapeOutput -- static trusted SVG
        echo '<h1>' . esc_html( $title ) . '</h1>';

        $right = '';
        if ( ! empty( $args['back_url'] ) ) {
            $right .= '<a class="hmc-back" href="' . esc_url( $args['back_url'] ) . '">' . esc_html__( '← All lists', 'hail-mail-connect' ) . '</a>';
        }
        if ( ! empty( $args['hail_url'] ) ) {
            $right .= ' <a class="button button-primary" href="' . esc_url( $args['hail_url'] ) . '" target="_blank" rel="noopener noreferrer">'
                . esc_html__( 'Open in Hail Mail', 'hail-mail-connect' )
                . ' <span class="dashicons dashicons-external" style="vertical-align:text-top;"></span></a>';
        }
        if ( '' !== $right ) {
            echo '<span style="margin-left:auto;display:flex;align-items:center;gap:12px;">' . $right . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput -- composed from escaped parts
        }
        echo '</div>';
    }

    private function icon_svg() {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 512" aria-hidden="true"><path fill="currentColor" d="M112 32l288 0c8.8 0 16 7.2 16 16l0 16 32 0 0-16c0-26.5-21.5-48-48-48L112 0C85.5 0 64 21.5 64 48l0 144 32 0L96 48c0-8.8 7.2-16 16-16zM224 144c0-8.8 7.2-16 16-16l352 0c8.8 0 16 7.2 16 16l0 224c0 8.8-7.2 16-16 16l-176 0 0 32 176 0c26.5 0 48-21.5 48-48l0-224c0-26.5-21.5-48-48-48L240 96c-26.5 0-48 21.5-48 48l0 48 32 0 0-48zM48 256l288 0c8.8 0 16 7.2 16 16l0 5L196.9 391.9c-1.4 1-3.1 1.6-4.9 1.6s-3.5-.6-4.9-1.6L32 277l0-5c0-8.8 7.2-16 16-16zM32 464l0-147.2L168 417.6c6.9 5.1 15.3 7.9 24 7.9s17-2.8 24-7.9L352 316.8 352 464c0 8.8-7.2 16-16 16L48 480c-8.8 0-16-7.2-16-16zM48 224c-26.5 0-48 21.5-48 48L0 464c0 26.5 21.5 48 48 48l288 0c26.5 0 48-21.5 48-48l0-192c0-26.5-21.5-48-48-48L48 224zm448-64c-8.8 0-16 7.2-16 16l0 64c0 8.8 7.2 16 16 16l64 0c8.8 0 16-7.2 16-16l0-64c0-8.8-7.2-16-16-16l-64 0zm16 64l0-32 32 0 0 32-32 0z"></path></svg>';
    }

    /* ------------------------------------------------------------------ *
     * List index
     * ------------------------------------------------------------------ */

    private function render_lists( $api ) {
        $this->header( __( 'Hail Mail — Mailing Lists', 'hail-mail-connect' ), array(
            'hail_url' => $api->get_hail_mail_dashboard_url(),
        ) );

        $response = $api->get_mail_lists();
        if ( is_wp_error( $response ) ) {
            $this->render_api_error( $response );
            return;
        }

        $lists = $this->collection( $response );
        if ( empty( $lists ) ) {
            echo '<p><em>' . esc_html__( 'No mailing lists found for this organisation.', 'hail-mail-connect' ) . '</em></p>';
            return;
        }
        ?>
        <div class="hmc-card">
        <table class="hmc-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Mail list', 'hail-mail-connect' ); ?></th>
                    <th style="width:140px;"><?php esc_html_e( 'Mail type', 'hail-mail-connect' ); ?></th>
                    <th style="width:160px;"><?php esc_html_e( 'Subscriber count', 'hail-mail-connect' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $lists as $list ) :
                $id      = $list['id'] ?? '';
                $name    = $list['name'] ?? $id;
                $count   = $this->list_count( $list );
                $updated = $this->list_updated( $list );
                $url     = add_query_arg(
                    array( 'page' => 'hail-mail-connect', 'list_id' => $id ),
                    admin_url( 'admin.php' )
                );
                ?>
                <tr>
                    <td>
                        <a class="hmc-listname" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $name ); ?></a>
                        <?php if ( $updated ) : ?>
                            <span class="hmc-sub"><span class="dashicons dashicons-update" style="font-size:14px;width:14px;height:14px;vertical-align:text-bottom;"></span> <?php echo esc_html( sprintf( __( 'Updated: %s', 'hail-mail-connect' ), $updated ) ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><span class="hmc-tag"><?php echo esc_html( $this->list_type( $list ) ); ?></span></td>
                    <td>
                        <a class="hmc-count" href="<?php echo esc_url( $url ); ?>"><?php
                            echo ( null === $count )
                                ? esc_html__( 'View', 'hail-mail-connect' )
                                : esc_html( sprintf( _n( '%s sub', '%s subs', $count, 'hail-mail-connect' ), number_format_i18n( $count ) ) );
                        ?></a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ *
     * List detail (tabbed: WP Users | All Subscribers)
     * ------------------------------------------------------------------ */

    private function render_list_detail( $api, $list_id ) {
        $back_url = add_query_arg( array( 'page' => 'hail-mail-connect' ), admin_url( 'admin.php' ) );

        // Resolve the list name (best-effort; falls back to the id).
        $list      = $api->get_mail_list( $list_id );
        $list_name = ( ! is_wp_error( $list ) ) ? ( $this->single( $list )['name'] ?? $list_id ) : $list_id;

        $tab = isset( $_GET['tab'] ) && 'subs' === $_GET['tab'] ? 'subs' : 'wp';

        $this->header(
            $list_name,
            array(
                'back_url' => $back_url,
                'hail_url' => $api->get_hail_mail_dashboard_url(),
            )
        );

        // Result notice after a membership save (redirected back with counts).
        $this->maybe_render_save_notice();

        // Tabs.
        $tab_url = function ( $which ) use ( $list_id ) {
            return add_query_arg(
                array( 'page' => 'hail-mail-connect', 'list_id' => $list_id, 'tab' => $which ),
                admin_url( 'admin.php' )
            );
        };
        echo '<h2 class="nav-tab-wrapper" style="margin-bottom:18px;">';
        echo '<a href="' . esc_url( $tab_url( 'wp' ) ) . '" class="nav-tab ' . ( 'wp' === $tab ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'WP Users', 'hail-mail-connect' ) . '</a>';
        echo '<a href="' . esc_url( $tab_url( 'subs' ) ) . '" class="nav-tab ' . ( 'subs' === $tab ? 'nav-tab-active' : '' ) . '">' . esc_html__( 'All Subscribers', 'hail-mail-connect' ) . '</a>';
        echo '</h2>';

        if ( 'subs' === $tab ) {
            $this->render_subscribers_tab( $api, $list_id );
        } else {
            $this->render_wp_users_tab( $api, $list_id );
        }
    }

    /* ------------------------------------------------------------------ *
     * Tab: WP Users (default) — registered non-admin users + in-list checkbox
     * ------------------------------------------------------------------ */

    private function render_wp_users_tab( $api, $list_id ) {
        // The list's full subscriber index (email => [id, subscribed]) so we can show
        // whether each WP user is currently on the list. Small org sizes → fetch all.
        $index = $this->subscriber_index( $api, $list_id );
        if ( is_wp_error( $index ) ) {
            $this->render_api_error( $index );
            return;
        }

        $paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $search = isset( $_GET['us'] ) ? sanitize_text_field( wp_unslash( $_GET['us'] ) ) : '';

        // Non-admin WP users (role-based exclusion — efficient and keeps the count
        // accurate; custom admin roles / multisite super admins are a future seam).
        $args = array(
            'number'       => self::PER_PAGE,
            'paged'        => $paged,
            'orderby'      => 'display_name',
            'order'        => 'ASC',
            'role__not_in' => array( 'administrator' ),
            'count_total'  => true,
            'fields'       => array( 'ID', 'user_email', 'display_name' ),
        );
        if ( '' !== $search ) {
            // Match username, email, display name or nicename.
            $args['search']         = '*' . $search . '*';
            $args['search_columns'] = array( 'user_login', 'user_email', 'display_name', 'user_nicename' );
        }
        $query = new WP_User_Query( $args );
        $users = $query->get_results();
        $total = (int) $query->get_total();

        $has_studio = $api->has_scope( 'studio' );

        if ( ! $has_studio ) {
            echo '<div class="notice notice-info inline" style="margin:0 0 14px;"><p>'
                . esc_html__( 'Adding a user to a list requires the studio scope (not currently granted) — those checkboxes are disabled. You can still remove users now. Enable studio in Settings and reconnect to add without opt-in.', 'hail-mail-connect' )
                . '</p></div>';
        }

        // Filter bar (by username or email), above the table.
        $this->render_filter_bar( $list_id, 'wp', 'us', $search, __( 'Search email or username…', 'hail-mail-connect' ) );

        if ( empty( $users ) ) {
            echo '<p><em>' . esc_html__( 'No matching users found.', 'hail-mail-connect' ) . '</em></p>';
            return;
        }

        $candidates = array();
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="hail_mail_save_members" />
            <input type="hidden" name="list_id" value="<?php echo esc_attr( $list_id ); ?>" />
            <input type="hidden" name="paged" value="<?php echo esc_attr( $paged ); ?>" />
            <input type="hidden" name="us" value="<?php echo esc_attr( $search ); ?>" />
            <?php wp_nonce_field( 'hail_mail_save_members_' . $list_id ); ?>

            <div class="hmc-card">
            <table class="hmc-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'WP user', 'hail-mail-connect' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'hail-mail-connect' ); ?></th>
                        <th style="width:130px;"><?php esc_html_e( 'Status', 'hail-mail-connect' ); ?></th>
                        <th style="width:90px;"><?php esc_html_e( 'In list', 'hail-mail-connect' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $users as $u ) :
                    $email        = strtolower( $u->user_email );
                    $candidates[] = $email;
                    $entry        = $index[ $email ] ?? null;
                    $subscribed   = $entry && ! empty( $entry['subscribed'] );
                    // Disable only when we cannot perform the implied action: adding
                    // (an unchecked box) needs studio; removing is always allowed.
                    $disabled = ( ! $subscribed && ! $has_studio );
                    // Membership status of this WP user against the current list.
                    if ( $subscribed ) {
                        $status_label = __( 'Subscribed', 'hail-mail-connect' );
                        $status_class = 'is-subscribed';
                    } elseif ( $entry ) {
                        $status_label = __( 'Unsubscribed', 'hail-mail-connect' );
                        $status_class = 'is-unsubscribed';
                    } else {
                        $status_label = __( 'Not on list', 'hail-mail-connect' );
                        $status_class = '';
                    }
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url( get_edit_user_link( $u->ID ) ); ?>"><?php echo esc_html( $u->display_name ); ?></a>
                        </td>
                        <td><?php echo esc_html( $u->user_email ); ?></td>
                        <td><span class="hmc-pill <?php echo esc_attr( $status_class ); ?>"><?php echo esc_html( $status_label ); ?></span></td>
                        <td>
                            <input type="checkbox" name="members[]" value="<?php echo esc_attr( $email ); ?>"
                                <?php checked( $subscribed ); ?> <?php disabled( $disabled ); ?> />
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <input type="hidden" name="candidates" value="<?php echo esc_attr( implode( ',', $candidates ) ); ?>" />

            <p style="margin-top:14px;">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'hail-mail-connect' ); ?></button>
            </p>
        </form>
        <?php
        $this->render_pagination( $total, $paged, array_filter( array( 'list_id' => $list_id, 'tab' => 'wp', 'us' => $search ) ), __( 'user', 'hail-mail-connect' ), __( 'users', 'hail-mail-connect' ) );
    }

    /* ------------------------------------------------------------------ *
     * Member save handler (admin-post; redirects back)
     * ------------------------------------------------------------------ */

    public function handle_member_save() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Permission denied.', 'hail-mail-connect' ) );
        }
        $list_id = isset( $_POST['list_id'] ) ? sanitize_text_field( wp_unslash( $_POST['list_id'] ) ) : '';
        check_admin_referer( 'hail_mail_save_members_' . $list_id );

        $paged      = isset( $_POST['paged'] ) ? max( 1, (int) $_POST['paged'] ) : 1;
        $search     = isset( $_POST['us'] ) ? sanitize_text_field( wp_unslash( $_POST['us'] ) ) : '';
        $candidates = isset( $_POST['candidates'] ) ? array_filter( array_map( 'strtolower', array_map( 'sanitize_text_field', explode( ',', wp_unslash( $_POST['candidates'] ) ) ) ) ) : array();
        $checked    = isset( $_POST['members'] ) ? array_map( 'strtolower', array_map( 'sanitize_email', (array) wp_unslash( $_POST['members'] ) ) ) : array();

        $api        = Hail_Mail_Connect::instance()->api;
        $index      = $this->subscriber_index( $api, $list_id );
        $has_studio = $api->has_scope( 'studio' );

        $added = 0;
        $removed = 0;
        $failed = 0;
        $blocked = 0; // add attempts blocked by missing studio scope

        if ( ! is_wp_error( $index ) ) {
            foreach ( $candidates as $email ) {
                $entry = $index[ $email ] ?? null;
                $is    = $entry && ! empty( $entry['subscribed'] );
                $want  = in_array( $email, $checked, true );

                if ( $want && ! $is ) {
                    if ( ! $has_studio ) {
                        $blocked++;
                        continue;
                    }
                    $wp_user = get_user_by( 'email', $email );
                    $r = $api->studio_add_subscribers( $list_id, array( array(
                        'email'      => $email,
                        'first_name' => $wp_user ? $wp_user->first_name : '',
                        'last_name'  => $wp_user ? $wp_user->last_name : '',
                    ) ) );
                    if ( is_wp_error( $r ) ) {
                        $failed++;
                    } else {
                        $added++;
                    }
                } elseif ( ! $want && $is ) {
                    $r = $api->remove_subscribers_from_list( $list_id, array( $entry['id'] ) );
                    if ( is_wp_error( $r ) ) {
                        $failed++;
                    } else {
                        $removed++;
                    }
                }
            }
        }

        $query = array(
            'page'        => 'hail-mail-connect',
            'list_id'     => $list_id,
            'tab'         => 'wp',
            'paged'       => $paged,
            'hmc_added'   => $added,
            'hmc_removed' => $removed,
            'hmc_failed'  => $failed,
            'hmc_blocked' => $blocked,
        );
        if ( '' !== $search ) {
            $query['us'] = $search; // keep the user on their filtered view
        }
        $redirect = add_query_arg( $query, admin_url( 'admin.php' ) );
        wp_safe_redirect( $redirect );
        exit;
    }

    private function maybe_render_save_notice() {
        if ( ! isset( $_GET['hmc_added'], $_GET['hmc_removed'] ) ) {
            return;
        }
        $added   = (int) $_GET['hmc_added'];
        $removed = (int) $_GET['hmc_removed'];
        $failed  = isset( $_GET['hmc_failed'] ) ? (int) $_GET['hmc_failed'] : 0;
        $blocked = isset( $_GET['hmc_blocked'] ) ? (int) $_GET['hmc_blocked'] : 0;

        $parts = array();
        if ( $added ) {
            $parts[] = sprintf( _n( '%d added', '%d added', $added, 'hail-mail-connect' ), $added );
        }
        if ( $removed ) {
            $parts[] = sprintf( _n( '%d removed', '%d removed', $removed, 'hail-mail-connect' ), $removed );
        }
        if ( empty( $parts ) ) {
            $parts[] = __( 'No changes', 'hail-mail-connect' );
        }
        $class = ( $failed || $blocked ) ? 'notice-warning' : 'notice-success';
        echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( implode( ', ', $parts ) );
        if ( $blocked ) {
            echo ' — ' . esc_html( sprintf( _n( '%d add skipped (studio scope required)', '%d adds skipped (studio scope required)', $blocked, 'hail-mail-connect' ), $blocked ) );
        }
        if ( $failed ) {
            echo ' — ' . esc_html( sprintf( _n( '%d failed', '%d failed', $failed, 'hail-mail-connect' ), $failed ) );
        }
        echo '</p></div>';
    }

    /**
     * Build email => [id, subscribed] for every contact on a list, fetching all
     * pages (small org sizes). `subscribed` is false when the membership carries an
     * unsubscribed_date. WP_Error on API failure.
     */
    private function subscriber_index( $api, $list_id ) {
        $index  = array();
        $offset = 0;
        $limit  = 500;
        $guard  = 0;
        do {
            $resp = $api->get_mail_list_subscribers( $list_id, array( 'limit' => $limit, 'offset' => $offset ) );
            if ( is_wp_error( $resp ) ) {
                return $resp;
            }
            $batch = $this->collection( $resp );
            foreach ( $batch as $s ) {
                $email = strtolower( $s['email'] ?? '' );
                if ( '' === $email ) {
                    continue;
                }
                $unsub = $s['unsubscribed_date'] ?? ( $s['pivot']['unsubscribed_date'] ?? null );
                $index[ $email ] = array(
                    'id'         => $s['id'] ?? '',
                    'subscribed' => empty( $unsub ),
                );
            }
            $offset += $limit;
            $guard++;
        } while ( count( $batch ) === $limit && $guard < 40 );

        return $index;
    }

    /* ------------------------------------------------------------------ *
     * Tab: All Subscribers — Hail subscribers + WP-match column
     * ------------------------------------------------------------------ */

    private function render_subscribers_tab( $api, $list_id ) {
        $paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $offset = ( $paged - 1 ) * self::PER_PAGE;

        // Filter bar (by email), above the table.
        $this->render_filter_bar( $list_id, 'subs', 's', $search, __( 'Search email…', 'hail-mail-connect' ) );

        $params = array( 'limit' => self::PER_PAGE, 'offset' => $offset );
        if ( '' !== $search ) {
            $params['search'] = $search;
        }

        $response = $api->get_mail_list_subscribers( $list_id, $params );
        if ( is_wp_error( $response ) ) {
            $this->render_api_error( $response );
            return;
        }
        $subscribers = $this->collection( $response );

        $count_params = ( '' !== $search ) ? array( 'search' => $search ) : array();
        $total        = $this->normalize_count( $api->get_mail_list_subscriber_count( $list_id, $count_params ) );

        if ( empty( $subscribers ) ) {
            echo '<p><em>' . esc_html__( 'No subscribers found.', 'hail-mail-connect' ) . '</em></p>';
            return;
        }

        // Batch-match this page's emails against WP core users (single query).
        $emails  = array();
        foreach ( $subscribers as $s ) {
            if ( ! empty( $s['email'] ) ) {
                $emails[] = $s['email'];
            }
        }
        $wp_map = $this->match_wp_users_by_email( $emails );
        ?>
        <div class="hmc-card">
        <table class="hmc-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Email', 'hail-mail-connect' ); ?></th>
                    <th><?php esc_html_e( 'Name', 'hail-mail-connect' ); ?></th>
                    <th style="width:130px;"><?php esc_html_e( 'Status', 'hail-mail-connect' ); ?></th>
                    <th style="width:240px;"><?php esc_html_e( 'WP user', 'hail-mail-connect' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $subscribers as $s ) :
                $email  = $s['email'] ?? '';
                $match  = $wp_map[ strtolower( $email ) ] ?? null;
                $status = $this->subscriber_status( $s );
                ?>
                <tr>
                    <td><?php echo esc_html( $email ); ?></td>
                    <td><?php echo esc_html( $this->subscriber_name( $s ) ); ?></td>
                    <td><span class="hmc-pill is-<?php echo esc_attr( sanitize_html_class( strtolower( $status ) ) ); ?>"><?php echo esc_html( $status ); ?></span></td>
                    <td class="hmc-match">
                        <?php if ( $match ) :
                            $edit     = get_edit_user_link( $match->ID );
                            $is_admin = user_can( $match->ID, 'manage_options' );
                            ?>
                            <span class="dashicons dashicons-yes"></span>
                            <a href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $match->display_name ); ?></a>
                            <?php if ( $is_admin ) : ?>
                                <span class="hmc-admin-flag">(<?php esc_html_e( 'admin', 'hail-mail-connect' ); ?>)</span>
                            <?php endif; ?>
                        <?php else : ?>
                            <span class="hmc-nomatch">&mdash; <?php esc_html_e( 'no WP user', 'hail-mail-connect' ); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php
        $this->render_pagination(
            $total,
            $paged,
            array_filter( array( 'list_id' => $list_id, 'tab' => 'subs', 's' => $search ) ),
            __( 'subscriber', 'hail-mail-connect' ),
            __( 'subscribers', 'hail-mail-connect' )
        );
    }

    /* ------------------------------------------------------------------ *
     * WP user matching
     * ------------------------------------------------------------------ */

    /**
     * Map lower-cased email => WP user row (ID, user_email, display_name) for the
     * given emails. One indexed query — cheap for a page of ~25 subscribers.
     *
     * @param string[] $emails
     * @return array<string,object>
     */
    private function match_wp_users_by_email( array $emails ) {
        global $wpdb;
        $emails = array_values( array_unique( array_filter( array_map( 'strtolower', $emails ) ) ) );
        if ( empty( $emails ) ) {
            return array();
        }
        $placeholders = implode( ',', array_fill( 0, count( $emails ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built from count
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, user_email, display_name FROM {$wpdb->users} WHERE LOWER(user_email) IN ($placeholders)",
            $emails
        ) );

        $map = array();
        foreach ( (array) $rows as $row ) {
            $map[ strtolower( $row->user_email ) ] = $row;
        }
        return $map;
    }

    /* ------------------------------------------------------------------ *
     * Helpers
     * ------------------------------------------------------------------ */

    /** Normalise a Hail collection response (bare array OR { data: [...] }) to a list. */
    private function collection( $response ) {
        if ( is_array( $response ) && isset( $response['data'] ) && is_array( $response['data'] ) ) {
            return $response['data'];
        }
        return is_array( $response ) ? $response : array();
    }

    /** Normalise a Hail single-resource response (bare object OR { data: {...} }). */
    private function single( $response ) {
        if ( is_array( $response ) && isset( $response['data'] ) && is_array( $response['data'] ) ) {
            return $response['data'];
        }
        return is_array( $response ) ? $response : array();
    }

    /** Normalise a count response: int, { count: N }, or { data: { count: N } }. */
    private function normalize_count( $response ) {
        if ( is_int( $response ) ) {
            return $response;
        }
        if ( is_array( $response ) ) {
            if ( isset( $response['count'] ) ) {
                return (int) $response['count'];
            }
            if ( isset( $response['data']['count'] ) ) {
                return (int) $response['data']['count'];
            }
        }
        return 0;
    }

    /** Subscriber count for a list row, tolerating field-name variation. */
    private function list_count( $list ) {
        foreach ( array( 'subscriber_count', 'contacts', 'number_of_subscribers' ) as $key ) {
            if ( isset( $list[ $key ] ) && is_numeric( $list[ $key ] ) ) {
                return (int) $list[ $key ];
            }
        }
        return null;
    }

    /** Formatted "last updated" date for a list, or '' if not present. */
    private function list_updated( $list ) {
        foreach ( array( 'last_updated', 'updated_date', 'updated_at' ) as $key ) {
            if ( ! empty( $list[ $key ] ) ) {
                $ts = strtotime( (string) $list[ $key ] );
                if ( $ts ) {
                    return date_i18n( get_option( 'date_format' ), $ts );
                }
            }
        }
        return '';
    }

    /** Human mail-type label (Hail / SMS / integration provider). */
    private function list_type( $list ) {
        if ( ! empty( $list['sms_type'] ) ) {
            return __( 'SMS', 'hail-mail-connect' );
        }
        if ( ! empty( $list['integration_provider'] ) ) {
            return ucfirst( (string) $list['integration_provider'] );
        }
        if ( ! empty( $list['is_alumni'] ) ) {
            return __( 'Alumni', 'hail-mail-connect' );
        }
        return __( 'Hail', 'hail-mail-connect' );
    }

    private function subscriber_name( $s ) {
        if ( ! empty( $s['name'] ) ) {
            return $s['name'];
        }
        $name = trim( ( $s['first_name'] ?? '' ) . ' ' . ( $s['last_name'] ?? '' ) );
        return '' !== $name ? $name : '—';
    }

    /** Derive a human status from whichever flags the API returned. */
    private function subscriber_status( $s ) {
        if ( ! empty( $s['unsubscribed_date'] ) ) {
            return __( 'Unsubscribed', 'hail-mail-connect' );
        }
        if ( ! empty( $s['bounced'] ) ) {
            return __( 'Bounced', 'hail-mail-connect' );
        }
        if ( ! empty( $s['complained'] ) ) {
            return __( 'Complained', 'hail-mail-connect' );
        }
        if ( ! empty( $s['muted'] ) ) {
            return __( 'Muted', 'hail-mail-connect' );
        }
        if ( isset( $s['status'] ) && '' !== $s['status'] ) {
            return ucfirst( (string) $s['status'] );
        }
        return __( 'Subscribed', 'hail-mail-connect' );
    }

    /**
     * Left-aligned filter bar above a tab's table. Shows a Reset link only when a
     * filter value is present in the params.
     *
     * @param string $list_id
     * @param string $tab          'wp' | 'subs'
     * @param string $param        query param name ('us' | 's')
     * @param string $value        current filter value
     * @param string $placeholder
     */
    private function render_filter_bar( $list_id, $tab, $param, $value, $placeholder ) {
        $reset_url = add_query_arg(
            array( 'page' => 'hail-mail-connect', 'list_id' => $list_id, 'tab' => $tab ),
            admin_url( 'admin.php' )
        );
        ?>
        <form method="get" class="hmc-filter">
            <input type="hidden" name="page" value="hail-mail-connect" />
            <input type="hidden" name="list_id" value="<?php echo esc_attr( $list_id ); ?>" />
            <input type="hidden" name="tab" value="<?php echo esc_attr( $tab ); ?>" />
            <input type="search" name="<?php echo esc_attr( $param ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="<?php echo esc_attr( $placeholder ); ?>" />
            <?php submit_button( __( 'Filter', 'hail-mail-connect' ), 'primary', '', false ); ?>
            <?php if ( '' !== $value ) : ?>
                <a href="<?php echo esc_url( $reset_url ); ?>" class="button button-primary"><?php esc_html_e( 'Reset', 'hail-mail-connect' ); ?></a>
            <?php endif; ?>
        </form>
        <?php
    }

    private function render_pagination( $total, $paged, $query_args, $noun_single, $noun_plural ) {
        $total_pages = (int) ceil( $total / self::PER_PAGE );
        if ( $total_pages <= 1 ) {
            return;
        }
        $base = add_query_arg(
            array_merge( array( 'page' => 'hail-mail-connect' ), $query_args ),
            admin_url( 'admin.php' )
        );

        echo '<div class="hmc-pagination">';
        echo '<span class="displaying-num">' . esc_html( sprintf( '%s %s', number_format_i18n( $total ), ( 1 === $total ? $noun_single : $noun_plural ) ) ) . '</span>';
        echo '<span class="hmc-pagination__nav">';
        if ( $paged > 1 ) {
            echo '<a class="button button-primary" href="' . esc_url( add_query_arg( 'paged', $paged - 1, $base ) ) . '">‹ ' . esc_html__( 'Previous', 'hail-mail-connect' ) . '</a>';
        }
        echo '<span class="paging-input">' . esc_html( sprintf( __( 'Page %1$d of %2$d', 'hail-mail-connect' ), $paged, $total_pages ) ) . '</span>';
        if ( $paged < $total_pages ) {
            echo '<a class="button button-primary" href="' . esc_url( add_query_arg( 'paged', $paged + 1, $base ) ) . '">' . esc_html__( 'Next', 'hail-mail-connect' ) . ' ›</a>';
        }
        echo '</span>';
        echo '</div>';
    }

    /** Admins see the technical detail; render an inline error block. */
    private function render_api_error( WP_Error $err ) {
        $data   = $err->get_error_data();
        $status = is_array( $data ) && isset( $data['status'] ) ? ' (HTTP ' . (int) $data['status'] . ')' : '';
        echo '<div class="notice notice-error"><p>';
        echo esc_html__( 'Could not load data from Hail: ', 'hail-mail-connect' ) . esc_html( $err->get_error_message() . $status );
        echo '</p></div>';
    }
}
