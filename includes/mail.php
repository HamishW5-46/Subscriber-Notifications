<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function pstn_generate_token(): string {
	return wp_generate_password( PSTN_TOKEN_LENGTH, false, false );
}

function pstn_render_email_template( string $template, array $vars = [] ): string {
	$path = PSTN_PLUGIN_DIR . 'templates/' . $template . '.php';
	if ( ! file_exists( $path ) ) {
		return '';
	}

	extract( $vars, EXTR_SKIP );
	ob_start();
	include $path;
	return (string) ob_get_clean();
}

function pstn_send_html_mail( string $to, string $subject, string $message ): bool {
	$settings   = pstn_get_settings();
	$from_name  = sanitize_text_field( (string) $settings['from_name'] );
	$from_email = sanitize_email( (string) $settings['from_email'] );

	$content_type_filter = static function (): string {
		return 'text/html';
	};

	$from_name_filter = static function () use ( $from_name ): string {
		return $from_name;
	};

	$from_email_filter = static function () use ( $from_email ): string {
		return $from_email;
	};

	add_filter( 'wp_mail_content_type', $content_type_filter );

	if ( $from_name ) {
		add_filter( 'wp_mail_from_name', $from_name_filter );
	}

	if ( $from_email ) {
		add_filter( 'wp_mail_from', $from_email_filter );
	}

	$sent = wp_mail( $to, $subject, $message );

	remove_filter( 'wp_mail_content_type', $content_type_filter );

	if ( $from_name ) {
		remove_filter( 'wp_mail_from_name', $from_name_filter );
	}

	if ( $from_email ) {
		remove_filter( 'wp_mail_from', $from_email_filter );
	}

	return (bool) $sent;
}

