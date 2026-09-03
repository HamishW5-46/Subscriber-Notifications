<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function pstn_schedule_post_notification( string $new_status, string $old_status, WP_Post $post ): void {
	$allowed_post_types = [
		'post',
		'notices',
		'tribe_events',
	];

	if ( ! in_array( $post->post_type, $allowed_post_types, true ) ) {
		return;
	}

	if ( 'publish' !== $new_status || 'publish' === $old_status ) {
		return;
	}

	if ( get_post_meta( $post->ID, PSTN_SENT_META, true ) ) {
		return;
	}

	if ( wp_next_scheduled( PSTN_CRON_HOOK, [ $post->ID ] ) ) {
		return;
	}

	delete_post_meta( $post->ID, PSTN_BATCH_OFFSET_META );
	wp_schedule_single_event( time() + pstn_get_send_delay(), PSTN_CRON_HOOK, [ $post->ID ] );
}

add_action( 'transition_post_status', 'pstn_schedule_post_notification', 10, 3 );

function pstn_send_post_notifications_batch( int $post_id ): void {
	global $wpdb;

	if ( get_post_meta( $post_id, PSTN_SENT_META, true ) ) {
		return;
	}

	$batch_size  = pstn_get_batch_size();
	$offset      = max( 0, (int) get_post_meta( $post_id, PSTN_BATCH_OFFSET_META, true ) );
	$table_name  = pstn_get_subscribers_table_name();
	$subscribers = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE status = %s ORDER BY id ASC LIMIT %d OFFSET %d",
			'subscribed',
			$batch_size,
			$offset
		),
		ARRAY_A
	);

	if ( empty( $subscribers ) ) {
		update_post_meta( $post_id, PSTN_SENT_META, 1 );
		delete_post_meta( $post_id, PSTN_BATCH_OFFSET_META );
		return;
	}

	foreach ( $subscribers as $subscriber ) {
		pstn_send_post_email( $subscriber, $post_id );
	}

	if ( count( $subscribers ) < $batch_size ) {
		update_post_meta( $post_id, PSTN_SENT_META, 1 );
		delete_post_meta( $post_id, PSTN_BATCH_OFFSET_META );
		return;
	}

	update_post_meta( $post_id, PSTN_BATCH_OFFSET_META, $offset + $batch_size );
	wp_schedule_single_event( time() + pstn_get_send_delay(), PSTN_CRON_HOOK, [ $post_id ] );
}

add_action( PSTN_CRON_HOOK, 'pstn_send_post_notifications_batch' );

