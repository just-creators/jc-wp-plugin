<?php
/**
 * Plugin Name: JustCreators Mods
 * Description: Modrinth-Mods im JustCreators Look, mit Admin-Verwaltung. Fabric-only Downloads.
 * Version: 1.1.0
 * Author: JustCreators Team
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Gemeinsamer Admin-Parent für alle JustCreators Screens
if ( ! defined( 'JC_ADMIN_PARENT_SLUG' ) ) {
	define( 'JC_ADMIN_PARENT_SLUG', 'justcreators-hub' );
}

if ( ! function_exists( 'jc_register_parent_menu' ) ) {
	function jc_register_parent_menu() {
		add_menu_page(
			'JustCreators',
			'JustCreators',
			'manage_options',
			JC_ADMIN_PARENT_SLUG,
			function() {
				echo '<div class="wrap"><h1>JustCreators</h1><p>Wähle einen Unterpunkt aus der linken Navigation.</p></div>';
			},
			'dashicons-admin-multisite',
			30
		);
	}
	add_action( 'admin_menu', 'jc_register_parent_menu', 0 );
}

// Optionen
define( 'JC_MODS_OPTION_KEY', 'jc_modrinth_mods' );
define( 'JC_MODS_MC_VERSION_OPTION', 'jc_modrinth_mc_version' );
define( 'JC_MODS_MODPACK_URL', 'https://modrinth.com/modpack/justcreators' );

// Hooks
add_action( 'wp_enqueue_scripts', 'jc_mods_enqueue_styles' );
add_action( 'admin_menu', 'jc_mods_register_menu' );
add_action( 'admin_init', 'jc_mods_handle_actions' );
add_shortcode( 'jc_mods', 'jc_mods_render_shortcode' );

function jc_mods_enqueue_styles() {
	wp_register_style( 'jc-mods-inline', false );
	wp_enqueue_style( 'jc-mods-inline' );
	wp_add_inline_style( 'jc-mods-inline', jc_mods_get_css() );
}

function jc_mods_get_css() {
	return <<<'EOT'
		:root { --jc-bg:#050712; --jc-panel:#0b0f1d; --jc-border:#1e2740; --jc-text:#e9ecf7; --jc-muted:#9eb3d5; --jc-accent:#6c7bff; --jc-accent-2:#56d8ff; }
		.jc-wrap { max-width: 1220px; margin: 26px auto; padding: 0 18px 40px; color: var(--jc-text); font-family: "Space Grotesk", "Inter", "SF Pro Display", system-ui, -apple-system, sans-serif; }
		.jc-hero { display:grid; grid-template-columns:2fr 1fr; gap:22px; background: radial-gradient(120% 140% at 10% 10%, rgba(108,123,255,0.12), transparent 50%), radial-gradient(110% 120% at 90% 20%, rgba(86,216,255,0.1), transparent 45%), var(--jc-panel); border:1px solid var(--jc-border); border-radius:20px; padding:28px; position:relative; overflow:hidden; box-shadow:0 22px 60px rgba(0,0,0,0.45); }
		.jc-hero:after { content:""; position:absolute; inset:0; background:linear-gradient(90deg, rgba(255,255,255,0.02), rgba(255,255,255,0)); pointer-events:none; }
		.jc-hero-left { position:relative; z-index:1; }
		.jc-kicker { display:inline-flex; align-items:center; gap:8px; padding:6px 12px; background:rgba(108,123,255,0.15); border:1px solid rgba(108,123,255,0.35); border-radius:999px; color:var(--jc-text); font-size:13px; letter-spacing:0.04em; text-transform:uppercase; }
		.jc-hero-title { margin:10px 0 6px; font-size:32px; line-height:1.2; color:var(--jc-text); }
		.jc-hero-sub { margin:0 0 14px; color:var(--jc-muted); line-height:1.6; max-width:680px; }
		.jc-hero-actions { display:flex; gap:10px; flex-wrap:wrap; }
		.jc-pill { background:rgba(108,123,255,0.15); border:1px solid rgba(108,123,255,0.35); color:var(--jc-text); padding:8px 12px; border-radius:999px; font-weight:600; }
		.jc-pill-ghost { background:transparent; border-color:var(--jc-border); color:var(--jc-muted); }
		.jc-hero-right { position:relative; min-height:180px; display:flex; align-items:center; justify-content:flex-end; }
		.jc-hero-badge { position:relative; z-index:1; padding:12px 18px; border-radius:14px; background:linear-gradient(135deg,var(--jc-accent),var(--jc-accent-2)); color:#040510; font-weight:800; letter-spacing:0.08em; box-shadow:0 18px 40px rgba(108,123,255,0.45); }
		.jc-hero-glow { position:absolute; inset:20px; border-radius:18px; background:radial-gradient(circle at 50% 50%, rgba(108,123,255,0.25), transparent 55%); filter:blur(20px); opacity:0.9; }
		.jc-toolbar { margin:18px 0 8px; display:flex; flex-wrap:wrap; gap:12px; align-items:center; justify-content:space-between; }
		.jc-toolbar-note { color:var(--jc-muted); font-size:14px; margin-bottom:10px; }
		.jc-search { flex:1; min-width:260px; position:relative; }
		.jc-loader-filters { display:flex; gap:8px; }
		.jc-filter-btn { padding:8px 14px; border-radius:10px; background:rgba(30,39,64,0.6); border:1px solid var(--jc-border); color:var(--jc-muted); font-weight:700; cursor:pointer; transition:all .2s; }
		.jc-filter-btn:hover { background:rgba(30,39,64,0.9); border-color:rgba(108,123,255,0.4); }
		.jc-filter-btn.active { background:linear-gradient(135deg,var(--jc-accent),var(--jc-accent-2)); color:#050712; border-color:transparent; box-shadow:0 8px 20px rgba(108,123,255,0.35); }
		.jc-search input { width:100%; padding:12px 44px 12px 40px; background:var(--jc-panel); border:1px solid var(--jc-border); border-radius:12px; color:var(--jc-text); box-shadow:0 12px 32px rgba(0,0,0,0.35); font-size:14px; }
		.jc-search input::placeholder { color:var(--jc-muted); opacity:0.6; }
		.jc-search input:focus { outline:none; border-color:var(--jc-accent); box-shadow:0 0 0 3px rgba(108,123,255,0.35); background:rgba(11,15,29,0.9); }
		.jc-search-icon { position:absolute; left:14px; top:50%; transform:translateY(-50%); opacity:0.7; }
		.jc-mods-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:20px; }
		.jc-mod-card { background:var(--jc-panel); border:1px solid var(--jc-border); border-radius:18px; padding:22px; display:grid; grid-template-columns:170px 1fr; gap:20px; position:relative; overflow:hidden; box-shadow:0 20px 54px rgba(0,0,0,0.4); transition:transform .2s, border-color .2s, box-shadow .2s; }
		.jc-mod-card:hover { transform:translateY(-5px); border-color:rgba(108,123,255,0.5); box-shadow:0 24px 68px rgba(0,0,0,0.5); }
		.jc-mod-card.jc-unavailable { opacity:0.5; border-color:rgba(244,67,54,0.35); }
		.jc-mod-card.jc-unavailable:hover { transform:none; border-color:rgba(244,67,54,0.5); }
		.jc-mod-card.jc-unavailable .jc-btn:disabled { opacity:0.6; cursor:not-allowed; background:rgba(100,100,120,0.3); box-shadow:none; }
		.jc-mod-thumb { position:relative; min-height:150px; }
		.jc-mod-thumb img { width:100%; height:100%; min-height:150px; max-height:220px; object-fit:cover; border-radius:14px; background:#050712; border:1px solid var(--jc-border); }
		.jc-badge { position:absolute; bottom:8px; left:8px; background:rgba(108,123,255,0.9); color:#050712; padding:6px 10px; border-radius:10px; font-weight:700; font-size:12px; }
		.jc-mod-body { display:flex; flex-direction:column; gap:12px; }
		.jc-mod-top { display:flex; align-items:flex-start; justify-content:space-between; gap:10px; }
		.jc-mod-title { color:var(--jc-text); font-weight:800; font-size:18px; line-height:1.3; }
		.jc-mod-author { color:var(--jc-muted); font-size:13px; margin-top:4px; }
		.jc-tag { background:rgba(86,216,255,0.12); color:#c9eeff; padding:6px 10px; border-radius:12px; font-size:12px; font-weight:700; border:1px solid rgba(86,216,255,0.35); }
		.jc-mod-bottom { display:flex; flex-direction:column; gap:8px; }
		.jc-meta-line { color:var(--jc-muted); font-size:13px; }
		.jc-btn { display:inline-flex; align-items:center; gap:8px; justify-content:center; padding:12px 16px; border-radius:12px; background:linear-gradient(135deg,var(--jc-accent),var(--jc-accent-2)); color:#050712; text-decoration:none; font-weight:800; letter-spacing:0.01em; box-shadow:0 14px 34px rgba(108,123,255,0.45); transition:transform .2s, box-shadow .2s; }
		.jc-btn:hover { transform:translateY(-2px); box-shadow:0 16px 40px rgba(86,216,255,0.5); color:#050712; }
		.jc-link { color:var(--jc-muted); text-decoration:none; font-weight:700; font-size:14px; }
		.jc-link:hover { color:var(--jc-text); }
		.jc-msg { padding:12px 14px; border-radius:12px; font-weight:700; background:rgba(255,105,105,0.12); color:#ff9a9a; border:1px solid rgba(255,105,105,0.3); }
		.jc-error { background:rgba(255,105,105,0.12); color:#ffb3b3; border:1px solid rgba(255,105,105,0.35); }
		.jc-callout { margin-top:18px; padding:18px 18px 20px; border-radius:16px; background:linear-gradient(120deg, rgba(108,123,255,0.16), rgba(86,216,255,0.12)); border:1px solid rgba(108,123,255,0.35); box-shadow:0 18px 48px rgba(0,0,0,0.35); }
		.jc-callout h3 { margin:0 0 6px; color:var(--jc-text); }
		.jc-callout p { margin:0; color:var(--jc-muted); line-height:1.6; }
		.jc-callout.jc-modpack { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
		@media (max-width: 900px) {
			.jc-hero { grid-template-columns:1fr; }
			.jc-hero-right { justify-content:flex-start; min-height:120px; }
		}
		@media (max-width: 640px) {
			.jc-mod-card { grid-template-columns:1fr; }
			.jc-toolbar { flex-direction:column; align-items:flex-start; }
			.jc-hero { padding:22px; }
		}
	EOT;
}

function jc_mods_register_menu() {
	add_submenu_page(
		JC_ADMIN_PARENT_SLUG,
		'JustCreators Mods',
		'Mods',
		'manage_options',
		'jc-mods',
		'jc_mods_render_admin_page'
	);
}

function jc_mods_handle_actions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Handle ZIP download separately to avoid header conflicts
	if ( isset( $_POST['jc_mods_download_zip'] ) ) {
		check_admin_referer( 'jc_mods_download_zip' );
		// Delay execution to after WordPress is fully loaded
		add_action( 'shutdown', 'jc_mods_create_zip_download' );
		return;
	}

	if ( isset( $_POST['jc_mods_save_mc_version'] ) ) {
		check_admin_referer( 'jc_mods_mc_version' );
		$version = isset( $_POST['jc_mc_version'] ) ? sanitize_text_field( wp_unslash( $_POST['jc_mc_version'] ) ) : '';
		if ( $version ) {
			update_option( JC_MODS_MC_VERSION_OPTION, $version );
			add_settings_error( 'jc_mods', 'jc_mods_version_saved', 'Minecraft-Version gespeichert.', 'updated' );
		}

		// Save fallback icon URL
		if ( isset( $_POST['jc_mods_save_fallback_icon'] ) ) {
			check_admin_referer( 'jc_mods_fallback_icon' );
			$fallback = isset( $_POST['jc_mods_fallback_icon_url'] ) ? esc_url_raw( wp_unslash( $_POST['jc_mods_fallback_icon_url'] ) ) : '';
			if ( $fallback ) {
				update_option( 'jc_mods_fallback_icon', $fallback );
				// Clear cached icon transients so change is visible immediately
				global $wpdb;
				$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_jc_mod_icon_%'" );
				add_settings_error( 'jc_mods', 'jc_mods_fallback_saved', 'Fallback-Icon gespeichert.', 'updated' );
			} else {
				add_settings_error( 'jc_mods', 'jc_mods_fallback_empty', 'Ungültige URL.', 'error' );
			}
		}
	}

	if ( isset( $_POST['jc_mods_import_config'] ) ) {
		check_admin_referer( 'jc_mods_import_config' );
		$config_file = plugin_dir_path( __FILE__ ) . 'mods-config.json';
		
		if ( ! file_exists( $config_file ) ) {
			add_settings_error( 'jc_mods', 'jc_mods_no_config', 'mods-config.json nicht gefunden.', 'error' );
			return;
		}

		$config_content = file_get_contents( $config_file );
		$config = json_decode( $config_content, true );

		if ( ! $config || ! isset( $config['mods'] ) || ! is_array( $config['mods'] ) ) {
			add_settings_error( 'jc_mods', 'jc_mods_invalid_config', 'Ungültige Config-Datei.', 'error' );
			return;
		}

		$mods = jc_mods_get_list();
		$added = 0;
		$skipped = 0;
		$errors = 0;

		foreach ( $config['mods'] as $slug ) {
			$slug = trim( $slug );
			
			if ( isset( $mods[ $slug ] ) ) {
				$skipped++;
				continue;
			}

			$project = jc_mods_fetch_project( $slug );
			if ( is_wp_error( $project ) ) {
				$errors++;
				continue;
			}

			$mods[ $slug ] = array(
				'slug'        => $project['slug'],
				'title'       => $project['title'],
				'author'      => $project['author'],
				'icon_url'    => $project['icon_url'],
				'project_url' => $project['project_url'],
				'added_at'    => current_time( 'mysql' ),
			);
			$added++;
		}

		update_option( JC_MODS_OPTION_KEY, $mods );
		add_settings_error( 'jc_mods', 'jc_mods_imported', sprintf( 'Import abgeschlossen: %d hinzugefügt, %d übersprungen, %d Fehler.', $added, $skipped, $errors ), 'updated' );
	}

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

function jc_mods_render_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$mods       = jc_mods_get_list();
	$mc_version = get_option( JC_MODS_MC_VERSION_OPTION, '1.21.1' );
	$fallback_icon = get_option( 'jc_mods_fallback_icon', 'https://avatars.githubusercontent.com/u/105741850?v=4' );

	settings_errors( 'jc_mods' );
	?>
	<div class="wrap" style="max-width:1240px;">
		<h1 style="margin-bottom:16px;">JustCreators Mods (Fabric only)</h1>

		<div class="jc-admin-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:18px;">
			<div class="jc-admin-card" style="background:#0f1220;padding:20px;border-radius:14px;border:1px solid #1f2740;box-shadow:0 16px 40px rgba(0,0,0,0.35);">
				<h2 style="margin:0 0 8px;color:#f8f9ff;">Minecraft-Version</h2>
				<p style="margin:0 0 14px;color:#9eb3d5;">Diese Version wird beim Download vorausgewählt. Fabric wird erzwungen.</p>
				<form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
					<?php wp_nonce_field( 'jc_mods_mc_version' ); ?>
					<input type="text" name="jc_mc_version" value="<?php echo esc_attr( $mc_version ); ?>" class="regular-text" placeholder="z.B. 1.21.1" style="min-width:160px;">
					<button type="submit" name="jc_mods_save_mc_version" class="button button-primary">Speichern</button>
				</form>
			</div>

			<div class="jc-admin-card" style="background:#0f1220;padding:20px;border-radius:14px;border:1px solid #1f2740;box-shadow:0 16px 40px rgba(0,0,0,0.35);">
				<h2 style="margin:0 0 8px;color:#f8f9ff;">Mod hinzufügen</h2>
				<p style="margin:0 0 14px;color:#9eb3d5;">Modrinth-Link oder Slug einfügen. Es werden nur Fabric-Versionen geladen.</p>
				<form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
					<?php wp_nonce_field( 'jc_mods_add' ); ?>
					<input type="text" name="jc_modrinth_link" class="regular-text" placeholder="https://modrinth.com/mod/your-mod" required style="flex:1;min-width:220px;">
					<button type="submit" name="jc_mods_add_mod" class="button">Hinzufügen</button>
				</form>
			</div>

			<div class="jc-admin-card" style="background:#0f1220;padding:20px;border-radius:14px;border:1px solid #1f2740;box-shadow:0 16px 40px rgba(0,0,0,0.35);">
				<h2 style="margin:0 0 8px;color:#f8f9ff;">Mods als ZIP downloaden</h2>
				<p style="margin:0 0 14px;color:#9eb3d5;">Alle kompatiblen Mods für MC <?php echo esc_html( $mc_version ); ?> in einer ZIP-Datei.</p>
				<form method="post">
					<?php wp_nonce_field( 'jc_mods_download_zip' ); ?>
					<button type="submit" name="jc_mods_download_zip" class="button button-secondary">ZIP downloaden</button>
				</form>
			</div>
		</div>

		<div class="jc-admin-card" style="background:#0f1220;padding:20px;border-radius:14px;border:1px solid #1f2740;box-shadow:0 16px 40px rgba(0,0,0,0.35);margin-top:12px;">
			<h2 style="margin:0 0 8px;color:#f8f9ff;">Fallback Icon</h2>
			<p style="margin:0 0 14px;color:#9eb3d5;">Wenn das Modrinth-Icon das Default-Logo ist oder nicht geladen werden kann, wird dieses Bild verwendet.</p>
			<form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
				<?php wp_nonce_field( 'jc_mods_fallback_icon' ); ?>
				<input type="text" name="jc_mods_fallback_icon_url" value="<?php echo esc_attr( $fallback_icon ); ?>" class="regular-text" placeholder="https://example.com/fallback.png" style="flex:1;min-width:220px;">
				<button type="submit" name="jc_mods_save_fallback_icon" class="button">Speichern</button>
			</form>
			<p style="margin-top:8px;color:#9eb3d5;">Standard: <code>https://avatars.githubusercontent.com/u/105741850?v=4</code></p>
		</div>

		<h2 style="margin:26px 0 12px;">Aktive Mods</h2>
		<?php if ( empty( $mods ) ) : ?>
			<p>Keine Mods hinterlegt. Füge die erste Mod oben hinzu.</p>
		<?php else : ?>
			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:14px;">
				<?php foreach ( $mods as $mod ) : ?>
					<div style="background:#0f1220;border:1px solid #1f2740;border-radius:12px;padding:14px;display:flex;gap:12px;align-items:center;box-shadow:0 12px 32px rgba(0,0,0,0.3);">
						<img src="<?php echo esc_url( jc_mods_validate_icon_url( $mod['icon_url'] ) ); ?>" alt="<?php echo esc_attr( $mod['title'] ); ?>" style="width:52px;height:52px;border-radius:12px;object-fit:cover;background:#0a0c16;">
						<div style="flex:1;">
							<strong style="display:block;color:#f4f6ff;"><?php echo esc_html( $mod['title'] ); ?></strong>
							<span style="color:#9eb3d5;font-size:12px;display:block;margin-top:2px;">von <?php echo esc_html( $mod['author'] ); ?></span>
						</div>
						<div>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=jc-mods&jc_remove_mod=' . rawurlencode( $mod['slug'] ) ), 'jc_mods_remove_' . $mod['slug'] ) ); ?>">Entfernen</a>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div style="margin-top:18px;max-width:1200px;">
			<p><strong>Shortcode:</strong> <code>[jc_mods]</code> auf einer Seite einfügen, um die Mods-Liste im Frontend anzuzeigen.</p>
		</div>
	</div>
	<?php
}

function jc_mods_get_list() {
	$mods = get_option( JC_MODS_OPTION_KEY, array() );
	return is_array( $mods ) ? $mods : array();
}

/**
 * Validate an icon URL and return a usable image URL.
 * If the URL points to a Modrinth default icon or is unreachable/not an image,
 * the configured fallback option is returned.
 */
function jc_mods_validate_icon_url( $url ) {
	if ( empty( $url ) ) {
		return get_option( 'jc_mods_fallback_icon', 'https://avatars.githubusercontent.com/u/105741850?v=4' );
	}

	$key = 'jc_mod_icon_' . md5( $url );
	if ( $cached = get_transient( $key ) ) {
		return $cached;
	}

	$fallback = get_option( 'jc_mods_fallback_icon', 'https://avatars.githubusercontent.com/u/105741850?v=4' );

	// Quick heuristics for Modrinth default icons
	$lower = strtolower( $url );
	if ( stripos( $lower, 'modrinth' ) !== false && ( stripos( $lower, 'default' ) !== false || stripos( $lower, 'avatar' ) !== false || stripos( $lower, 'mod-default' ) !== false ) ) {
		set_transient( $key, $fallback, DAY_IN_SECONDS );
		return $fallback;
	}

	// Try HEAD first
	$res = false;
	if ( function_exists( 'wp_remote_head' ) ) {
		$res = wp_remote_head( $url, array( 'timeout' => 8 ) );
	}
	if ( ! $res || is_wp_error( $res ) ) {
		$res = wp_remote_get( $url, array( 'timeout' => 8 ) );
	}

	if ( is_wp_error( $res ) ) {
		set_transient( $key, $fallback, DAY_IN_SECONDS );
		return $fallback;
	}

	$code = intval( wp_remote_retrieve_response_code( $res ) );
	$ctype = wp_remote_retrieve_header( $res, 'content-type' ) ?: '';
	if ( $code !== 200 || stripos( $ctype, 'image/' ) === false ) {
		set_transient( $key, $fallback, DAY_IN_SECONDS );
		return $fallback;
	}

	// OK - use original URL
	set_transient( $key, $url, DAY_IN_SECONDS );
	return $url;
}

function jc_mods_parse_slug( $input ) {
	if ( ! $input ) {
		return '';
	}

	if ( preg_match( '#modrinth\.com/mod/([A-Za-z0-9_-]+)#', $input, $matches ) ) {
		return sanitize_title( $matches[1] );
	}

	return sanitize_title( $input );
}

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

function jc_mods_fetch_latest_version( $slug, $mc_version ) {
	$cache_key = 'jc_mods_v_' . md5( $slug . $mc_version );
	$cached    = get_transient( $cache_key );
	if ( $cached ) {
		return $cached;
	}

	$query = array(
		'game_versions' => wp_json_encode( array( $mc_version ) ),
		'loaders'       => wp_json_encode( array( 'fabric' ) ),
		'featured'      => 'true',
	);

	$url      = 'https://api.modrinth.com/v2/project/' . rawurlencode( $slug ) . '/version?' . http_build_query( $query );
	$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'jc_mods_api', 'Fehler beim Laden der Versionen.' );
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		return new WP_Error( 'jc_mods_api', 'Keine Fabric-Version für diese Minecraft-Version gefunden.' );
	}

	$versions = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( empty( $versions ) ) {
		return new WP_Error( 'jc_mods_api', 'Keine Fabric-Downloads für diese Mod gefunden.' );
	}

	$version = $versions[0];

	// Wähle Datei: überspringe NeoForge/Forge, bevorzuge Fabric im Dateinamen
	$files = $version['files'] ?? array();
	if ( empty( $files ) ) {
		return new WP_Error( 'jc_mods_api', 'Keine Fabric-Downloads für diese Mod gefunden.' );
	}

	// Filtere NeoForge/Forge-Dateien aus
	$fabric_files = array();
	foreach ( $files as $f ) {
		$filename = isset( $f['filename'] ) ? strtolower( $f['filename'] ) : '';
		// Überspringe Dateien mit neoforge, forge (aber nicht fabric+forge)
		if ( stripos( $filename, 'neoforge' ) !== false || 
		     ( stripos( $filename, 'forge' ) !== false && stripos( $filename, 'fabric' ) === false ) ) {
			continue;
		}
		$fabric_files[] = $f;
	}

	if ( empty( $fabric_files ) ) {
		return new WP_Error( 'jc_mods_api', 'Keine Fabric-Downloads für diese Mod gefunden (nur NeoForge/Forge verfügbar).' );
	}

	// Wähle aus gefilterten Dateien: primary oder mit "fabric" im Namen
	$file = null;
	foreach ( $fabric_files as $f ) {
		if ( isset( $f['primary'] ) && $f['primary'] ) {
			$file = $f;
			break;
		}
	}
	if ( ! $file ) {
		foreach ( $fabric_files as $f ) {
			if ( isset( $f['filename'] ) && stripos( $f['filename'], 'fabric' ) !== false ) {
				$file = $f;
				break;
			}
		}
	}
	if ( ! $file ) {
		$file = $fabric_files[0];
	}

	if ( empty( $file['url'] ) ) {
		return new WP_Error( 'jc_mods_api', 'Keine Fabric-Downloads für diese Mod gefunden.' );
	}

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
 * Check all loaders for a mod
 */
function jc_mods_check_all_loaders( $slug, $mc_version ) {
	$loaders = array( 'fabric', 'forge', 'neoforge' );
	$results = array();

	foreach ( $loaders as $loader ) {
		$cache_key = 'jc_mod_v_' . md5( $slug . $mc_version . $loader );
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			$results[ $loader ] = $cached;
			continue;
		}

		$url = add_query_arg(
			array(
				'game_versions' => wp_json_encode( array( $mc_version ) ),
				'loaders'       => wp_json_encode( array( $loader ) ),
				'featured'      => 'true',
			),
			'https://api.modrinth.com/v2/project/' . urlencode( $slug ) . '/version'
		);

		$resp = wp_remote_get( $url, array( 'timeout' => 10 ) );
		if ( is_wp_error( $resp ) ) {
			$result = new WP_Error( 'jc_mods_api', 'API nicht erreichbar.' );
			$results[ $loader ] = $result;
			set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );
			continue;
		}

		$body = wp_remote_retrieve_body( $resp );
		$data = json_decode( $body, true );
		$versions = is_array( $data ) ? $data : array();

		if ( empty( $versions ) ) {
			$result = new WP_Error( 'jc_mods_api', 'Nicht verfügbar' );
			$results[ $loader ] = $result;
			set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );
			continue;
		}

		$version = $versions[0];
		$files = $version['files'] ?? array();
		if ( empty( $files ) ) {
			$result = new WP_Error( 'jc_mods_api', 'Keine Downloads gefunden.' );
			$results[ $loader ] = $result;
			set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );
			continue;
		}

		$file = null;
		foreach ( $files as $f ) {
			if ( isset( $f['primary'] ) && $f['primary'] ) {
				$file = $f;
				break;
			}
		}
		if ( ! $file ) {
			$file = $files[0];
		}

		if ( empty( $file['url'] ) ) {
			$result = new WP_Error( 'jc_mods_api', 'Keine Downloads gefunden.' );
			$results[ $loader ] = $result;
			set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );
			continue;
		}

		$result = array(
			'download_url' => $file['url'],
			'filename'     => $file['filename'] ?? '',
		);

		$results[ $loader ] = $result;
		set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );
	}

	return $results;
}

/**
 * Create ZIP file with all compatible mods for download
 */
function jc_mods_create_zip_download() {
	$mods = jc_mods_get_list();
	$mc_version = get_option( JC_MODS_MC_VERSION_OPTION, '1.21.1' );

	if ( empty( $mods ) ) {
		wp_die( 'Keine Mods zum Herunterladen.' );
	}

	if ( ! class_exists( 'ZipArchive' ) ) {
		wp_die( 'ZIP-Erweiterung nicht verfügbar auf diesem Server.' );
	}

	$temp_dir = wp_get_temp_dir() . 'jc_mods_' . time() . '/';
	if ( ! @mkdir( $temp_dir, 0755, true ) ) {
		wp_die( 'Fehler beim Erstellen des temporären Verzeichnisses.' );
	}

	$downloaded = 0;
	$failed = 0;

	foreach ( $mods as $mod ) {
		$loaders = jc_mods_check_all_loaders( $mod['slug'], $mc_version );
		$fabric_info = $loaders['fabric'];

		if ( is_wp_error( $fabric_info ) ) {
			$failed++;
			continue;
		}

		$url = $fabric_info['download_url'];
		$filename = $fabric_info['filename'] ?: sanitize_file_name( $mod['slug'] . '.jar' );
		$file_path = $temp_dir . $filename;

		$response = wp_safe_remote_get( $url, array( 'timeout' => 30 ) );

		if ( is_wp_error( $response ) ) {
			$failed++;
			continue;
		}

		$body = wp_remote_retrieve_body( $response );
		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code || empty( $body ) ) {
			$failed++;
			continue;
		}

		if ( ! file_put_contents( $file_path, $body ) ) {
			$failed++;
			continue;
		}

		$downloaded++;
	}

	if ( $downloaded === 0 ) {
		@array_map( 'unlink', glob( "$temp_dir*" ) );
		@rmdir( $temp_dir );
		wp_die( 'Keine kompatiblen Mods zum Herunterladen.' );
	}

	// Create ZIP
	$zip_path = wp_get_temp_dir() . 'justcreators-mods-' . sanitize_file_name( $mc_version ) . '-' . time() . '.zip';
	$zip = new ZipArchive();

	if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		@array_map( 'unlink', glob( "$temp_dir*" ) );
		@rmdir( $temp_dir );
		wp_die( 'ZIP-Datei konnte nicht erstellt werden.' );
	}

	$files = glob( $temp_dir . '*' );
	foreach ( $files as $file ) {
		if ( is_file( $file ) ) {
			$zip->addFile( $file, basename( $file ) );
		}
	}

	$zip->close();

	// Clean up temp files
	foreach ( $files as $file ) {
		if ( is_file( $file ) ) {
			@unlink( $file );
		}
	}
	@rmdir( $temp_dir );

	// Send file
	if ( ! is_file( $zip_path ) ) {
		wp_die( 'ZIP-Datei konnte nicht erstellt werden.' );
	}

	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: attachment; filename="justcreators-mods-' . sanitize_file_name( $mc_version ) . '.zip"' );
	header( 'Content-Length: ' . filesize( $zip_path ) );
	header( 'Pragma: no-cache' );
	header( 'Expires: 0' );

	readfile( $zip_path );
	@unlink( $zip_path );
}

function jc_mods_render_shortcode() {
	$mods       = jc_mods_get_list();
	$mc_version = get_option( JC_MODS_MC_VERSION_OPTION, '1.21.11' );

	if ( empty( $mods ) ) {
		return '<p>Keine Mods hinterlegt.</p>';
	}

	ob_start();
	?>
	<div class="jc-wrap">
		<section class="jc-hero">
			<div class="jc-hero-left">
				<div class="jc-kicker">Erlaubte Mods · MC <?php echo esc_html( $mc_version ); ?></div>
				<h1 class="jc-hero-title">JustCreators Mod Hub</h1>
				<p class="jc-hero-sub">Alle erlaubten Mods auf JustCreators. Filtere nach Fabric, Forge oder NeoForge.</p>
				<div class="jc-hero-actions">
					<div class="jc-pill">MC Version: <strong><?php echo esc_html( $mc_version ); ?></strong></div>
					<div class="jc-pill jc-pill-ghost">Fabric · Forge · NeoForge</div>
				</div>
			</div>
			<div class="jc-hero-right">
				<div class="jc-hero-badge">Mods</div>
				<div class="jc-hero-glow"></div>
			</div>
		</section>

		<section class="jc-toolbar">
			<div class="jc-search">
				<span class="jc-search-icon">🔍</span>
				<input id="jc-mods-search" type="search" placeholder="Mods suchen..." aria-label="Mods suchen" />
			</div>
			<div class="jc-loader-filters">
				<button class="jc-filter-btn active" data-loader="fabric">Fabric</button>
				<button class="jc-filter-btn" data-loader="forge">Forge</button>
				<button class="jc-filter-btn" data-loader="neoforge">NeoForge</button>
			</div>
		</section>
		<div class="jc-version-notice" style="background:rgba(255,193,7,0.1);border:1px solid rgba(255,193,7,0.3);border-radius:12px;padding:12px 16px;margin-bottom:16px;color:#ffc107;font-size:14px;">
			⚠️ Hinweis: Die meisten Mods sind für Minecraft 1.21.11 noch nicht verfügbar, da die Version erst kürzlich erschienen ist.
		</div>
		<div class="jc-toolbar-note">Fehlt etwas? Melde dich im Teilnehmer-Discord, wir prüfen neue Mods schnell.</div>

		<div id="jc-mods-grid" class="jc-mods-grid">
			<?php foreach ( $mods as $mod ) :
				$loaders = jc_mods_check_all_loaders( $mod['slug'], $mc_version );
				$fabric_available = ! is_wp_error( $loaders['fabric'] );
				$forge_available = ! is_wp_error( $loaders['forge'] );
				$neoforge_available = ! is_wp_error( $loaders['neoforge'] );
				$fabric_url = $fabric_available ? $loaders['fabric']['download_url'] : '';
				$forge_url = $forge_available ? $loaders['forge']['download_url'] : '';
				$neoforge_url = $neoforge_available ? $loaders['neoforge']['download_url'] : '';
			?>
			<div class="jc-mod-card" 
				data-search="<?php echo esc_attr( strtolower( $mod['title'] . ' ' . $mod['author'] ) ); ?>" 
				data-fabric="<?php echo $fabric_available ? '1' : '0'; ?>" 
				data-forge="<?php echo $forge_available ? '1' : '0'; ?>" 
				data-neoforge="<?php echo $neoforge_available ? '1' : '0'; ?>"
				data-fabric-url="<?php echo esc_attr( $fabric_url ); ?>"
				data-forge-url="<?php echo esc_attr( $forge_url ); ?>"
				data-neoforge-url="<?php echo esc_attr( $neoforge_url ); ?>">
				<div class="jc-mod-thumb">
					<img src="<?php echo esc_url( jc_mods_validate_icon_url( $mod['icon_url'] ) ); ?>" alt="<?php echo esc_attr( $mod['title'] ); ?>">
				</div>
				<div class="jc-mod-body">
					<div class="jc-mod-top">
						<div>
							<div class="jc-mod-title"><?php echo esc_html( $mod['title'] ); ?></div>
						</div>
						<span class="jc-tag">MC <?php echo esc_html( $mc_version ); ?></span>
					</div>
					<div class="jc-mod-bottom">
						<?php if ( $fabric_available ) : ?>
							<a class="jc-btn jc-download-btn" href="<?php echo esc_url( $fabric_url ); ?>" target="_blank" rel="noopener noreferrer">⬇️ Download</a>
						<?php else : ?>
							<button class="jc-btn jc-download-btn" disabled>⬇️ Download</button>
						<?php endif; ?>
						<a class="jc-link" href="<?php echo esc_url( $mod['project_url'] ); ?>" target="_blank" rel="noopener noreferrer">Mod auf Modrinth öffnen</a>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
		</div>

		<section class="jc-callout">
			<div>
				<h3>Neue Mod vorschlagen</h3>
				<p>Schreib im Teilnehmer-Discord, welche Mod du brauchst. Unser Team prüft und fügt sie bei Freigabe hinzu.</p>
			</div>
		</section>

		<section class="jc-callout jc-modpack">
			<div>
				<h3>JustCreators Modpack auf Modrinth</h3>
				<p>Dieses Modpack ist nicht verpflichtend für die Teilnahme bei JustCreators.</p>
			</div>
			<div>
				<a class="jc-btn" href="<?php echo esc_url( JC_MODS_MODPACK_URL ); ?>" target="_blank" rel="noopener noreferrer">Modpack ansehen</a>
			</div>
		</section>
	</div>

	<script>
	(function(){
		const search = document.getElementById('jc-mods-search');
		const cards  = document.querySelectorAll('.jc-mod-card');
		const filterBtns = document.querySelectorAll('.jc-filter-btn');
		let currentLoader = 'fabric';

		function updateCards() {
			const term = search ? search.value.toLowerCase() : '';
			cards.forEach(card => {
				const hay = card.dataset.search;
				const matchesSearch = !term || hay.includes(term);
				const available = card.dataset[currentLoader] === '1';
				
				if (!matchesSearch) {
					card.style.display = 'none';
				} else {
					card.style.display = 'grid';
					const btn = card.querySelector('.jc-download-btn');
					
					if (available) {
						card.classList.remove('jc-unavailable');
						if (btn) {
							const url = card.dataset[currentLoader + 'Url'];
							if (url) {
								if (btn.tagName === 'A') {
									btn.href = url;
									btn.disabled = false;
								} else if (btn.tagName === 'BUTTON') {
									// Konvertiere Button zu Link
									const newBtn = document.createElement('a');
									newBtn.className = btn.className;
									newBtn.href = url;
									newBtn.target = '_blank';
									newBtn.rel = 'noopener noreferrer';
									newBtn.innerHTML = btn.innerHTML;
									btn.parentNode.replaceChild(newBtn, btn);
								}
							}
						}
					} else {
						card.classList.add('jc-unavailable');
						if (btn) {
							if (btn.tagName === 'A') {
								// Konvertiere Link zu disabled Button
								const newBtn = document.createElement('button');
								newBtn.className = btn.className;
								newBtn.disabled = true;
								newBtn.innerHTML = btn.innerHTML;
								btn.parentNode.replaceChild(newBtn, btn);
							} else {
								btn.disabled = true;
							}
						}
					}
				}
			});
		}

		if (search) {
			search.addEventListener('input', updateCards);
		}

		filterBtns.forEach(btn => {
			btn.addEventListener('click', function() {
				filterBtns.forEach(b => b.classList.remove('active'));
				this.classList.add('active');
				currentLoader = this.dataset.loader;
				updateCards();
			});
		});

		updateCards();
	})();
	</script>
	<?php
	return ob_get_clean();
}

