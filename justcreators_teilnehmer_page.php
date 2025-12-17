<?php
/**
 * Plugin Name: JustCreators Teilnehmer
 * Description: Teilnehmer-Verwaltung mit Social Media Integration im JustCreators Design
 * Version: 1.0.0
 * Author: JustCreators Team
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Konstanten
define( 'JC_TEILNEHMER_TABLE', 'jc_teilnehmer' );
define( 'JC_TEILNEHMER_VERSION', '1.0.0' );

// Hooks
register_activation_hook( __FILE__, 'jc_teilnehmer_install' );
add_action( 'admin_menu', 'jc_teilnehmer_register_menu' );
add_action( 'admin_init', 'jc_teilnehmer_handle_actions' );
add_shortcode( 'jc_teilnehmer', 'jc_teilnehmer_render_shortcode' );

/**
 * Installation: Erstelle Tabelle
 */
function jc_teilnehmer_install() {
	global $wpdb;
	$table_name = $wpdb->prefix . JC_TEILNEHMER_TABLE;
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		display_name varchar(255) NOT NULL,
		title varchar(255) DEFAULT '',
		social_channels longtext DEFAULT NULL,
		profile_image_url varchar(500) DEFAULT '',
		sort_order int(11) DEFAULT 0,
		is_active tinyint(1) DEFAULT 1,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY sort_order (sort_order),
		KEY is_active (is_active)
	) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );

	update_option( 'jc_teilnehmer_db_version', JC_TEILNEHMER_VERSION );
}

/**
 * Admin Menu registrieren
 */
function jc_teilnehmer_register_menu() {
	add_menu_page(
		'JustCreators Teilnehmer',
		'Teilnehmer',
		'manage_options',
		'jc-teilnehmer',
		'jc_teilnehmer_render_admin_page',
		'dashicons-groups',
		59
	);
}

/**
 * Admin-Actions verarbeiten
 */
function jc_teilnehmer_handle_actions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	global $wpdb;
	$table = $wpdb->prefix . JC_TEILNEHMER_TABLE;

	// Teilnehmer hinzufügen
	if ( isset( $_POST['jc_teilnehmer_add'] ) ) {
		check_admin_referer( 'jc_teilnehmer_add' );
		
		$display_name = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$social_channels = isset( $_POST['social_channels'] ) ? sanitize_textarea_field( wp_unslash( $_POST['social_channels'] ) ) : '';
		
		if ( empty( $display_name ) ) {
			add_settings_error( 'jc_teilnehmer', 'empty_name', 'Bitte gib einen Namen ein.', 'error' );
			return;
		}

		// Parse Social Channels (JSON oder Zeile für Zeile)
		$channels_array = jc_teilnehmer_parse_channels( $social_channels );
		$channels_json = wp_json_encode( $channels_array );

		// Profilbild automatisch ermitteln
		$profile_image = jc_teilnehmer_get_profile_image( $channels_array );

		$inserted = $wpdb->insert(
			$table,
			array(
				'display_name' => $display_name,
				'title' => $title,
				'social_channels' => $channels_json,
				'profile_image_url' => $profile_image,
				'sort_order' => 0,
				'is_active' => 1,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d' )
		);

		if ( $inserted ) {
			add_settings_error( 'jc_teilnehmer', 'teilnehmer_added', 'Teilnehmer wurde hinzugefügt!', 'updated' );
		} else {
			add_settings_error( 'jc_teilnehmer', 'db_error', 'Fehler beim Speichern: ' . $wpdb->last_error, 'error' );
		}
	}

	// Teilnehmer bearbeiten
	if ( isset( $_POST['jc_teilnehmer_edit'] ) ) {
		check_admin_referer( 'jc_teilnehmer_edit_' . intval( $_POST['teilnehmer_id'] ) );
		
		$id = intval( $_POST['teilnehmer_id'] );
		$display_name = isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '';
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$social_channels = isset( $_POST['social_channels'] ) ? sanitize_textarea_field( wp_unslash( $_POST['social_channels'] ) ) : '';
		$is_active = isset( $_POST['is_active'] ) ? 1 : 0;

		$channels_array = jc_teilnehmer_parse_channels( $social_channels );
		$channels_json = wp_json_encode( $channels_array );
		$profile_image = jc_teilnehmer_get_profile_image( $channels_array );

		$updated = $wpdb->update(
			$table,
			array(
				'display_name' => $display_name,
				'title' => $title,
				'social_channels' => $channels_json,
				'profile_image_url' => $profile_image,
				'is_active' => $is_active,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%d' ),
			array( '%d' )
		);

		if ( $updated !== false ) {
			add_settings_error( 'jc_teilnehmer', 'teilnehmer_updated', 'Teilnehmer wurde aktualisiert!', 'updated' );
		}
	}

	// Teilnehmer löschen
	if ( isset( $_GET['action'], $_GET['id'], $_GET['_wpnonce'] ) && $_GET['action'] === 'delete' ) {
		$id = intval( $_GET['id'] );
		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'jc_teilnehmer_delete_' . $id ) ) {
			$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
			if ( $deleted ) {
				add_settings_error( 'jc_teilnehmer', 'teilnehmer_deleted', 'Teilnehmer wurde gelöscht.', 'updated' );
			}
		}
	}

	// Sortierung aktualisieren
	if ( isset( $_POST['jc_teilnehmer_update_order'] ) ) {
		check_admin_referer( 'jc_teilnehmer_order' );
		if ( isset( $_POST['order'] ) && is_array( $_POST['order'] ) ) {
			foreach ( $_POST['order'] as $id => $order ) {
				$wpdb->update(
					$table,
					array( 'sort_order' => intval( $order ) ),
					array( 'id' => intval( $id ) ),
					array( '%d' ),
					array( '%d' )
				);
			}
			add_settings_error( 'jc_teilnehmer', 'order_updated', 'Reihenfolge gespeichert!', 'updated' );
		}
	}

	// Automatischer Import aus Bewerbungs-DB
	if ( isset( $_POST['jc_teilnehmer_import_from_db'] ) ) {
		check_admin_referer( 'jc_teilnehmer_import_db' );
		$result = jc_teilnehmer_import_from_applications();
		if ( is_wp_error( $result ) ) {
			add_settings_error( 'jc_teilnehmer', 'import_error', $result->get_error_message(), 'error' );
		} else {
			add_settings_error( 'jc_teilnehmer', 'import_success', $result['message'], 'updated' );
		}
	}
}

/**
 * Parse Social Channels (unterstützt JSON oder Zeile für Zeile)
 */
function jc_teilnehmer_parse_channels( $input ) {
	if ( empty( $input ) ) {
		return array();
	}

	// Versuche JSON zu parsen
	$decoded = json_decode( $input, true );
	if ( is_array( $decoded ) ) {
		return $decoded;
	}

	// Fallback: Zeile für Zeile parsen
	$lines = explode( "\n", $input );
	$channels = array();

	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( empty( $line ) ) {
			continue;
		}

		// Erkenne Plattform
		$platform = 'unknown';
		if ( stripos( $line, 'youtube.com' ) !== false || stripos( $line, 'youtu.be' ) !== false ) {
			$platform = 'youtube';
		} elseif ( stripos( $line, 'twitch.tv' ) !== false ) {
			$platform = 'twitch';
		} elseif ( stripos( $line, 'tiktok.com' ) !== false ) {
			$platform = 'tiktok';
		} elseif ( stripos( $line, 'instagram.com' ) !== false ) {
			$platform = 'instagram';
		} elseif ( stripos( $line, 'twitter.com' ) !== false || stripos( $line, 'x.com' ) !== false ) {
			$platform = 'twitter';
		}

		$channels[] = array(
			'platform' => $platform,
			'url' => $line,
		);
	}

	return $channels;
}

/**
 * Hole Profilbild von Social Media Kanal
 */
function jc_teilnehmer_get_profile_image( $channels ) {
	if ( empty( $channels ) || ! is_array( $channels ) ) {
		return '';
	}

	// Priorisiere YouTube > Twitch > Instagram > TikTok
	$priority = array( 'youtube', 'twitch', 'instagram', 'tiktok' );

	foreach ( $priority as $platform ) {
		foreach ( $channels as $channel ) {
			if ( isset( $channel['platform'] ) && $channel['platform'] === $platform && ! empty( $channel['url'] ) ) {
				$image = jc_teilnehmer_fetch_profile_image( $platform, $channel['url'] );
				if ( $image ) {
					return $image;
				}
			}
		}
	}

	return 'https://via.placeholder.com/300x300/1e2740/6c7bff?text=JC';
}

/**
 * Fetch Profilbild von API
 */
function jc_teilnehmer_fetch_profile_image( $platform, $url ) {
	$cache_key = 'jc_profile_' . md5( $platform . $url );
	$cached = get_transient( $cache_key );
	
	if ( $cached !== false ) {
		return $cached;
	}

	$image_url = '';

	switch ( $platform ) {
		case 'youtube':
			// Extrahiere Channel-ID oder Handle
			$handle = jc_teilnehmer_extract_youtube_handle( $url );
			if ( $handle ) {
				// Verwende YouTube oEmbed API
				$oembed_url = 'https://www.youtube.com/oembed?url=' . urlencode( 'https://www.youtube.com/' . $handle ) . '&format=json';
				$response = wp_remote_get( $oembed_url, array( 'timeout' => 10 ) );
				
				if ( ! is_wp_error( $response ) ) {
					$body = wp_remote_retrieve_body( $response );
					$data = json_decode( $body, true );
					
					if ( isset( $data['thumbnail_url'] ) ) {
						$image_url = $data['thumbnail_url'];
					}
				}
			}
			break;

		case 'twitch':
			// Extrahiere Username
			$username = jc_teilnehmer_extract_twitch_username( $url );
			if ( $username ) {
				// Twitch benötigt Client ID + Token - Fallback zu generischem Bild
				$image_url = 'https://static-cdn.jtvnw.net/jtv_user_pictures/' . strtolower( $username ) . '-profile_image-300x300.png';
			}
			break;

		case 'instagram':
			// Instagram hat keine öffentliche API mehr - Placeholder
			$image_url = 'https://via.placeholder.com/300x300/E4405F/ffffff?text=IG';
			break;

		case 'tiktok':
			// TikTok hat keine öffentliche API - Placeholder
			$image_url = 'https://via.placeholder.com/300x300/000000/ffffff?text=TT';
			break;
	}

	// Cache für 24 Stunden
	set_transient( $cache_key, $image_url, DAY_IN_SECONDS );

	return $image_url;
}

/**
 * Extrahiere YouTube Handle/Channel
 */
function jc_teilnehmer_extract_youtube_handle( $url ) {
	// Suche nach @handle oder /channel/ID
	if ( preg_match( '/@([\w-]+)/', $url, $matches ) ) {
		return '@' . $matches[1];
	}
	if ( preg_match( '/channel\/([\w-]+)/', $url, $matches ) ) {
		return 'channel/' . $matches[1];
	}
	if ( preg_match( '/user\/([\w-]+)/', $url, $matches ) ) {
		return 'user/' . $matches[1];
	}
	return null;
}

/**
 * Extrahiere Twitch Username
 */
function jc_teilnehmer_extract_twitch_username( $url ) {
	if ( preg_match( '/twitch\.tv\/([\w-]+)/i', $url, $matches ) ) {
		return $matches[1];
	}
	return null;
}

/**
 * Importiere akzeptierte Bewerbungen aus der Discord-Applications-Tabelle
 */
function jc_teilnehmer_import_from_applications() {
	global $wpdb;
	$teilnehmer_table = $wpdb->prefix . JC_TEILNEHMER_TABLE;
	$applications_table = $wpdb->prefix . 'jc_discord_applications';

	// Prüfe ob Applications-Tabelle existiert
	if ( $wpdb->get_var( "SHOW TABLES LIKE '$applications_table'" ) !== $applications_table ) {
		return new WP_Error( 'no_table', 'Bewerbungs-Tabelle nicht gefunden. Bewerbungsportal muss aktiv sein.' );
	}

	// Hole alle akzeptierten Bewerbungen
	$accepted = $wpdb->get_results( 
		"SELECT * FROM $applications_table WHERE status = 'accepted' ORDER BY created_at DESC"
	);

	if ( empty( $accepted ) ) {
		return new WP_Error( 'no_applications', 'Keine akzeptierten Bewerbungen gefunden.' );
	}

	$imported = 0;
	$skipped = 0;
	$errors = 0;

	foreach ( $accepted as $app ) {
		// Prüfe ob bereits vorhanden (anhand discord_id oder Name)
		$exists = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM $teilnehmer_table WHERE display_name = %s LIMIT 1",
			$app->applicant_name
		) );

		if ( $exists ) {
			$skipped++;
			continue;
		}

		// Parse Social Channels
		$channels_array = json_decode( $app->social_channels, true );
		if ( ! is_array( $channels_array ) ) {
			$channels_array = array();
		}

		// Filter leere Einträge
		$channels_array = array_filter( $channels_array, function( $ch ) {
			return ! empty( $ch ) && ! empty( $ch['url'] ) && $ch['url'] !== 'null';
		} );
		$channels_array = array_values( $channels_array );

		$channels_json = wp_json_encode( $channels_array );

		// Hole Profilbild
		$profile_image = jc_teilnehmer_get_profile_image( $channels_array );

		// Füge Teilnehmer hinzu
		$inserted = $wpdb->insert(
			$teilnehmer_table,
			array(
				'display_name' => $app->applicant_name,
				'title' => 'Creator', // Standard-Titel
				'social_channels' => $channels_json,
				'profile_image_url' => $profile_image,
				'sort_order' => 0,
				'is_active' => 1,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d' )
		);

		if ( $inserted ) {
			$imported++;
		} else {
			$errors++;
		}
	}

	return array(
		'success' => true,
		'message' => sprintf(
			'Import abgeschlossen: %d importiert, %d übersprungen, %d Fehler.',
			$imported,
			$skipped,
			$errors
		)
	);
}

/**
 * Admin-Seite rendern
 */
function jc_teilnehmer_render_admin_page() {
	global $wpdb;
	$table = $wpdb->prefix . JC_TEILNEHMER_TABLE;

	settings_errors( 'jc_teilnehmer' );

	// Bearbeitungsmodus?
	$edit_mode = false;
	$edit_teilnehmer = null;
	if ( isset( $_GET['edit'] ) ) {
		$edit_id = intval( $_GET['edit'] );
		$edit_teilnehmer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $edit_id ) );
		if ( $edit_teilnehmer ) {
			$edit_mode = true;
		}
	}

	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<p>Verwalte JustCreators Teilnehmer mit Social-Media-Integration.</p>

		<?php if ( $edit_mode ) : ?>
			<!-- Bearbeitungsformular -->
			<div class="card" style="max-width: 800px;">
				<h2>Teilnehmer bearbeiten</h2>
				<form method="post" action="">
					<?php wp_nonce_field( 'jc_teilnehmer_edit_' . $edit_teilnehmer->id ); ?>
					<input type="hidden" name="teilnehmer_id" value="<?php echo esc_attr( $edit_teilnehmer->id ); ?>" />

					<table class="form-table">
						<tr>
							<th><label for="display_name">Name *</label></th>
							<td><input type="text" id="display_name" name="display_name" class="regular-text" value="<?php echo esc_attr( $edit_teilnehmer->display_name ); ?>" required /></td>
						</tr>
						<tr>
							<th><label for="title">Titel / Rolle</label></th>
							<td><input type="text" id="title" name="title" class="regular-text" value="<?php echo esc_attr( $edit_teilnehmer->title ); ?>" placeholder="z.B. Content Creator" /></td>
						</tr>
						<tr>
							<th><label for="social_channels">Social Media Kanäle</label></th>
							<td>
								<textarea id="social_channels" name="social_channels" rows="6" class="large-text code"><?php
									$channels = json_decode( $edit_teilnehmer->social_channels, true );
									if ( is_array( $channels ) ) {
										foreach ( $channels as $ch ) {
											echo esc_textarea( $ch['url'] ) . "\n";
										}
									}
								?></textarea>
								<p class="description">Ein Link pro Zeile (YouTube, Twitch, TikTok, Instagram)</p>
							</td>
						</tr>
						<tr>
							<th><label for="is_active">Aktiv</label></th>
							<td>
								<input type="checkbox" id="is_active" name="is_active" value="1" <?php checked( $edit_teilnehmer->is_active, 1 ); ?> />
								<span class="description">Nur aktive Teilnehmer werden angezeigt</span>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" name="jc_teilnehmer_edit" class="button button-primary">💾 Änderungen speichern</button>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=jc-teilnehmer' ) ); ?>" class="button">Abbrechen</a>
					</p>
				</form>
			</div>
		<?php else : ?>
			<!-- Import aus DB -->
			<div class="card" style="max-width: 800px; background: linear-gradient(135deg, rgba(108,123,255,0.08), rgba(86,216,255,0.06)); border: 1px solid rgba(108,123,255,0.25);">
				<h2>📥 Automatischer Import</h2>
				<p>Importiere alle akzeptierten Bewerbungen automatisch als Teilnehmer. Bereits vorhandene werden übersprungen.</p>
				<form method="post" action="" style="margin-top: 15px;">
					<?php wp_nonce_field( 'jc_teilnehmer_import_db' ); ?>
					<button type="submit" name="jc_teilnehmer_import_from_db" class="button button-primary button-hero" style="background: linear-gradient(135deg, #6c7bff, #56d8ff); border: none; box-shadow: 0 4px 12px rgba(108,123,255,0.35);">
						🚀 Jetzt aus Bewerbungs-DB importieren
					</button>
				</form>
			</div>

			<!-- Hinzufügen-Formular -->
			<div class="card" style="max-width: 800px; margin-top: 20px;">
				<h2>➕ Neuen Teilnehmer manuell hinzufügen</h2>
				<form method="post" action="">
					<?php wp_nonce_field( 'jc_teilnehmer_add' ); ?>

					<table class="form-table">
						<tr>
							<th><label for="display_name">Name *</label></th>
							<td><input type="text" id="display_name" name="display_name" class="regular-text" required placeholder="z.B. MaxMustermann" /></td>
						</tr>
						<tr>
							<th><label for="title">Titel / Rolle</label></th>
							<td><input type="text" id="title" name="title" class="regular-text" placeholder="z.B. Content Creator" /></td>
						</tr>
						<tr>
							<th><label for="social_channels">Social Media Kanäle</label></th>
							<td>
								<textarea id="social_channels" name="social_channels" rows="6" class="large-text code" placeholder="https://youtube.com/@channel&#10;https://twitch.tv/username&#10;https://tiktok.com/@user"></textarea>
								<p class="description">Ein Link pro Zeile (YouTube, Twitch, TikTok, Instagram)</p>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" name="jc_teilnehmer_add" class="button button-primary">✅ Teilnehmer hinzufügen</button>
					</p>
				</form>
			</div>

			<!-- Liste -->
			<h2 style="margin-top: 40px;">📋 Alle Teilnehmer</h2>
			<?php
			$teilnehmer = $wpdb->get_results( "SELECT * FROM $table ORDER BY sort_order ASC, display_name ASC" );

			if ( empty( $teilnehmer ) ) {
				echo '<p>Noch keine Teilnehmer vorhanden.</p>';
			} else {
				?>
				<form method="post" action="">
					<?php wp_nonce_field( 'jc_teilnehmer_order' ); ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="width: 50px;">Bild</th>
								<th>Name</th>
								<th>Titel</th>
								<th>Social Media</th>
								<th>Status</th>
								<th style="width: 80px;">Sortierung</th>
								<th style="width: 150px;">Aktionen</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $teilnehmer as $t ) :
								$channels = json_decode( $t->social_channels, true );
								$channel_count = is_array( $channels ) ? count( $channels ) : 0;
							?>
								<tr>
									<td>
										<?php if ( ! empty( $t->profile_image_url ) ) : ?>
											<img src="<?php echo esc_url( $t->profile_image_url ); ?>" alt="" style="width: 50px; height: 50px; border-radius: 8px; object-fit: cover;" />
										<?php else : ?>
											<div style="width: 50px; height: 50px; background: #ddd; border-radius: 8px;"></div>
										<?php endif; ?>
									</td>
									<td><strong><?php echo esc_html( $t->display_name ); ?></strong></td>
									<td><?php echo esc_html( $t->title ); ?></td>
									<td><?php echo esc_html( $channel_count ); ?> Kanal<?php echo $channel_count !== 1 ? 'äle' : ''; ?></td>
									<td>
										<?php if ( $t->is_active ) : ?>
											<span style="color: green;">✅ Aktiv</span>
										<?php else : ?>
											<span style="color: gray;">⏸ Inaktiv</span>
										<?php endif; ?>
									</td>
									<td>
										<input type="number" name="order[<?php echo esc_attr( $t->id ); ?>]" value="<?php echo esc_attr( $t->sort_order ); ?>" style="width: 60px;" />
									</td>
									<td>
										<a href="<?php echo esc_url( admin_url( 'admin.php?page=jc-teilnehmer&edit=' . $t->id ) ); ?>" class="button button-small">✏️ Bearbeiten</a>
										<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=jc-teilnehmer&action=delete&id=' . $t->id ), 'jc_teilnehmer_delete_' . $t->id ) ); ?>" class="button button-small" onclick="return confirm('Wirklich löschen?');" style="color: red;">🗑️</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="submit">
						<button type="submit" name="jc_teilnehmer_update_order" class="button button-secondary">💾 Reihenfolge speichern</button>
					</p>
				</form>
				<?php
			}
			?>

			<div class="card" style="margin-top: 30px; max-width: 800px;">
				<h3>📌 Shortcode verwenden</h3>
				<p>Füge diesen Shortcode in eine Seite oder einen Beitrag ein:</p>
				<code style="display: block; padding: 10px; background: #f0f0f1; border-radius: 4px;">[jc_teilnehmer]</code>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Frontend Shortcode
 */
function jc_teilnehmer_render_shortcode( $atts ) {
	global $wpdb;
	$table = $wpdb->prefix . JC_TEILNEHMER_TABLE;

	// Lade CSS nur einmal
	static $styles_loaded = false;
	if ( ! $styles_loaded ) {
		wp_enqueue_style( 'jc-teilnehmer-styles', false );
		wp_add_inline_style( 'jc-teilnehmer-styles', jc_teilnehmer_get_css() );
		$styles_loaded = true;
	}

	$atts = shortcode_atts( array(
		'limit' => 0,
		'show_inactive' => false,
	), $atts );

	$query = "SELECT * FROM $table";
	
	if ( ! $atts['show_inactive'] ) {
		$query .= " WHERE is_active = 1";
	}
	
	$query .= " ORDER BY sort_order ASC, display_name ASC";
	
	if ( $atts['limit'] > 0 ) {
		$query .= " LIMIT " . intval( $atts['limit'] );
	}

	$teilnehmer = $wpdb->get_results( $query );

	if ( empty( $teilnehmer ) ) {
		return '<p style="text-align: center; color: #999;">Noch keine Teilnehmer vorhanden.</p>';
	}

	ob_start();
	?>
	<div class="jc-wrap">
		:root { --jc-bg:#050712; --jc-panel:#0b0f1d; --jc-border:#1e2740; --jc-text:#e9ecf7; --jc-muted:#9eb3d5; --jc-accent:#6c7bff; --jc-accent-2:#56d8ff; }
		.jc-wrap { max-width: 1220px; margin: 26px auto; padding: 0 18px 40px; color: var(--jc-text); font-family: "Space Grotesk", "Inter", "SF Pro Display", system-ui, -apple-system, sans-serif; }
		.jc-hero { display:grid; grid-template-columns:2fr 1fr; gap:22px; background: radial-gradient(120% 140% at 10% 10%, rgba(108,123,255,0.12), transparent 50%), radial-gradient(110% 120% at 90% 20%, rgba(86,216,255,0.1), transparent 45%), var(--jc-panel); border:1px solid var(--jc-border); border-radius:20px; padding:28px; position:relative; overflow:hidden; box-shadow:0 22px 60px rgba(0,0,0,0.45); }
		.jc-hero:after { content:""; position:absolute; inset:0; background:linear-gradient(90deg, rgba(255,255,255,0.02), rgba(255,255,255,0)); pointer-events:none; }
		.jc-hero-left { position:relative; z-index:1; }
		.jc-kicker { display:inline-flex; align-items:center; gap:8px; padding:6px 12px; background:rgba(108,123,255,0.15); border:1px solid rgba(108,123,255,0.35); border-radius:999px; color:var(--jc-text); font-size:13px; letter-spacing:0.04em; text-transform:uppercase; }
		.jc-hero-title { margin:10px 0 6px; font-size:32px; line-height:1.2; color:var(--jc-text); }
		.jc-hero-sub { margin:0 0 14px; color:var(--jc-muted); line-height:1.6; max-width:680px; }
		.jc-hero-right { position:relative; min-height:180px; display:flex; align-items:center; justify-content:flex-end; }
		.jc-hero-badge { position:relative; z-index:1; padding:12px 18px; border-radius:14px; background:linear-gradient(135deg,var(--jc-accent),var(--jc-accent-2)); color:#040510; font-weight:800; letter-spacing:0.08em; box-shadow:0 18px 40px rgba(108,123,255,0.45); }
		.jc-hero-glow { position:absolute; inset:20px; border-radius:18px; background:radial-gradient(circle at 50% 50%, rgba(108,123,255,0.25), transparent 55%); filter:blur(20px); opacity:0.9; }
		.jc-toolbar { margin:18px 0 8px; display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; }
		.jc-search { flex:1; min-width:260px; position:relative; }
		.jc-search input { width:100%; padding:12px 44px 12px 40px; background:var(--jc-panel); border:1px solid var(--jc-border); border-radius:12px; color:var(--jc-text); box-shadow:0 12px 32px rgba(0,0,0,0.35); font-size:14px; }
		.jc-search input::placeholder { color:var(--jc-muted); opacity:0.6; }
		.jc-search input:focus { outline:none; border-color:var(--jc-accent); box-shadow:0 0 0 3px rgba(108,123,255,0.35); background:rgba(11,15,29,0.9); }
		.jc-search-icon { position:absolute; left:14px; top:50%; transform:translateY(-50%); opacity:0.7; }
		.jc-teilnehmer-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:20px; margin-top:20px; }
		.jc-teilnehmer-card { background:var(--jc-panel); border:1px solid var(--jc-border); border-radius:18px; padding:22px; position:relative; overflow:hidden; box-shadow:0 20px 54px rgba(0,0,0,0.4); transition:transform .2s, border-color .2s, box-shadow .2s; }
		.jc-teilnehmer-card:hover { transform:translateY(-5px); border-color:rgba(108,123,255,0.5); box-shadow:0 24px 68px rgba(0,0,0,0.5); }
		.jc-teilnehmer-header { display:flex; gap:16px; align-items:center; margin-bottom:18px; }
		.jc-teilnehmer-avatar { width:80px; height:80px; border-radius:50%; object-fit:cover; border:3px solid var(--jc-border); box-shadow:0 8px 20px rgba(0,0,0,0.3); }
		.jc-teilnehmer-info { flex:1; }
		.jc-teilnehmer-name { margin:0; font-size:20px; font-weight:800; color:var(--jc-text); line-height:1.2; }
		.jc-teilnehmer-title { margin:6px 0 0; font-size:14px; color:var(--jc-muted); }
		.jc-teilnehmer-socials { display:flex; flex-wrap:wrap; gap:10px; }
		.jc-social-btn { display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:10px; background:rgba(108,123,255,0.12); border:1px solid rgba(108,123,255,0.3); color:var(--jc-text); text-decoration:none; font-weight:700; font-size:13px; transition:all .2s; }
		.jc-social-btn:hover { background:rgba(108,123,255,0.2); border-color:rgba(108,123,255,0.5); transform:translateY(-2px); box-shadow:0 8px 16px rgba(108,123,255,0.25); }
		.jc-social-youtube { background:rgba(255,0,0,0.12); border-color:rgba(255,0,0,0.3); color:#ff6b6b; }
		.jc-social-youtube:hover { background:rgba(255,0,0,0.2); border-color:rgba(255,0,0,0.5); box-shadow:0 8px 16px rgba(255,0,0,0.3); }
		.jc-social-twitch { background:rgba(145,70,255,0.12); border-color:rgba(145,70,255,0.3); color:#bf9dff; }
		.jc-social-twitch:hover { background:rgba(145,70,255,0.2); border-color:rgba(145,70,255,0.5); box-shadow:0 8px 16px rgba(145,70,255,0.3); }
		.jc-social-tiktok { background:rgba(255,255,255,0.08); border-color:rgba(255,255,255,0.2); color:#e9ecf7; }
		.jc-social-tiktok:hover { background:rgba(255,255,255,0.15); border-color:rgba(255,255,255,0.4); box-shadow:0 8px 16px rgba(255,255,255,0.2); }
		.jc-social-instagram { background:rgba(228,64,95,0.12); border-color:rgba(228,64,95,0.3); color:#ff87a8; }
		.jc-social-instagram:hover { background:rgba(228,64,95,0.2); border-color:rgba(228,64,95,0.5); box-shadow:0 8px 16px rgba(228,64,95,0.3); }
		.jc-social-twitter { background:rgba(29,155,240,0.12); border-color:rgba(29,155,240,0.3); color:#6db6f7; }
		.jc-social-twitter:hover { background:rgba(29,155,240,0.2); border-color:rgba(29,155,240,0.5); box-shadow:0 8px 16px rgba(29,155,240,0.3); }
		@media (max-width: 900px) {
			.jc-hero { grid-template-columns:1fr; }
			.jc-hero-right { justify-content:flex-start; min-height:120px; }
		}
		@media (max-width: 640px) {
			.jc-teilnehmer-grid { grid-template-columns:1fr; }
			.jc-hero { padding:22px; }
		}
	</style>
	<div class="jc-wrap">
		<div class="jc-hero">
			<div class="jc-hero-left">
				<div class="jc-kicker">
					<span>👥</span>
					<span>JustCreators Season 2</span>
				</div>
				<h1 class="jc-hero-title">Unsere Teilnehmer</h1>
				<p class="jc-hero-sub">
					Triff die kreativen Köpfe von JustCreators Season 2. Entdecke ihre Kanäle und folge ihnen auf ihren Social-Media-Plattformen.
				</p>
			</div>
			<div class="jc-hero-right">
				<div class="jc-hero-glow"></div>
				<div class="jc-hero-badge">
					<?php echo count( $teilnehmer ); ?> CREATOR<?php echo count( $teilnehmer ) !== 1 ? 'S' : ''; ?>
				</div>
			</div>
		</div>

		<div class="jc-toolbar">
			<div class="jc-search">
				<svg class="jc-search-icon" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<circle cx="9" cy="9" r="7"/>
					<path d="m15 15 3 3"/>
				</svg>
				<input type="text" id="jc-teilnehmer-search" placeholder="Teilnehmer suchen..." />
			</div>
		</div>

		<div class="jc-teilnehmer-grid">
			<?php foreach ( $teilnehmer as $t ) :
				$channels = json_decode( $t->social_channels, true );
				$channels = is_array( $channels ) ? $channels : array();
				$search_text = strtolower( $t->display_name . ' ' . $t->title );
			?>
				<div class="jc-teilnehmer-card" data-search="<?php echo esc_attr( $search_text ); ?>">
					<div class="jc-teilnehmer-header">
						<img src="<?php echo esc_url( $t->profile_image_url ? $t->profile_image_url : 'https://via.placeholder.com/300x300/1e2740/6c7bff?text=' . urlencode( substr( $t->display_name, 0, 2 ) ) ); ?>" 
						     alt="<?php echo esc_attr( $t->display_name ); ?>" 
						     class="jc-teilnehmer-avatar" />
						<div class="jc-teilnehmer-info">
							<h3 class="jc-teilnehmer-name"><?php echo esc_html( $t->display_name ); ?></h3>
							<?php if ( ! empty( $t->title ) ) : ?>
								<p class="jc-teilnehmer-title"><?php echo esc_html( $t->title ); ?></p>
							<?php endif; ?>
						</div>
					</div>

					<?php if ( ! empty( $channels ) ) : ?>
						<div class="jc-teilnehmer-socials">
							<?php foreach ( $channels as $channel ) :
								$platform = $channel['platform'] ?? 'unknown';
								$url = $channel['url'] ?? '#';
								$icon = jc_teilnehmer_get_platform_icon( $platform );
								$label = jc_teilnehmer_get_platform_label( $platform );
							?>
								<a href="<?php echo esc_url( $url ); ?>" 
								   class="jc-social-btn jc-social-<?php echo esc_attr( $platform ); ?>" 
								   target="_blank" 
								   rel="noopener noreferrer"
								   title="<?php echo esc_attr( $label ); ?>">
									<?php echo $icon; ?>
									<span><?php echo esc_html( $label ); ?></span>
								</a>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>

	<script>
	(function(){
		const search = document.getElementById('jc-teilnehmer-search');
		const cards = document.querySelectorAll('.jc-teilnehmer-card');

		if (search) {
			search.addEventListener('input', function() {
				const term = this.value.toLowerCase();
				cards.forEach(card => {
					const hay = card.dataset.search;
					card.style.display = (!term || hay.includes(term)) ? 'block' : 'none';
				});
			});
		}
	})();
	</script>
	<?php
	return ob_get_clean();
}

/**
 * Platform Icons
 */
function jc_teilnehmer_get_platform_icon( $platform ) {
	$icons = array(
		'youtube' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>',
		'twitch' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M11.571 4.714h1.715v5.143H11.57zm4.715 0H18v5.143h-1.714zM6 0L1.714 4.286v15.428h5.143V24l4.286-4.286h3.428L22.286 12V0zm14.571 11.143l-3.428 3.428h-3.429l-3 3v-3H6.857V1.714h13.714Z"/></svg>',
		'tiktok' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 0 1-5.2 1.74 2.89 2.89 0 0 1 2.31-4.64 2.93 2.93 0 0 1 .88.13V9.4a6.84 6.84 0 0 0-1-.05A6.33 6.33 0 0 0 5 20.1a6.34 6.34 0 0 0 10.86-4.43v-7a8.16 8.16 0 0 0 4.77 1.52v-3.4a4.85 4.85 0 0 1-1-.1z"/></svg>',
		'instagram' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg>',
		'twitter' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>',
	);

	return $icons[ $platform ] ?? '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M10 13a2 2 0 100-4 2 2 0 000 4zm0-7a5 5 0 110 10 5 5 0 010-10zm7 2a1 1 0 110-2 1 1 0 010 2z"/></svg>';
}

/**
 * Platform Labels
 */
function jc_teilnehmer_get_platform_label( $platform ) {
	$labels = array(
		'youtube' => 'YouTube',
		'twitch' => 'Twitch',
		'tiktok' => 'TikTok',
		'instagram' => 'Instagram',
		'twitter' => 'Twitter/X',
	);

	return $labels[ $platform ] ?? 'Website';
}

/**
 * Get CSS as string
 */
function jc_teilnehmer_get_css() {
	return '
		:root { --jc-bg:#050712; --jc-panel:#0b0f1d; --jc-border:#1e2740; --jc-text:#e9ecf7; --jc-muted:#9eb3d5; --jc-accent:#6c7bff; --jc-accent-2:#56d8ff; }
		.jc-wrap { max-width: 1220px; margin: 26px auto; padding: 0 18px 40px; color: var(--jc-text); font-family: "Space Grotesk", "Inter", "SF Pro Display", system-ui, -apple-system, sans-serif; }
		.jc-hero { display:grid; grid-template-columns:2fr 1fr; gap:22px; background: radial-gradient(120% 140% at 10% 10%, rgba(108,123,255,0.12), transparent 50%), radial-gradient(110% 120% at 90% 20%, rgba(86,216,255,0.1), transparent 45%), var(--jc-panel); border:1px solid var(--jc-border); border-radius:20px; padding:28px; position:relative; overflow:hidden; box-shadow:0 22px 60px rgba(0,0,0,0.45); }
		.jc-hero:after { content:""; position:absolute; inset:0; background:linear-gradient(90deg, rgba(255,255,255,0.02), rgba(255,255,255,0)); pointer-events:none; }
		.jc-hero-left { position:relative; z-index:1; }
		.jc-kicker { display:inline-flex; align-items:center; gap:8px; padding:6px 12px; background:rgba(108,123,255,0.15); border:1px solid rgba(108,123,255,0.35); border-radius:999px; color:var(--jc-text); font-size:13px; letter-spacing:0.04em; text-transform:uppercase; }
		.jc-hero-title { margin:10px 0 6px; font-size:32px; line-height:1.2; color:var(--jc-text); }
		.jc-hero-sub { margin:0 0 14px; color:var(--jc-muted); line-height:1.6; max-width:680px; }
		.jc-hero-right { position:relative; min-height:180px; display:flex; align-items:center; justify-content:flex-end; }
		.jc-hero-badge { position:relative; z-index:1; padding:12px 18px; border-radius:14px; background:linear-gradient(135deg,var(--jc-accent),var(--jc-accent-2)); color:#040510; font-weight:800; letter-spacing:0.08em; box-shadow:0 18px 40px rgba(108,123,255,0.45); }
		.jc-hero-glow { position:absolute; inset:20px; border-radius:18px; background:radial-gradient(circle at 50% 50%, rgba(108,123,255,0.25), transparent 55%); filter:blur(20px); opacity:0.9; }
		.jc-toolbar { margin:18px 0 8px; display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; }
		.jc-search { flex:1; min-width:260px; position:relative; }
		.jc-search input { width:100%; padding:12px 44px 12px 40px; background:var(--jc-panel); border:1px solid var(--jc-border); border-radius:12px; color:var(--jc-text); box-shadow:0 12px 32px rgba(0,0,0,0.35); font-size:14px; }
		.jc-search input::placeholder { color:var(--jc-muted); opacity:0.6; }
		.jc-search input:focus { outline:none; border-color:var(--jc-accent); box-shadow:0 0 0 3px rgba(108,123,255,0.35); background:rgba(11,15,29,0.9); }
		.jc-search-icon { position:absolute; left:14px; top:50%; transform:translateY(-50%); opacity:0.7; }
		.jc-teilnehmer-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:20px; margin-top:20px; }
		.jc-teilnehmer-card { background:var(--jc-panel); border:1px solid var(--jc-border); border-radius:18px; padding:22px; position:relative; overflow:hidden; box-shadow:0 20px 54px rgba(0,0,0,0.4); transition:transform .2s, border-color .2s, box-shadow .2s; }
		.jc-teilnehmer-card:hover { transform:translateY(-5px); border-color:rgba(108,123,255,0.5); box-shadow:0 24px 68px rgba(0,0,0,0.5); }
		.jc-teilnehmer-header { display:flex; gap:16px; align-items:center; margin-bottom:18px; }
		.jc-teilnehmer-avatar { width:80px; height:80px; border-radius:50%; object-fit:cover; border:3px solid var(--jc-border); box-shadow:0 8px 20px rgba(0,0,0,0.3); }
		.jc-teilnehmer-info { flex:1; }
		.jc-teilnehmer-name { margin:0; font-size:20px; font-weight:800; color:var(--jc-text); line-height:1.2; }
		.jc-teilnehmer-title { margin:6px 0 0; font-size:14px; color:var(--jc-muted); }
		.jc-teilnehmer-socials { display:flex; flex-wrap:wrap; gap:10px; }
		.jc-social-btn { display:inline-flex; align-items:center; gap:8px; padding:10px 14px; border-radius:10px; background:rgba(108,123,255,0.12); border:1px solid rgba(108,123,255,0.3); color:var(--jc-text); text-decoration:none; font-weight:700; font-size:13px; transition:all .2s; }
		.jc-social-btn:hover { background:rgba(108,123,255,0.2); border-color:rgba(108,123,255,0.5); transform:translateY(-2px); box-shadow:0 8px 16px rgba(108,123,255,0.25); }
		.jc-social-youtube { background:rgba(255,0,0,0.12); border-color:rgba(255,0,0,0.3); color:#ff6b6b; }
		.jc-social-youtube:hover { background:rgba(255,0,0,0.2); border-color:rgba(255,0,0,0.5); box-shadow:0 8px 16px rgba(255,0,0,0.3); }
		.jc-social-twitch { background:rgba(145,70,255,0.12); border-color:rgba(145,70,255,0.3); color:#bf9dff; }
		.jc-social-twitch:hover { background:rgba(145,70,255,0.2); border-color:rgba(145,70,255,0.5); box-shadow:0 8px 16px rgba(145,70,255,0.3); }
		.jc-social-tiktok { background:rgba(255,255,255,0.08); border-color:rgba(255,255,255,0.2); color:#e9ecf7; }
		.jc-social-tiktok:hover { background:rgba(255,255,255,0.15); border-color:rgba(255,255,255,0.4); box-shadow:0 8px 16px rgba(255,255,255,0.2); }
		.jc-social-instagram { background:rgba(228,64,95,0.12); border-color:rgba(228,64,95,0.3); color:#ff87a8; }
		.jc-social-instagram:hover { background:rgba(228,64,95,0.2); border-color:rgba(228,64,95,0.5); box-shadow:0 8px 16px rgba(228,64,95,0.3); }
		.jc-social-twitter { background:rgba(29,155,240,0.12); border-color:rgba(29,155,240,0.3); color:#6db6f7; }
		.jc-social-twitter:hover { background:rgba(29,155,240,0.2); border-color:rgba(29,155,240,0.5); box-shadow:0 8px 16px rgba(29,155,240,0.3); }
		@media (max-width: 900px) {
			.jc-hero { grid-template-columns:1fr; }
			.jc-hero-right { justify-content:flex-start; min-height:120px; }
		}
		@media (max-width: 640px) {
			.jc-teilnehmer-grid { grid-template-columns:1fr; }
			.jc-hero { padding:22px; }
		}
	';
