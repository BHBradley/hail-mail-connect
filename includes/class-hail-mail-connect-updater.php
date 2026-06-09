<?php
/**
 * Plugin self-updater via GitHub Releases.
 *
 * Hooks into WordPress's native update system to check a GitHub repository for new
 * releases and allow one-click updates from the dashboard. Ported from
 * Hail_Connect_Updater — same architecture, namespaced for this plugin.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CONFIGURATION
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * 1. Plugin Settings (wp-admin → Hail Mail → Settings → Updates):
 *    - GitHub Repository: owner/repo (e.g. BHBradley/hail-mail-connect)
 *    - GitHub Access Token: a fine-grained PAT with Contents: Read-only (only needed
 *      for a private repo; public repos work without one, subject to rate limits)
 *
 * 2. Creating a fine-grained PAT:
 *    GitHub → Settings → Developer settings → Personal access tokens → Fine-grained
 *    → Generate new token → Repository access: only the plugin repo → Permissions →
 *    Repository permissions → Contents: Read-only. Copy the token (shown once).
 *
 * 3. Releasing a new version:
 *    a) Bump the Version header and HAIL_MAIL_CONNECT_VERSION in hail-mail-connect.php
 *    b) Commit and merge to main
 *    c) Repo → Releases → Draft a new release → tag (e.g. v0.2.0) → publish
 *    d) Client sites see the update within 12 hours (the cache TTL).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * SECRET STORAGE
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * The token is stored as plain text in wp_options (as WordPress core and most
 * plugins store API credentials). It is Read-only scoped, so a compromised token
 * cannot modify the repo. The Settings page is manage_options only. If encryption
 * is ever required, wrap the value with wp_salt() + openssl_encrypt/decrypt.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hail_Mail_Connect_Updater {

    /** @var string GitHub owner/repo slug (read from settings). */
    private $repo;

    /** @var string Full path to the main plugin file. */
    private $plugin_file;

    /** @var string Plugin basename (e.g. hail-mail-connect/hail-mail-connect.php). */
    private $plugin_slug;

    /** @var string GitHub Personal Access Token (read from settings). */
    private $token;

    /** @var string Option key for caching the GitHub response. */
    private $cache_key = 'hail_mail_connect_github_update';

    /** @var int Cache lifetime in seconds (12 hours). */
    private $cache_ttl = 43200;

    /**
     * @param string $plugin_file Absolute path to the main plugin file.
     */
    public function __construct( $plugin_file ) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename( $plugin_file );

        $settings    = get_option( HAIL_MAIL_CONNECT_SETTINGS_KEY, array() );
        $this->repo  = $settings['github_repo'] ?? '';
        $this->token = $settings['github_token'] ?? '';

        // Only hook into the update system if a repo is configured.
        if ( ! empty( $this->repo ) ) {
            add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );
            add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
            add_filter( 'upgrader_post_install', array( $this, 'after_install' ), 10, 3 );

            // Inject the Authorization header when WordPress downloads from GitHub.
            if ( ! empty( $this->token ) ) {
                add_filter( 'http_request_args', array( $this, 'inject_auth_header' ), 10, 2 );
            }
        }
    }

    /**
     * Get a cached value from wp_options with manual expiry (avoids object-cache
     * transient eviction).
     *
     * @param string $key Option key.
     * @return mixed|false Cached data or false if missing/expired.
     */
    private function cache_get( $key ) {
        $row = get_option( $key, false );

        if ( false === $row || ! is_array( $row ) || ! isset( $row['expires_at'], $row['data'] ) ) {
            return false;
        }

        if ( time() > $row['expires_at'] ) {
            delete_option( $key );
            return false;
        }

        return $row['data'];
    }

    /**
     * Store a value in wp_options with an expiry timestamp.
     *
     * @param string $key  Option key.
     * @param mixed  $data Data to cache.
     * @param int    $ttl  Time to live in seconds.
     */
    private function cache_set( $key, $data, $ttl ) {
        update_option( $key, array(
            'data'       => $data,
            'expires_at' => time() + $ttl,
        ), false );
    }

    /**
     * Fetch the latest release from GitHub (cached).
     *
     * @return object|false Release data or false on failure.
     */
    private function get_latest_release() {
        $cached = $this->cache_get( $this->cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $url = sprintf( 'https://api.github.com/repos/%s/releases/latest', $this->repo );

        $args = array(
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'Hail-Mail-Connect-Updater',
            ),
        );

        if ( ! empty( $this->token ) ) {
            $args['headers']['Authorization'] = 'token ' . $this->token;
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );

        if ( empty( $body->tag_name ) ) {
            return false;
        }

        $this->cache_set( $this->cache_key, $body, $this->cache_ttl );

        return $body;
    }

    /**
     * Normalise a version tag by stripping a leading "v".
     *
     * @param string $tag e.g. "v0.2.0".
     * @return string e.g. "0.2.0".
     */
    private function normalise_version( $tag ) {
        return ltrim( $tag, 'v' );
    }

    /**
     * Build the download URL for the release zipball.
     *
     * @param object $release GitHub release object.
     * @return string Zipball URL (auth is handled via inject_auth_header).
     */
    private function get_download_url( $release ) {
        return $release->zipball_url;
    }

    /**
     * Inject the Authorization header into HTTP requests to the GitHub API.
     *
     * WordPress's upgrader downloads the package URL without auth headers. GitHub
     * requires the token as a header (the access_token query param is deprecated),
     * otherwise private downloads fail.
     *
     * @param array  $args HTTP request arguments.
     * @param string $url  The request URL.
     * @return array Modified arguments.
     */
    public function inject_auth_header( $args, $url ) {
        if ( false !== strpos( $url, 'api.github.com' ) && ! empty( $this->token ) ) {
            $args['headers']['Authorization'] = 'token ' . $this->token;
        }

        return $args;
    }

    /**
     * Inject update information into the WordPress update transient.
     *
     * @param object $transient The update_plugins transient.
     * @return object Modified transient.
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_latest_release();

        if ( false === $release ) {
            return $transient;
        }

        $remote_version  = $this->normalise_version( $release->tag_name );
        $current_version = $transient->checked[ $this->plugin_slug ] ?? HAIL_MAIL_CONNECT_VERSION;

        if ( version_compare( $remote_version, $current_version, '>' ) ) {
            $transient->response[ $this->plugin_slug ] = (object) array(
                'slug'        => dirname( $this->plugin_slug ),
                'plugin'      => $this->plugin_slug,
                'new_version' => $remote_version,
                'url'         => $release->html_url,
                'package'     => $this->get_download_url( $release ),
            );
        }

        return $transient;
    }

    /**
     * Provide plugin details for the "View Details" modal in wp-admin.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( dirname( $this->plugin_slug ) !== ( $args->slug ?? '' ) ) {
            return $result;
        }

        $release = $this->get_latest_release();

        if ( false === $release ) {
            return $result;
        }

        $plugin_data = get_plugin_data( $this->plugin_file );

        return (object) array(
            'name'          => $plugin_data['Name'],
            'slug'          => dirname( $this->plugin_slug ),
            'version'       => $this->normalise_version( $release->tag_name ),
            'author'        => $plugin_data['Author'],
            'homepage'      => $plugin_data['PluginURI'] ?: $release->html_url,
            'requires'      => '6.0',
            'tested'        => '6.7',
            'requires_php'  => '7.4',
            'download_link' => $this->get_download_url( $release ),
            'sections'      => array(
                'description' => $plugin_data['Description'],
                'changelog'   => nl2br( esc_html( $release->body ?? '' ) ),
            ),
        );
    }

    /**
     * After installing a GitHub zipball, rename the extracted folder to match the
     * expected plugin directory name (zipballs extract to "owner-repo-hash/").
     *
     * @param bool  $response
     * @param array $hook_extra
     * @param array $result
     * @return array|WP_Error
     */
    public function after_install( $response, $hook_extra, $result ) {
        if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_slug ) {
            return $result;
        }

        global $wp_filesystem;

        $plugin_dir = WP_PLUGIN_DIR . '/' . dirname( $this->plugin_slug );
        $wp_filesystem->move( $result['destination'], $plugin_dir );
        $result['destination'] = $plugin_dir;

        activate_plugin( $this->plugin_slug );

        return $result;
    }
}
