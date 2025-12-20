<?php
/**
 * Plugin Name: JustCreators Archiv
 * Description: Archiv für JustCreators Seasons mit Maps, Bilder-Galerie und Videos.
 * Version: 1.0.0
 * Author: JustCreators Team
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Konstanten
define( 'JC_ARCHIV_TABLE', 'jc_archiv' );
define( 'JC_ARCHIV_VERSION', '1.0.0' );

// Hooks
register_activation_hook( __FILE__, 'jc_archiv_install' );
add_action( 'admin_menu', 'jc_archiv_register_menu' );
add_action( 'admin_init', 'jc_archiv_handle_actions' );
add_shortcode( 'jc_archiv', 'jc_archiv_render_shortcode' );
add_action( 'wp_enqueue_scripts', 'jc_archiv_enqueue_scripts' );

/**
 * 1. INSTALLATION & DB
 */
function jc_archiv_install() {
    global $wpdb;
    $table_name = $wpdb->prefix . JC_ARCHIV_TABLE;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        season_name varchar(255) NOT NULL,
        season_number int(11) NOT NULL,
        description longtext DEFAULT '',
        map_download_url varchar(500) DEFAULT '',
        map_file_name varchar(255) DEFAULT '',
        content_type varchar(50) DEFAULT 'gallery',
        content_data longtext DEFAULT NULL,
        gallery_images longtext DEFAULT NULL,
        videos longtext DEFAULT NULL,
        participants longtext DEFAULT NULL,
        sort_order int(11) DEFAULT 0,
        is_published tinyint(1) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY season_number (season_number),
        KEY is_published (is_published)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );

    update_option( 'jc_archiv_db_version', JC_ARCHIV_VERSION );
}

/**
 * 2. ADMIN MENU
 */
function jc_archiv_register_menu() {
    add_menu_page(
        'JustCreators Archiv',
        'Archiv',
        'manage_options',
        'jc-archiv',
        'jc_archiv_render_admin_page',
        'dashicons-archive',
        60
    );
}

/**
 * 3. ENQUEUE SCRIPTS & STYLES
 */
function jc_archiv_enqueue_scripts() {
	wp_enqueue_style( 'jc-archiv-lightbox', 'https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.3/css/lightbox.min.css' );
	wp_enqueue_script( 'jc-archiv-lightbox', 'https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.3/js/lightbox.min.js', array( 'jquery' ), '2.11.3', true );
	
	wp_register_style( 'jc-archiv-inline', false );
	wp_enqueue_style( 'jc-archiv-inline' );
	wp_add_inline_style( 'jc-archiv-inline', jc_archiv_get_css() );
}

function jc_archiv_get_css() {
	return <<<'EOT'
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
		.jc-season-card { background:var(--jc-panel); border:1px solid var(--jc-border); border-radius:18px; padding:28px; margin-bottom:24px; position:relative; overflow:hidden; box-shadow:0 20px 54px rgba(0,0,0,0.4); transition:transform .2s, border-color .2s, box-shadow .2s; }
		.jc-season-card:hover { transform:translateY(-3px); border-color:rgba(108,123,255,0.5); box-shadow:0 24px 68px rgba(0,0,0,0.5); }
		.jc-season-header { display:flex; align-items:center; gap:16px; margin-bottom:20px; flex-wrap:wrap; }
		.jc-season-badge { background:rgba(108,123,255,0.9); color:#050712; padding:6px 12px; border-radius:10px; font-weight:700; font-size:12px; letter-spacing:0.05em; }
		.jc-season-title { margin:0; font-size:28px; line-height:1.2; color:var(--jc-text); }
		.jc-season-description { color:var(--jc-muted); line-height:1.6; margin:16px 0 20px; }
		.jc-download-box { background:linear-gradient(135deg, rgba(108,123,255,0.15), rgba(86,216,255,0.1)); border:1px solid rgba(108,123,255,0.35); border-radius:14px; padding:20px; margin-bottom:24px; text-align:center; }
		.jc-download-box p { margin:0 0 12px; color:var(--jc-muted); font-size:14px; }
		.jc-btn { display:inline-flex; align-items:center; gap:8px; justify-content:center; padding:12px 20px; border-radius:12px; background:linear-gradient(135deg,var(--jc-accent),var(--jc-accent-2)); color:#050712; text-decoration:none; font-weight:800; letter-spacing:0.01em; box-shadow:0 14px 34px rgba(108,123,255,0.45); transition:transform .2s, box-shadow .2s; border:none; cursor:pointer; }
		.jc-btn:hover { transform:translateY(-2px); box-shadow:0 16px 40px rgba(86,216,255,0.5); color:#050712; }
		.jc-section-title { font-size:18px; font-weight:800; margin:24px 0 16px; color:var(--jc-text); padding-bottom:12px; border-bottom:2px solid var(--jc-accent); }
		.jc-gallery { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:12px; margin-bottom:24px; }
		.jc-gallery-item { position:relative; border-radius:12px; overflow:hidden; aspect-ratio:16/9; cursor:pointer; border:1px solid var(--jc-border); transition:all .2s; }
		.jc-gallery-item:hover { border-color:rgba(108,123,255,0.5); box-shadow:0 12px 32px rgba(108,123,255,0.25); }
		.jc-gallery-item img { width:100%; height:100%; object-fit:cover; display:block; }
		.jc-gallery-overlay { position:absolute; inset:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; opacity:0; transition:opacity .2s; }
		.jc-gallery-item:hover .jc-gallery-overlay { opacity:1; }
		.jc-gallery-icon { width:44px; height:44px; background:var(--jc-accent); border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:22px; box-shadow:0 8px 20px rgba(108,123,255,0.4); }
		.jc-videos { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:16px; }
		.jc-video-item { border-radius:12px; overflow:hidden; border:1px solid var(--jc-border); background:#000; aspect-ratio:16/9; transition:all .2s; }
		.jc-video-item:hover { border-color:rgba(108,123,255,0.5); box-shadow:0 12px 32px rgba(108,123,255,0.25); }
		.jc-video-item iframe { width:100%; height:100%; border:none; }
		.jc-participants { display:grid; grid-template-columns:repeat(auto-fill,minmax(140px,1fr)); gap:12px; margin-top:20px; }
		.jc-participant-card { background:linear-gradient(135deg, rgba(108,123,255,0.12), rgba(86,216,255,0.08)); border:1px solid var(--jc-border); border-radius:14px; padding:14px; text-align:center; transition:all .2s; display:flex; align-items:center; justify-content:center; min-height:80px; }
		.jc-participant-card:hover { border-color:rgba(108,123,255,0.5); box-shadow:0 12px 32px rgba(108,123,255,0.15); transform:translateY(-2px); }
		.jc-participant-name { font-weight:700; color:var(--jc-text); font-size:15px; word-break:break-word; }
		@media (max-width: 900px) {
			.jc-hero { grid-template-columns:1fr; }
			.jc-hero-right { justify-content:flex-start; min-height:120px; }
		}
		@media (max-width: 640px) {
			.jc-season-header { flex-direction:column; text-align:left; }
			.jc-season-title { font-size:22px; }
			.jc-gallery { grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); }
			.jc-videos { grid-template-columns:1fr; }
			.jc-participants { grid-template-columns:1fr; }
		}
	EOT;
}

/**
 * 4. ADMIN LOGIK
 */
function jc_archiv_handle_actions() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    global $wpdb;
    $table = $wpdb->prefix . JC_ARCHIV_TABLE;

    // Erstellen/Bearbeiten
    if ( isset( $_POST['jc_archiv_save'] ) ) {
        check_admin_referer( 'jc_archiv_save' );
        
        $id = isset( $_POST['archiv_id'] ) ? intval( $_POST['archiv_id'] ) : 0;
        $season_name = sanitize_text_field( $_POST['season_name'] ?? '' );
        $season_number = intval( $_POST['season_number'] ?? 1 );
        $description = wp_kses_post( $_POST['description'] ?? '' );
        $is_published = isset( $_POST['is_published'] ) ? 1 : 0;

        // Map Download URL
        $map_download_url = sanitize_url( $_POST['map_download_url'] ?? '' );
        $map_file_name = sanitize_file_name( $_POST['map_file_name'] ?? '' );

        // Bilder (JSON)
        $gallery_images = isset( $_POST['gallery_images'] ) ? wp_json_encode( array_filter( explode( "\n", $_POST['gallery_images'] ) ) ) : '[]';
        
        // Videos (JSON)
        $video_ids = isset( $_POST['video_ids'] ) ? wp_json_encode( array_filter( explode( "\n", $_POST['video_ids'] ) ) ) : '[]';

        // Teilnehmer (JSON)
        $participants = isset( $_POST['participants'] ) ? wp_json_encode( array_filter( array_map( 'trim', explode( "\n", $_POST['participants'] ) ) ) ) : '[]';

        if ( empty( $season_name ) ) {
            add_settings_error( 'jc_archiv', 'empty', 'Season Name erforderlich.', 'error' );
            return;
        }

        $data = array(
            'season_name' => $season_name,
            'season_number' => $season_number,
            'description' => $description,
            'map_download_url' => $map_download_url,
            'map_file_name' => $map_file_name,
            'gallery_images' => $gallery_images,
            'videos' => $video_ids,
            'participants' => $participants,
            'is_published' => $is_published,
            'updated_at' => current_time( 'mysql' )
        );

        if ( $id ) {
            $wpdb->update( $table, $data, array( 'id' => $id ) );
            add_settings_error( 'jc_archiv', 'updated', 'Season aktualisiert.', 'updated' );
        } else {
            $wpdb->insert( $table, array_merge( $data, array( 'sort_order' => 0, 'created_at' => current_time( 'mysql' ) ) ) );
            add_settings_error( 'jc_archiv', 'created', 'Season erstellt.', 'updated' );
        }
    }

    // Löschen
    if ( isset( $_GET['action'], $_GET['id'] ) && $_GET['action'] === 'delete' ) {
        $id = intval( $_GET['id'] );
        check_admin_referer( 'jc_archiv_delete_' . $id );
        $wpdb->delete( $table, array( 'id' => $id ) );
        add_settings_error( 'jc_archiv', 'deleted', 'Gelöscht.', 'updated' );
    }
}

/**
 * 5. ADMIN SEITE
 */
function jc_archiv_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Keine Berechtigung' );

    global $wpdb;
    $table = $wpdb->prefix . JC_ARCHIV_TABLE;

    $edit_id = isset( $_GET['edit'] ) ? intval( $_GET['edit'] ) : 0;
    $edit_row = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $edit_id ) ) : null;

    settings_errors( 'jc_archiv' );
    ?>
    <div class="wrap">
        <h1>📦 JustCreators Archiv</h1>
        
        <?php if ( ! $edit_id ) : ?>
            <button class="button button-primary" onclick="document.getElementById('form-section').style.display='block'">+ Neue Season</button>
        <?php else : ?>
            <a href="?page=jc-archiv" class="button">← Zurück</a>
        <?php endif; ?>

        <div id="form-section" style="<?php echo $edit_id ? '' : 'display:none;'; ?> max-width: 800px; margin-top: 20px; background: #fff; padding: 20px; border-radius: 8px;">
            <h2><?php echo $edit_id ? 'Season bearbeiten' : 'Neue Season'; ?></h2>
            
            <form method="post" action="">
                <?php wp_nonce_field( 'jc_archiv_save' ); ?>
                <?php if ( $edit_id ) : ?>
                    <input type="hidden" name="archiv_id" value="<?php echo $edit_id; ?>">
                <?php endif; ?>

                <table class="form-table">
                    <tr>
                        <th><label for="season_name">Season Name *</label></th>
                        <td>
                            <input type="text" id="season_name" name="season_name" 
                                   value="<?php echo esc_attr( $edit_row->season_name ?? '' ); ?>" 
                                   class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="season_number">Season Nummer</label></th>
                        <td>
                            <input type="number" id="season_number" name="season_number" 
                                   value="<?php echo intval( $edit_row->season_number ?? 1 ); ?>" 
                                   class="small-text">
                        </td>
                    </tr>
                    <tr>
                        <th><label for="description">Beschreibung</label></th>
                        <td>
                            <?php wp_editor( $edit_row->description ?? '', 'description', array( 'media_buttons' => true, 'textarea_rows' => 6 ) ); ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="map_file_name">Map Name</label></th>
                        <td>
                            <input type="text" id="map_file_name" name="map_file_name" 
                                   value="<?php echo esc_attr( $edit_row->map_file_name ?? '' ); ?>" 
                                   class="regular-text" placeholder="z.B. Season1_Map.zip">
                            <p class="description">Dateiname der Map</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="map_download_url">Map Download Link</label></th>
                        <td>
                            <input type="url" id="map_download_url" name="map_download_url" 
                                   value="<?php echo esc_attr( $edit_row->map_download_url ?? '' ); ?>" 
                                   class="regular-text" placeholder="https://...">
                            <p class="description">Direkter Download Link zur Map (z.B. Google Drive oder Cloud Storage)</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="gallery_images">Bilder URLs (eine pro Zeile)</label></th>
                        <td>
                            <textarea id="gallery_images" name="gallery_images" rows="8" class="large-text" placeholder="https://example.com/bild1.jpg
https://example.com/bild2.jpg"><?php 
                                if ( $edit_row ) {
                                    $images = json_decode( $edit_row->gallery_images, true ) ?: [];
                                    echo implode( "\n", $images );
                                }
                            ?></textarea>
                            <p class="description">URLs zu deinen Screenshot/Galerie-Bildern</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="video_ids">YouTube Video IDs (eine pro Zeile)</label></th>
                        <td>
                            <textarea id="video_ids" name="video_ids" rows="6" class="large-text" placeholder="dQw4w9WgXcQ
jNQXAC9IVRw"><?php 
                                if ( $edit_row ) {
                                    $videos = json_decode( $edit_row->videos, true ) ?: [];
                                    echo implode( "\n", $videos );
                                }
                            ?></textarea>
                            <p class="description">Nur die Video ID, z.B. aus https://youtube.com/watch?v=<strong>dQw4w9WgXcQ</strong></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="participants">Teilnehmer (eine pro Zeile)</label></th>
                        <td>
                            <textarea id="participants" name="participants" rows="8" class="large-text" placeholder="Spielername 1
Spielername 2
Spielername 3"><?php 
                                if ( $edit_row ) {
                                    $participants = json_decode( $edit_row->participants, true ) ?: [];
                                    echo implode( "\n", $participants );
                                }
                            ?></textarea>
                            <p class="description">Namen der Teilnehmer als einfache Liste</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="is_published">Veröffentlicht</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="is_published" name="is_published" 
                                       <?php echo ( $edit_row->is_published ?? 0 ) ? 'checked' : ''; ?>>
                                Diese Season auf der Website anzeigen
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" name="jc_archiv_save" class="button button-primary">💾 Speichern</button>
                    <?php if ( $edit_id ) : ?>
                        <a href="?page=jc-archiv" class="button">Abbrechen</a>
                    <?php endif; ?>
                </p>
            </form>
        </div>

        <h2 style="margin-top: 40px;">Seasons</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Season</th>
                    <th>Name</th>
                    <th>Bilder</th>
                    <th>Videos</th>
                    <th>Teilnehmer</th>
                    <th>Map</th>
                    <th>Status</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $rows = $wpdb->get_results( "SELECT * FROM $table ORDER BY season_number DESC" );
                if ( empty( $rows ) ) echo '<tr><td colspan="8">Keine Seasons.</td></tr>';
                foreach ( $rows as $r ) :
                    $images = json_decode( $r->gallery_images, true ) ?: [];
                    $videos = json_decode( $r->videos, true ) ?: [];
                    $participants = json_decode( $r->participants, true ) ?: [];
                    $status = $r->is_published ? '✅ Veröffentlicht' : '🔒 Entwurf';
                ?>
                <tr>
                    <td><strong>#<?php echo $r->season_number; ?></strong></td>
                    <td><?php echo esc_html( $r->season_name ); ?></td>
                    <td><?php echo count( $images ); ?></td>
                    <td><?php echo count( $videos ); ?></td>
                    <td><?php echo count( $participants ); ?></td>
                    <td><?php echo !empty( $r->map_download_url ) ? '✅' : '❌'; ?></td>
                    <td><?php echo $status; ?></td>
                    <td>
                        <a href="?page=jc-archiv&edit=<?php echo $r->id; ?>" class="button button-small">Edit</a>
                        <a href="<?php echo wp_nonce_url( "?page=jc-archiv&action=delete&id=$r->id", 'jc_archiv_delete_' . $r->id ); ?>" 
                           class="button button-small" style="color:red" onclick="return confirm('Löschen?')">X</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="margin-top: 20px;"><strong>Shortcode:</strong> <code>[jc_archiv]</code> oder <code>[jc_archiv season="2"]</code></p>
    </div>
    <?php
}

/**
 * 6. FRONTEND SHORTCODE
 */
function jc_archiv_render_shortcode( $atts ) {
    global $wpdb;
    $table = $wpdb->prefix . JC_ARCHIV_TABLE;
    
    $atts = shortcode_atts( array(
        'season' => null,
        'limit' => 999
    ), $atts );

    $sql = "SELECT * FROM $table WHERE is_published = 1";
    if ( $atts['season'] ) {
        $sql .= $wpdb->prepare( " AND season_number = %d", intval( $atts['season'] ) );
    }
    $sql .= " ORDER BY season_number DESC LIMIT " . intval( $atts['limit'] );

    $seasons = $wpdb->get_results( $sql );
    if ( empty( $seasons ) ) return '<div style="text-align:center;padding:40px;color:#9eb3d5;">Noch keine Archive verfügbar.</div>';

	ob_start();
	?>
	<div class="jc-wrap">
		<?php foreach ( $seasons as $season ) :
			$images = json_decode( $season->gallery_images, true ) ?: [];
			$videos = json_decode( $season->videos, true ) ?: [];
		?>
		<div class="jc-season-card">
			<div class="jc-season-header">
				<h2 class="jc-season-title"><?php echo esc_html( $season->season_name ); ?></h2>
			</div>

			<?php if ( ! empty( $season->description ) ) : ?>
				<div class="jc-season-description">
					<?php echo wp_kses_post( $season->description ); ?>
				</div>
			<?php endif; ?>

			<!-- MAP DOWNLOAD -->
			<?php if ( ! empty( $season->map_download_url ) ) : ?>
				<div class="jc-download-box">
					<p>📦 Map Download</p>
					<a href="<?php echo esc_url( $season->map_download_url ); ?>" class="jc-btn" download>
						<span>⬇️</span>
						<span><?php echo esc_html( $season->map_file_name ?: 'Map herunterladen' ); ?></span>
					</a>
				</div>
			<?php endif; ?>

			<!-- GALERIE -->
			<?php if ( ! empty( $images ) ) : ?>
				<h3 class="jc-section-title">🖼️ Bilder-Galerie</h3>
				<div class="jc-gallery">
					<?php foreach ( $images as $img ) :
						$img = trim( $img );
						if ( empty( $img ) ) continue;
					?>
					<a href="<?php echo esc_url( $img ); ?>" class="jc-gallery-item" data-lightbox="season-<?php echo $season->id; ?>">
						<img src="<?php echo esc_url( $img ); ?>" alt="Galerie Bild" loading="lazy">
						<div class="jc-gallery-overlay">
							<div class="jc-gallery-icon">🔍</div>
						</div>
					</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<!-- VIDEOS -->
			<?php if ( ! empty( $videos ) ) : ?>
				<h3 class="jc-section-title">🎬 Video-Highlights</h3>
				<div class="jc-videos">
					<?php foreach ( $videos as $video_id ) :
						$video_id = trim( $video_id );
						if ( empty( $video_id ) ) continue;
					?>
					<div class="jc-video-item">
						<iframe src="https://www.youtube.com/embed/<?php echo esc_attr( $video_id ); ?>" 
						        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
						        allowfullscreen loading="lazy"></iframe>
					</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<!-- TEILNEHMER -->
			<?php 
				$participants = json_decode( $season->participants, true ) ?: [];
				if ( ! empty( $participants ) ) :
			?>
				<h3 class="jc-section-title">👥 Teilnehmer</h3>
				<div class="jc-participants">
					<?php foreach ( $participants as $name ) :
						if ( empty( trim( $name ) ) ) continue;
					?>
					<div class="jc-participant-card">
						<div class="jc-participant-name"><?php echo esc_html( $name ); ?></div>
					</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php endforeach; ?>
	</div>

    <?php
    return ob_get_clean();
}
