<?php
/**
 * Plugin Name: Post Notifications - Email Subscription
 * Description: Automatically emails subscribers when new posts are published.
 * Version: 2.0.0
 * Author: Hamish Wright
 * Author URI: https://github.com/HamishW5-46/Post-Notifications-Email-Subscription
 * License: GPL2+
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PSTN_TOKEN_LENGTH       = 64;
const PSTN_SENT_META          = '_pstn_notification_sent';
const PSTN_CRON_HOOK          = 'pstn_send_post_notifications';
const PSTN_BATCH_OFFSET_META  = '_pstn_notification_offset';
const PSTN_OPTION_KEY         = 'pstn_settings';
const PSTN_TABLE_VERSION      = '1.0';

define( 'PSTN_PLUGIN_FILE', __FILE__ );
define( 'PSTN_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

$pstn_includes = array(
	'includes/bootstrap.php',
	'includes/mail.php',
	'includes/subscribers.php',
	'includes/notifications.php',
	'includes/public.php',
	'includes/admin.php',
);

foreach ( $pstn_includes as $pstn_include ) {
	require_once PSTN_PLUGIN_DIR . $pstn_include;
}

function pstn_frontend_styles() {
    $css_url = plugins_url( 'assets/css/style.css', __FILE__ );

    wp_enqueue_style(
        'pstn-frontend-style',
        $css_url,
        array(),
        '1.0.0',
        'all'
    );
}

add_action( 'wp_enqueue_scripts', 'pstn_frontend_styles' );
