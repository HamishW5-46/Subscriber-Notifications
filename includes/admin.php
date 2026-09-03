<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const PSTN_MANAGE_CAPABILITY = 'manage_post_notifications';
const PSTN_WIDGET_CAPABILITY = 'view_post_subscription_qr_widget';

function pstn_register_settings(): void {
	register_setting(
		'pstn_settings_group',
		PSTN_OPTION_KEY,
		[
			'type'              => 'array',
			'sanitize_callback' => 'pstn_sanitize_settings',
			'default'           => pstn_get_default_settings(),
		]
	);

	add_settings_section( 'pstn_general_section', 'General Settings', '__return_false', 'pstn-settings' );
	add_settings_section( 'pstn_email_section', 'Email Settings', '__return_false', 'pstn-settings' );

	$fields = [
		'subscribe_slug'        => [ 'label' => 'Subscribe Page Slug', 'section' => 'pstn_general_section' ],
		'subscriptions_base'    => [ 'label' => 'Token URL Base', 'section' => 'pstn_general_section' ],
		'confirm_slug'          => [ 'label' => 'Confirm Slug', 'section' => 'pstn_general_section' ],
		'unsubscribe_slug'      => [ 'label' => 'Unsubscribe Slug', 'section' => 'pstn_general_section' ],
		'batch_size'            => [ 'label' => 'Batch Size', 'section' => 'pstn_general_section', 'type' => 'number' ],
		'send_delay'            => [ 'label' => 'Send Delay (seconds)', 'section' => 'pstn_general_section', 'type' => 'number' ],
		'show_dashboard_widget' => [ 'label' => 'Show Dashboard Widget', 'section' => 'pstn_general_section', 'type' => 'checkbox' ],
		'notification_subject'  => [ 'label' => 'Notification Subject', 'section' => 'pstn_email_section' ],
		'confirmation_subject'  => [ 'label' => 'Confirmation Subject', 'section' => 'pstn_email_section' ],
		'from_name'             => [ 'label' => 'From Name', 'section' => 'pstn_email_section' ],
		'from_email'            => [ 'label' => 'From Email', 'section' => 'pstn_email_section' ],
	];

	foreach ( $fields as $key => $field ) {
		add_settings_field(
			$key,
			$field['label'],
			'pstn_render_settings_field',
			'pstn-settings',
			$field['section'],
			[
				'key'  => $key,
				'type' => $field['type'] ?? 'text',
			]
		);
	}
}

add_action( 'admin_init', 'pstn_register_settings' );

function pstn_settings_capability(): string {
	return PSTN_MANAGE_CAPABILITY;
}

add_filter( 'option_page_capability_pstn_settings_group', 'pstn_settings_capability' );

function pstn_sanitize_settings( array $input ): array {
	$defaults = pstn_get_default_settings();
	$current  = pstn_get_settings();

	$output = [
		'subscribe_slug'        => sanitize_title( $input['subscribe_slug'] ?? $defaults['subscribe_slug'] ),
		'subscriptions_base'    => sanitize_title( $input['subscriptions_base'] ?? $defaults['subscriptions_base'] ),
		'confirm_slug'          => sanitize_title( $input['confirm_slug'] ?? $defaults['confirm_slug'] ),
		'unsubscribe_slug'      => sanitize_title( $input['unsubscribe_slug'] ?? $defaults['unsubscribe_slug'] ),
		'batch_size'            => max( 1, (int) ( $input['batch_size'] ?? $defaults['batch_size'] ) ),
		'send_delay'            => max( 10, (int) ( $input['send_delay'] ?? $defaults['send_delay'] ) ),
		'notification_subject'  => sanitize_text_field( $input['notification_subject'] ?? $defaults['notification_subject'] ),
		'confirmation_subject'  => sanitize_text_field( $input['confirmation_subject'] ?? $defaults['confirmation_subject'] ),
		'from_name'             => sanitize_text_field( $input['from_name'] ?? $defaults['from_name'] ),
		'from_email'            => sanitize_email( $input['from_email'] ?? $defaults['from_email'] ),
		'show_dashboard_widget' => empty( $input['show_dashboard_widget'] ) ? 0 : 1,
	];

	foreach ( [ 'subscribe_slug', 'subscriptions_base', 'confirm_slug', 'unsubscribe_slug' ] as $key ) {
		if ( $current[ $key ] !== $output[ $key ] ) {
			break;
		}
	}

	return $output;
}

function pstn_render_settings_field( array $args ): void {
	$key      = $args['key'];
	$type     = $args['type'];
	$settings = pstn_get_settings();
	$value    = $settings[ $key ] ?? '';
	$name     = PSTN_OPTION_KEY . '[' . $key . ']';

	if ( 'checkbox' === $type ) {
		echo '<label><input type="checkbox" name="' . esc_attr( $name ) . '" value="1" ' . checked( (int) $value, 1, false ) . '> Enabled</label>';
		return;
	}

	$input_type = 'number' === $type ? 'number' : 'text';
	echo '<input class="regular-text" type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';

	if ( 'notification_subject' === $key ) {
		echo '<p class="description">Use <code>{post_title}</code> to include the published post title.</p>';
	}
}

function pstn_handle_admin_subscriber_actions(): void {
	if ( ! is_admin() || ! current_user_can( PSTN_MANAGE_CAPABILITY ) ) {
		return;
	}

	if ( empty( $_GET['page'] ) || 'pstn-subscribers' !== $_GET['page'] ) {
		return;
	}

	if ( empty( $_GET['pstn_action'] ) || empty( $_GET['subscriber_id'] ) ) {
		return;
	}

	check_admin_referer( 'pstn_subscriber_action' );

	$action        = sanitize_key( wp_unslash( $_GET['pstn_action'] ) );
	$subscriber_id = absint( $_GET['subscriber_id'] );

	if ( 'delete' === $action ) {
		pstn_delete_subscriber( $subscriber_id );
	}

	if ( 'resend' === $action ) {
		global $wpdb;
		$table_name = pstn_get_subscribers_table_name();
		$subscriber = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE id = %d LIMIT 1",
				$subscriber_id
			),
			ARRAY_A
		);

		if ( $subscriber ) {
			pstn_update_subscriber(
				$subscriber_id,
				[
					'status' => 'pending',
					'token'  => pstn_generate_token(),
				]
			);
			$subscriber = pstn_get_subscriber_by_email( $subscriber['email'] );
			if ( $subscriber ) {
				pstn_send_confirmation_email( $subscriber );
			}
		}
	}

	wp_safe_redirect( admin_url( 'admin.php?page=pstn-subscribers' ) );
	exit;
}

add_action( 'admin_init', 'pstn_handle_admin_subscriber_actions' );

function pstn_add_admin_pages(): void {
	add_menu_page(
		'Post Notifications',
		'Post Notifications',
		PSTN_MANAGE_CAPABILITY,
		'pstn-subscribers',
		'pstn_render_subscribers_page',
		'dashicons-email-alt2'
	);

	add_submenu_page(
		'pstn-subscribers',
		'Subscribers',
		'Subscribers',
		PSTN_MANAGE_CAPABILITY,
		'pstn-subscribers',
		'pstn_render_subscribers_page'
	);

	add_submenu_page(
		'pstn-subscribers',
		'Settings',
		'Settings',
		PSTN_MANAGE_CAPABILITY,
		'pstn-settings',
		'pstn_render_settings_page'
	);
}

add_action( 'admin_menu', 'pstn_add_admin_pages' );

function pstn_render_settings_page(): void {
	if ( ! current_user_can( PSTN_MANAGE_CAPABILITY ) ) {
		return;
	}

	$counts = pstn_get_subscriber_counts();
	?>
	<div class="wrap">
		<h1>Post Notifications Settings</h1>
		<p>Manage the subscription flow, email settings, and public URLs used by the plugin.</p>

		<div class="notice notice-info inline">
			<p><strong>Subscribe URL:</strong> <a href="<?php echo esc_url( pstn_get_subscribe_url() ); ?>" target="_blank" rel="noreferrer"><?php echo esc_html( pstn_get_subscribe_url() ); ?></a></p>
			<p><strong>Subscribers:</strong> <?php echo esc_html( (string) $counts['subscribed'] ); ?> subscribed, <?php echo esc_html( (string) $counts['pending'] ); ?> pending</p>
		</div>

		<form action="options.php" method="post">
			<?php
			settings_fields( 'pstn_settings_group' );
			do_settings_sections( 'pstn-settings' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

function pstn_render_subscribers_page(): void {
	if ( ! current_user_can( PSTN_MANAGE_CAPABILITY ) ) {
		return;
	}

	$search      = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$page_number = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$per_page    = 25;
	$offset      = ( $page_number - 1 ) * $per_page;
	$total       = pstn_count_all_subscribers( $search );
	$subscribers = pstn_get_subscribers( $per_page, $offset, $search );
	$counts      = pstn_get_subscriber_counts();
	$total_pages = max( 1, (int) ceil( $total / $per_page ) );
	?>
	<div class="wrap">
		<h1>Subscribers</h1>
		<p>Manage post notification subscribers separately from WordPress site users.</p>

		<ul style="display:flex;gap:16px;padding:0;margin:16px 0;list-style:none;">
			<li><strong>Subscribed:</strong> <?php echo esc_html( (string) $counts['subscribed'] ); ?></li>
			<li><strong>Pending:</strong> <?php echo esc_html( (string) $counts['pending'] ); ?></li>
			<li><strong>Unsubscribed:</strong> <?php echo esc_html( (string) $counts['unsubscribed'] ); ?></li>
		</ul>

		<form method="get" style="margin:16px 0;">
			<input type="hidden" name="page" value="pstn-subscribers">
			<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search email address">
			<?php submit_button( 'Search', 'secondary', '', false ); ?>
		</form>

		<table class="widefat striped">
			<thead>
				<tr>
					<th>Email</th>
					<th>Status</th>
					<th>Source</th>
					<th>Created</th>
					<th>Confirmed</th>
					<th>Last Sent</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $subscribers ) ) : ?>
					<tr><td colspan="7">No subscribers found.</td></tr>
				<?php else : ?>
					<?php foreach ( $subscribers as $subscriber ) : ?>
						<?php $action_url = wp_nonce_url( admin_url( 'admin.php?page=pstn-subscribers&subscriber_id=' . (int) $subscriber['id'] ), 'pstn_subscriber_action' ); ?>
						<tr>
							<td><?php echo esc_html( $subscriber['email'] ); ?></td>
							<td><?php echo esc_html( ucfirst( $subscriber['status'] ) ); ?></td>
							<td><?php echo esc_html( $subscriber['source'] ); ?></td>
							<td><?php echo esc_html( $subscriber['created_at'] ); ?></td>
							<td><?php echo esc_html( $subscriber['confirmed_at'] ?: '—' ); ?></td>
							<td><?php echo esc_html( $subscriber['last_sent_at'] ?: '—' ); ?></td>
							<td>
								<a href="<?php echo esc_url( $action_url . '&pstn_action=resend' ); ?>">Resend confirmation</a>
								|
								<a href="<?php echo esc_url( $action_url . '&pstn_action=delete' ); ?>" onclick="return confirm('Delete this subscriber?');">Delete</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>

		<?php if ( $total_pages > 1 ) : ?>
			<div class="tablenav" style="margin-top:16px;">
				<div class="tablenav-pages">
					<span class="displaying-num"><?php echo esc_html( (string) $total ); ?> items</span>
					<span class="pagination-links">
						<?php for ( $page = 1; $page <= $total_pages; $page++ ) : ?>
							<?php $url = add_query_arg( [ 'page' => 'pstn-subscribers', 'paged' => $page, 's' => $search ], admin_url( 'admin.php' ) ); ?>
							<a class="<?php echo $page === $page_number ? 'button button-primary' : 'button'; ?>" href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( (string) $page ); ?></a>
						<?php endfor; ?>
					</span>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

function pstn_widget(): void {
	if ( ! current_user_can( PSTN_WIDGET_CAPABILITY ) || ! pstn_get_setting( 'show_dashboard_widget' ) ) {
		return;
	}

	wp_add_dashboard_widget(
		'pstn_dashboard_qr_widget',
		'Post Subscription QR',
		'pstn_dashboard_qr_widget_callback'
	);
}

add_action( 'wp_dashboard_setup', 'pstn_widget' );

function pstn_dashboard_qr_widget_callback(): void {
	$image_url = plugins_url( 'qr.png', PSTN_PLUGIN_FILE );
	echo '<img src="' . esc_url( $image_url ) . '" alt="Subscribe QR Code" style="display:block;margin:0 auto;max-width:100%;height:auto;" />';
	echo '<p>Public subscribe page: <a href="' . esc_url( pstn_get_subscribe_url() ) . '" target="_blank" rel="noreferrer">' . esc_html( pstn_get_subscribe_url() ) . '</a></p>';
	echo '<p>Use this QR image on flyers or posters so visitors can reach the subscribe page quickly.</p>';
}

add_filter(
	'plugin_action_links_' . plugin_basename( PSTN_PLUGIN_FILE ),
	static function ( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=pstn-settings' ) ) . '">Settings</a>' );
		return $links;
	}
);

