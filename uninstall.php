<?php
/**
 * Uninstall cleanup for Hail Mail Connect.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'hail_mail_connect_settings' );
delete_option( 'hail_mail_connect_tokens' );
