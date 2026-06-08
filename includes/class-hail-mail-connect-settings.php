<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin settings + page rendering for Hail Mail Connect.
 *
 * v1 scope:
 *   - Settings page: the OAuth Connection (credentials + connect/disconnect + granted
 *     scopes, with studio-not-granted messaging).
 *   - Mailing Lists page: read-only browse (stub here; rendering lands next milestone),
 *     which will link each list through to its subscribers ↔ WP-user matching view.
 *
 * List CRUD (create/edit/delete) is intentionally NOT here — list management belongs
 * in Hail's own UI. (Possible future seam, gated on content.write, but not v1.)
 */
class Hail_Mail_Connect_Settings {

    public function __construct() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'wp_ajax_hail_mail_connect_test', array( $this, 'ajax_test_connection' ) );
    }

    public function register_settings() {
        register_setting( 'hail_mail_connect_settings_group', HAIL_MAIL_CONNECT_SETTINGS_KEY, array(
            'sanitize_callback' => array( $this, 'sanitize_settings' ),
        ) );
    }

    /**
     * Merge-with-existing sanitisation so saving the form never clobbers other keys.
     */
    public function sanitize_settings( $input ) {
        $existing = get_option( HAIL_MAIL_CONNECT_SETTINGS_KEY, array() );
        $clean    = is_array( $existing ) ? $existing : array();

        foreach ( array( 'client_id', 'client_secret', 'organisation_id' ) as $key ) {
            if ( isset( $input[ $key ] ) ) {
                $clean[ $key ] = sanitize_text_field( $input[ $key ] );
            }
        }
        return $clean;
    }

    public function ajax_test_connection() {
        check_ajax_referer( 'hail_mail_connect_admin', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'hail-mail-connect' ) ) );
        }
        $result = Hail_Mail_Connect::instance()->api->test_connection();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        wp_send_json_success( array( 'message' => __( 'Connection OK.', 'hail-mail-connect' ) ) );
    }

    /* ------------------------------------------------------------------ */

    public function render_settings_page() {
        $api      = Hail_Mail_Connect::instance()->api;
        $settings = $api->get_settings();
        $connected = $api->is_connected();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Hail Mail — Settings', 'hail-mail-connect' ); ?></h1>
            <?php settings_errors(); ?>

            <h2><?php esc_html_e( 'Connection', 'hail-mail-connect' ); ?></h2>
            <?php if ( $connected ) :
                $granted = $api->get_granted_scopes();
                $has_studio = $api->has_scope( 'studio' );
                $disconnect_url = wp_nonce_url(
                    add_query_arg( 'hail_mail_connect_disconnect', '1', $api->get_callback_url() ),
                    'hail_mail_connect_disconnect'
                );
                ?>
                <p>
                    <span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
                    <strong><?php esc_html_e( 'Connected to Hail.', 'hail-mail-connect' ); ?></strong>
                </p>
                <p>
                    <strong><?php esc_html_e( 'Granted scopes:', 'hail-mail-connect' ); ?></strong>
                    <code><?php echo esc_html( $granted ? implode( ' ', $granted ) : __( '(unknown — reconnect to capture)', 'hail-mail-connect' ) ); ?></code>
                </p>
                <?php if ( ! $has_studio ) : ?>
                    <div class="notice notice-warning inline" style="margin:10px 0;">
                        <p><?php esc_html_e( 'The studio scope is not granted on this connection. Browsing and self-service subscription work, and admins can remove subscribers, but adding subscribers without their opt-in (studio) is disabled. Once your client_id is whitelisted by Hail for studio, disconnect and reconnect to enable it.', 'hail-mail-connect' ); ?></p>
                    </div>
                <?php endif; ?>
                <p>
                    <button type="button" class="button" id="hail-mail-connect-test"><?php esc_html_e( 'Test Connection', 'hail-mail-connect' ); ?></button>
                    <a href="<?php echo esc_url( $disconnect_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Disconnect', 'hail-mail-connect' ); ?></a>
                    <span id="hail-mail-connect-test-result" style="margin-left:8px;"></span>
                </p>
            <?php else : ?>
                <p>
                    <span class="dashicons dashicons-warning" style="color:#dc3232;"></span>
                    <?php esc_html_e( 'Not connected.', 'hail-mail-connect' ); ?>
                </p>
                <?php if ( ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] ) ) : ?>
                    <p><a href="<?php echo esc_url( $api->get_authorize_url() ); ?>" class="button button-primary"><?php esc_html_e( 'Connect to Hail', 'hail-mail-connect' ); ?></a></p>
                <?php else : ?>
                    <p><em><?php esc_html_e( 'Enter your API credentials below and save, then connect.', 'hail-mail-connect' ); ?></em></p>
                <?php endif; ?>
            <?php endif; ?>

            <h2><?php esc_html_e( 'API Credentials', 'hail-mail-connect' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Use the dedicated studio-whitelisted Hail application (separate from Hail Connect). Callback URL:', 'hail-mail-connect' ); ?>
                <code><?php echo esc_html( $api->get_callback_url() ); ?></code>
            </p>
            <form method="post" action="options.php">
                <?php settings_fields( 'hail_mail_connect_settings_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="hmc_client_id"><?php esc_html_e( 'Client ID', 'hail-mail-connect' ); ?></label></th>
                        <td><input type="text" id="hmc_client_id" name="<?php echo esc_attr( HAIL_MAIL_CONNECT_SETTINGS_KEY ); ?>[client_id]" value="<?php echo esc_attr( $settings['client_id'] ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hmc_client_secret"><?php esc_html_e( 'Client Secret', 'hail-mail-connect' ); ?></label></th>
                        <td><input type="password" id="hmc_client_secret" name="<?php echo esc_attr( HAIL_MAIL_CONNECT_SETTINGS_KEY ); ?>[client_secret]" value="<?php echo esc_attr( $settings['client_secret'] ?? '' ); ?>" class="regular-text" autocomplete="off" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hmc_org_id"><?php esc_html_e( 'Organisation ID', 'hail-mail-connect' ); ?></label></th>
                        <td><input type="text" id="hmc_org_id" name="<?php echo esc_attr( HAIL_MAIL_CONNECT_SETTINGS_KEY ); ?>[organisation_id]" value="<?php echo esc_attr( $settings['organisation_id'] ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Mailing Lists browse — read-only. Stub for now; the next milestone renders the
     * org's lists (GET organisations/{org}/mail/lists) with subscriber counts, each
     * linking to ?page=hail-mail-connect&list_id={id} for the subscribers view.
     */
    public function render_lists_page() {
        $api = Hail_Mail_Connect::instance()->api;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Hail Mail — Mailing Lists', 'hail-mail-connect' ); ?></h1>
            <?php if ( ! $api->is_connected() ) : ?>
                <div class="notice notice-warning"><p>
                    <?php
                    printf(
                        /* translators: %s: settings page URL */
                        wp_kses_post( __( 'Not connected to Hail. <a href="%s">Open Settings</a> to connect.', 'hail-mail-connect' ) ),
                        esc_url( admin_url( 'admin.php?page=hail-mail-connect-settings' ) )
                    );
                    ?>
                </p></div>
            <?php else : ?>
                <p><em><?php esc_html_e( 'Mailing list browsing is being built. Connection is live.', 'hail-mail-connect' ); ?></em></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
