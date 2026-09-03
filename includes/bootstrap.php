<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function pstn_get_default_settings(): array {
	return [
		'subscribe_slug'        => 'subscribe',
		'subscriptions_base'    => 'subscriptions',
		'confirm_slug'          => 'confirm',
		'unsubscribe_slug'      => 'unsubscribe',
		'batch_size'            => 50,
		'send_delay'            => 60,
		'notification_subject'  => 'New Post Published: {post_title}',
		'confirmation_subject'  => 'Opt in to post notifications',
		'from_name'             => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
		'from_email'            => get_option( 'admin_email' ),
		'show_dashboard_widget' => 1,
	];
}

function pstn_get_settings(): array {
	return wp_parse_args( get_option( PSTN_OPTION_KEY, [] ), pstn_get_default_settings() );
}

function pstn_get_setting( string $key ) {
	$settings = pstn_get_settings();
	return $settings[ $key ] ?? null;
}

function pstn_get_batch_size(): int {
	return max( 1, (int) pstn_get_setting( 'batch_size' ) );
}

function pstn_get_send_delay(): int {
	return max( 10, (int) pstn_get_setting( 'send_delay' ) );
}

function pstn_get_subscribe_slug(): string {
	return sanitize_title( (string) pstn_get_setting( 'subscribe_slug' ) );
}

function pstn_get_subscriptions_base(): string {
	return sanitize_title( (string) pstn_get_setting( 'subscriptions_base' ) );
}

function pstn_get_confirm_slug(): string {
	return sanitize_title( (string) pstn_get_setting( 'confirm_slug' ) );
}

function pstn_get_unsubscribe_slug(): string {
	return sanitize_title( (string) pstn_get_setting( 'unsubscribe_slug' ) );
}

function pstn_get_subscribe_url(): string {
	return home_url( '/' . pstn_get_subscribe_slug() . '/' );
}

function pstn_get_token_url( string $action, string $token ): string {
	$base = pstn_get_subscriptions_base();
	$slug = 'confirm' === $action ? pstn_get_confirm_slug() : pstn_get_unsubscribe_slug();
	return home_url( '/' . $base . '/' . $slug . '/' . rawurlencode( $token ) . '/' );
}

function pstn_get_subscribers_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . 'pstn_subscribers';
}

function pstn_install_table(): void {
	global $wpdb;

	$table_name      = pstn_get_subscribers_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		email varchar(190) NOT NULL,
		status varchar(20) NOT NULL DEFAULT 'pending',
		token varchar(64) NOT NULL,
		source varchar(100) NOT NULL DEFAULT 'website',
		created_at datetime NOT NULL,
		confirmed_at datetime NULL DEFAULT NULL,
		unsubscribed_at datetime NULL DEFAULT NULL,
		last_sent_at datetime NULL DEFAULT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY email (email),
		UNIQUE KEY token (token),
		KEY status (status)
	) {$charset_collate};";

	dbDelta( $sql );
	update_option( 'pstn_table_version', PSTN_TABLE_VERSION );
}

function pstn_register_rewrite_rules(): void {
	add_rewrite_rule(
		'^' . preg_quote( pstn_get_subscriptions_base(), '/' ) . '/' . preg_quote( pstn_get_confirm_slug(), '/' ) . '/([A-Za-z0-9]{64})/?$',
		'index.php?pstn_action=confirm&token=$matches[1]',
		'top'
	);

	add_rewrite_rule(
		'^' . preg_quote( pstn_get_subscriptions_base(), '/' ) . '/' . preg_quote( pstn_get_unsubscribe_slug(), '/' ) . '/([A-Za-z0-9]{64})/?$',
		'index.php?pstn_action=unsubscribe&token=$matches[1]',
		'top'
	);

	add_rewrite_rule(
		'^' . preg_quote( pstn_get_subscribe_slug(), '/' ) . '/?$',
		'index.php?pstn_virtual=subscribe',
		'top'
	);
}

function pstn_activate_plugin(): void {
	pstn_install_table();
	pstn_register_rewrite_rules();
	flush_rewrite_rules();
}

function pstn_deactivate_plugin(): void {
	flush_rewrite_rules();
}

register_activation_hook( PSTN_PLUGIN_FILE, 'pstn_activate_plugin' );
register_deactivation_hook( PSTN_PLUGIN_FILE, 'pstn_deactivate_plugin' );

function pstn_maybe_install_table(): void {
	if ( PSTN_TABLE_VERSION !== get_option( 'pstn_table_version' ) ) {
		pstn_install_table();
	}
}

add_action( 'plugins_loaded', 'pstn_maybe_install_table' );

add_action( 'init', 'pstn_register_rewrite_rules' );

add_filter(
	'query_vars',
	static function ( array $vars ): array {
		$vars[] = 'pstn_action';
		$vars[] = 'token';
		$vars[] = 'pstn_virtual';
		return $vars;
	}
);

