<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hail Mail Connect API client.
 *
 * Ported from Hail_Connect_API — same OAuth2 Authorization Code flow, lazy refresh,
 * 401-retry, and refresh-token rotation handling. Divergences:
 *   - Requests the write/studio scopes this plugin needs, and CAPTURES granted scopes
 *     so the UI can gate write actions on what was actually granted.
 *   - request() is generalised to GET/POST/PUT/DELETE (Hail Connect was GET-only).
 *   - Endpoints are mailing-list / subscriber focused, not articles/publications.
 *   - No health/cache dependencies (deferred for this plugin).
 */
class Hail_Mail_Connect_API {

    const API_BASE      = 'https://hail.to/api/v1/';
    const AUTHORIZE_URL = 'https://hail.to/oauth/authorise';
    const TOKEN_URL     = 'https://hail.to/api/v1/oauth/access_token';

    /**
     * Base scopes always requested at connect time:
     *   - content.read   : browse mail lists + subscribers
     *   - content.write  : self-service subscribe/unsubscribe, admin remove-from-list
     */
    const BASE_SCOPE = 'user.basic content.read content.write';

    /**
     * The studio scope (admin add-without-opt-in, consent bypass) is OPT-IN.
     *
     * Hail rejects the ENTIRE authorize request with 401 "Invalid Client Id" if studio
     * is requested by a client that isn't whitelisted in config('studio.client_id')
     * (AuthCodeController::authorize). Hail's authorize SPA reads that 401 as a lost
     * session and bounces to login — so requesting studio prematurely makes connection
     * impossible. We therefore only append it when the admin has confirmed (via the
     * `request_studio` setting) that their client is whitelisted.
     */
    const STUDIO_SCOPE = 'studio';

    public function __construct() {
        add_action( 'admin_init', array( $this, 'handle_oauth_callback' ) );
        add_action( 'admin_init', array( $this, 'handle_disconnect' ) );
    }

    public function get_settings() {
        return get_option( HAIL_MAIL_CONNECT_SETTINGS_KEY, array(
            'client_id'       => '',
            'client_secret'   => '',
            'organisation_id' => '',
        ) );
    }

    public function get_tokens() {
        return get_option( HAIL_MAIL_CONNECT_TOKENS_KEY, array(
            'access_token'   => '',
            'refresh_token'  => '',
            'expires_at'     => 0,
            'granted_scopes' => array(),
        ) );
    }

    public function is_connected() {
        $tokens = $this->get_tokens();
        return ! empty( $tokens['access_token'] ) && ! empty( $tokens['refresh_token'] );
    }

    /**
     * Scopes actually granted on the current connection (captured from the token
     * response). Empty array if never connected or the field is missing (legacy).
     */
    public function get_granted_scopes() {
        $tokens = $this->get_tokens();
        $scopes = $tokens['granted_scopes'] ?? array();
        return is_array( $scopes ) ? $scopes : array();
    }

    /**
     * Whether the current connection holds a given scope. Used to enable/disable
     * write UI: content.write gates self-service + admin remove; studio gates the
     * admin add-without-opt-in button.
     */
    public function has_scope( $scope ) {
        return in_array( $scope, $this->get_granted_scopes(), true );
    }

    public function get_callback_url() {
        return admin_url( 'admin.php?page=hail-mail-connect-settings' );
    }

    /**
     * The scope string to request. Always the base scopes; studio is appended only
     * when the admin has flagged their client as studio-whitelisted (see STUDIO_SCOPE).
     */
    public function get_requested_scope() {
        $settings = $this->get_settings();
        $scope    = self::BASE_SCOPE;
        if ( ! empty( $settings['request_studio'] ) ) {
            $scope .= ' ' . self::STUDIO_SCOPE;
        }
        return $scope;
    }

    public function get_authorize_url() {
        $settings = $this->get_settings();
        $state    = wp_create_nonce( 'hail_mail_connect_oauth' );

        return add_query_arg( array(
            'client_id'     => $settings['client_id'],
            'redirect_uri'  => urlencode( $this->get_callback_url() ),
            'response_type' => 'code',
            'scope'         => $this->get_requested_scope(),
            'state'         => $state,
        ), self::AUTHORIZE_URL );
    }

    /**
     * Flatten an OAuth error body (which may be string OR array) to a readable string.
     * Mirrors Hail_Connect_API::extract_error_message — avoids the "Array" bug.
     */
    private static function extract_error_message( $body, $fallback ) {
        if ( is_array( $body ) ) {
            foreach ( array( 'error_description', 'error' ) as $key ) {
                if ( ! isset( $body[ $key ] ) ) {
                    continue;
                }
                $val = $body[ $key ];
                if ( is_array( $val ) ) {
                    $parts = array();
                    array_walk_recursive( $val, function ( $v ) use ( &$parts ) {
                        if ( is_scalar( $v ) ) {
                            $parts[] = (string) $v;
                        }
                    } );
                    $val = implode( '; ', $parts );
                }
                if ( is_string( $val ) && '' !== trim( $val ) ) {
                    return $val;
                }
            }
        }
        return $fallback;
    }

    /**
     * Normalise a scope value from a token response (space-delimited string or array)
     * into an array of scope ids.
     */
    private static function parse_scopes( $raw ) {
        if ( is_array( $raw ) ) {
            return array_values( array_filter( array_map( 'strval', $raw ) ) );
        }
        if ( is_string( $raw ) && '' !== trim( $raw ) ) {
            return array_values( array_filter( preg_split( '/\s+/', trim( $raw ) ) ) );
        }
        return array();
    }

    /**
     * Handle the OAuth callback when Hail redirects back with an authorization code.
     */
    public function handle_oauth_callback() {
        if ( ! isset( $_GET['page'] ) || 'hail-mail-connect-settings' !== $_GET['page'] ) {
            return;
        }
        if ( ! isset( $_GET['code'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! isset( $_GET['state'] ) || ! wp_verify_nonce( $_GET['state'], 'hail_mail_connect_oauth' ) ) {
            add_settings_error( HAIL_MAIL_CONNECT_SETTINGS_KEY, 'oauth_state', __( 'OAuth state verification failed. Please try again.', 'hail-mail-connect' ), 'error' );
            return;
        }

        $code     = sanitize_text_field( $_GET['code'] );
        $settings = $this->get_settings();

        $response = wp_remote_post( self::TOKEN_URL, array(
            'timeout' => 15,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array(
                'client_id'     => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $this->get_callback_url(),
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'Hail Mail Connect: Token exchange failed — ' . $response->get_error_message() );
            add_settings_error( HAIL_MAIL_CONNECT_SETTINGS_KEY, 'oauth_exchange', __( 'Failed to connect: ', 'hail-mail-connect' ) . $response->get_error_message(), 'error' );
            return;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) || empty( $body['refresh_token'] ) ) {
            error_log( 'Hail Mail Connect: Token exchange response invalid — ' . wp_json_encode( $body ) );
            $error_msg = self::extract_error_message( $body, __( 'Invalid response from Hail.', 'hail-mail-connect' ) );
            add_settings_error( HAIL_MAIL_CONNECT_SETTINGS_KEY, 'oauth_exchange', __( 'Failed to connect: ', 'hail-mail-connect' ) . $error_msg, 'error' );
            return;
        }

        $tokens = array(
            'access_token'   => $body['access_token'],
            'refresh_token'  => $body['refresh_token'],
            'expires_at'     => time() + intval( $body['expires_in'] ),
            // Capture what was actually granted. If Hail omits `scope` on success it
            // means the full request was granted, so fall back to the requested set.
            'granted_scopes' => self::parse_scopes( $body['scope'] ?? $this->get_requested_scope() ),
        );

        update_option( HAIL_MAIL_CONNECT_TOKENS_KEY, $tokens );
        add_settings_error( HAIL_MAIL_CONNECT_SETTINGS_KEY, 'oauth_success', __( 'Successfully connected to Hail!', 'hail-mail-connect' ), 'success' );

        wp_safe_redirect( $this->get_callback_url() . '&hail_mail_connected=1' );
        exit;
    }

    public function handle_disconnect() {
        if ( ! isset( $_GET['hail_mail_connect_disconnect'] ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        if ( ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'hail_mail_connect_disconnect' ) ) {
            return;
        }

        delete_option( HAIL_MAIL_CONNECT_TOKENS_KEY );
        delete_option( 'hail_mail_connect_org_slug' );
        add_settings_error( HAIL_MAIL_CONNECT_SETTINGS_KEY, 'disconnected', __( 'Disconnected from Hail.', 'hail-mail-connect' ), 'info' );

        wp_safe_redirect( $this->get_callback_url() );
        exit;
    }

    public function get_access_token() {
        $tokens = $this->get_tokens();

        if ( ! empty( $tokens['access_token'] ) && time() < $tokens['expires_at'] ) {
            return $tokens['access_token'];
        }
        if ( empty( $tokens['refresh_token'] ) ) {
            return false;
        }
        return $this->refresh_token();
    }

    public function refresh_token() {
        $settings = $this->get_settings();
        $tokens   = $this->get_tokens();

        if ( empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) || empty( $tokens['refresh_token'] ) ) {
            error_log( 'Hail Mail Connect: Missing credentials for token refresh.' );
            return false;
        }

        $response = wp_remote_post( self::TOKEN_URL, array(
            'timeout' => 15,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array(
                'client_id'     => $settings['client_id'],
                'client_secret' => $settings['client_secret'],
                'grant_type'    => 'refresh_token',
                'refresh_token' => $tokens['refresh_token'],
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            error_log( 'Hail Mail Connect: Token refresh failed — ' . $response->get_error_message() );
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['access_token'] ) ) {
            error_log( 'Hail Mail Connect: Token refresh response missing access_token — ' . wp_json_encode( $body ) );
            return false;
        }

        $new_tokens = array(
            'access_token'   => $body['access_token'],
            // Hail rotates the refresh token on each use — always store the latest.
            'refresh_token'  => ! empty( $body['refresh_token'] ) ? $body['refresh_token'] : $tokens['refresh_token'],
            'expires_at'     => time() + intval( $body['expires_in'] ),
            // Preserve previously-captured scopes if the refresh response omits them.
            'granted_scopes' => self::parse_scopes( $body['scope'] ?? '' ) ?: $this->get_granted_scopes(),
        );

        update_option( HAIL_MAIL_CONNECT_TOKENS_KEY, $new_tokens );

        return $new_tokens['access_token'];
    }

    /**
     * Make an authenticated request to the Hail API.
     *
     * Generalised over Hail Connect's GET-only client so we can POST/PUT/DELETE for
     * subscriber writes. Returns the decoded body on 2xx, or a WP_Error carrying the
     * HTTP status + raw body on failure.
     *
     * @param string     $endpoint API path relative to API_BASE.
     * @param string     $method   GET|POST|PUT|DELETE.
     * @param array|null $body      Request body (JSON-encoded) for writes.
     * @param bool       $retry     Internal: whether a 401 may trigger one refresh+retry.
     */
    public function request( $endpoint, $method = 'GET', $body = null, $retry = true ) {
        $token = $this->get_access_token();

        if ( ! $token ) {
            return new WP_Error( 'hail_mail_connect_no_token', 'Unable to obtain access token. Please connect to Hail in the plugin settings.' );
        }

        $args = array(
            'method'  => $method,
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ),
        );

        if ( null !== $body ) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body']                    = wp_json_encode( $body );
        }

        $response = wp_remote_request( self::API_BASE . ltrim( $endpoint, '/' ), $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );

        // On 401, force a token refresh and retry once.
        if ( 401 === $code && $retry ) {
            $this->refresh_token();
            return $this->request( $endpoint, $method, $body, false );
        }

        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error(
                'hail_mail_connect_api_error',
                'Hail API returned HTTP ' . $code,
                array( 'status' => $code, 'body' => wp_remote_retrieve_body( $response ) )
            );
        }

        // 204 No Content (e.g. successful DELETE) has no body to decode.
        if ( 204 === $code ) {
            return true;
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    private function require_org() {
        $org_id = $this->get_settings()['organisation_id'] ?? '';
        if ( empty( $org_id ) ) {
            return new WP_Error( 'hail_mail_connect_no_org', 'Organisation ID is not configured.' );
        }
        return $org_id;
    }

    /* ------------------------------------------------------------------ *
     * Reads (scope: content.read)
     * ------------------------------------------------------------------ */

    /** List the organisation's mailing lists. */
    public function get_mail_lists() {
        $org_id = $this->require_org();
        if ( is_wp_error( $org_id ) ) {
            return $org_id;
        }
        return $this->request( 'organisations/' . $org_id . '/mail/lists' );
    }

    /** Get a single mailing list. */
    public function get_mail_list( $list_id ) {
        return $this->request( 'mail/lists/' . sanitize_text_field( $list_id ) );
    }

    /**
     * List subscribers in a mailing list.
     *
     * @param string $list_id
     * @param array  $params  limit, offset, search, status.
     */
    public function get_mail_list_subscribers( $list_id, $params = array() ) {
        $endpoint = 'mail/lists/' . sanitize_text_field( $list_id ) . '/subscribers';
        if ( ! empty( $params ) ) {
            $endpoint .= '?' . http_build_query( $params );
        }
        return $this->request( $endpoint );
    }

    /** Subscriber count for a list (for pagination totals). */
    public function get_mail_list_subscriber_count( $list_id, $params = array() ) {
        $endpoint = 'mail/lists/' . sanitize_text_field( $list_id ) . '/subscribers/count';
        if ( ! empty( $params ) ) {
            $endpoint .= '?' . http_build_query( $params );
        }
        return $this->request( $endpoint );
    }

    /**
     * List org-level subscribers, optionally searched by email — used to resolve a
     * contact (and its current list memberships) from a WP user's email for the
     * self-service shortcode.
     *
     * @param array $params limit, offset, search, status.
     */
    public function get_org_subscribers( $params = array() ) {
        $org_id = $this->require_org();
        if ( is_wp_error( $org_id ) ) {
            return $org_id;
        }
        $endpoint = 'organisations/' . $org_id . '/mail/subscribers';
        if ( ! empty( $params ) ) {
            $endpoint .= '?' . http_build_query( $params );
        }
        return $this->request( $endpoint );
    }

    /**
     * Find a contact by exact email and return its record (including `lists`
     * memberships), or null if not found. Returns WP_Error on API failure.
     *
     * Used by the self-service shortcode to resolve the logged-in user's contact id
     * and current list memberships. Makes a live call per render — fine for a
     * subscription-management page; cache per-user with a short TTL if it ever lands
     * on a high-traffic template.
     */
    public function find_contact_by_email( $email ) {
        $email = sanitize_email( $email );
        if ( empty( $email ) ) {
            return null;
        }
        $resp = $this->get_org_subscribers( array( 'search' => $email, 'limit' => 50 ) );
        if ( is_wp_error( $resp ) ) {
            return $resp;
        }
        $list = ( isset( $resp['data'] ) && is_array( $resp['data'] ) ) ? $resp['data'] : ( is_array( $resp ) ? $resp : array() );
        foreach ( $list as $contact ) {
            if ( isset( $contact['email'] ) && strtolower( $contact['email'] ) === strtolower( $email ) ) {
                return $contact;
            }
        }
        return null;
    }

    /**
     * Resolve the organisation SLUG (e.g. "auckland-primary-principals-association")
     * for the configured organisation ID. The Hail web app addresses orgs by slug,
     * but we only store the ID — so look it up via the user's organisations list
     * (covered by user.basic, no extra scope needed) and cache it, since it rarely
     * changes. Returns '' if it can't be resolved.
     */
    public function get_organisation_slug() {
        $org_id = $this->get_settings()['organisation_id'] ?? '';
        if ( empty( $org_id ) ) {
            return '';
        }

        $cache = get_option( 'hail_mail_connect_org_slug', array() );
        if ( is_array( $cache ) && ( $cache['org_id'] ?? '' ) === $org_id && ! empty( $cache['slug'] ) ) {
            return $cache['slug'];
        }

        $me = $this->request( 'me' );
        if ( is_wp_error( $me ) ) {
            return '';
        }
        $uid = $me['id'] ?? ( $me['data']['id'] ?? '' );
        if ( empty( $uid ) ) {
            return '';
        }

        $orgs = $this->request( 'users/' . rawurlencode( $uid ) . '/organisations' );
        if ( is_wp_error( $orgs ) ) {
            return '';
        }
        $list = ( isset( $orgs['data'] ) && is_array( $orgs['data'] ) ) ? $orgs['data'] : ( is_array( $orgs ) ? $orgs : array() );

        foreach ( $list as $org ) {
            if ( (string) ( $org['id'] ?? '' ) === (string) $org_id ) {
                $slug = $org['slug'] ?? '';
                if ( $slug ) {
                    update_option( 'hail_mail_connect_org_slug', array( 'org_id' => $org_id, 'slug' => $slug ), false );
                }
                return $slug;
            }
        }
        return '';
    }

    /** Deep link to this organisation's Hail Mail dashboard, or '' if slug unresolved. */
    public function get_hail_mail_dashboard_url() {
        $slug = $this->get_organisation_slug();
        return $slug ? 'https://hail.to/app/' . $slug . '/mail' : '';
    }

    /* ------------------------------------------------------------------ *
     * Writes (scope: content.write) — callers must gate on has_scope('content.write')
     * ------------------------------------------------------------------ */

    /**
     * Create a subscriber in the organisation and attach to list(s). Used by the
     * self-service shortcode where the WP user genuinely consents, so gave_consent
     * defaults to true. NEVER call with an email sourced from request input for
     * another user — pass the current user's own email.
     *
     * @param string   $email
     * @param string   $first_name
     * @param string   $last_name
     * @param string[] $list_ids
     * @param bool     $gave_consent
     */
    public function create_subscriber( $email, $first_name = '', $last_name = '', $list_ids = array(), $gave_consent = true ) {
        $org_id = $this->require_org();
        if ( is_wp_error( $org_id ) ) {
            return $org_id;
        }
        $lists = array();
        foreach ( (array) $list_ids as $id ) {
            $lists[] = array( 'id' => sanitize_text_field( $id ) );
        }
        return $this->request(
            'organisations/' . $org_id . '/mail/subscribers',
            'POST',
            array(
                'email'        => sanitize_email( $email ),
                'first_name'   => sanitize_text_field( $first_name ),
                'last_name'    => sanitize_text_field( $last_name ),
                'gave_consent' => (bool) $gave_consent,
                'lists'        => $lists,
            )
        );
    }

    /** Add already-existing contacts to a list (PUT mail/lists/{id}/subscribers). */
    public function add_existing_subscribers_to_list( $list_id, $contact_ids ) {
        return $this->request(
            'mail/lists/' . sanitize_text_field( $list_id ) . '/subscribers',
            'PUT',
            array( 'contact_ids' => implode( ',', array_map( 'sanitize_text_field', (array) $contact_ids ) ) )
        );
    }

    /** Remove contacts from a list (DELETE mail/lists/{id}/subscribers). content.write — no studio needed. */
    public function remove_subscribers_from_list( $list_id, $contact_ids ) {
        return $this->request(
            'mail/lists/' . sanitize_text_field( $list_id ) . '/subscribers',
            'DELETE',
            array( 'contact_ids' => implode( ',', array_map( 'sanitize_text_field', (array) $contact_ids ) ) )
        );
    }

    /* ------------------------------------------------------------------ *
     * Writes (scope: studio) — callers must gate on has_scope('studio')
     * ------------------------------------------------------------------ */

    /**
     * Admin bulk-add subscribers to a list, bypassing double-opt-in. The studio
     * endpoint hardcodes consent server-side. Requires a Hail-whitelisted client_id.
     *
     * @param string $list_id
     * @param array  $contacts Array of [ 'email' => , 'first_name' => , 'last_name' => ] (max 100).
     */
    public function studio_add_subscribers( $list_id, $contacts ) {
        return $this->request(
            'studio/mail/lists/' . sanitize_text_field( $list_id ) . '/subscribers',
            'POST',
            array( 'contacts' => $contacts )
        );
    }

    /**
     * Lightweight connection test — lists mail lists (cheap content.read call).
     */
    public function test_connection() {
        $settings = $this->get_settings();
        if ( empty( $settings['client_id'] ) || empty( $settings['organisation_id'] ) ) {
            return new WP_Error( 'hail_mail_connect_not_configured', 'Plugin credentials are not configured.' );
        }
        if ( ! $this->is_connected() ) {
            return new WP_Error( 'hail_mail_connect_not_connected', 'Not connected to Hail. Please authorize first.' );
        }
        $result = $this->get_mail_lists();
        return is_wp_error( $result ) ? $result : true;
    }
}
