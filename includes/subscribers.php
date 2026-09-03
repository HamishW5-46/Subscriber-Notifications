<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function pstn_get_subscriber_by_email( string $email ): ?array {
	global $wpdb;

	$table_name = pstn_get_subscribers_table_name();
	$row        = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE email = %s LIMIT 1",
			$email
		),
		ARRAY_A
	);

	return $row ?: null;
}

function pstn_get_subscriber_by_token( string $token ): ?array {
	global $wpdb;

	$table_name = pstn_get_subscribers_table_name();
	$row        = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE token = %s LIMIT 1",
			$token
		),
		ARRAY_A
	);

	return $row ?: null;
}

function pstn_insert_subscriber( string $email, string $status = 'pending', string $source = 'website' ): ?array {
	global $wpdb;

	$table_name = pstn_get_subscribers_table_name();
	$token      = pstn_generate_token();
	$created_at = current_time( 'mysql' );

	$inserted = $wpdb->insert(
		$table_name,
		[
			'email'      => $email,
			'status'     => $status,
			'token'      => $token,
			'source'     => $source,
			'created_at' => $created_at,
		],
		[
			'%s',
			'%s',
			'%s',
			'%s',
			'%s',
		]
	);

	if ( false === $inserted ) {
		return null;
	}

	return pstn_get_subscriber_by_email( $email );
}

function pstn_update_subscriber( int $subscriber_id, array $data ): bool {
	global $wpdb;

	if ( empty( $data ) ) {
		return true;
	}

	$table_name = pstn_get_subscribers_table_name();
	$formats    = [];

	foreach ( array_keys( $data ) as $key ) {
		$formats[] = 'id' === $key ? '%d' : '%s';
	}

	$updated = $wpdb->update(
		$table_name,
		$data,
		[ 'id' => $subscriber_id ],
		$formats,
		[ '%d' ]
	);

	return false !== $updated;
}

function pstn_get_subscriber_counts(): array {
	global $wpdb;

	$table_name = pstn_get_subscribers_table_name();
	$rows       = $wpdb->get_results(
		"SELECT status, COUNT(*) AS total FROM {$table_name} GROUP BY status",
		ARRAY_A
	);

	$counts = [
		'pending'      => 0,
		'subscribed'   => 0,
		'unsubscribed' => 0,
	];

	foreach ( $rows as $row ) {
		if ( isset( $counts[ $row['status'] ] ) ) {
			$counts[ $row['status'] ] = (int) $row['total'];
		}
	}

	return $counts;
}

function pstn_get_subscribers( int $per_page = 50, int $offset = 0, string $search = '' ): array {
	global $wpdb;

	$table_name = pstn_get_subscribers_table_name();
	$where_sql  = '';
	$params     = [];

	if ( '' !== $search ) {
		$where_sql = 'WHERE email LIKE %s';
		$params[]  = '%' . $wpdb->esc_like( $search ) . '%';
	}

	$params[] = $per_page;
	$params[] = $offset;

	$query = "SELECT * FROM {$table_name} {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";

	return $wpdb->get_results( $wpdb->prepare( $query, $params ), ARRAY_A );
}

function pstn_count_all_subscribers( string $search = '' ): int {
	global $wpdb;

	$table_name = pstn_get_subscribers_table_name();

	if ( '' === $search ) {
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
	}

	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table_name} WHERE email LIKE %s",
			'%' . $wpdb->esc_like( $search ) . '%'
		)
	);
}

function pstn_delete_subscriber( int $subscriber_id ): bool {
	global $wpdb;

	return false !== $wpdb->delete( pstn_get_subscribers_table_name(), [ 'id' => $subscriber_id ], [ '%d' ] );
}

function pstn_send_confirmation_email( array $subscriber ): bool {
	$message = pstn_render_email_template(
		'opt-in-confirmation',
		[
			'confirm_url' => pstn_get_token_url( 'confirm', $subscriber['token'] ),
			'site_name'   => get_bloginfo( 'name' ),
		]
	);

	return pstn_send_html_mail(
		$subscriber['email'],
		(string) pstn_get_setting( 'confirmation_subject' ),
		$message
	);
}

function pstn_send_post_email( array $subscriber, int $post_id ): bool {
	global $wpdb;

	$post_title   = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES | ENT_HTML5 );
	$post_url     = get_permalink( $post_id );
	$post    = get_post( $post_id );
	$post_excerpt = '';

	if ( $post instanceof WP_Post ) {
		$post_excerpt = has_excerpt( $post_id )
			? $post->post_excerpt
			: wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 40 );
	}

	$message = pstn_render_email_template(
		'email-notification',
		[
			'post_title'    => $post_title,
			'post_url'      => $post_url,
			'post_excerpt'  => $post_excerpt,
			'unsubscribe'   => pstn_get_token_url( 'unsubscribe', $subscriber['token'] ),
			'site_name'     => get_bloginfo( 'name' ),
			'site_url'      => home_url( '/' ),
		]
	);

	$sent = pstn_send_html_mail(
		$subscriber['email'],
		str_replace( '{post_title}', $post_title, (string) pstn_get_setting( 'notification_subject' ) ),
		$message
	);

	if ( $sent ) {
		$wpdb->update(
			pstn_get_subscribers_table_name(),
			[ 'last_sent_at' => current_time( 'mysql' ) ],
			[ 'id' => (int) $subscriber['id'] ],
			[ '%s' ],
			[ '%d' ]
		);
	}

	return $sent;
}

function pstn_subscribe_email( string $email, string $source = 'website' ): array {
	$email = sanitize_email( $email );

	if ( ! is_email( $email ) ) {
		return [
			'success' => false,
			'message' => 'Please enter a valid email address.',
		];
	}

	$subscriber = pstn_get_subscriber_by_email( $email );
	$was_pending = $subscriber && 'pending' === $subscriber['status'];

	if ( $subscriber && 'subscribed' === $subscriber['status'] ) {
		return [
			'success' => true,
			'message' => 'This email is already subscribed to post notifications.',
		];
	}

	if ( ! $subscriber ) {
		$subscriber = pstn_insert_subscriber( $email, 'pending', $source );
		if ( ! $subscriber ) {
			return [
				'success' => false,
				'message' => 'We could not save this subscription. Please try again.',
			];
		}
	} else {
		$updated = pstn_update_subscriber(
			(int) $subscriber['id'],
			[
				'status'          => 'pending',
				'token'           => pstn_generate_token(),
				'source'          => $source,
				'unsubscribed_at' => null,
			]
		);

		if ( ! $updated ) {
			return [
				'success' => false,
				'message' => 'We could not update this subscription. Please try again.',
			];
		}

		$subscriber = pstn_get_subscriber_by_email( $email );
	}

	if ( ! pstn_send_confirmation_email( $subscriber ) ) {
		return [
			'success' => false,
			'message' => 'We could not send the confirmation email. Please try again.',
		];
	}

	return [
		'success' => true,
		'message' => $was_pending
			? 'A fresh confirmation email has been sent. Please check your inbox.'
			: 'Thanks! Check your inbox to confirm your subscription.',
	];
}

