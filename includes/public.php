<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function pstn_render_message_page( string $title, string $message ): void {
	status_header( 200 );
	get_header();

	echo '<main class="container" style="max-width:600px;margin:80px auto;text-align:center;">';
	echo '<h1>' . esc_html( $title ) . '</h1>';
	echo '<p>' . esc_html( $message ) . '</p>';
	echo '<p><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Return to site', 'post-notifications-email-subscription' ) . '</a></p>';
	echo '</main>';

	get_footer();
	exit;
}

function pstn_confirm_subscription_token( string $token ): void {
	$subscriber = pstn_get_subscriber_by_token( $token );

	if ( ! $subscriber ) {
		wp_die( esc_html__( 'Invalid or expired confirmation link.', 'post-notifications-email-subscription' ) );
	}

	pstn_update_subscriber(
		(int) $subscriber['id'],
		[
			'status'       => 'subscribed',
			'confirmed_at' => current_time( 'mysql' ),
		]
	);

	pstn_render_message_page(
		'Subscription Confirmed',
		'You will now receive post notifications.'
	);
}

function pstn_handle_unsubscribe_token( string $token ): void {
	$subscriber = pstn_get_subscriber_by_token( $token );

	if ( ! $subscriber ) {
		wp_die( esc_html__( 'Invalid or expired link.', 'post-notifications-email-subscription' ) );
	}

	pstn_update_subscriber(
		(int) $subscriber['id'],
		[
			'status'          => 'unsubscribed',
			'unsubscribed_at' => current_time( 'mysql' ),
		]
	);

	pstn_render_message_page(
		'You Have Been Unsubscribed',
		'You will no longer receive post notifications.'
	);
}

add_action(
	'template_redirect',
	static function (): void {
		$action = get_query_var( 'pstn_action' );
		$token  = sanitize_text_field( (string) get_query_var( 'token' ) );

		if ( ! $action || ! $token ) {
			return;
		}

		if ( 'confirm' === $action ) {
			pstn_confirm_subscription_token( $token );
		}

		if ( 'unsubscribe' === $action ) {
			pstn_handle_unsubscribe_token( $token );
		}
	}
);

function pstn_handle_fullpage_optin_submission(): void {
	if (
		empty( $_POST['pstn_fullpage_optin'] ) ||
		empty( $_POST['pstn_email'] ) ||
		empty( $_POST['pstn_fullpage_optin_nonce'] )
	) {
		return;
	}

	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pstn_fullpage_optin_nonce'] ) ), 'pstn_fullpage_optin_action' ) ) {
		return;
	}

	$result = pstn_subscribe_email( sanitize_email( wp_unslash( $_POST['pstn_email'] ) ), 'fullpage-form' );

	if ( $result['success'] ) {
		set_query_var( 'pstn_form_notice', $result['message'] );
	}
}

add_action( 'init', 'pstn_handle_fullpage_optin_submission' );

function pstn_render_fullpage_optin_form(): string {
	$notice = (string) get_query_var( 'pstn_form_notice' );

	ob_start();
	?>
	<div class="pstn-fullpage-optin">
		<?php if ( $notice ) : ?>
			<p class="pstn-success"><?php echo esc_html( $notice ); ?></p>
		<?php endif; ?>
		<form method="post" class="pstn-form">
			<input type="email" name="pstn_email" required placeholder="Your email address">
			<input type="hidden" name="pstn_fullpage_optin" value="1">
			<?php wp_nonce_field( 'pstn_fullpage_optin_action', 'pstn_fullpage_optin_nonce' ); ?>
			<button type="submit">Subscribe</button>
		</form>
	</div>
	<?php
	return (string) ob_get_clean();
}

add_action(
	'template_redirect',
	static function (): void {
		if ( 'subscribe' !== get_query_var( 'pstn_virtual' ) ) {
			return;
		}

		status_header( 200 );
		get_header();
		echo '<main class="container">';
		echo '<div class="pstn-subscribe-main">';
		echo '<section class="pstn-subscribe-section">';
		echo '<h1>Subscribe for Post Notifications</h1>';
		echo '<p>Sign up to receive email notifications when new posts are published.</p>';
		echo pstn_render_fullpage_optin_form();
		echo '</section>';
		echo '</div>';
		echo '</main>';
		get_footer();
		exit;
	}
);

add_action(
	'wp_enqueue_scripts',
	static function (): void {
		if ( 'subscribe' !== get_query_var( 'pstn_virtual' ) ) {
			return;
		}

		wp_register_style( 'pstn-fullpage-optin-style', false );
		wp_enqueue_style( 'pstn-fullpage-optin-style' );
		wp_add_inline_style(
			'pstn-fullpage-optin-style',
			'
			.pstn-subscribe-main {
				width: 100%;
				display: flex;
				align-items: center;
				justify-content: center;
			}
			.pstn-subscribe-section {
				background: #fff;
				padding: 2rem 2.5rem;
				border-radius: 10px;
				box-shadow: 0 2px 16px rgba(0,0,0,0.07);
				max-width: 400px;
				width: 100%;
				text-align: center;
			}
			.pstn-subscribe-section h1 {
				margin-bottom: 1rem;
			}
			.pstn-subscribe-section p {
				margin-bottom: 2rem;
				color: #555;
			}
			.pstn-fullpage-optin .pstn-form {
				display: flex;
				flex-direction: column;
				gap: 1rem;
				align-items: stretch;
			}
			.pstn-fullpage-optin input[type="email"] {
				width: 100%;
				padding: 0.75rem;
				border-radius: 5px;
				border: 1px solid #ccc;
				font-size: 1rem;
			}
			.pstn-fullpage-optin button {
				padding: 0.75rem 1.5rem;
				border-radius: 5px;
				background: #0073aa;
				color: #fff;
				border: none;
				font-weight: bold;
				cursor: pointer;
				font-size: 1rem;
				transition: background 0.2s;
			}
			.pstn-fullpage-optin button:hover {
				background: #005177;
			}
			.pstn-fullpage-optin .pstn-success {
				padding: 1rem;
				border-left: 4px solid #0073aa;
				background: #e6f4fa;
				color: #0073aa;
				border-radius: 5px;
				margin-bottom: 1rem;
			}
			'
		);
	}
);

function pstn_optin_form_shortcode(): string {
	$form_id     = wp_unique_id( 'pstn-optin-' );
	$button_id   = $form_id . '-button';
	$email_id    = $form_id . '-email';
	$response_id = $form_id . '-response';
	$nonce_id    = $form_id . '-nonce';

	ob_start();
	?>
	<div class="pstn-shortcode-form">
		<h2>Want to know when new posts are published?</h2>
		<div class="pstn-flex-container">
			<div>
				<input type="email" id="<?php echo esc_attr( $email_id ); ?>" placeholder="Enter your email" required>
			</div>
			<div>
				<a id="<?php echo esc_attr( $button_id ); ?>">
					<span>Subscribe</span>
				</a>
			</div>
		</div>

		<input type="hidden" id="<?php echo esc_attr( $nonce_id ); ?>" value="<?php echo esc_attr( wp_create_nonce( 'pstn_optin_action' ) ); ?>">
		<p id="<?php echo esc_attr( $response_id ); ?>"></p>
	</div>
	<script>
	document.addEventListener('DOMContentLoaded', function() {
		const subButton = document.getElementById('<?php echo esc_js( $button_id ); ?>');
		const emailInput = document.getElementById('<?php echo esc_js( $email_id ); ?>');
		const responseMsg = document.getElementById('<?php echo esc_js( $response_id ); ?>');
		const nonceInput = document.getElementById('<?php echo esc_js( $nonce_id ); ?>');

		if (!subButton || !emailInput || !responseMsg) {
			return;
		}

		subButton.addEventListener('click', function(e) {
			e.preventDefault();

			const email = emailInput.value.trim();
			const nonce = nonceInput ? nonceInput.value : '';

			if (!email || !email.includes('@')) {
				responseMsg.textContent = 'Please enter a valid email address.';
				responseMsg.style.color = 'red';
				responseMsg.style.display = 'block';
				return;
			}

			subButton.classList.add('elementor-button-disabled');
			subButton.style.pointerEvents = 'none';
			subButton.textContent = 'Sending...';

			fetch('<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Accept': 'application/json' },
				body: new URLSearchParams({
					action: 'pstn_optin',
					pstn_email: email,
					pstn_optin_nonce: nonce
				})
			})
			.then(response => response.json())
			.then(data => {
				responseMsg.textContent = data.data && data.data.message ? data.data.message : 'Could not subscribe. Try again.';
				responseMsg.style.color = data.success ? '#005177' : 'red';
				responseMsg.style.display = 'block';

				if (data.success) {
					emailInput.style.display = 'none';
					subButton.style.display = 'none';
					return;
				}

				subButton.classList.remove('elementor-button-disabled');
				subButton.style.pointerEvents = 'auto';
				subButton.textContent = 'Subscribe';
			})
			.catch(() => {
				responseMsg.textContent = 'Could not subscribe. Try again.';
				responseMsg.style.color = 'red';
				responseMsg.style.display = 'block';
				subButton.classList.remove('elementor-button-disabled');
				subButton.style.pointerEvents = 'auto';
				subButton.textContent = 'Subscribe';
			});
		});
	});
	</script>
	<?php
	return (string) ob_get_clean();
}

add_shortcode( 'pstn_optin_form', 'pstn_optin_form_shortcode' );

function pstn_optin_ajax_handler(): void {
	if (
		empty( $_POST['pstn_email'] ) ||
		empty( $_POST['pstn_optin_nonce'] ) ||
		! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pstn_optin_nonce'] ) ), 'pstn_optin_action' )
	) {
		wp_send_json_error( [ 'message' => 'Security check failed. Please refresh the page and try again.' ] );
	}

	$result = pstn_subscribe_email( sanitize_email( wp_unslash( $_POST['pstn_email'] ) ), 'shortcode-form' );

	if ( $result['success'] ) {
		wp_send_json_success( [ 'message' => $result['message'] ] );
	}

	wp_send_json_error( [ 'message' => $result['message'] ] );
}

add_action( 'wp_ajax_pstn_optin', 'pstn_optin_ajax_handler' );
add_action( 'wp_ajax_nopriv_pstn_optin', 'pstn_optin_ajax_handler' );

