<?php
/**
 * Plugin Name: JustCreators Support
 * Description: Discord-basiertes Support-System (Bug Reports, Hacker Reports, Mod Suggestions, allgemeiner Support) mit reinem Web-Frontend.
 * Version: 1.0.1
 * Author: JustCreators Team
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Constants (option keys + defaults)
define( 'JC_SUPPORT_DISCORD_CLIENT_ID', 'jc_support_discord_client_id' );
define( 'JC_SUPPORT_DISCORD_CLIENT_SECRET', 'jc_support_discord_client_secret' );
define( 'JC_SUPPORT_DISCORD_SERVER_ID', '1432046234117341267' );
define( 'JC_SUPPORT_DISCORD_MEMBER_ROLE_ID', 'jc_support_discord_member_role_id' );
define( 'JC_SUPPORT_SUPER_ADMIN', 'kabel_entwirer' ); // Discord-Username des Super-Admins
define( 'JC_SUPPORT_ADMINS_OPTION', 'jc_support_admin_users' ); // Liste zugelassener Admin-User-IDs

// Hooks                                                       
add_action( 'init', 'jc_support_session_start', 1 );
add_action( 'init', 'jc_support_register_post_type' );
add_action( 'init', 'jc_support_handle_discord_callback' );
add_action( 'init', 'jc_support_handle_frontend_actions' );
add_action( 'admin_menu', 'jc_support_register_admin_menu' );
add_shortcode( 'jc_support', 'jc_support_render_shortcode' );

/**
 * Starte Session zentral und sicher.
 */
function jc_support_session_start() {
	if ( session_status() === PHP_SESSION_ACTIVE ) {
		return;
	}

	$domain = parse_url( home_url(), PHP_URL_HOST );
	if ( $domain && substr( $domain, 0, 1 ) !== '.' ) {
		$domain = '.' . $domain;
	}

	$secure   = is_ssl();
	$httponly = true;

	@session_set_cookie_params( array(
		'lifetime' => 0,
		'path'     => '/',
		'domain'   => $domain ?: '',
		'secure'   => $secure,
		'httponly' => $httponly,
		'samesite' => 'Lax',
	) );

	@session_start();
}

// Credential / Config Getter (nutzt wp-config Konstanten falls vorhanden)
function jc_support_get_client_id() {
	if ( defined( 'JC_DISCORD_CLIENT_ID' ) && JC_DISCORD_CLIENT_ID ) {
		return JC_DISCORD_CLIENT_ID;
	}
	return get_option( JC_SUPPORT_DISCORD_CLIENT_ID );
}

function jc_support_get_client_secret() {
	if ( defined( 'JC_DISCORD_CLIENT_SECRET' ) && JC_DISCORD_CLIENT_SECRET ) {
		return JC_DISCORD_CLIENT_SECRET;
	}
	return get_option( JC_SUPPORT_DISCORD_CLIENT_SECRET );
}

function jc_support_get_server_id() {
	if ( defined( 'JC_DISCORD_SERVER_ID' ) && JC_DISCORD_SERVER_ID ) {
		return JC_DISCORD_SERVER_ID;
	}
	return get_option( JC_SUPPORT_DISCORD_SERVER_ID );
}

function jc_support_get_member_role_id() {
	if ( defined( 'JC_DISCORD_MEMBER_ROLE_ID' ) && JC_DISCORD_MEMBER_ROLE_ID ) {
		return JC_DISCORD_MEMBER_ROLE_ID;
	}
	return get_option( JC_SUPPORT_DISCORD_MEMBER_ROLE_ID );
}

/**
 * Admin-Menu für Settings registrieren (nur Super-Admin).
 */
function jc_support_register_admin_menu() {
	$super_admin = get_option( 'jc_support_super_admin_user' ) ?: JC_SUPPORT_SUPER_ADMIN;
	add_menu_page(
		'JC Support Settings',
		'Support Settings',
		'manage_options',
		'jc-support-settings',
		'jc_support_render_admin_page',
		'dashicons-sos',
		99
	);
}

/**
 * Admin-Seite für Settings rendern.
 */
function jc_support_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Zugriff verweigert.' );
	}

	// Save Webhooks
	if ( isset( $_POST['jc_save_webhooks'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'jc_support_webhooks' ) ) {
		$webhook_url = isset( $_POST['jc_webhook_url'] ) ? esc_url_raw( $_POST['jc_webhook_url'] ) : '';
		update_option( 'jc_support_webhook_url', $webhook_url );
		echo '<div class="updated"><p>Webhook gespeichert!</p></div>';
	}

	$webhook_url = get_option( 'jc_support_webhook_url', '' );
	$tickets = get_posts( array( 'post_type' => 'jc_support_ticket', 'numberposts' => -1 ) );

	?>
	<div class="wrap">
		<h1>Support System Settings</h1>

		<div style="max-width:900px; margin:20px 0;">
			<h2>🔗 Discord Webhooks</h2>
			<p>Webhooks werden verwendet um neue Tickets und Replies im Discord-Server zu posten.</p>
			<form method="post">
				<?php wp_nonce_field( 'jc_support_webhooks' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row"><label for="webhook_url">Webhook URL</label></th>
						<td>
							<input type="url" name="jc_webhook_url" id="webhook_url" class="regular-text" value="<?php echo esc_attr( $webhook_url ); ?>" placeholder="https://discordapp.com/api/webhooks/...">
							<p class="description">URL für Discord-Webhooks um Benachrichtigungen zu senden.</p>
						</td>
					</tr>
				</table>
				<p class="submit"><button type="submit" name="jc_save_webhooks" class="button button-primary">Speichern</button></p>
			</form>
		</div>

		<hr>

		<div style="max-width:900px; margin:20px 0;">
			<h2>📊 Ticket-Statistik</h2>
			<p>Gesamt Tickets: <strong><?php echo count( $tickets ); ?></strong></p>
			<p style="margin-top:20px;"><a href="edit.php?post_type=jc_support_ticket" class="button">Tickets im WP-Admin anzeigen</a></p>
		</div>
	</div>
	<?php
}

/**
 * Helper: Generiere eindeutige Ticket-ID.
 */
function jc_support_generate_ticket_id() {
	$last_ticket = get_posts( array(
		'post_type'      => 'jc_support_ticket',
		'posts_per_page' => 1,
		'orderby'        => 'ID',
		'order'          => 'DESC',
	) );

	$next_num = 1;
	if ( ! empty( $last_ticket ) ) {
		$last_meta = get_post_meta( $last_ticket[0]->ID, '_jc_ticket_number', true );
		$next_num  = ( $last_meta ? intval( $last_meta ) : 0 ) + 1;
	}

	return str_pad( $next_num, 4, '0', STR_PAD_LEFT );
}

/**
 * Helper: Sende Webhook-Benachrichtigung.
 */
function jc_support_send_webhook( $message ) {
	$webhook_url = get_option( 'jc_support_webhook_url', '' );
	if ( ! $webhook_url ) {
		return;
	}

	$response = wp_remote_post( $webhook_url, array(
		'body' => json_encode( $message ),
		'headers' => array( 'Content-Type' => 'application/json' ),
	) );

	if ( is_wp_error( $response ) ) {
		error_log( '[JC Support] Webhook error: ' . $response->get_error_message() );
	}
}

/**
 * Custom Post Type für Tickets.
 */
function jc_support_register_post_type() {
	register_post_type( 'jc_support_ticket', array(
		'labels' => array(
			'name' => 'Support Tickets',
			'singular_name' => 'Ticket',
		),
		'public' => false,
		'show_ui' => false,
		'supports' => array( 'title', 'editor', 'author' ),
	) );
}

/**
 * Discord OAuth2 Callback.
 */
function jc_support_handle_discord_callback() {
	if ( ! isset( $_GET['jc_discord_callback'], $_GET['code'] ) ) {
		return;
	}

	jc_support_session_start();

	$code          = sanitize_text_field( wp_unslash( $_GET['code'] ) );
	$client_id     = jc_support_get_client_id();
	$client_secret = jc_support_get_client_secret();
	$redirect_uri  = home_url( '/?jc_discord_callback=1' );

	// Token anfordern
	$token_response = wp_remote_post( 'https://discord.com/api/oauth2/token', array(
		'body'    => array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => $redirect_uri,
		),
		'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
	) );

	if ( is_wp_error( $token_response ) ) {
		wp_die( 'Discord Login fehlgeschlagen (Token).' );
	}

	$token_data = json_decode( wp_remote_retrieve_body( $token_response ), true );
	if ( empty( $token_data['access_token'] ) ) {
		wp_die( 'Discord Login fehlgeschlagen: Kein Access Token.' );
	}
	$access_token = $token_data['access_token'];

	// User-Info
	$user_response = wp_remote_get( 'https://discord.com/api/users/@me', array(
		'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
	) );
	if ( is_wp_error( $user_response ) ) {
		wp_die( 'Fehler beim Abrufen der Discord-Daten.' );
	}
	$user_data = json_decode( wp_remote_retrieve_body( $user_response ), true );
	if ( empty( $user_data['id'] ) ) {
		wp_die( 'Fehler beim Abrufen der Discord-Daten.' );
	}

	// Mitgliedschaft prüfen
	$is_member = jc_support_check_membership( $user_data['id'], $access_token );

	$_SESSION['jc_discord_user'] = array(
		'id'           => $user_data['id'],
		'username'     => $user_data['username'],
		'discriminator'=> $user_data['discriminator'] ?? '0',
		'avatar'       => $user_data['avatar'],
		'is_member'    => $is_member,
		'access_token' => $access_token,
	);

	$return_url = isset( $_GET['state'] ) ? base64_decode( sanitize_text_field( wp_unslash( $_GET['state'] ) ) ) : home_url();
	wp_safe_redirect( $return_url );
	exit;
}

/**
 * Prüfe Discord-Server Mitgliedschaft via User-Token.
 */
function jc_support_check_membership( $user_id, $access_token ) {
	$server_id = jc_support_get_server_id();
	if ( ! $server_id ) {
		// Server ID nicht konfiguriert - akzeptiere alle als Mitglieder für Tests
		error_log( '[JC Support] Server ID nicht konfiguriert. Benutzer als Mitglied angenommen.' );
		return true;
	}

	$response = wp_remote_get( "https://discord.com/api/v10/users/@me/guilds/{$server_id}/member", array(
		'headers' => array( 
			'Authorization' => 'Bearer ' . $access_token,
			'User-Agent' => 'JustCreators-Support/1.0'
		),
		'timeout' => 10,
	) );
	
	$code = wp_remote_retrieve_response_code( $response );
	
	if ( is_wp_error( $response ) ) {
		error_log( '[JC Support] Membership check error: ' . $response->get_error_message() );
		return false;
	}
	
	$is_member = 200 === $code;
	
	// Debug-Logging
	if ( ! $is_member ) {
		$body = wp_remote_retrieve_body( $response );
		error_log( '[JC Support] Membership check failed. Code: ' . $code . ', Response: ' . $body );
	}
	
	return $is_member;
}

/**
 * Login-URL bauen.
 */
function jc_support_get_discord_login_url( $return_url = '' ) {
	$client_id    = jc_support_get_client_id();
	$redirect_uri = home_url( '/?jc_discord_callback=1' );
	if ( ! $return_url ) {
		$return_url = home_url();
	}
	$state = base64_encode( $return_url );

	return add_query_arg( array(
		'client_id'     => $client_id,
		'redirect_uri'  => $redirect_uri,
		'response_type' => 'code',
		'scope'         => 'identify guilds.members.read',
		'state'         => $state,
	), 'https://discord.com/oauth2/authorize' );
}

/**
 * Aktuellen Discord-User aus Session holen.
 */
function jc_support_get_current_user() {
	jc_support_session_start();
	return isset( $_SESSION['jc_discord_user'] ) ? $_SESSION['jc_discord_user'] : null;
}

/**
 * Logout.
 */
function jc_support_logout() {
	jc_support_session_start();
	unset( $_SESSION['jc_discord_user'] );
}

/**
 * Super-Admin prüfen (per Discord-Username).
 */
function jc_support_is_super_admin( $discord_user ) {
	return $discord_user && ! empty( $discord_user['username'] ) && $discord_user['username'] === JC_SUPPORT_SUPER_ADMIN;
}

/**
 * Admin prüfen (Liste + Super-Admin).
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

function jc_support_get_admin_list() {
	return get_option( JC_SUPPORT_ADMINS_OPTION, array() );
}

function jc_support_add_admin( $user_id ) {
	$admins = jc_support_get_admin_list();
	if ( ! in_array( $user_id, $admins, true ) ) {
		$admins[] = $user_id;
		update_option( JC_SUPPORT_ADMINS_OPTION, $admins );
	}
}

function jc_support_remove_admin( $user_id ) {
	$admins = jc_support_get_admin_list();
	$key    = array_search( $user_id, $admins, true );
	if ( $key !== false ) {
		unset( $admins[ $key ] );
		update_option( JC_SUPPORT_ADMINS_OPTION, array_values( $admins ) );
	}
}

/**
 * Frontend-Aktionen (Admins / Tickets) verarbeiten.
 */
function jc_support_handle_frontend_actions() {
	$discord_user = jc_support_get_current_user();
	if ( ! $discord_user ) {
		return;
	}

	// Admin hinzufügen (nur Super-Admin)
	if ( isset( $_POST['jc_add_admin'], $_POST['admin_user_id'], $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'jc_add_admin' ) ) {
		if ( jc_support_is_super_admin( $discord_user ) ) {
			$user_id = sanitize_text_field( wp_unslash( $_POST['admin_user_id'] ) );
			jc_support_add_admin( $user_id );
			wp_safe_redirect( remove_query_arg( array() ) );
			exit;
		}
	}

	// Admin entfernen (nur Super-Admin)
	if ( isset( $_GET['jc_remove_admin'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'jc_remove_admin' ) ) {
		if ( jc_support_is_super_admin( $discord_user ) ) {
			$user_id = sanitize_text_field( wp_unslash( $_GET['jc_remove_admin'] ) );
			jc_support_remove_admin( $user_id );
			wp_safe_redirect( remove_query_arg( array( 'jc_remove_admin', '_wpnonce' ) ) );
			exit;
		}
	}

	// Ticket claimen
	if ( isset( $_GET['jc_claim_ticket'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'jc_claim_ticket' ) ) {
		if ( jc_support_is_admin( $discord_user ) ) {
			$ticket_id = intval( $_GET['jc_claim_ticket'] );
			update_post_meta( $ticket_id, '_jc_ticket_claimed_by', $discord_user['id'] );
			update_post_meta( $ticket_id, '_jc_ticket_claimed_by_username', $discord_user['username'] );
			wp_safe_redirect( remove_query_arg( array( 'jc_claim_ticket', '_wpnonce' ) ) );
			exit;
		}
	}

	// Claim lösen
	if ( isset( $_GET['jc_unclaim_ticket'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'jc_unclaim_ticket' ) ) {
		if ( jc_support_is_admin( $discord_user ) ) {
			$ticket_id = intval( $_GET['jc_unclaim_ticket'] );
			delete_post_meta( $ticket_id, '_jc_ticket_claimed_by' );
			delete_post_meta( $ticket_id, '_jc_ticket_claimed_by_username' );
			wp_safe_redirect( remove_query_arg( array( 'jc_unclaim_ticket', '_wpnonce' ) ) );
			exit;
		}
	}

	// Ticket schließen
	if ( isset( $_GET['jc_close_ticket'], $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'jc_close_ticket' ) ) {
		if ( jc_support_is_admin( $discord_user ) ) {
			$ticket_id = intval( $_GET['jc_close_ticket'] );
			update_post_meta( $ticket_id, '_jc_ticket_status', 'closed' );
			wp_safe_redirect( remove_query_arg( array( 'jc_close_ticket', '_wpnonce' ) ) );
			exit;
		}
	}

	// Admin-Antwort
	if ( isset( $_POST['jc_admin_reply'], $_POST['ticket_id'], $_POST['admin_reply_message'], $_POST['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'jc_admin_reply' ) ) {
		if ( jc_support_is_admin( $discord_user ) ) {
			$ticket_id = intval( $_POST['ticket_id'] );
			$reply     = sanitize_textarea_field( wp_unslash( $_POST['admin_reply_message'] ) );
			$replies   = get_post_meta( $ticket_id, '_jc_ticket_replies', true ) ?: array();
			$replies[] = array(
				'author'    => $discord_user['username'],
				'author_id' => $discord_user['id'],
				'message'   => $reply,
				'date'      => current_time( 'mysql' ),
				'is_admin'  => true,
			);
			update_post_meta( $ticket_id, '_jc_ticket_replies', $replies );
			update_post_meta( $ticket_id, '_jc_ticket_status', 'answered' );

			// Webhook senden - Admin Reply Benachrichtigung
			$ticket_number = get_post_meta( $ticket_id, '_jc_ticket_number', true );
			$ticket = get_post( $ticket_id );
			jc_support_send_webhook( array(
				'content' => '💬 **Admin hat geantwortet auf Ticket #' . $ticket_number . '**',
				'embeds' => array( array(
					'description' => $reply,
					'color' => 6571543,
					'fields' => array(
						array( 'name' => 'Admin', 'value' => $discord_user['username'], 'inline' => true ),
						array( 'name' => 'Ticket', 'value' => $ticket->post_title, 'inline' => true ),
					),
				) ),
			) );

			wp_safe_redirect( remove_query_arg( array() ) );
			exit;
		}
	}
}

/**
 * Shortcode Render.
 */
function jc_support_render_shortcode() {
	jc_support_session_start();

	$discord_user   = jc_support_get_current_user();
	$is_admin       = jc_support_is_admin( $discord_user );
	$is_super_admin = jc_support_is_super_admin( $discord_user );

	// Logout
	if ( isset( $_GET['jc_logout'] ) ) {
		jc_support_logout();
		wp_safe_redirect( remove_query_arg( 'jc_logout' ) );
		exit;
	}

	// Ticket erstellen
	if ( isset( $_POST['jc_submit_ticket'] ) && $discord_user ) {
		$category = sanitize_text_field( wp_unslash( $_POST['ticket_category'] ?? '' ) );
		$title    = sanitize_text_field( wp_unslash( $_POST['ticket_title'] ?? '' ) );
		$message  = sanitize_textarea_field( wp_unslash( $_POST['ticket_message'] ?? '' ) );

		if ( $category && $title && $message ) {
			$ticket_id = wp_insert_post( array(
				'post_type'    => 'jc_support_ticket',
				'post_title'   => $title,
				'post_content' => $message,
				'post_status'  => 'publish',
			) );
			if ( $ticket_id ) {
				$ticket_number = jc_support_generate_ticket_id();
				update_post_meta( $ticket_id, '_jc_ticket_number', $ticket_number );
				update_post_meta( $ticket_id, '_jc_ticket_category', $category );
				update_post_meta( $ticket_id, '_jc_ticket_status', 'open' );
				update_post_meta( $ticket_id, '_jc_ticket_discord_user', $discord_user );
				
				// Webhook senden
				jc_support_send_webhook( array(
					'content' => '🎫 **Neues Support Ticket**',
					'embeds' => array( array(
						'title' => "#$ticket_number - $title",
						'description' => $message,
						'color' => 6570280,
						'fields' => array(
							array( 'name' => 'Kategorie', 'value' => $category, 'inline' => true ),
							array( 'name' => 'Von', 'value' => $discord_user['username'], 'inline' => true ),
						),
					) ),
				) );

				echo '<div style="background:rgba(76,175,80,0.1);border:1px solid rgba(76,175,80,0.3);color:#4caf50;padding:12px;border-radius:8px;margin-bottom:20px;">✅ Ticket #' . esc_html( $ticket_number ) . ' erfolgreich erstellt!</div>';
			}
		}
	}

	// User-Reply
	if ( isset( $_POST['jc_ticket_user_reply'], $_POST['ticket_id'], $_POST['user_reply_message'] ) && $discord_user ) {
		$ticket_id = intval( $_POST['ticket_id'] );
		$reply     = sanitize_textarea_field( wp_unslash( $_POST['user_reply_message'] ) );
		$ticket    = get_post( $ticket_id );
		if ( $ticket && $ticket->post_type === 'jc_support_ticket' ) {
			$ticket_user = get_post_meta( $ticket_id, '_jc_ticket_discord_user', true );
			if ( isset( $ticket_user['id'] ) && $ticket_user['id'] === $discord_user['id'] ) {
				$replies   = get_post_meta( $ticket_id, '_jc_ticket_replies', true ) ?: array();
				$replies[] = array(
					'author'   => $discord_user['username'],
					'message'  => $reply,
					'date'     => current_time( 'mysql' ),
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
			<div class="jc-support-login">
				<div class="jc-login-card jc-card">
					<div class="jc-login-icon">🎮</div>
					<h1 class="jc-login-title">JustCreators Support</h1>
					<p class="jc-login-sub">Melde dich mit deinem Discord-Account an, um Support-Tickets zu erstellen.</p>
					<a href="<?php echo esc_url( jc_support_get_discord_login_url( add_query_arg( array() ) ) ); ?>" class="jc-discord-btn">Mit Discord anmelden</a>
				</div>
			</div>
		<?php else : ?>
			<div class="jc-support-header">
				<div class="jc-support-user">
					<img src="https://cdn.discordapp.com/avatars/<?php echo esc_attr( $discord_user['id'] ); ?>/<?php echo esc_attr( $discord_user['avatar'] ); ?>.png" alt="Avatar" class="jc-user-avatar">
					<div>
						<div class="jc-user-name"><?php echo esc_html( $discord_user['username'] ); ?></div>
						<div class="jc-user-badges">
							<?php if ( $is_super_admin ) : ?>
								<span class="jc-user-badge jc-badge-super-admin">👑 Super Admin</span>
							<?php elseif ( $is_admin ) : ?>
								<span class="jc-user-badge jc-badge-admin">👨‍💼 Admin</span>
							<?php elseif ( $discord_user['is_member'] ) : ?>
								<span class="jc-user-badge">✓ Teilnehmer</span>
							<?php else : ?>
								<span class="jc-user-badge jc-badge-error">Kein Teilnehmer</span>
							<?php endif; ?>
						</div>
					</div>
				</div>
				<a href="?jc_logout=1" class="jc-logout-btn">Abmelden</a>
			</div>

			<?php if ( $is_admin ) : ?>
				<?php jc_support_render_admin_view( $discord_user, $is_super_admin ); ?>
			<?php elseif ( ! $discord_user['is_member'] ) : ?>
				<div class="jc-error-notice">⚠️ Du bist kein verifizierter Teilnehmer. Tritt dem Discord-Server bei, um Support-Anfragen zu erstellen.</div>
			<?php else : ?>
				<div class="jc-ticket-form-container">
					<h2 class="jc-section-title">Neues Ticket erstellen</h2>
					<form method="post" class="jc-ticket-form">
						<div class="jc-form-group">
							<label>Kategorie</label>
							<select name="ticket_category" required class="jc-select">
								<option value="">Wähle eine Kategorie</option>
								<option value="Bug Report">🐛 Bug Report</option>
								<option value="Hacker Report">🚫 Hacker Report</option>
								<option value="Mod Suggestion">💡 Mod Suggestion</option>
								<option value="Allgemeiner Support">❓ Allgemeiner Support</option>
							</select>
						</div>
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

				<div class="jc-tickets-container">
					<h2 class="jc-section-title">Deine Tickets</h2>
					<?php
					$user_tickets = get_posts( array(
						'post_type'      => 'jc_support_ticket',
						'posts_per_page' => -1,
						'meta_query'     => array(
							array(
								'key'     => '_jc_ticket_discord_user',
								'value'   => serialize( $discord_user['id'] ),
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
								$status   = get_post_meta( $ticket->ID, '_jc_ticket_status', true ) ?: 'open';
								$replies  = get_post_meta( $ticket->ID, '_jc_ticket_replies', true ) ?: array();
							?>
							<div class="jc-ticket-card" data-status="<?php echo esc_attr( $status ); ?>">
								<div class="jc-ticket-header">
									<span class="jc-ticket-category"><?php echo esc_html( $category ); ?></span>
									<span class="jc-ticket-status jc-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( array('open'=>'Offen','answered'=>'Beantwortet','closed'=>'Geschlossen')[ $status ] ?? 'Offen' ); ?></span>
								</div>
								<h3 class="jc-ticket-title"><?php echo esc_html( $ticket->post_title ); ?></h3>
								<p class="jc-ticket-message"><?php echo esc_html( wp_trim_words( $ticket->post_content, 30 ) ); ?></p>
								<?php if ( ! empty( $replies ) ) : ?>
									<div class="jc-ticket-replies">
										<div class="jc-replies-header">💬 <?php echo count( $replies ); ?> Antwort(en)</div>
										<?php foreach ( $replies as $reply ) : ?>
											<div class="jc-reply <?php echo $reply['is_admin'] ? 'jc-reply-admin' : ''; ?>">
												<div class="jc-reply-author">
													<?php echo $reply['is_admin'] ? '👨‍💼 ' : ''; ?><?php echo esc_html( $reply['author'] ); ?>
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
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
	<?php
	return ob_get_clean();
}

/**
 * Admin-View (Frontend für Admins / Super-Admin).
 */
function jc_support_render_admin_view( $discord_user, $is_super_admin ) {
	$tickets = get_posts( array(
		'post_type'      => 'jc_support_ticket',
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
	) );
	$admin_list = jc_support_get_admin_list();
	?>
	<?php if ( $is_super_admin ) : ?>
	<div class="jc-admin-management">
		<h2 class="jc-section-title">👑 Admin Verwaltung</h2>
		<form method="post" class="jc-admin-form">
			<?php wp_nonce_field( 'jc_add_admin' ); ?>
			<div style="display:flex;gap:12px;align-items:end;">
				<div style="flex:1;">
					<label style="display:block;margin-bottom:6px;color:var(--jc-text);font-weight:700;">Discord User ID</label>
					<input type="text" name="admin_user_id" required placeholder="123456789012345678" class="jc-input">
				</div>
				<button type="submit" name="jc_add_admin" class="jc-submit-btn">Admin hinzufügen</button>
			</div>
		</form>
		<?php if ( ! empty( $admin_list ) ) : ?>
		<div class="jc-admin-list">
			<h3 style="color:var(--jc-text);font-size:16px;margin:20px 0 12px;">Aktuelle Admins</h3>
			<?php foreach ( $admin_list as $admin_id ) : ?>
				<div class="jc-admin-item">
					<span class="jc-admin-id">👨‍💼 <?php echo esc_html( $admin_id ); ?></span>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'jc_remove_admin' => urlencode( $admin_id ) ) ), 'jc_remove_admin' ) ); ?>" class="jc-remove-btn" onclick="return confirm('Admin wirklich entfernen?');">Entfernen</a>
				</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<div class="jc-admin-tickets-container">
		<h2 class="jc-section-title">🎫 Alle Support Tickets (<?php echo count( $tickets ); ?>)</h2>
		<?php if ( empty( $tickets ) ) : ?>
			<p class="jc-no-tickets">Keine Tickets vorhanden.</p>
		<?php else : ?>
			<div class="jc-tickets-grid">
				<?php foreach ( $tickets as $ticket ) :
					$category            = get_post_meta( $ticket->ID, '_jc_ticket_category', true );
					$status              = get_post_meta( $ticket->ID, '_jc_ticket_status', true ) ?: 'open';
					$ticket_discord_user = get_post_meta( $ticket->ID, '_jc_ticket_discord_user', true );
					$replies             = get_post_meta( $ticket->ID, '_jc_ticket_replies', true ) ?: array();
					$claimed_by          = get_post_meta( $ticket->ID, '_jc_ticket_claimed_by', true );
					$claimed_by_username = get_post_meta( $ticket->ID, '_jc_ticket_claimed_by_username', true );
				?>
				<div class="jc-ticket-card jc-admin-ticket-card" data-status="<?php echo esc_attr( $status ); ?>">
					<div class="jc-ticket-header">
						<span class="jc-ticket-category"><?php echo esc_html( $category ); ?></span>
						<span class="jc-ticket-status jc-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( array('open'=>'Offen','answered'=>'Beantwortet','closed'=>'Geschlossen')[ $status ] ?? 'Offen' ); ?></span>
					</div>
					<div class="jc-ticket-meta">
						<strong>Von:</strong> <?php echo esc_html( $ticket_discord_user['username'] ?? 'Unbekannt' ); ?><br>
						<strong>Erstellt:</strong> <?php echo esc_html( date_i18n( 'd.m.Y H:i', strtotime( $ticket->post_date ) ) ); ?>
					</div>
					<?php if ( $claimed_by ) : ?>
						<div class="jc-ticket-claimed">✅ Bearbeitet von: <strong><?php echo esc_html( $claimed_by_username ); ?></strong>
							<?php if ( $claimed_by === $discord_user['id'] || $is_super_admin ) : ?>
								<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'jc_unclaim_ticket' => $ticket->ID ) ), 'jc_unclaim_ticket' ) ); ?>" class="jc-unclaim-link">Freigeben</a>
							<?php endif; ?>
						</div>
					<?php else : ?>
						<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'jc_claim_ticket' => $ticket->ID ) ), 'jc_claim_ticket' ) ); ?>" class="jc-claim-btn">📌 Ticket claimen</a>
					<?php endif; ?>

					<h3 class="jc-ticket-title"><?php echo esc_html( $ticket->post_title ); ?></h3>
					<p class="jc-ticket-message"><?php echo nl2br( esc_html( $ticket->post_content ) ); ?></p>

					<?php if ( ! empty( $replies ) ) : ?>
						<div class="jc-ticket-replies">
							<div class="jc-replies-header">💬 <?php echo count( $replies ); ?> Antwort(en)</div>
							<?php foreach ( $replies as $reply ) : ?>
								<div class="jc-reply <?php echo $reply['is_admin'] ? 'jc-reply-admin' : ''; ?>">
									<div class="jc-reply-author">
										<?php echo $reply['is_admin'] ? '👨‍💼 ' : ''; ?><?php echo esc_html( $reply['author'] ); ?>
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
								<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'jc_close_ticket' => $ticket->ID ) ), 'jc_close_ticket' ) ); ?>" class="jc-close-ticket-btn" onclick="return confirm('Ticket schließen?');">Schließen</a>
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
 * Styles.
 */
function jc_support_styles() {
	?>
	<style>
		:root { --jc-bg:#050712; --jc-panel:#0b0f1d; --jc-border:#1e2740; --jc-text:#e9ecf7; --jc-muted:#9eb3d5; --jc-accent:#6c7bff; --jc-accent-2:#56d8ff; }
		.jc-support-wrap { max-width:1220px; margin:26px auto; padding:0 18px 40px; color:var(--jc-text); font-family:"Space Grotesk","Inter","SF Pro Display",system-ui,-apple-system,sans-serif; }
		.jc-support-login { display:flex; align-items:center; justify-content:center; min-height:70vh; }
		.jc-login-card { max-width:560px; width:100%; border-radius:20px; padding:28px; text-align:center; position:relative; overflow:hidden; }
		.jc-card { background:var(--jc-panel); border:1px solid var(--jc-border); box-shadow:0 22px 60px rgba(0,0,0,0.45); }
		.jc-login-icon { font-size:56px; margin-bottom:16px; }
		.jc-login-title { margin:10px 0 6px; font-size:32px; line-height:1.2; color:var(--jc-text); }
		.jc-login-sub { margin:0 0 14px; color:var(--jc-muted); line-height:1.6; }
		.jc-discord-btn { display:inline-flex; justify-content:center; align-items:center; gap:8px; padding:12px 16px; border-radius:12px; background:linear-gradient(135deg,var(--jc-accent),var(--jc-accent-2)); color:#040510; font-weight:800; text-decoration:none; letter-spacing:0.01em; box-shadow:0 14px 34px rgba(108,123,255,0.45); transition:transform .2s, box-shadow .2s; }
		.jc-discord-btn:hover { transform:translateY(-2px); box-shadow:0 16px 40px rgba(86,216,255,0.5); }
		.jc-support-header { display:flex; justify-content:space-between; align-items:center; border-radius:20px; padding:22px 28px; margin-bottom:20px; background:var(--jc-panel); border:1px solid var(--jc-border); box-shadow:0 22px 60px rgba(0,0,0,0.45); }
		.jc-support-user { display:flex; align-items:center; gap:14px; }
		.jc-user-avatar { width:56px; height:56px; border-radius:50%; border:2px solid var(--jc-accent); }
		.jc-user-name { font-weight:800; font-size:18px; color:var(--jc-text); }
		.jc-user-badges { display:flex; gap:8px; flex-wrap:wrap; }
		.jc-user-badge { padding:6px 12px; border-radius:999px; font-size:12px; font-weight:700; border:1px solid rgba(76,175,80,0.3); background:rgba(76,175,80,0.12); color:#4caf50; }
		.jc-badge-error { background:rgba(244,67,54,0.15); border-color:rgba(244,67,54,0.3); color:#f44336; }
		.jc-badge-admin { background:rgba(108,123,255,0.15); border-color:rgba(108,123,255,0.35); color:var(--jc-accent); }
		.jc-badge-super-admin { background:linear-gradient(135deg,rgba(255,215,0,0.2),rgba(255,193,7,0.2)); border-color:rgba(255,215,0,0.5); color:#ffd700; }
		.jc-logout-btn { padding:10px 16px; border-radius:12px; background:rgba(244,67,54,0.12); border:1px solid rgba(244,67,54,0.35); color:#f44336; text-decoration:none; font-weight:800; }
		.jc-error-notice { background:rgba(255,193,7,0.1); border:1px solid rgba(255,193,7,0.3); color:#ffc107; padding:12px 16px; border-radius:12px; margin-bottom:16px; }
		.jc-ticket-form-container, .jc-tickets-container, .jc-admin-tickets-container, .jc-admin-management { border-radius:18px; padding:22px; margin-bottom:20px; background:var(--jc-panel); border:1px solid var(--jc-border); box-shadow:0 20px 54px rgba(0,0,0,0.4); }
		.jc-section-title { margin:0 0 16px; font-size:22px; font-weight:800; }
		.jc-form-group { margin-bottom:16px; }
		.jc-form-group label { display:block; margin-bottom:6px; color:var(--jc-muted); font-weight:700; font-size:13px; }
		.jc-select, .jc-input, .jc-textarea, .jc-reply-textarea { width:100%; padding:12px 14px; background:var(--jc-panel); border:1px solid var(--jc-border); border-radius:12px; color:var(--jc-text); font-size:14px; }
		.jc-select:focus, .jc-input:focus, .jc-textarea:focus, .jc-reply-textarea:focus { outline:none; border-color:var(--jc-accent); box-shadow:0 0 0 3px rgba(108,123,255,0.35); background:rgba(11,15,29,0.9); }
		.jc-submit-btn, .jc-reply-btn { display:inline-flex; align-items:center; gap:8px; justify-content:center; padding:12px 16px; border-radius:12px; background:linear-gradient(135deg,var(--jc-accent),var(--jc-accent-2)); color:#040510; text-decoration:none; font-weight:800; letter-spacing:0.01em; box-shadow:0 14px 34px rgba(108,123,255,0.45); transition:transform .2s, box-shadow .2s; border:none; cursor:pointer; }
		.jc-submit-btn:hover, .jc-reply-btn:hover { transform:translateY(-2px); box-shadow:0 16px 40px rgba(86,216,255,0.5); }
		.jc-tickets-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:20px; }
		.jc-ticket-card { border-radius:18px; padding:22px; display:grid; grid-template-columns:1fr; gap:12px; position:relative; overflow:hidden; background:var(--jc-panel); border:1px solid var(--jc-border); box-shadow:0 20px 54px rgba(0,0,0,0.4); transition:transform .2s, border-color .2s, box-shadow .2s; }
		.jc-ticket-card:hover { transform:translateY(-5px); border-color:rgba(108,123,255,0.5); box-shadow:0 24px 68px rgba(0,0,0,0.5); }
		.jc-ticket-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
		.jc-ticket-category { padding:6px 10px; border-radius:12px; background:rgba(86,216,255,0.12); color:#c9eeff; border:1px solid rgba(86,216,255,0.35); font-weight:700; font-size:12px; }
		.jc-ticket-status { padding:6px 10px; border-radius:10px; font-weight:800; font-size:12px; }
		.jc-status-open { background:rgba(244,67,54,0.15); border:1px solid rgba(244,67,54,0.35); color:#f44336; }
		.jc-status-answered { background:rgba(255,193,7,0.15); border:1px solid rgba(255,193,7,0.35); color:#ffc107; }
		.jc-status-closed { background:rgba(76,175,80,0.15); border:1px solid rgba(76,175,80,0.35); color:#4caf50; }
		.jc-ticket-title { margin:0 0 8px; font-size:18px; font-weight:800; color:var(--jc-text); }
		.jc-ticket-message { margin:0 0 12px; color:var(--jc-muted); line-height:1.6; font-size:13px; }
		.jc-ticket-replies { border-top:1px solid var(--jc-border); padding-top:10px; margin-top:10px; }
		.jc-reply { background:rgba(11,15,29,0.6); border:1px solid var(--jc-border); border-radius:12px; padding:10px; margin-bottom:8px; }
		.jc-reply-admin { border-left:3px solid var(--jc-accent); }
		.jc-reply-author { display:flex; justify-content:space-between; font-weight:700; color:var(--jc-text); }
		.jc-reply-date { color:var(--jc-muted); font-weight:600; font-size:12px; }
		.jc-admin-ticket-card { border:2px solid var(--jc-border); }
		.jc-ticket-meta { color:var(--jc-muted); font-size:13px; margin-bottom:10px; line-height:1.4; }
		.jc-ticket-claimed { background:rgba(76,175,80,0.12); border:1px solid rgba(76,175,80,0.35); color:#4caf50; padding:10px 12px; border-radius:10px; margin-bottom:10px; display:flex; justify-content:space-between; }
		.jc-unclaim-link { color:#4caf50; text-decoration:underline; }
		.jc-claim-btn { display:inline-block; padding:8px 14px; background:rgba(108,123,255,0.15); border:1px solid rgba(108,123,255,0.35); color:var(--jc-accent); border-radius:10px; text-decoration:none; font-weight:800; margin-bottom:10px; }
		.jc-close-ticket-btn { padding:8px 14px; background:rgba(244,67,54,0.12); border:1px solid rgba(244,67,54,0.35); color:#f44336; border-radius:10px; text-decoration:none; font-weight:800; }
		.jc-admin-item { display:flex; justify-content:space-between; align-items:center; background:rgba(30,39,64,0.6); border:1px solid var(--jc-border); padding:10px 12px; border-radius:12px; margin-bottom:8px; }
		.jc-admin-id { color:var(--jc-text); font-weight:800; }
		.jc-remove-btn { padding:6px 10px; background:rgba(244,67,54,0.12); border:1px solid rgba(244,67,54,0.35); color:#f44336; border-radius:10px; text-decoration:none; font-weight:800; }
		.jc-no-tickets { color:var(--jc-muted); padding:20px 0; }
		@media (max-width:900px) { .jc-support-header { grid-template-columns:1fr; } }
		@media (max-width:640px) { .jc-ticket-card { grid-template-columns:1fr; } .jc-support-header { flex-direction:column; align-items:flex-start; } }
	</style>
	<?php
}
