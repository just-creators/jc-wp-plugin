<?php
/**
 * Plugin Name: JustCreators Support
 * Description: Discord-basiertes Support-System für Bug Reports, Hacker Reports, Mod Suggestions und allgemeinen Support
 * Version: 1.0.0
 * Author: JustCreators Team
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Constants
define( 'JC_SUPPORT_DISCORD_CLIENT_ID', 'jc_support_discord_client_id' );
define( 'JC_SUPPORT_DISCORD_CLIENT_SECRET', 'jc_support_discord_client_secret' );
define( 'JC_SUPPORT_DISCORD_SERVER_ID', 'jc_support_discord_server_id' );
define( 'JC_SUPPORT_DISCORD_MEMBER_ROLE_ID', 'jc_support_discord_member_role_id' );
define( 'JC_SUPPORT_SUPER_ADMIN', 'kabel_entwirer' ); // Super Admin Discord Username
define( 'JC_SUPPORT_ADMINS_OPTION', 'jc_support_admin_users' ); // List of admin user IDs

// Hooks
add_action( 'init', 'jc_support_session_start', 1 );
add_action( 'init', 'jc_support_register_post_type' );
add_action( 'init', 'jc_support_handle_discord_callback' );
add_action( 'init', 'jc_support_handle_frontend_actions' );
add_shortcode( 'jc_support', 'jc_support_render_shortcode' );

/**
 * Start PHP session safely (single place)
 */
function jc_support_session_start() {
	// Avoid restarting if already active
	if ( session_status() === PHP_SESSION_ACTIVE ) {
		return;
	}

	// Configure cookie params (adjust domain if needed)
	$domain = parse_url( home_url(), PHP_URL_HOST );
	if ( ! empty( $domain ) ) {
		// Ensure leading dot for subdomains
		if ( substr( $domain, 0, 1 ) !== '.' ) {
			$domain = '.' . $domain;
		}
	}

	$secure   = is_ssl();
	$httponly = true;

	// Set cookie params before starting session
	session_set_cookie_params( array(
		'lifetime' => 0,
		'path'     => '/',
		'domain'   => $domain ?: '',
		'secure'   => $secure,
		'httponly' => $httponly,
		'samesite' => 'Lax',
	) );

	@session_start();
}

/**
 * Register custom post type for tickets
 */
function jc_support_register_post_type() {
	register_post_type( 'jc_support_ticket', array(
		'labels' => array(
			'name' => 'Support Tickets',
			'singular_name' => 'Ticket',
		),
		'public' => false,
		'show_ui' => false,
		'capability_type' => 'post',
		'supports' => array( 'title', 'editor', 'author' ),
	) );
}

/**
 * Handle Discord OAuth callback
 */
function jc_support_handle_discord_callback() {
	if ( ! isset( $_GET['jc_discord_callback'], $_GET['code'] ) ) {
		return;
	}

	jc_support_session_start();

	$code = sanitize_text_field( wp_unslash( $_GET['code'] ) );

	$client_id = get_option( JC_SUPPORT_DISCORD_CLIENT_ID );
	$client_secret = get_option( JC_SUPPORT_DISCORD_CLIENT_SECRET );
	$redirect_uri = home_url( '/?jc_discord_callback=1' );

	// Exchange code for token
	$token_response = wp_remote_post( 'https://discord.com/api/oauth2/token', array(
		'body' => array(
			'client_id' => $client_id,
			'client_secret' => $client_secret,
			'grant_type' => 'authorization_code',
			'code' => $code,
			'redirect_uri' => $redirect_uri,
		),
		'headers' => array(
			'Content-Type' => 'application/x-www-form-urlencoded',
		),
	) );

	if ( is_wp_error( $token_response ) ) {
		wp_die( 'Discord Login fehlgeschlagen.' );
	}

	$token_data = json_decode( wp_remote_retrieve_body( $token_response ), true );
	if ( empty( $token_data['access_token'] ) ) {
		wp_die( 'Discord Login fehlgeschlagen: Kein Access Token.' );
	}

	$access_token = $token_data['access_token'];

	// Get user info
	$user_response = wp_remote_get( 'https://discord.com/api/users/@me', array(
		'headers' => array(
			'Authorization' => 'Bearer ' . $access_token,
		),
	) );

	if ( is_wp_error( $user_response ) ) {
		wp_die( 'Fehler beim Abrufen der Discord-Daten.' );
	}

	$user_data = json_decode( wp_remote_retrieve_body( $user_response ), true );
	if ( empty( $user_data['id'] ) ) {
		wp_die( 'Fehler beim Abrufen der Discord-Daten.' );
	}

	// Check server membership
	$is_member = jc_support_check_server_membership( $user_data['id'], $access_token );

	// Store in session
	$_SESSION['jc_discord_user'] = array(
		'id' => $user_data['id'],
		'username' => $user_data['username'],
		'discriminator' => $user_data['discriminator'] ?? '0',
		'avatar' => $user_data['avatar'],
		'is_member' => $is_member,
		'access_token' => $access_token,
	);

	// Redirect back to support page
	$return_url = isset( $_GET['state'] ) ? base64_decode( sanitize_text_field( wp_unslash( $_GET['state'] ) ) ) : home_url();
	wp_safe_redirect( $return_url );
	exit;
}

/**
 * Check if user is server member
 */
function jc_support_check_server_membership( $user_id, $access_token ) {
	$server_id = get_option( JC_SUPPORT_DISCORD_SERVER_ID );
	if ( empty( $server_id ) ) {
		return false;
	}

	$response = wp_remote_get( "https://discord.com/api/users/@me/guilds/{$server_id}/member", array(
		'headers' => array(
			'Authorization' => 'Bearer ' . $access_token,
		),
	) );

	if ( is_wp_error( $response ) ) {
		return false;
	}

	$code = wp_remote_retrieve_response_code( $response );
	return 200 === $code;
}

/**
 * Get Discord login URL
 */
function jc_support_get_discord_login_url( $return_url = '' ) {
	$client_id = get_option( JC_SUPPORT_DISCORD_CLIENT_ID );
	$redirect_uri = home_url( '/?jc_discord_callback=1' );
	
	if ( empty( $return_url ) ) {
		$return_url = home_url();
	}

	$state = base64_encode( $return_url );

	return add_query_arg( array(
		'client_id' => $client_id,
		'redirect_uri' => urlencode( $redirect_uri ),
		'response_type' => 'code',
		'scope' => 'identify guilds.members.read',
		'state' => $state,
	), 'https://discord.com/oauth2/authorize' );
}

/**
 * Get current Discord user from session
 */
function jc_support_get_current_user() {
	jc_support_session_start();
	return isset( $_SESSION['jc_discord_user'] ) ? $_SESSION['jc_discord_user'] : null;

/**
 * Check if user is super admin
 */
function jc_support_is_super_admin( $discord_user ) {
	if ( ! $discord_user ) {
		return false;
	}
	return $discord_user['username'] === JC_SUPPORT_SUPER_ADMIN;
}

/**
 * Check if user is admin
 */
function jc_support_is_admin( $discord_user ) {
	if ( ! $discord_user ) {
		return false;
	}
	
	if ( jc_support_is_super_admin( $discord_user ) ) {
		return true;
	}

	$admins = get_option( JC_SUPPORT_ADMINS_OPTION, array() );
	return in_array( $discord_user['id'], $admins, true );
}

/**
 * Get admin list
 */
function jc_support_get_admin_list() {
	return get_option( JC_SUPPORT_ADMINS_OPTION, array() );
}

/**
 * Add admin
 */
function jc_support_add_admin( $user_id ) {
	$admins = jc_support_get_admin_list();
	if ( ! in_array( $user_id, $admins, true ) ) {
		$admins[] = $user_id;
		update_option( JC_SUPPORT_ADMINS_OPTION, $admins );
		return true;
	}
	return false;
}

/**
 * Remove admin
 */
function jc_support_remove_admin( $user_id ) {
	$admins = jc_support_get_admin_list();
	$key = array_search( $user_id, $admins, true );
	if ( $key !== false ) {
		unset( $admins[ $key ] );
		update_option( JC_SUPPORT_ADMINS_OPTION, array_values( $admins ) );
		return true;
	}
	return false;
}

/**
 * Handle frontend actions (admin actions, ticket management)
 */
function jc_support_handle_frontend_actions() {
	if ( ! session_id() ) {
		session_start();
	}

	$discord_user = jc_support_get_current_user();
	if ( ! $discord_user ) {
		return;
	}

	// Add admin (super admin only)
	if ( isset( $_POST['jc_add_admin'], $_POST['admin_user_id'], $_POST['_wpnonce'] ) ) {
		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'jc_add_admin' ) && jc_support_is_super_admin( $discord_user ) ) {
			$user_id = sanitize_text_field( wp_unslash( $_POST['admin_user_id'] ) );
			jc_support_add_admin( $user_id );
			wp_safe_redirect( remove_query_arg( array() ) );
			exit;
		}
	}

	// Remove admin (super admin only)
	if ( isset( $_GET['jc_remove_admin'], $_GET['_wpnonce'] ) ) {
		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'jc_remove_admin' ) && jc_support_is_super_admin( $discord_user ) ) {
			$user_id = sanitize_text_field( wp_unslash( $_GET['jc_remove_admin'] ) );
			jc_support_remove_admin( $user_id );
			wp_safe_redirect( remove_query_arg( array( 'jc_remove_admin', '_wpnonce' ) ) );
			exit;
		}
	}

	// Claim ticket
	if ( isset( $_GET['jc_claim_ticket'], $_GET['_wpnonce'] ) ) {
		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'jc_claim_ticket' ) && jc_support_is_admin( $discord_user ) ) {
			$ticket_id = intval( $_GET['jc_claim_ticket'] );
			update_post_meta( $ticket_id, '_jc_ticket_claimed_by', $discord_user['id'] );
			update_post_meta( $ticket_id, '_jc_ticket_claimed_by_username', $discord_user['username'] );
			wp_safe_redirect( remove_query_arg( array( 'jc_claim_ticket', '_wpnonce' ) ) );
			exit;
		}
	}

	// Unclaim ticket
	if ( isset( $_GET['jc_unclaim_ticket'], $_GET['_wpnonce'] ) ) {
		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'jc_unclaim_ticket' ) && jc_support_is_admin( $discord_user ) ) {
			$ticket_id = intval( $_GET['jc_unclaim_ticket'] );
			delete_post_meta( $ticket_id, '_jc_ticket_claimed_by' );
			delete_post_meta( $ticket_id, '_jc_ticket_claimed_by_username' );
			wp_safe_redirect( remove_query_arg( array( 'jc_unclaim_ticket', '_wpnonce' ) ) );
			exit;
		}
	}

	// Admin reply
	if ( isset( $_POST['jc_admin_reply'], $_POST['ticket_id'], $_POST['admin_reply_message'], $_POST['_wpnonce'] ) ) {
		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'jc_admin_reply' ) && jc_support_is_admin( $discord_user ) ) {
			$ticket_id = intval( $_POST['ticket_id'] );
			$reply = sanitize_textarea_field( wp_unslash( $_POST['admin_reply_message'] ) );

			$replies = get_post_meta( $ticket_id, '_jc_ticket_replies', true ) ?: array();
			$replies[] = array(
				'author' => $discord_user['username'],
				'author_id' => $discord_user['id'],
				'message' => $reply,
				'date' => current_time( 'mysql' ),
				'is_admin' => true,
			);
			update_post_meta( $ticket_id, '_jc_ticket_replies', $replies );
			update_post_meta( $ticket_id, '_jc_ticket_status', 'answered' );
			
			wp_safe_redirect( remove_query_arg( array() ) );
			exit;
		}
	}

	// Close ticket
	if ( isset( $_GET['jc_close_ticket'], $_GET['_wpnonce'] ) ) {
		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'jc_close_ticket' ) && jc_support_is_admin( $discord_user ) ) {
			$ticket_id = intval( $_GET['jc_close_ticket'] );
			update_post_meta( $ticket_id, '_jc_ticket_status', 'closed' );
			wp_safe_redirect( remove_query_arg( array( 'jc_close_ticket', '_wpnonce' ) ) );
			exit;
		}
	}
}

/**
 * Admin page - WordPress settings only
 */
function jc_support_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	?>
	<div class="wrap">
		<h1>Support System</h1>
		<p>Das Support-System wird komplett im Frontend verwaltet. Verwende den Shortcode <code>[jc_support]</code> auf einer Seite.</p>
		<p>Super Admin: <strong><?php echo esc_html( JC_SUPPORT_SUPER_ADMIN ); ?></strong></p>
		<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=jc-support-settings' ) ); ?>" class="button button-primary">Einstellungen</a></p>
	</div>
	<?php
}

/**
 * Settings page
 */
function jc_support_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['jc_support_save_settings'] ) ) {
		check_admin_referer( 'jc_support_settings' );

		update_option( JC_SUPPORT_DISCORD_CLIENT_ID, sanitize_text_field( wp_unslash( $_POST['discord_client_id'] ?? '' ) ) );
		update_option( JC_SUPPORT_DISCORD_CLIENT_SECRET, sanitize_text_field( wp_unslash( $_POST['discord_client_secret'] ?? '' ) ) );
		update_option( JC_SUPPORT_DISCORD_SERVER_ID, sanitize_text_field( wp_unslash( $_POST['discord_server_id'] ?? '' ) ) );
		update_option( JC_SUPPORT_DISCORD_MEMBER_ROLE_ID, sanitize_text_field( wp_unslash( $_POST['discord_member_role_id'] ?? '' ) ) );

		echo '<div class="notice notice-success"><p>Einstellungen gespeichert.</p></div>';
	}

	$client_id = get_option( JC_SUPPORT_DISCORD_CLIENT_ID );
	$client_secret = get_option( JC_SUPPORT_DISCORD_CLIENT_SECRET );
	$server_id = get_option( JC_SUPPORT_DISCORD_SERVER_ID );
	$member_role_id = get_option( JC_SUPPORT_DISCORD_MEMBER_ROLE_ID );

	?>
	<div class="wrap" style="max-width:800px;">
		<h1>Support Einstellungen</h1>

		<form method="post" style="background:#fff;padding:20px;border-radius:8px;margin-top:20px;">
			<?php wp_nonce_field( 'jc_support_settings' ); ?>

			<h2>Discord OAuth2 Konfiguration</h2>
			
			<table class="form-table">
				<tr>
					<th><label>Client ID</label></th>
					<td>
						<input type="text" name="discord_client_id" value="<?php echo esc_attr( $client_id ); ?>" class="regular-text" required>
					</td>
				</tr>
				<tr>
					<th><label>Client Secret</label></th>
					<td>
						<input type="text" name="discord_client_secret" value="<?php echo esc_attr( $client_secret ); ?>" class="regular-text" required>
					</td>
				</tr>
				<tr>
					<th><label>Server ID</label></th>
					<td>
						<input type="text" name="discord_server_id" value="<?php echo esc_attr( $server_id ); ?>" class="regular-text" required>
						<p class="description">Die ID deines Discord-Servers</p>
					</td>
				</tr>
				<tr>
					<th><label>Teilnehmer Role ID (optional)</label></th>
					<td>
						<input type="text" name="discord_member_role_id" value="<?php echo esc_attr( $member_role_id ); ?>" class="regular-text">
						<p class="description">Role ID für Teilnehmer-Verifizierung</p>
					</td>
				</tr>
			</table>

			<h3>Redirect URI</h3>
			<p>Füge diese URL in deiner Discord App hinzu:</p>
			<code style="background:#f0f0f0;padding:8px 12px;border-radius:4px;display:inline-block;">
				<?php echo esc_html( home_url( '/?jc_discord_callback=1' ) ); ?>
			</code>

			<p style="margin-top:20px;">
				<button type="submit" name="jc_support_save_settings" class="button button-primary">Einstellungen speichern</button>
			</p>
		</form>
	</div>
	<?php
}

/**
 * Frontend shortcode
 */
function jc_support_render_shortcode() {
	jc_support_session_start();

	$discord_user = jc_support_get_current_user();
	$is_admin = jc_support_is_admin( $discord_user );
	$is_super_admin = jc_support_is_super_admin( $discord_user );

	// Handle logout
	if ( isset( $_GET['jc_logout'] ) ) {
		jc_support_logout();
		wp_safe_redirect( remove_query_arg( 'jc_logout' ) );
		exit;
	}

	// Handle ticket submission
	if ( isset( $_POST['jc_submit_ticket'] ) && $discord_user ) {
		$category = sanitize_text_field( wp_unslash( $_POST['ticket_category'] ?? '' ) );
		$title = sanitize_text_field( wp_unslash( $_POST['ticket_title'] ?? '' ) );
		$message = sanitize_textarea_field( wp_unslash( $_POST['ticket_message'] ?? '' ) );

		if ( ! empty( $category ) && ! empty( $title ) && ! empty( $message ) ) {
			$ticket_id = wp_insert_post( array(
				'post_type' => 'jc_support_ticket',
				'post_title' => $title,
				'post_content' => $message,
				'post_status' => 'publish',
			) );

			if ( $ticket_id ) {
				update_post_meta( $ticket_id, '_jc_ticket_category', $category );
				update_post_meta( $ticket_id, '_jc_ticket_status', 'open' );
				update_post_meta( $ticket_id, '_jc_ticket_discord_user', $discord_user );
				
				echo '<div style="background:rgba(76,175,80,0.1);border:1px solid rgba(76,175,80,0.3);color:#4caf50;padding:12px;border-radius:8px;margin-bottom:20px;">Ticket erfolgreich erstellt!</div>';
			}
		}
	}

	// Handle ticket reply
	if ( isset( $_POST['jc_ticket_user_reply'], $_POST['ticket_id'], $_POST['user_reply_message'] ) && $discord_user ) {
		$ticket_id = intval( $_POST['ticket_id'] );
		$reply = sanitize_textarea_field( wp_unslash( $_POST['user_reply_message'] ) );

		$ticket = get_post( $ticket_id );
		if ( $ticket && $ticket->post_type === 'jc_support_ticket' ) {
			$ticket_discord_user = get_post_meta( $ticket_id, '_jc_ticket_discord_user', true );
			
			if ( $ticket_discord_user['id'] === $discord_user['id'] ) {
				$replies = get_post_meta( $ticket_id, '_jc_ticket_replies', true ) ?: array();
				$replies[] = array(
					'author' => $discord_user['username'],
					'message' => $reply,
					'date' => current_time( 'mysql' ),
					'is_admin' => false,
				);
				update_post_meta( $ticket_id, '_jc_ticket_replies', $replies );
				update_post_meta( $ticket_id, '_jc_ticket_status', 'open' );
			}
		}
	}

	ob_start();
	jc_support_styles();
	?>
	<div class="jc-support-wrap">
		<?php if ( ! $discord_user ) : ?>
			<!-- Login Screen -->
			<div class="jc-support-login">
				<div class="jc-login-card">
					<div class="jc-login-icon">🎮</div>
					<h1 class="jc-login-title">JustCreators Support</h1>
					<p class="jc-login-sub">Melde dich mit deinem Discord-Account an, um Support-Tickets zu erstellen.</p>
					
					<a href="<?php echo esc_url( jc_support_get_discord_login_url( add_query_arg( array() ) ) ); ?>" class="jc-discord-btn">
						<svg width="20" height="20" viewBox="0 0 71 55" fill="none" xmlns="http://www.w3.org/2000/svg">
							<path d="M60.1045 4.8978C55.5792 2.8214 50.7265 1.2916 45.6527 0.41542C45.5603 0.39851 45.468 0.440769 45.4204 0.525289C44.7963 1.6353 44.105 3.0834 43.6209 4.2216C38.1637 3.4046 32.7345 3.4046 27.3892 4.2216C26.905 3.0581 26.1886 1.6353 25.5617 0.525289C25.5141 0.443589 25.4218 0.40133 25.3294 0.41542C20.2584 1.2888 15.4057 2.8186 10.8776 4.8978C10.8384 4.9147 10.8048 4.9429 10.7825 4.9795C1.57795 18.7309 -0.943561 32.1443 0.293408 45.3914C0.299005 45.4562 0.335386 45.5182 0.385761 45.5576C6.45866 50.0174 12.3413 52.7249 18.1147 54.5195C18.2071 54.5477 18.305 54.5139 18.3638 54.4378C19.7295 52.5728 20.9469 50.6063 21.9907 48.5383C22.0523 48.4172 21.9935 48.2735 21.8676 48.2256C19.9366 47.4931 18.0979 46.6 16.3292 45.5858C16.1893 45.5041 16.1781 45.304 16.3068 45.2082C16.679 44.9293 17.0513 44.6391 17.4067 44.3461C17.471 44.2926 17.5606 44.2813 17.6362 44.3151C29.2558 49.6202 41.8354 49.6202 53.3179 44.3151C53.3935 44.2785 53.4831 44.2898 53.5502 44.3433C53.9057 44.6363 54.2779 44.9293 54.6529 45.2082C54.7816 45.304 54.7732 45.5041 54.6333 45.5858C52.8646 46.6197 51.0259 47.4931 49.0921 48.2228C48.9662 48.2707 48.9102 48.4172 48.9718 48.5383C50.038 50.6034 51.2554 52.5699 52.5959 54.435C52.6519 54.5139 52.7526 54.5477 52.845 54.5195C58.6464 52.7249 64.529 50.0174 70.6019 45.5576C70.6551 45.5182 70.6887 45.459 70.6943 45.3942C72.1747 30.0791 68.2147 16.7757 60.1968 4.9823C60.1772 4.9429 60.1437 4.9147 60.1045 4.8978ZM23.7259 37.3253C20.2276 37.3253 17.3451 34.1136 17.3451 30.1693C17.3451 26.225 20.1717 23.0133 23.7259 23.0133C27.308 23.0133 30.1626 26.2532 30.1066 30.1693C30.1066 34.1136 27.28 37.3253 23.7259 37.3253ZM47.3178 37.3253C43.8196 37.3253 40.9371 34.1136 40.9371 30.1693C40.9371 26.225 43.7636 23.0133 47.3178 23.0133C50.9 23.0133 53.7545 26.2532 53.6986 30.1693C53.6986 34.1136 50.9 37.3253 47.3178 37.3253Z" fill="currentColor"/>
						</svg>
						Mit Discord anmelden
					</a>

					<div class="jc-login-info">
						<div class="jc-info-item">
							<span class="jc-info-icon">🐛</span>
							<div>
								<strong>Bug Reports</strong>
								<p>Melde Fehler und Probleme</p>
							</div>
						</div>
						<div class="jc-info-item">
							<span class="jc-info-icon">🚫</span>
							<div>
								<strong>Hacker Reports</strong>
								<p>Melde verdächtige Spieler</p>
							</div>
						</div>
						<div class="jc-info-item">
							<span class="jc-info-icon">💡</span>
							<div>
								<strong>Mod Suggestions</strong>
								<p>Schlage neue Mods vor</p>
							</div>
						</div>
						<div class="jc-info-item">
							<span class="jc-info-icon">❓</span>
							<div>
								<strong>Allgemeiner Support</strong>
								<p>Stelle Fragen</p>
							</div>
						</div>
					</div>
				</div>
			</div>

		<?php else : ?>
			<!-- Logged In Screen -->
			<div class="jc-support-header">
				<div class="jc-support-user">
					<img src="https://cdn.discordapp.com/avatars/<?php echo esc_attr( $discord_user['id'] ); ?>/<?php echo esc_attr( $discord_user['avatar'] ); ?>.png" alt="Avatar" class="jc-user-avatar">
					<div>
						<div class="jc-user-name"><?php echo esc_html( $discord_user['username'] ); ?></div>
						<?php if ( $discord_user['is_member'] ) : ?>
							<div class="jc-user-badge">✓ Teilnehmer</div>
						<?php else : ?>
							<div class="jc-user-badge jc-badge-error">Kein Teilnehmer</div>
						<?php endif; ?>
					</div>
				</div>
				<a href="?jc_logout=1" class="jc-logout-btn">Abmelden</a>
			</div>
$is_admin ) : ?>
				<!-- ADMIN VIEW -->
				<?php jc_support_render_admin_view( $discord_user, $is_super_admin ); ?>

			<?php elseif ( 
			<?php if ( ! $discord_user['is_member'] ) : ?>
				<div class="jc-error-notice">
					⚠️ Du bist kein verifizierter Teilnehmer. Tritt dem Discord-Server bei, um Support-Anfragen zu erstellen.
				</div>
			<?php else : ?>

				<!-- Ticket Creation Form -->
				<div class="jc-ticket-form-container">
					<h2 class="jc-section-title">Neues Ticket erstellen</h2>
					<form method="post" class="jc-ticket-form">
						<div class="jc-form-group">
							<label>Kategorie</label>
							<select name="ticket_category" required class="jc-select">
								<option value="">Wähle eine Kategorie</option>
								<option value="Bug Report">🐛 Bug Report</option>
							div class="jc-user-badges">
							<?php if ( $is_super_admin ) : ?>
								<span class="jc-user-badge jc-badge-super-admin">👑 Super Admin</span>
							<?php elseif ( $is_admin ) : ?>
								<span class="jc-user-badge jc-badge-admin">👨‍💼 Admin</span>
							<?php elseif ( $discord_user['is_member'] ) : ?>
								<span class="jc-user-badge">✓ Teilnehmer</span>
							<?php else : ?>
								<span class="jc-user-badge jc-badge-error">Kein Teilnehmer</span>
							<?php endif; ?>
						</div

						<div class="jc-form-group">
							<label>Titel</label>
							<input type="text" name="ticket_title" required class="jc-input" placeholder="Kurze Beschreibung des Problems">
						</div>

						<div class="jc-form-group">
							<label>Nachricht</label>
							<textarea name="ticket_message" rows="6" required class="jc-textarea" placeholder="Beschreibe dein Anliegen ausführlich..."></textarea>
						</div>

						<button type="submit" name="jc_submit_ticket" class="jc-submit-btn">Ticket erstellen</button>
					</form>
				</div>

				<!-- User's Tickets -->
				<div class="jc-tickets-container">
					<h2 class="jc-section-title">Deine Tickets</h2>
					
					<?php
					$user_tickets = get_posts( array(
						'post_type' => 'jc_support_ticket',
						'posts_per_page' => -1,
						'meta_query' => array(
							array(
								'key' => '_jc_ticket_discord_user',
								'value' => serialize( $discord_user['id'] ),
								'compare' => 'LIKE',
							),
						),
					) );

					if ( empty( $user_tickets ) ) : ?>
						<p class="jc-no-tickets">Du hast noch keine Tickets erstellt.</p>
					<?php else : ?>
						<div class="jc-tickets-grid">
							<?php foreach ( $user_tickets as $ticket ) :
								$category = get_post_meta( $ticket->ID, '_jc_ticket_category', true );
								$status = get_post_meta( $ticket->ID, '_jc_ticket_status', true ) ?: 'open';
								$replies = get_post_meta( $ticket->ID, '_jc_ticket_replies', true ) ?: array();
							?>
							<div class="jc-ticket-card" data-status="<?php echo esc_attr( $status ); ?>">
								<div class="jc-ticket-header">
									<span class="jc-ticket-category"><?php echo esc_html( $category ); ?></span>
									<span class="jc-ticket-status jc-status-<?php echo esc_attr( $status ); ?>">
										<?php
										$status_map = array( 'open' => 'Offen', 'answered' => 'Beantwortet', 'closed' => 'Geschlossen' );
										echo esc_html( $status_map[ $status ] ?? 'Offen' );
										?>
									</span>
								</div>

								<h3 class="jc-ticket-title"><?php echo esc_html( $ticket->post_title ); ?></h3>
								<p class="jc-ticket-message"><?php echo esc_html( wp_trim_words( $ticket->post_content, 30 ) ); ?></p>

								<?php if ( ! empty( $replies ) ) : ?>
									<div class="jc-ticket-replies">
										<div class="jc-replies-header">💬 <?php echo count( $replies ); ?> Antwort(en)</div>
										<?php foreach ( $replies as $reply ) : ?>
											<div class="jc-reply <?php echo $reply['is_admin'] ? 'jc-reply-admin' : ''; ?>">
												<div class="jc-reply-author">
													<?php echo $reply['is_admin'] ? '👨‍💼 ' : ''; ?>
													<?php echo esc_html( $reply['author'] ); ?>
													<span class="jc-reply-date"><?php echo esc_html( date_i18n( 'd.m.Y H:i', strtotime( $reply['date'] ) ) ); ?></span>
												</div>
												<div class="jc-reply-message"><?php echo nl2br( esc_html( $reply['message'] ) ); ?></div>
											</div>
										<?php endforeach; ?>
									</div>

									<?php if ( $status !== 'closed' ) : ?>
										<form method="post" class="jc-reply-form">
											<input type="hidden" name="ticket_id" value="<?php echo esc_attr( $ticket->ID ); ?>">
											<textarea name="user_reply_message" rows="3" placeholder="Deine Antwort..." class="jc-reply-textarea" required></textarea>
											<button type="submit" name="jc_ticket_user_reply" class="jc-reply-btn">Antworten</button>
										</form>
									<?php endif; ?>
								<?php endif; ?>
							</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
			Render admin view
 */
function jc_support_render_admin_view( $discord_user, $is_super_admin ) {
	// Get all tickets
	$tickets = get_posts( array(
		'post_type' => 'jc_support_ticket',
		'posts_per_page' => -1,
		'orderby' => 'date',
		'order' => 'DESC',
	) );

	$admin_list = jc_support_get_admin_list();
	?>

	<?php if ( $is_super_admin ) : ?>
	<!-- Admin Management -->
	<div class="jc-admin-management">
		<h2 class="jc-section-title">👑 Admin Verwaltung</h2>
		<form method="post" class="jc-admin-form">
			<?php wp_nonce_field( 'jc_add_admin' ); ?>
			<div style="display:flex;gap:12px;align-items:end;">
				<div style="flex:1;">
					<label style="display:block;margin-bottom:6px;color:var(--jc-text);font-weight:700;">Discord User ID</label>
					<input type="text" name="admin_user_id" required placeholder="z.B. 123456789012345678" class="jc-input">
				</div>
				<button type="submit" name="jc_add_admin" class="jc-submit-btn">Admin hinzufügen</button>
			</div>
		</form>
s { display:flex; gap:8px; flex-wrap:wrap; margin-top:4px; }
		.jc-user-badge { display:inline-block; padding:4px 10px; background:rgba(76,175,80,0.15); border:1px solid rgba(76,175,80,0.3); color:#4caf50; border-radius:6px; font-size:12px; font-weight:700; }
		.jc-badge-error { background:rgba(244,67,54,0.15); border-color:rgba(244,67,54,0.3); color:#f44336; }
		.jc-badge-admin { background:rgba(108,123,255,0.15); border-color:rgba(108,123,255,0.3); color:#6c7bff; }
		.jc-badge-super-admin { background:linear-gradient(135deg,rgba(255,215,0,0.15),rgba(255,193,7,0.15)); border:1px solid rgba(255,215,0,0.4); color:#ffd700
			<div class="jc-admin-list">
				<h3 style="color:var(--jc-text);font-size:16px;margin:20px 0 12px;">Aktuelle Admins:</h3>
				<?php foreach ( $admin_list as $admin_id ) : ?>
					<div class="jc-admin-item">
						<span class="jc-admin-id">👨‍💼 <?php echo esc_html( $admin_id ); ?></span>
						<a href="<?php echo esc_url( wp_nonce_url( '?jc_remove_admin=' . urlencode( $admin_id ), 'jc_remove_admin' ) ); ?>" class="jc-remove-btn" onclick="return confirm('Admin wirklich entfernen?')">Entfernen</a>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<!-- All Tickets -->
	<div class="jc-admin-tickets-container">
		<h2 class="jc-section-title">🎫 Alle Support Tickets (<?php echo count( $tickets ); ?>)</h2>

		<?php if ( empty( $tickets ) ) : ?>
			<p class="jc-no-tickets">Keine Tickets vorhanden.</p>
		<?php else : ?>
			<div class="jc-tickets-grid">
				<?php foreach ( $tickets as $ticket ) :
					$category = get_post_meta( $ticket->ID, '_jc_ticket_category', true );
					$status = get_post_meta( $ticket->ID, '_jc_ticket_status', true ) ?: 'open';
					$ticket_discord_user = get_post_meta( $ticket->ID, '_jc_ticket_discord_user', true );
					$replies = get_post_meta( $ticket->ID, '_jc_ticket_replies', true ) ?: array();
					$claimed_by = get_post_meta( $ticket->ID, '_jc_ticket_claimed_by', true );
					$claimed_by_username = get_post_meta( $ticket->ID, '_jc_ticket_claimed_by_username', true );
				?>
				<div class="jc-ticket-card jc-admin-ticket-card" data-status="<?php echo esc_attr( $status ); ?>">
					<div class="jc-ticket-header">
						<span class="jc-ticket-category"><?php echo esc_html( $category ); ?></span>
						<span class="jc-ticket-status jc-status-<?php echo esc_attr( $status ); ?>">
							<?php
							$status_map = array( 'open' => 'Offen', 'answered' => 'Beantwortet', 'closed' => 'Geschlossen' );
							echo esc_html( $status_map[ $status ] ?? 'Offen' );
							?>
						</span>
					</div>

					<div class="jc-ticket-meta">
						<strong style="color:var(--jc-text);">Von:</strong> <?php echo esc_html( $ticket_discord_user['username'] ?? 'Unbekannt' ); ?>
						<br>
						<strong style="color:var(--jc-text);">Erstellt:</strong> <?php echo esc_html( date_i18n( 'd.m.Y H:i', strtotime( $ticket->post_date ) ) ); ?>
					</div>

					<?php if ( $claimed_by ) : ?>
		/* Admin Management */
		.jc-admin-management { background:var(--jc-panel); border:1px solid var(--jc-border); border-radius:16px; padding:30px; margin-bottom:30px; box-shadow:0 12px 32px rgba(0,0,0,0.3); }
		.jc-admin-form { margin-top:16px; }
		.jc-admin-list { margin-top:20px; }
		.jc-admin-item { display:flex; justify-content:space-between; align-items:center; background:rgba(30,39,64,0.4); border:1px solid var(--jc-border); padding:12px 16px; border-radius:10px; margin-bottom:8px; }
		.jc-admin-id { color:var(--jc-text); font-weight:600; }
		.jc-remove-btn { padding:6px 12px; background:rgba(244,67,54,0.1); border:1px solid rgba(244,67,54,0.3); color:#f44336; border-radius:6px; text-decoration:none; font-size:12px; font-weight:700; transition:all .2s; }
		.jc-remove-btn:hover { background:rgba(244,67,54,0.2); color:#f44336; }

		/* Admin Tickets */
		.jc-admin-tickets-container { background:var(--jc-panel); border:1px solid var(--jc-border); border-radius:16px; padding:30px; box-shadow:0 12px 32px rgba(0,0,0,0.3); }
		.jc-admin-ticket-card { border:2px solid var(--jc-border); }
		.jc-ticket-meta { color:var(--jc-muted); font-size:13px; margin-bottom:12px; line-height:1.6; }
		.jc-ticket-claimed { background:rgba(76,175,80,0.1); border:1px solid rgba(76,175,80,0.3); color:#4caf50; padding:8px 12px; border-radius:8px; font-size:13px; margin-bottom:12px; display:flex; justify-content:space-between; align-items:center; }
		.jc-unclaim-link { color:#4caf50; text-decoration:underline; font-size:12px; }
		.jc-claim-btn { display:inline-block; padding:8px 14px; background:rgba(108,123,255,0.15); border:1px solid rgba(108,123,255,0.3); color:var(--jc-accent); border-radius:8px; text-decoration:none; font-size:13px; font-weight:700; margin-bottom:12px; transition:all .2s; }
		.jc-claim-btn:hover { background:rgba(108,123,255,0.25); color:var(--jc-accent); }
		.jc-admin-reply-form { margin-top:12px; }
		.jc-close-ticket-btn { padding:8px 16px; background:rgba(244,67,54,0.1); border:1px solid rgba(244,67,54,0.3); color:#f44336; border-radius:8px; text-decoration:none; font-size:13px; font-weight:700; transition:all .2s; display:inline-block; }
		.jc-close-ticket-btn:hover { background:rgba(244,67,54,0.2); color:#f44336; }

		@media (max-width:768px) {
			.jc-support-wrap { padding:20px 12px; }
			.jc-login-card { padding:30px 20px; }
			.jc-support-header { flex-direction:column; gap:16px; text-align:center; }
			.jc-admin-item { flex-direction:column; gap:10jc_unclaim_ticket=' . $ticket->ID, 'jc_unclaim_ticket' ) ); ?>" class="jc-unclaim-link">Freigeben</a>
							<?php endif; ?>
						</div>
					<?php else : ?>
						<a href="<?php echo esc_url( wp_nonce_url( '?jc_claim_ticket=' . $ticket->ID, 'jc_claim_ticket' ) ); ?>" class="jc-claim-btn">📌 Ticket claimen</a>
					<?php endif; ?>

					<h3 class="jc-ticket-title"><?php echo esc_html( $ticket->post_title ); ?></h3>
					<p class="jc-ticket-message"><?php echo nl2br( esc_html( $ticket->post_content ) ); ?></p>

					<?php if ( ! empty( $replies ) ) : ?>
						<div class="jc-ticket-replies">
							<div class="jc-replies-header">💬 <?php echo count( $replies ); ?> Antwort(en)</div>
							<?php foreach ( $replies as $reply ) : ?>
								<div class="jc-reply <?php echo $reply['is_admin'] ? 'jc-reply-admin' : ''; ?>">
									<div class="jc-reply-author">
										<?php echo $reply['is_admin'] ? '👨‍💼 ' : ''; ?>
										<?php echo esc_html( $reply['author'] ); ?>
										<span class="jc-reply-date"><?php echo esc_html( date_i18n( 'd.m.Y H:i', strtotime( $reply['date'] ) ) ); ?></span>
									</div>
									<div class="jc-reply-message"><?php echo nl2br( esc_html( $reply['message'] ) ); ?></div>
								</div>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>

					<?php if ( $status !== 'closed' ) : ?>
						<form method="post" class="jc-admin-reply-form">
							<?php wp_nonce_field( 'jc_admin_reply' ); ?>
							<input type="hidden" name="ticket_id" value="<?php echo esc_attr( $ticket->ID ); ?>">
							<textarea name="admin_reply_message" rows="3" placeholder="Deine Antwort als Admin..." class="jc-reply-textarea" required></textarea>
							<div style="display:flex;gap:8px;margin-top:8px;">
								<button type="submit" name="jc_admin_reply" class="jc-reply-btn">Antworten</button>
								<a href="<?php echo esc_url( wp_nonce_url( '?jc_close_ticket=' . $ticket->ID, 'jc_close_ticket' ) ); ?>" class="jc-close-ticket-btn" onclick="return confirm('Ticket schließen?')">Schließen</a>
							</div>
						</form>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>

	<?php
}

/**
 * 	</div>

			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Styles
 */
function jc_support_styles() {
	?>
	<style>
		:root { --jc-bg:#050712; --jc-panel:#0b0f1d; --jc-border:#1e2740; --jc-text:#e9ecf7; --jc-muted:#9eb3d5; --jc-accent:#6c7bff; --jc-accent-2:#56d8ff; }
		
		.jc-support-wrap { min-height:100vh; background:var(--jc-bg); color:var(--jc-text); font-family:"Space Grotesk","Inter","SF Pro Display",system-ui,-apple-system,sans-serif; padding:40px 20px; }
		
		/* Login Screen */
		.jc-support-login { display:flex; align-items:center; justify-content:center; min-height:80vh; }
		.jc-login-card { max-width:600px; width:100%; background:var(--jc-panel); border:1px solid var(--jc-border); border-radius:20px; padding:40px; text-align:center; box-shadow:0 24px 68px rgba(0,0,0,0.5); }
		.jc-login-icon { font-size:64px; margin-bottom:20px; }
		.jc-login-title { font-size:32px; color:var(--jc-text); margin:0 0 12px; }
		.jc-login-sub { color:var(--jc-muted); margin:0 0 30px; line-height:1.6; }
		.jc-discord-btn { display:inline-flex; align-items:center; gap:12px; padding:14px 28px; background:linear-gradient(135deg,#5865f2,#7983f5); color:#fff; text-decoration:none; border-radius:12px; font-weight:700; transition:transform .2s,box-shadow .2s; box-shadow:0 12px 32px rgba(88,101,242,0.4); }
		.jc-discord-btn:hover { transform:translateY(-2px); box-shadow:0 16px 40px rgba(88,101,242,0.5); color:#fff; }
		.jc-login-info { margin-top:40px; display:grid; grid-template-columns:repeat(auto-fit,minmax(250px,1fr)); gap:16px; text-align:left; }
		.jc-info-item { display:flex; gap:12px; align-items:start; padding:16px; background:rgba(108,123,255,0.05); border:1px solid rgba(108,123,255,0.1); border-radius:12px; }
		.jc-info-icon { font-size:24px; }
		.jc-info-item strong { display:block; color:var(--jc-text); margin-bottom:4px; }
		.jc-info-item p { margin:0; color:var(--jc-muted); font-size:13px; }

		/* Header */
		.jc-support-header { display:flex; justify-content:space-between; align-items:center; background:var(--jc-panel); border:1px solid var(--jc-border); border-radius:16px; padding:20px 24px; margin-bottom:30px; box-shadow:0 12px 32px rgba(0,0,0,0.3); }
		.jc-support-user { display:flex; align-items:center; gap:16px; }
		.jc-user-avatar { width:48px; height:48px; border-radius:50%; border:2px solid var(--jc-accent); }
		.jc-user-name { font-weight:700; font-size:18px; color:var(--jc-text); }
		.jc-user-badge { display:inline-block; padding:4px 10px; background:rgba(76,175,80,0.15); border:1px solid rgba(76,175,80,0.3); color:#4caf50; border-radius:6px; font-size:12px; font-weight:700; margin-top:4px; }
		.jc-badge-error { background:rgba(244,67,54,0.15); border-color:rgba(244,67,54,0.3); color:#f44336; }
		.jc-logout-btn { padding:10px 20px; background:rgba(244,67,54,0.1); border:1px solid rgba(244,67,54,0.3); color:#f44336; border-radius:10px; text-decoration:none; font-weight:700; transition:all .2s; }
		.jc-logout-btn:hover { background:rgba(244,67,54,0.2); color:#f44336; }

		/* Error Notice */
		.jc-error-notice { background:rgba(255,193,7,0.1); border:1px solid rgba(255,193,7,0.3); color:#ffc107; padding:16px 20px; border-radius:12px; margin-bottom:30px; }

		/* Ticket Form */
		.jc-ticket-form-container { background:var(--jc-panel); border:1px solid var(--jc-border); border-radius:16px; padding:30px; margin-bottom:30px; box-shadow:0 12px 32px rgba(0,0,0,0.3); }
		.jc-section-title { font-size:24px; color:var(--jc-text); margin:0 0 20px; }
		.jc-form-group { margin-bottom:20px; }
		.jc-form-group label { display:block; color:var(--jc-text); font-weight:700; margin-bottom:8px; }
		.jc-select, .jc-input, .jc-textarea { width:100%; padding:12px 16px; background:rgba(30,39,64,0.6); border:1px solid var(--jc-border); border-radius:10px; color:var(--jc-text); font-size:14px; font-family:inherit; transition:border-color .2s; }
		.jc-select:focus, .jc-input:focus, .jc-textarea:focus { outline:none; border-color:var(--jc-accent); }
		.jc-textarea { resize:vertical; }
		.jc-submit-btn { padding:14px 28px; background:linear-gradient(135deg,var(--jc-accent),var(--jc-accent-2)); color:#050712; border:none; border-radius:12px; font-weight:800; cursor:pointer; transition:transform .2s,box-shadow .2s; box-shadow:0 12px 32px rgba(108,123,255,0.4); }
		.jc-submit-btn:hover { transform:translateY(-2px); box-shadow:0 16px 40px rgba(108,123,255,0.5); }

		/* Tickets */
		.jc-tickets-container { background:var(--jc-panel); border:1px solid var(--jc-border); border-radius:16px; padding:30px; box-shadow:0 12px 32px rgba(0,0,0,0.3); }
		.jc-no-tickets { color:var(--jc-muted); text-align:center; padding:40px; }
		.jc-tickets-grid { display:grid; gap:20px; }
		.jc-ticket-card { background:rgba(30,39,64,0.4); border:1px solid var(--jc-border); border-radius:12px; padding:20px; transition:transform .2s,border-color .2s; }
		.jc-ticket-card:hover { transform:translateY(-3px); border-color:rgba(108,123,255,0.3); }
		.jc-ticket-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
		.jc-ticket-category { background:rgba(108,123,255,0.15); border:1px solid rgba(108,123,255,0.3); color:var(--jc-accent); padding:6px 12px; border-radius:8px; font-size:12px; font-weight:700; }
		.jc-ticket-status { padding:6px 12px; border-radius:8px; font-size:12px; font-weight:700; }
		.jc-status-open { background:rgba(244,67,54,0.15); border:1px solid rgba(244,67,54,0.3); color:#f44336; }
		.jc-status-answered { background:rgba(255,193,7,0.15); border:1px solid rgba(255,193,7,0.3); color:#ffc107; }
		.jc-status-closed { background:rgba(76,175,80,0.15); border:1px solid rgba(76,175,80,0.3); color:#4caf50; }
		.jc-ticket-title { font-size:18px; color:var(--jc-text); margin:0 0 10px; font-weight:700; }
		.jc-ticket-message { color:var(--jc-muted); line-height:1.6; margin:0 0 16px; }

		/* Replies */
		.jc-ticket-replies { border-top:1px solid var(--jc-border); margin-top:16px; padding-top:16px; }
		.jc-replies-header { color:var(--jc-accent); font-weight:700; margin-bottom:12px; }
		.jc-reply { background:rgba(11,15,29,0.6); padding:12px; border-radius:8px; margin-bottom:10px; border-left:3px solid var(--jc-border); }
		.jc-reply-admin { border-left-color:var(--jc-accent); background:rgba(108,123,255,0.05); }
		.jc-reply-author { display:flex; justify-content:space-between; color:var(--jc-text); font-weight:700; font-size:13px; margin-bottom:6px; }
		.jc-reply-date { color:var(--jc-muted); font-weight:400; }
		.jc-reply-message { color:var(--jc-muted); line-height:1.5; font-size:14px; }
		.jc-reply-form { margin-top:12px; }
		.jc-reply-textarea { width:100%; padding:10px 14px; background:rgba(30,39,64,0.6); border:1px solid var(--jc-border); border-radius:8px; color:var(--jc-text); font-size:13px; font-family:inherit; resize:vertical; margin-bottom:8px; }
		.jc-reply-btn { padding:8px 16px; background:linear-gradient(135deg,var(--jc-accent),var(--jc-accent-2)); color:#050712; border:none; border-radius:8px; font-weight:700; cursor:pointer; font-size:13px; }

		@media (max-width:768px) {
			.jc-support-wrap { padding:20px 12px; }
			.jc-login-card { padding:30px 20px; }
			.jc-support-header { flex-direction:column; gap:16px; text-align:center; }
		}
	</style>
	<?php
}
