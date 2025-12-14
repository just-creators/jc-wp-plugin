<?php
/**
 * Plugin Name: JustCreators Mods
 * Description: Zeigt Modrinth-Mods im JustCreators Stil an und erlaubt Verwaltung im Admin-Menü.
 * Version: 1.0.0
 * Author: JustCreators Team
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Optionen
define( 'JC_MODS_OPTION_KEY', 'jc_modrinth_mods' );
define( 'JC_MODS_MC_VERSION_OPTION', 'jc_modrinth_mc_version' );

// Admin Menü
add_action( 'admin_menu', 'jc_mods_register_menu' );
add_action( 'admin_init', 'jc_mods_handle_actions' );

// Shortcode für die öffentliche Seite
add_shortcode( 'jc_mods', 'jc_mods_render_shortcode' );

/**
 * Menüpunkt registrieren.
 */
function jc_mods_register_menu() {
	add_menu_page(
		'JustCreators Mods',
		'Mods',
		'manage_options',
		'jc-mods',
		'jc_mods_render_admin_page',
		'dashicons-admin-plugins',
		58
	);
}

/**
 * Formularverarbeitung für Admin-Aktionen.
 */
function jc_mods_handle_actions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Minecraft-Version speichern
	if ( isset( $_POST['jc_mods_save_mc_version'] ) ) {
		check_admin_referer( 'jc_mods_mc_version' );
		$version = isset( $_POST['jc_mc_version'] ) ? sanitize_text_field( wp_unslash( $_POST['jc_mc_version'] ) ) : '';
		if ( $version ) {
			update_option( JC_MODS_MC_VERSION_OPTION, $version );
			add_settings_error( 'jc_mods', 'jc_mods_version_saved', 'Minecraft-Version gespeichert.', 'updated' );
		}
	}

	// Mod hinzufügen
	if ( isset( $_POST['jc_mods_add_mod'] ) ) {
		check_admin_referer( 'jc_mods_add' );
		$input = isset( $_POST['jc_modrinth_link'] ) ? sanitize_text_field( wp_unslash( $_POST['jc_modrinth_link'] ) ) : '';
		$slug  = jc_mods_parse_slug( $input );

		if ( ! $slug ) {
			add_settings_error( 'jc_mods', 'jc_mods_invalid_slug', 'Der Link oder Slug konnte nicht erkannt werden.', 'error' );
			return;
		}

		$project = jc_mods_fetch_project( $slug );

		if ( is_wp_error( $project ) ) {
			add_settings_error( 'jc_mods', 'jc_mods_fetch_error', $project->get_error_message(), 'error' );
			return;
		}

		$mods          = jc_mods_get_list();
		$mods[ $slug ] = array(
			'slug'        => $project['slug'],
			'title'       => $project['title'],
			'author'      => $project['author'],
			'icon_url'    => $project['icon_url'],
			'project_url' => $project['project_url'],
			'added_at'    => current_time( 'mysql' ),
		);

		update_option( JC_MODS_OPTION_KEY, $mods );
		add_settings_error( 'jc_mods', 'jc_mods_added', 'Mod wurde hinzugefügt.', 'updated' );
	}

	// Mod entfernen
	if ( isset( $_GET['jc_remove_mod'], $_GET['_wpnonce'] ) ) {
		$slug = sanitize_text_field( wp_unslash( $_GET['jc_remove_mod'] ) );

		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'jc_mods_remove_' . $slug ) ) {
			$mods = jc_mods_get_list();
			if ( isset( $mods[ $slug ] ) ) {
				unset( $mods[ $slug ] );
				update_option( JC_MODS_OPTION_KEY, $mods );
				add_settings_error( 'jc_mods', 'jc_mods_removed', 'Mod entfernt.', 'updated' );
			}
		}
	}
}

/**
 * Admin-Seite rendern.
 */
function jc_mods_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$mods       = jc_mods_get_list();
	$mc_version = get_option( JC_MODS_MC_VERSION_OPTION, '1.21.1' );

	settings_errors( 'jc_mods' );
	?>
	<div class="wrap">
		<h1>JustCreators Mods</h1>

		<div class="jc-admin-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:1200px;">
			<div class="jc-admin-card" style="background:#1f2230;padding:20px;border-radius:12px;border:1px solid #2b2e3d;box-shadow:0 10px 30px rgba(0,0,0,0.2);">
				<h2>Minecraft-Version</h2>
				<p>Diese Version wird beim Download automatisch bevorzugt.</p>
				<form method="post">
					<?php wp_nonce_field( 'jc_mods_mc_version' ); ?>
					<input type="text" name="jc_mc_version" value="<?php echo esc_attr( $mc_version ); ?>" class="regular-text" placeholder="z.B. 1.21.1">
					<p class="submit" style="margin-top:10px;">
						<button type="submit" name="jc_mods_save_mc_version" class="button button-primary">Speichern</button>
					</p>
				</form>
			</div>

			<div class="jc-admin-card" style="background:#1f2230;padding:20px;border-radius:12px;border:1px solid #2b2e3d;box-shadow:0 10px 30px rgba(0,0,0,0.2);">
				<h2>Mod hinzufügen</h2>
				<p>Modrinth-Link oder Slug einfügen. Name, Autor und Bild werden automatisch geladen.</p>
				<form method="post">
					<?php wp_nonce_field( 'jc_mods_add' ); ?>
					<input type="text" name="jc_modrinth_link" class="regular-text" placeholder="https://modrinth.com/mod/your-mod" required>
					<p class="submit" style="margin-top:10px;">
						<button type="submit" name="jc_mods_add_mod" class="button button-secondary">Hinzufügen</button>
					</p>
				</form>
			</div>
		</div>

		<h2 style="margin-top:30px;">Aktive Mods</h2>
		<?php if ( empty( $mods ) ) : ?>
			<p>Keine Mods hinterlegt. Füge die erste Mod oben hinzu.</p>
		<?php else : ?>
			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;max-width:1200px;">
				<?php foreach ( $mods as $mod ) : ?>
					<div style="background:#1f2230;border:1px solid #2b2e3d;border-radius:12px;padding:16px;display:flex;gap:12px;align-items:center;">
						<img src="<?php echo esc_url( $mod['icon_url'] ); ?>" alt="<?php echo esc_attr( $mod['title'] ); ?>" style="width:48px;height:48px;border-radius:10px;object-fit:cover;background:#111;">
						<div style="flex:1;">
							<strong style="display:block;color:#fff;"><?php echo esc_html( $mod['title'] ); ?></strong>
							<span style="color:#9aa0b5;font-size:12px;">von <?php echo esc_html( $mod['author'] ); ?></span>
						</div>
						<div>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=jc-mods&jc_remove_mod=' . rawurlencode( $mod['slug'] ) ), 'jc_mods_remove_' . $mod['slug'] ) ); ?>">Entfernen</a>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div style="margin-top:30px;max-width:1200px;">
			<p><strong>Shortcode:</strong> <code>[jc_mods]</code> auf einer Seite einfügen, um die Mods-Liste im Frontend anzuzeigen.</p>
		</div>
	</div>
	<?php
}

/**
 * Mods-Liste aus Option holen.
 */
function jc_mods_get_list() {
	$mods = get_option( JC_MODS_OPTION_KEY, array() );
	return is_array( $mods ) ? $mods : array();
}

/**
 * Slug aus Modrinth-Link oder Eingabe extrahieren.
 */
function jc_mods_parse_slug( $input ) {
	if ( ! $input ) {
		return '';
	}

	// Link
	if ( preg_match( '#modrinth\.com/mod/([A-Za-z0-9_-]+)#', $input, $matches ) ) {
		return sanitize_title( $matches[1] );
	}

	// Nur Slug
	return sanitize_title( $input );
}

/**
 * Projektinfos von Modrinth laden.
 */
function jc_mods_fetch_project( $slug ) {
	$url      = 'https://api.modrinth.com/v2/project/' . rawurlencode( $slug );
	$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'jc_mods_api', 'Fehler beim Abrufen der Modrinth API.' );
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return new WP_Error( 'jc_mods_api', 'Mod nicht gefunden (HTTP ' . intval( $code ) . ').' );
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $data['slug'] ) ) {
		return new WP_Error( 'jc_mods_api', 'Ungültige Antwort der Modrinth API.' );
	}

	return array(
		'slug'        => $data['slug'],
		'title'       => $data['title'],
		'author'      => $data['author'],
		'icon_url'    => $data['icon_url'],
		'project_url' => 'https://modrinth.com/mod/' . $data['slug'],
	);
}

/**
 * Neueste Version für eine Mod und Minecraft-Version holen (gecacht).
 */
function jc_mods_fetch_latest_version( $slug, $mc_version ) {
	$cache_key = 'jc_mods_v_' . md5( $slug . $mc_version );
	$cached    = get_transient( $cache_key );
	if ( $cached ) {
		return $cached;
	}

	$query = array(
		'game_versions' => wp_json_encode( array( $mc_version ) ),
		'loaders'       => wp_json_encode( array( 'fabric', 'quilt', 'forge', 'neoforge' ) ),
		'featured'      => 'true',
	);

	$url      = 'https://api.modrinth.com/v2/project/' . rawurlencode( $slug ) . '/version?' . http_build_query( $query );
	$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'jc_mods_api', 'Fehler beim Laden der Versionen.' );
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return new WP_Error( 'jc_mods_api', 'Keine Version für diese Minecraft-Version gefunden.' );
	}

	$versions = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $versions[0]['files'][0]['url'] ) ) {
		return new WP_Error( 'jc_mods_api', 'Keine Downloads für diese Mod gefunden.' );
	}

	$version = $versions[0];
	$file    = $version['files'][0];

	$result = array(
		'name'          => $version['name'] ?? ( $version['version_number'] ?? '' ),
		'version'       => $version['version_number'] ?? '',
		'download_url'  => $file['url'],
		'filename'      => $file['filename'] ?? '',
		'game_versions' => $version['game_versions'] ?? array(),
	);

	set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );

	return $result;
}

/**
 * Shortcode-Ausgabe.
 */
function jc_mods_render_shortcode() {
	$mods       = jc_mods_get_list();
	$mc_version = get_option( JC_MODS_MC_VERSION_OPTION, '1.21.1' );

	if ( empty( $mods ) ) {
		return '<p>Keine Mods hinterlegt.</p>';
	}

	ob_start();
	jc_mods_styles();
	?>
	<div class="jc-wrap">
		<div class="jc-card" style="margin-bottom:20px;">
			<h1 class="jc-h">📦 JustCreators Mod-Download</h1>
			<p style="color:#a0a8b8;line-height:1.6;margin:10px 0 0;">Alle freigegebenen Mods im einheitlichen JustCreators Design. Voreingestellte Minecraft-Version: <strong style="color:#fff;"><?php echo esc_html( $mc_version ); ?></strong></p>
		</div>

		<div class="jc-card" style="margin-bottom:16px;display:flex;flex-wrap:wrap;align-items:center;gap:12px;">
			<input id="jc-mods-search" type="search" placeholder="Mods durchsuchen..." class="jc-input" style="flex:1;min-width:220px;">
			<div style="color:#a0a8b8;font-size:14px;">Tipp: Mod fehlt? Melde dich im Teilnehmer-Discord, damit das Team sie pr&uuml;fen kann.</div>
		</div>

		<div id="jc-mods-grid" class="jc-mods-grid">
			<?php foreach ( $mods as $mod ) :
				$version_info = jc_mods_fetch_latest_version( $mod['slug'], $mc_version );
				$download     = ! is_wp_error( $version_info ) ? $version_info['download_url'] : '';
				$version_name = ! is_wp_error( $version_info ) ? ( $version_info['name'] ?: $version_info['version'] ) : '';
				$error_text   = is_wp_error( $version_info ) ? $version_info->get_error_message() : '';
			?>
			<div class="jc-mod-card" data-search="<?php echo esc_attr( strtolower( $mod['title'] . ' ' . $mod['author'] ) ); ?>">
				<div class="jc-mod-header">
					<img src="<?php echo esc_url( $mod['icon_url'] ); ?>" alt="<?php echo esc_attr( $mod['title'] ); ?>" class="jc-mod-icon">
					<div class="jc-mod-meta">
						<div class="jc-mod-title"><?php echo esc_html( $mod['title'] ); ?></div>
						<div class="jc-mod-author">von <?php echo esc_html( $mod['author'] ); ?></div>
					</div>
					<span class="jc-tag">MC <?php echo esc_html( $mc_version ); ?></span>
				</div>
				<div class="jc-mod-footer">
					<?php if ( $download ) : ?>
						<a class="jc-btn" href="<?php echo esc_url( $download ); ?>" target="_blank" rel="noopener noreferrer">⬇️ Download (<?php echo esc_html( $version_name ); ?>)</a>
					<?php else : ?>
						<div class="jc-msg jc-error" style="margin:0;"><?php echo esc_html( $error_text ); ?></div>
					<?php endif; ?>
					<a class="jc-link" href="<?php echo esc_url( $mod['project_url'] ); ?>" target="_blank" rel="noopener noreferrer">Projekt auf Modrinth</a>
				</div>
			</div>
			<?php endforeach; ?>
		</div>

		<div class="jc-card" style="margin-top:16px;">
			<h3 style="margin:0 0 8px;color:#fff;">Neue Mod vorschlagen</h3>
			<p style="color:#a0a8b8;line-height:1.6;margin:0;">Schreib uns im Teilnehmer-Discord, wenn du eine weitere Mod m&ouml;chtest. Das Team pr&uuml;ft und f&uuml;gt sie bei Freigabe hinzu.</p>
		</div>
	</div>

	<script>
	(function(){
		const search = document.getElementById('jc-mods-search');
		const cards  = document.querySelectorAll('.jc-mod-card');
		if (!search) return;
		search.addEventListener('input', function(){
			const term = this.value.toLowerCase();
			cards.forEach(card => {
				const hay = card.dataset.search;
				card.style.display = hay.includes(term) ? 'block' : 'none';
			});
		});
	})();
	</script>
	<?php
	return ob_get_clean();
}

/**
 * Styles für Frontend.
 */
function jc_mods_styles() {
	?>
	<style>
		.jc-wrap { max-width: 1100px; margin: 30px auto; padding: 30px; }
		.jc-card { background: #1e2230; border: 1px solid #2b3042; border-radius: 16px; padding: 24px; box-shadow: 0 12px 40px rgba(0,0,0,0.35); }
		.jc-h { color: #fff; margin: 0 0 10px; display: flex; align-items: center; gap: 12px; }
		.jc-input { width: 100%; padding: 12px 14px; background: #161925; border: 1px solid #2b3042; border-radius: 10px; color: #fff; }
		.jc-input:focus { outline: none; border-color: #5865F2; box-shadow: 0 0 0 3px rgba(88,101,242,0.25); }
		.jc-btn { display: inline-flex; align-items: center; gap: 10px; padding: 12px 18px; border-radius: 10px; background: linear-gradient(135deg,#5865F2,#4752c4); color: #fff; text-decoration: none; font-weight: 600; box-shadow: 0 8px 22px rgba(88,101,242,0.35); transition: transform .2s, box-shadow .2s; }
		.jc-btn:hover { transform: translateY(-2px); box-shadow: 0 12px 26px rgba(88,101,242,0.45); color:#fff; }
		.jc-link { color: #8ea0ff; text-decoration: none; font-weight: 600; }
		.jc-link:hover { text-decoration: underline; }
		.jc-msg { padding: 12px 14px; border-radius: 10px; font-weight: 600; }
		.jc-error { background: rgba(244,67,54,0.12); color: #f88; border-left: 3px solid #f44336; }
		.jc-mods-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:16px; }
		.jc-mod-card { background:#141824; border:1px solid #23283a; border-radius:14px; padding:16px; display:flex; flex-direction:column; gap:12px; box-shadow:0 10px 28px rgba(0,0,0,0.25); transition:transform .2s, border-color .2s; }
		.jc-mod-card:hover { transform: translateY(-2px); border-color:#5865F2; }
		.jc-mod-header { display:flex; align-items:center; gap:12px; }
		.jc-mod-icon { width:56px; height:56px; border-radius:12px; object-fit:cover; background:#0f111a; }
		.jc-mod-meta { flex:1; }
		.jc-mod-title { color:#fff; font-weight:700; font-size:17px; }
		.jc-mod-author { color:#9aa0b5; font-size:13px; margin-top:2px; }
		.jc-tag { background: rgba(88,101,242,0.14); color:#cbd1ff; padding:6px 10px; border-radius:20px; font-size:12px; font-weight:700; border:1px solid rgba(88,101,242,0.35); }
		.jc-mod-footer { display:flex; flex-direction:column; gap:8px; }
		@media (max-width: 720px) {
			.jc-wrap { padding: 20px; }
		}
	</style>
	<?php
}

