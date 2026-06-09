<?php
/**
 * Plugin Name: Hail Mail Connect
 * Description: Connects WordPress with the Hail CMS platform to browse mailing lists, match subscribers against WP users, and let logged-in users self-manage their subscriptions.
 * Version: 0.1.5
 * Author: Hail
 * Author URI: https://get.hail.to/
 * Text Domain: hail-mail-connect
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'HAIL_MAIL_CONNECT_VERSION', '0.1.5' );
define( 'HAIL_MAIL_CONNECT_PATH', plugin_dir_path( __FILE__ ) );
define( 'HAIL_MAIL_CONNECT_URL', plugin_dir_url( __FILE__ ) );
define( 'HAIL_MAIL_CONNECT_SETTINGS_KEY', 'hail_mail_connect_settings' );
define( 'HAIL_MAIL_CONNECT_TOKENS_KEY', 'hail_mail_connect_tokens' );

require_once HAIL_MAIL_CONNECT_PATH . 'includes/class-hail-mail-connect-api.php';
require_once HAIL_MAIL_CONNECT_PATH . 'includes/class-hail-mail-connect-settings.php';
require_once HAIL_MAIL_CONNECT_PATH . 'includes/class-hail-mail-connect-lists.php';
require_once HAIL_MAIL_CONNECT_PATH . 'includes/class-hail-mail-connect-shortcodes.php';
require_once HAIL_MAIL_CONNECT_PATH . 'includes/class-hail-mail-connect-updater.php';

/**
 * Main plugin singleton. Mirrors Hail Connect's bootstrap so the two plugins share
 * a familiar shape, but the moving parts diverge: no routes, no shortcodes-for-content,
 * no publications — this plugin is about mailing lists and subscribers.
 */
class Hail_Mail_Connect {

    private static $instance = null;

    /** @var Hail_Mail_Connect_API */
    public $api;

    /** @var Hail_Mail_Connect_Settings */
    public $settings;

    /** @var Hail_Mail_Connect_Lists */
    public $lists;

    /** @var Hail_Mail_Connect_Shortcodes */
    public $shortcodes;

    /** @var Hail_Mail_Connect_Updater */
    public $updater;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api        = new Hail_Mail_Connect_API();
        $this->settings   = new Hail_Mail_Connect_Settings();
        $this->lists      = new Hail_Mail_Connect_Lists();
        $this->shortcodes = new Hail_Mail_Connect_Shortcodes();
        $this->updater    = new Hail_Mail_Connect_Updater( __FILE__ );

        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
    }

    /**
     * Register the dedicated "Hail Mail" admin menu.
     *
     * Top-level item opens the Mailing Lists browse (the primary view); clicking a
     * list there drills into its subscribers. Settings (the OAuth connection) is a
     * submenu, mirroring Hail Connect.
     */
    public function add_admin_menu() {
        // Use the bundled SVG (FontAwesome "envelopes-bulk") as the menu icon. Passed
        // as a base64 data URI with fill="currentColor" so WordPress recolours it to
        // match the admin scheme. Falls back to a dashicon if the file is unreadable.
        $icon     = 'dashicons-email-alt';
        $svg_path = HAIL_MAIL_CONNECT_PATH . 'assets/img/menu-icon.svg';
        if ( is_readable( $svg_path ) ) {
            $icon = 'data:image/svg+xml;base64,' . base64_encode( file_get_contents( $svg_path ) );
        }

        add_menu_page(
            __( 'Hail Mail', 'hail-mail-connect' ),
            __( 'Hail Mail', 'hail-mail-connect' ),
            'manage_options',
            'hail-mail-connect',
            array( $this->lists, 'render_page' ),
            $icon,
            31
        );

        add_submenu_page(
            'hail-mail-connect',
            __( 'Mailing Lists', 'hail-mail-connect' ),
            __( 'Mailing Lists', 'hail-mail-connect' ),
            'manage_options',
            'hail-mail-connect',
            array( $this->lists, 'render_page' )
        );

        add_submenu_page(
            'hail-mail-connect',
            __( 'Settings', 'hail-mail-connect' ),
            __( 'Settings', 'hail-mail-connect' ),
            'manage_options',
            'hail-mail-connect-settings',
            array( $this->settings, 'render_settings_page' )
        );
    }

    /**
     * Enqueue admin assets on this plugin's pages only.
     */
    public function enqueue_admin_assets( $hook ) {
        if ( ! in_array( $hook, array( 'toplevel_page_hail-mail-connect', 'hail-mail_page_hail-mail-connect-settings' ), true ) ) {
            return;
        }

        wp_enqueue_style(
            'hail-mail-connect-admin',
            HAIL_MAIL_CONNECT_URL . 'assets/css/admin.css',
            array(),
            HAIL_MAIL_CONNECT_VERSION
        );

        wp_enqueue_script(
            'hail-mail-connect-admin-js',
            HAIL_MAIL_CONNECT_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            HAIL_MAIL_CONNECT_VERSION,
            true
        );

        wp_localize_script( 'hail-mail-connect-admin-js', 'hailMailConnectAdmin', array(
            'nonce'       => wp_create_nonce( 'hail_mail_connect_admin' ),
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'isConnected' => $this->api->is_connected(),
        ) );
    }

    /**
     * Activation: seed default settings.
     */
    public static function activate() {
        if ( ! get_option( HAIL_MAIL_CONNECT_SETTINGS_KEY ) ) {
            add_option( HAIL_MAIL_CONNECT_SETTINGS_KEY, array(
                'client_id'       => '',
                'client_secret'   => '',
                'organisation_id' => '',
            ) );
        }
    }
}

register_activation_hook( __FILE__, array( 'Hail_Mail_Connect', 'activate' ) );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function( $links ) {
    $settings_link = '<a href="' . admin_url( 'admin.php?page=hail-mail-connect-settings' ) . '">' . __( 'Settings', 'hail-mail-connect' ) . '</a>';
    array_unshift( $links, $settings_link );
    return $links;
} );

Hail_Mail_Connect::instance();
