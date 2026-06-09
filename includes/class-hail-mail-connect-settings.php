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

        foreach ( array( 'client_id', 'client_secret', 'organisation_id', 'github_repo', 'github_token' ) as $key ) {
            if ( isset( $input[ $key ] ) ) {
                $clean[ $key ] = sanitize_text_field( $input[ $key ] );
            }
        }
        // Opt-in studio scope. Only safe to enable once Hail has whitelisted this
        // client_id — otherwise the authorize request is rejected with 401 and the
        // connection can't complete.
        $clean['request_studio'] = empty( $input['request_studio'] ) ? 0 : 1;
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
        <div class="wrap hmc">
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
                    <button type="button" class="button button-primary" id="hail-mail-connect-test"><?php esc_html_e( 'Test Connection', 'hail-mail-connect' ); ?></button>
                    <a href="<?php echo esc_url( $disconnect_url ); ?>" class="button button-primary"><?php esc_html_e( 'Disconnect', 'hail-mail-connect' ); ?></a>
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

            <div class="hmc-card" style="max-width:1024px;margin:0 0 20px;padding:14px 18px;">
                <p style="margin:0 0 6px;font-weight:600;color:var(--hmc-ink,#2f3e4d);"><?php esc_html_e( 'Studio scope', 'hail-mail-connect' ); ?></p>
                <p style="margin:0;font-size:13px;color:#50575e;"><?php esc_html_e( 'Leave the “Studio scope” option below OFF until Hail has whitelisted this Client ID for it. If you enable it beforehand, Hail rejects the entire authorisation (HTTP 401) and the connect flow bounces back to the login screen. Connect now with it off, then enable it and reconnect once whitelisting is confirmed.', 'hail-mail-connect' ); ?></p>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( 'hail_mail_connect_settings_group' ); ?>

                <div class="hmc-section">
                <h2><?php esc_html_e( 'API Credentials', 'hail-mail-connect' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Use the dedicated studio-whitelisted Hail application (separate from Hail Connect). Callback URL:', 'hail-mail-connect' ); ?>
                    <code><?php echo esc_html( $api->get_callback_url() ); ?></code>
                </p>
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
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Studio scope', 'hail-mail-connect' ); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr( HAIL_MAIL_CONNECT_SETTINGS_KEY ); ?>[request_studio]" value="1" <?php checked( ! empty( $settings['request_studio'] ) ); ?> />
                                <?php esc_html_e( 'Request the studio scope (admin add-without-opt-in)', 'hail-mail-connect' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                </div>

                <div class="hmc-section">
                <h2><?php esc_html_e( 'Updates', 'hail-mail-connect' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Enable one-click updates from the plugin\'s GitHub releases. The access token is only needed for a private repository (fine-grained PAT, Contents: Read-only).', 'hail-mail-connect' ); ?></p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="hmc_github_repo"><?php esc_html_e( 'GitHub Repository', 'hail-mail-connect' ); ?></label></th>
                        <td>
                            <input type="text" id="hmc_github_repo" name="<?php echo esc_attr( HAIL_MAIL_CONNECT_SETTINGS_KEY ); ?>[github_repo]" value="<?php echo esc_attr( $settings['github_repo'] ?? '' ); ?>" class="regular-text" placeholder="BHBradley/hail-mail-connect" />
                            <p class="description"><?php esc_html_e( 'owner/repo format. Leave blank to disable update checks.', 'hail-mail-connect' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="hmc_github_token"><?php esc_html_e( 'GitHub Access Token', 'hail-mail-connect' ); ?></label></th>
                        <td><input type="password" id="hmc_github_token" name="<?php echo esc_attr( HAIL_MAIL_CONNECT_SETTINGS_KEY ); ?>[github_token]" value="<?php echo esc_attr( $settings['github_token'] ?? '' ); ?>" class="regular-text" autocomplete="off" /></td>
                    </tr>
                </table>
                </div>

                <?php submit_button(); ?>
            </form>

            <div class="hmc-section">
                <h2><?php esc_html_e( 'Shortcode', 'hail-mail-connect' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Add a self-service subscription form to any page or post. Logged-in members manage their own list subscriptions; logged-out visitors see a WordPress login form.', 'hail-mail-connect' ); ?></p>
                <p><code>[hail_mail_subscribe]</code></p>
                <table class="widefat striped" style="max-width:760px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Attribute', 'hail-mail-connect' ); ?></th>
                            <th><?php esc_html_e( 'Default', 'hail-mail-connect' ); ?></th>
                            <th><?php esc_html_e( 'Description', 'hail-mail-connect' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>lists</code></td>
                            <td><em><?php esc_html_e( 'all subscribable', 'hail-mail-connect' ); ?></em></td>
                            <td><?php esc_html_e( 'Comma-separated Hail list IDs to offer. Omit to show all subscribable lists.', 'hail-mail-connect' ); ?></td>
                        </tr>
                        <tr>
                            <td><code>title</code></td>
                            <td><?php echo esc_html__( 'Manage your email subscriptions', 'hail-mail-connect' ); ?></td>
                            <td><?php esc_html_e( 'Heading shown above the form.', 'hail-mail-connect' ); ?></td>
                        </tr>
                        <tr>
                            <td><code>button</code></td>
                            <td><?php echo esc_html__( 'Save preferences', 'hail-mail-connect' ); ?></td>
                            <td><?php esc_html_e( 'Submit button label.', 'hail-mail-connect' ); ?></td>
                        </tr>
                    </tbody>
                </table>
                <p class="description"><?php esc_html_e( 'Example:', 'hail-mail-connect' ); ?> <code>[hail_mail_subscribe title="Newsletters" button="Update"]</code></p>
            </div>

            <?php
            $preview_client = $settings['client_id'] ?? '';
            $preview_scope  = $api->get_requested_scope();
            ?>
            <div class="hmc-card" style="max-width:760px;margin-top:6px;padding:14px 16px;">
                <p style="margin:0 0 6px;font-weight:600;color:var(--hmc-ink,#2f3e4d);"><?php esc_html_e( 'Authorisation request preview', 'hail-mail-connect' ); ?></p>
                <p style="margin:0;font-size:13px;color:#50575e;">
                    <?php esc_html_e( 'Client ID:', 'hail-mail-connect' ); ?>
                    <code><?php echo esc_html( '' !== $preview_client ? $preview_client : __( '(not set)', 'hail-mail-connect' ) ); ?></code><br>
                    <?php esc_html_e( 'Requested scope:', 'hail-mail-connect' ); ?>
                    <code><?php echo esc_html( $preview_scope ); ?></code>
                </p>
                <p style="margin:8px 0 0;font-size:12px;color:#b32d2e;">
                    <?php esc_html_e( 'For the studio scope to be granted, Hail’s STUDIO_CLIENT_ID must equal the Client ID above exactly (no extra spaces, same case). Send this exact value to the Hail team to confirm the match.', 'hail-mail-connect' ); ?>
                </p>
            </div>

            <details class="hmc-troubleshoot" style="margin-top:18px;max-width:760px;">
                <summary style="cursor:pointer;font-weight:600;color:var(--hmc-ink,#2f3e4d);"><?php esc_html_e( 'Studio scope troubleshooting', 'hail-mail-connect' ); ?></summary>
                <div style="margin-top:10px;border-left:3px solid #e7eaed;padding:4px 0 4px 14px;color:#50575e;font-size:13px;">
                    <p><strong><?php esc_html_e( 'Symptom:', 'hail-mail-connect' ); ?></strong>
                        <?php esc_html_e( 'with the Studio scope enabled, clicking “Connect to Hail” briefly shows the authorise screen then bounces to the Hail login page — even when you are already logged in.', 'hail-mail-connect' ); ?></p>

                    <p><strong><?php esc_html_e( 'Cause:', 'hail-mail-connect' ); ?></strong>
                        <?php esc_html_e( 'Hail only allows the studio scope for ONE whitelisted OAuth client. If studio is requested by any other client, Hail returns HTTP 401 “Invalid Client Id”, and its login app reads that 401 as a lost session — so you land on the login screen.', 'hail-mail-connect' ); ?></p>

                    <p><strong><?php esc_html_e( 'How to fix:', 'hail-mail-connect' ); ?></strong></p>
                    <ul style="list-style:disc;margin:0 0 0 18px;">
                        <li><?php esc_html_e( 'The Client ID above must EXACTLY match the value Hail set as STUDIO_CLIENT_ID — it is a single value, so only one app can hold studio.', 'hail-mail-connect' ); ?></li>
                        <li><?php esc_html_e( 'Ask the Hail team to confirm they cleared the config cache after setting it (php artisan config:clear) and redeployed — a common reason the change does not take effect.', 'hail-mail-connect' ); ?></li>
                        <li><?php esc_html_e( 'You can always connect with the Studio scope OFF: browsing, self-service subscribe/unsubscribe, and admin remove all work on content.read + content.write. Only “add a subscriber without opt-in” needs studio.', 'hail-mail-connect' ); ?></li>
                    </ul>
                </div>
            </details>
        </div>
        <?php
    }

}
