<?php
/**
 * Plugin Name: JustCreators Teilnehmer
 * Description: Teilnehmer-Verwaltung mit Social Media Integration im JustCreators Design.
 * Version: 1.0.2
 * Author: JustCreators Team
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Konstanten
define( 'JC_TEILNEHMER_TABLE', 'jc_teilnehmer' );
define( 'JC_TEILNEHMER_VERSION', '1.0.2' );

// Hooks
register_activation_hook( __FILE__, 'jc_teilnehmer_install' );
add_action( 'admin_menu', 'jc_teilnehmer_register_menu' );
add_action( 'admin_init', 'jc_teilnehmer_handle_actions' );
add_shortcode( 'jc_teilnehmer', 'jc_teilnehmer_render_shortcode' );

/**
 * 1. INSTALLATION & DB
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
 * 2. ADMIN MENU
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
    
    add_submenu_page(
        'jc-teilnehmer',
        'API Einstellungen',
        'API Einstellungen',
        'manage_options',
        'jc-teilnehmer-api',
        'jc_teilnehmer_render_api_settings_page'
    );
}

/**
 * 3. ADMIN LOGIK (Speichern, Löschen, Import)
 */
function jc_teilnehmer_handle_actions() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    global $wpdb;
    $table = $wpdb->prefix . JC_TEILNEHMER_TABLE;

    // API Einstellungen speichern
    if ( isset( $_POST['jc_teilnehmer_save_api'] ) ) {
        check_admin_referer( 'jc_teilnehmer_api_settings' );
        update_option( 'jc_twitch_client_id', sanitize_text_field( $_POST['twitch_client_id'] ?? '' ) );
        update_option( 'jc_twitch_client_secret', sanitize_text_field( $_POST['twitch_client_secret'] ?? '' ) );
        
        // Token Cache löschen
        delete_transient( 'jc_twitch_access_token' );
        
        add_settings_error( 'jc_teilnehmer', 'api_saved', 'API Einstellungen gespeichert.', 'updated' );
    }

    // Hinzufügen
    if ( isset( $_POST['jc_teilnehmer_add'] ) ) {
        check_admin_referer( 'jc_teilnehmer_add' );
        $display_name = sanitize_text_field( $_POST['display_name'] ?? '' );
        
        if ( empty( $display_name ) ) {
            add_settings_error( 'jc_teilnehmer', 'empty', 'Name fehlt.', 'error' );
        } else {
            $channels = jc_teilnehmer_parse_channels( $_POST['social_channels'] ?? '' );
            $wpdb->insert( $table, array(
                'display_name' => $display_name,
                'title' => sanitize_text_field( $_POST['title'] ?? '' ),
                'social_channels' => wp_json_encode( $channels ),
                'profile_image_url' => jc_teilnehmer_get_profile_image( $channels ),
                'is_active' => 1,
                'sort_order' => 0
            ));
            add_settings_error( 'jc_teilnehmer', 'added', 'Teilnehmer hinzugefügt.', 'updated' );
        }
    }

    // Bearbeiten
    if ( isset( $_POST['jc_teilnehmer_edit'] ) ) {
        $id = intval( $_POST['teilnehmer_id'] );
        check_admin_referer( 'jc_teilnehmer_edit_' . $id );
        
        $channels = jc_teilnehmer_parse_channels( $_POST['social_channels'] ?? '' );
        $wpdb->update( $table, array(
            'display_name' => sanitize_text_field( $_POST['display_name'] ),
            'title' => sanitize_text_field( $_POST['title'] ),
            'social_channels' => wp_json_encode( $channels ),
            'profile_image_url' => jc_teilnehmer_get_profile_image( $channels ), // Bild aktualisieren
            'is_active' => isset( $_POST['is_active'] ) ? 1 : 0
        ), array( 'id' => $id ) );
        
        add_settings_error( 'jc_teilnehmer', 'updated', 'Gespeichert.', 'updated' );
    }

    // Löschen
    if ( isset( $_GET['action'], $_GET['id'] ) && $_GET['action'] === 'delete' ) {
        $id = intval( $_GET['id'] );
        check_admin_referer( 'jc_teilnehmer_delete_' . $id );
        $wpdb->delete( $table, array( 'id' => $id ) );
        add_settings_error( 'jc_teilnehmer', 'deleted', 'Gelöscht.', 'updated' );
    }

    // Sortierung
    if ( isset( $_POST['jc_teilnehmer_update_order'], $_POST['order'] ) ) {
        check_admin_referer( 'jc_teilnehmer_order' );
        foreach ( $_POST['order'] as $id => $order ) {
            $wpdb->update( $table, array( 'sort_order' => intval( $order ) ), array( 'id' => intval( $id ) ) );
        }
        add_settings_error( 'jc_teilnehmer', 'order', 'Reihenfolge gespeichert.', 'updated' );
    }

    // Import
    if ( isset( $_POST['jc_teilnehmer_import_from_db'] ) ) {
        check_admin_referer( 'jc_teilnehmer_import_db' );
        $res = jc_teilnehmer_import_from_applications();
        if ( is_wp_error( $res ) ) {
            add_settings_error( 'jc_teilnehmer', 'imp_err', $res->get_error_message(), 'error' );
        } else {
            add_settings_error( 'jc_teilnehmer', 'imp_ok', $res['message'], 'updated' );
        }
    }
}

/**
 * 4. HELPER FUNCTIONS
 */
function jc_teilnehmer_parse_channels( $input ) {
    if ( empty( $input ) ) return array();
    // Wenn schon JSON
    $decoded = json_decode( html_entity_decode( stripslashes( $input ) ), true );
    if ( is_array( $decoded ) ) return $decoded;

    // Sonst Zeile für Zeile
    $lines = explode( "\n", $input );
    $channels = array();
    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( empty( $line ) ) continue;
        
        $platform = 'unknown';
        if ( stripos( $line, 'youtube' ) !== false || stripos( $line, 'youtu.be' ) !== false ) $platform = 'youtube';
        elseif ( stripos( $line, 'twitch' ) !== false ) $platform = 'twitch';
        elseif ( stripos( $line, 'tiktok' ) !== false ) $platform = 'tiktok';
        elseif ( stripos( $line, 'instagram' ) !== false ) $platform = 'instagram';
        elseif ( stripos( $line, 'twitter' ) !== false || stripos( $line, 'x.com' ) !== false ) $platform = 'twitter';

        $channels[] = array( 'platform' => $platform, 'url' => $line );
    }
    return $channels;
}

function jc_teilnehmer_get_profile_image( $channels ) {
    if ( empty( $channels ) ) return 'https://via.placeholder.com/300x300/1e2740/6c7bff?text=JC';
    
    // Priorität: YT > Twitch > Insta > TikTok
    foreach ( array('youtube', 'twitch', 'instagram', 'tiktok') as $p ) {
        foreach ( $channels as $ch ) {
            if ( ($ch['platform'] ?? '') === $p && !empty( $ch['url'] ) ) {
                $img = jc_teilnehmer_fetch_image( $p, $ch['url'] );
                if ( $img ) return $img;
            }
        }
    }
    return 'https://via.placeholder.com/300x300/1e2740/6c7bff?text=JC';
}

/**
 * Twitch API Token abrufen
 */
function jc_teilnehmer_get_twitch_token() {
    $token = get_transient( 'jc_twitch_access_token' );
    if ( $token ) return $token;

    // Twitch Client ID und Secret (sollten in wp-config.php oder Options gespeichert werden)
    $client_id = defined( 'JC_TWITCH_CLIENT_ID' ) ? JC_TWITCH_CLIENT_ID : get_option( 'jc_twitch_client_id', '' );
    $client_secret = defined( 'JC_TWITCH_CLIENT_SECRET' ) ? JC_TWITCH_CLIENT_SECRET : get_option( 'jc_twitch_client_secret', '' );

    if ( empty( $client_id ) || empty( $client_secret ) ) {
        return false;
    }

    $response = wp_remote_post( 'https://id.twitch.tv/oauth2/token', array(
        'body' => array(
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'grant_type' => 'client_credentials'
        )
    ));

    if ( is_wp_error( $response ) ) return false;

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( isset( $body['access_token'] ) ) {
        $token = $body['access_token'];
        $expires = isset( $body['expires_in'] ) ? intval( $body['expires_in'] ) - 300 : 3600; // 5 Min Puffer
        set_transient( 'jc_twitch_access_token', $token, $expires );
        return $token;
    }

    return false;
}

/**
 * Twitch Benutzerdaten über API abrufen
 */
function jc_teilnehmer_fetch_twitch_user( $username ) {
    $token = jc_teilnehmer_get_twitch_token();
    if ( ! $token ) return false;

    $client_id = defined( 'JC_TWITCH_CLIENT_ID' ) ? JC_TWITCH_CLIENT_ID : get_option( 'jc_twitch_client_id', '' );
    if ( empty( $client_id ) ) return false;

    $response = wp_remote_get( 'https://api.twitch.tv/helix/users?login=' . urlencode( strtolower( $username ) ), array(
        'headers' => array(
            'Client-ID' => $client_id,
            'Authorization' => 'Bearer ' . $token
        )
    ));

    if ( is_wp_error( $response ) ) return false;

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( isset( $body['data'][0] ) ) {
        return $body['data'][0];
    }

    return false;
}

function jc_teilnehmer_fetch_image( $platform, $url ) {
    $key = 'jc_img_' . md5( $platform . $url );
    if ( $c = get_transient( $key ) ) return $c;

    $img = '';
    if ( $platform === 'youtube' ) {
        // oEmbed Versuch
        $res = wp_remote_get( 'https://www.youtube.com/oembed?url=' . urlencode( $url ) . '&format=json' );
        if ( !is_wp_error( $res ) ) {
            $data = json_decode( wp_remote_retrieve_body( $res ), true );
            $img = $data['thumbnail_url'] ?? '';
        }
    } elseif ( $platform === 'twitch' ) {
        if ( preg_match( '/twitch\.tv\/([\w-]+)/i', $url, $m ) ) {
            $username = strtolower( $m[1] );
            $user_data = jc_teilnehmer_fetch_twitch_user( $username );
            if ( $user_data && isset( $user_data['profile_image_url'] ) ) {
                $img = $user_data['profile_image_url'];
            }
        }
    }

    if ( $img ) set_transient( $key, $img, DAY_IN_SECONDS );
    return $img;
}

function jc_teilnehmer_fetch_channel_meta( $platform, $url ) {
    $key = 'jc_meta_' . md5( $platform . $url );
    if ( $cached = get_transient( $key ) ) return $cached;

    $meta = array( 'title' => '', 'image' => '' );

    if ( $platform === 'youtube' ) {
        // Versuche oEmbed
        $res = wp_remote_get( 'https://www.youtube.com/oembed?url=' . urlencode( $url ) . '&format=json', array( 'timeout' => 10 ) );
        if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
            $data = json_decode( wp_remote_retrieve_body( $res ), true );
            if ( is_array( $data ) ) {
                $meta['title'] = $data['title'] ?? '';
                $meta['image'] = $data['thumbnail_url'] ?? '';
            }
        }
    } elseif ( $platform === 'twitch' ) {
        if ( preg_match( '/twitch\.tv\/([\w-]+)/i', $url, $m ) ) {
            $username = strtolower( $m[1] );
            $user_data = jc_teilnehmer_fetch_twitch_user( $username );
            if ( $user_data ) {
                $meta['title'] = $user_data['display_name'] ?? $username;
                $meta['image'] = $user_data['profile_image_url'] ?? '';
            } else {
                $meta['title'] = $username;
            }
        }
    } elseif ( $platform === 'instagram' ) {
        if ( preg_match( '#instagram\.com/([^/?]+)/?#i', $url, $m ) ) {
            $meta['title'] = $m[1];
        }
    } elseif ( $platform === 'tiktok' ) {
        if ( preg_match( '#tiktok\.com/@([^/?]+)#i', $url, $m ) ) {
            $meta['title'] = $m[1];
        }
    }

    set_transient( $key, $meta, DAY_IN_SECONDS );
    return $meta;
}

function jc_teilnehmer_resolve_social_meta( $channels, $fallback_name = '' ) {
    $placeholder = 'https://via.placeholder.com/300x300/1e2740/6c7bff?text=JC';
    $meta = array(
        'title' => $fallback_name,
        'image' => $placeholder,
    );

    if ( empty( $channels ) || ! is_array( $channels ) ) {
        return $meta;
    }

    $priority = array( 'youtube', 'twitch', 'instagram', 'tiktok' );
    foreach ( $priority as $platform ) {
        foreach ( $channels as $ch ) {
            if ( ( $ch['platform'] ?? '' ) !== $platform || empty( $ch['url'] ) ) continue;
            $data = jc_teilnehmer_fetch_channel_meta( $platform, $ch['url'] );
            if ( ! empty( $data['title'] ) ) {
                $meta['title'] = $data['title'];
            }
            if ( ! empty( $data['image'] ) ) {
                $meta['image'] = $data['image'];
            }
            return $meta;
        }
    }

    return $meta;
}

function jc_teilnehmer_import_from_applications() {
    global $wpdb;
    $target = $wpdb->prefix . JC_TEILNEHMER_TABLE;
    $source = $wpdb->prefix . 'jc_discord_applications';

    if ( $wpdb->get_var( "SHOW TABLES LIKE '$source'" ) !== $source ) 
        return new WP_Error( 'no_table', 'Bewerbungstabelle nicht gefunden.' );

    $apps = $wpdb->get_results( "SELECT * FROM $source WHERE status = 'accepted'" );
    $count = 0;

    foreach ( $apps as $app ) {
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $target WHERE display_name = %s", $app->applicant_name ) );
        if ( $exists ) continue;

        $ch = json_decode( $app->social_channels, true );
        if ( !is_array( $ch ) ) $ch = array();
        
        $wpdb->insert( $target, array(
            'display_name' => $app->applicant_name,
            'title' => 'Creator',
            'social_channels' => wp_json_encode( array_values( array_filter( $ch ) ) ),
            'profile_image_url' => jc_teilnehmer_get_profile_image( $ch ),
            'is_active' => 1
        ));
        $count++;
    }
    return array( 'message' => "$count importiert." );
}

/**
 * 5. ADMIN PAGE OUTPUT
 */
function jc_teilnehmer_render_admin_page() {
    global $wpdb;
    settings_errors( 'jc_teilnehmer' );
    $table = $wpdb->prefix . JC_TEILNEHMER_TABLE;

    // Edit Mode
    if ( isset( $_GET['edit'] ) ) {
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $_GET['edit'] ) );
        if ( $row ) {
            $chs = json_decode( $row->social_channels, true );
            $txt = '';
            if ( is_array( $chs ) ) foreach( $chs as $c ) $txt .= $c['url'] . "\n";
            ?>
            <div class="wrap">
                <h1>Bearbeiten: <?php echo esc_html( $row->display_name ); ?></h1>
                <form method="post">
                    <?php wp_nonce_field( 'jc_teilnehmer_edit_' . $row->id ); ?>
                    <input type="hidden" name="teilnehmer_id" value="<?php echo $row->id; ?>">
                    <table class="form-table">
                        <tr><th>Name</th><td><input name="display_name" value="<?php echo esc_attr( $row->display_name ); ?>" class="regular-text"></td></tr>
                        <tr><th>Titel</th><td><input name="title" value="<?php echo esc_attr( $row->title ); ?>" class="regular-text"></td></tr>
                        <tr><th>Links</th><td><textarea name="social_channels" rows="5" class="large-text code"><?php echo esc_textarea( $txt ); ?></textarea></td></tr>
                        <tr><th>Aktiv</th><td><input type="checkbox" name="is_active" value="1" <?php checked( $row->is_active, 1 ); ?>></td></tr>
                    </table>
                    <p class="submit"><button type="submit" name="jc_teilnehmer_edit" class="button button-primary">Speichern</button> <a href="?page=jc-teilnehmer" class="button">Zurück</a></p>
                </form>
            </div>
            <?php
            return;
        }
    }

    // List Mode
    ?>
    <div class="wrap">
        <h1>JustCreators Teilnehmer</h1>
        
        <div class="card" style="max-width:800px; margin-bottom:20px;">
            <h3>Neu hinzufügen</h3>
            <form method="post">
                <?php wp_nonce_field( 'jc_teilnehmer_add' ); ?>
                <p><input name="display_name" placeholder="Name *" required class="regular-text"> <input name="title" placeholder="Titel" class="regular-text"></p>
                <p><textarea name="social_channels" rows="3" class="large-text code" placeholder="Links (YouTube, Twitch...)"></textarea></p>
                <button type="submit" name="jc_teilnehmer_add" class="button button-primary">Hinzufügen</button>
            </form>
        </div>

        <form method="post" style="margin-bottom:20px;">
            <?php wp_nonce_field( 'jc_teilnehmer_import_db' ); ?>
            <button type="submit" name="jc_teilnehmer_import_from_db" class="button">Aus Bewerbungs-DB importieren</button>
        </form>

        <form method="post">
            <?php wp_nonce_field( 'jc_teilnehmer_order' ); ?>
            <table class="wp-list-table widefat fixed striped">
                <thead><tr><th width="50">Bild</th><th>Name</th><th>Titel</th><th>Kanäle</th><th width="60">Sort</th><th width="120">Aktionen</th></tr></thead>
                <tbody>
                    <?php 
                    $rows = $wpdb->get_results( "SELECT * FROM $table ORDER BY sort_order ASC, display_name ASC" );
                    if ( empty( $rows ) ) echo '<tr><td colspan="6">Keine Teilnehmer.</td></tr>';
                    foreach ( $rows as $r ) : 
                        $cc = count( json_decode( $r->social_channels, true ) ?: [] );
                    ?>
                    <tr style="<?php echo $r->is_active ? '' : 'opacity:0.6'; ?>">
                        <td><img src="<?php echo esc_url( $r->profile_image_url ); ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;"></td>
                        <td><strong><?php echo esc_html( $r->display_name ); ?></strong></td>
                        <td><?php echo esc_html( $r->title ); ?></td>
                        <td><?php echo $cc; ?></td>
                        <td><input type="number" name="order[<?php echo $r->id; ?>]" value="<?php echo $r->sort_order; ?>" style="width:50px"></td>
                        <td>
                            <a href="?page=jc-teilnehmer&edit=<?php echo $r->id; ?>" class="button button-small">Edit</a>
                            <a href="<?php echo wp_nonce_url( "?page=jc-teilnehmer&action=delete&id=$r->id", 'jc_teilnehmer_delete_' . $r->id ); ?>" class="button button-small" style="color:red" onclick="return confirm('Löschen?')">X</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p><button type="submit" name="jc_teilnehmer_update_order" class="button button-secondary">Reihenfolge speichern</button></p>
        </form>
        <p>Shortcode: <code>[jc_teilnehmer]</code></p>
    </div>
    <?php
}

/**
 * 6. FRONTEND SHORTCODE (FIXED)
 */
function jc_teilnehmer_render_shortcode( $atts ) {
    global $wpdb;
    $table = $wpdb->prefix . JC_TEILNEHMER_TABLE;
    $atts = shortcode_atts( array( 'limit' => 0, 'show_inactive' => false ), $atts );

    $sql = "SELECT * FROM $table WHERE 1=1";
    if ( ! $atts['show_inactive'] ) $sql .= " AND is_active = 1";
    $sql .= " ORDER BY sort_order ASC, display_name ASC";
    if ( $atts['limit'] > 0 ) $sql .= " LIMIT " . intval( $atts['limit'] );

    $rows = $wpdb->get_results( $sql );
    if ( empty( $rows ) ) return '<div style="text-align:center;padding:20px">Noch keine Teilnehmer.</div>';

    // OUTPUT BUFFERING START
    ob_start();
    
    // CSS MINIFIED (Wichtig: Alles in einer Zeile!)
    echo '<style>:root{--jc-bg:#050712;--jc-panel:#0b0f1d;--jc-border:#1e2740;--jc-text:#e9ecf7;--jc-muted:#9eb3d5;--jc-accent:#6c7bff;--jc-accent-2:#56d8ff}.jc-wrap{max-width:1220px;margin:26px auto;padding:0 18px 40px;color:var(--jc-text);font-family:"Space Grotesk",system-ui,sans-serif}.jc-hero{display:grid;grid-template-columns:2fr 1fr;gap:22px;background:radial-gradient(120% 140% at 10% 10%,rgba(108,123,255,.12),transparent 50%),var(--jc-panel);border:1px solid var(--jc-border);border-radius:20px;padding:28px;position:relative;overflow:hidden;box-shadow:0 22px 60px rgba(0,0,0,.45)}.jc-hero-left{position:relative;z-index:1}.jc-kicker{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;background:rgba(108,123,255,.15);border:1px solid rgba(108,123,255,.35);border-radius:99px;font-size:13px;text-transform:uppercase}.jc-hero-title{margin:10px 0 6px;font-size:32px;color:var(--jc-text)}.jc-hero-sub{color:var(--jc-muted);line-height:1.6;max-width:680px}.jc-hero-right{display:flex;align-items:center;justify-content:flex-end}.jc-hero-badge{padding:12px 18px;border-radius:14px;background:linear-gradient(135deg,var(--jc-accent),var(--jc-accent-2));color:#040510;font-weight:800}.jc-toolbar{margin:18px 0 8px;display:flex;gap:12px}.jc-search{flex:1;position:relative}.jc-search input{width:100%;padding:12px 44px 12px 40px;background:var(--jc-panel);border:1px solid var(--jc-border);border-radius:12px;color:var(--jc-text)}.jc-search-icon{position:absolute;left:14px;top:50%;transform:translateY(-50%);opacity:.7}.jc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:20px;margin-top:20px}.jc-card{background:var(--jc-panel);border:1px solid var(--jc-border);border-radius:18px;padding:22px;transition:transform .2s}.jc-card:hover{transform:translateY(-5px);border-color:var(--jc-accent)}.jc-head{display:flex;gap:16px;align-items:center;margin-bottom:18px}.jc-av{width:70px;height:70px;border-radius:50%;object-fit:cover;border:2px solid var(--jc-border)}.jc-name{margin:0;font-size:18px;font-weight:700;color:var(--jc-text)}.jc-role{margin:4px 0 0;font-size:13px;color:var(--jc-muted)}.jc-socials{display:flex;flex-wrap:wrap;gap:8px}.jc-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:8px;background:rgba(255,255,255,.05);color:var(--jc-text);text-decoration:none;font-size:12px;font-weight:600;border:1px solid transparent;transition:all .2s}.jc-btn:hover{background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.2)}.jc-btn svg{width:16px;height:16px}@media(max-width:700px){.jc-hero{grid-template-columns:1fr}}</style>';
    ?>

    <div class="jc-wrap">
        <div class="jc-hero">
            <div class="jc-hero-left">
                <div class="jc-kicker"><span>👥</span><span>Season 2</span></div>
                <h1 class="jc-hero-title">Unsere Teilnehmer</h1>
                <p class="jc-hero-sub">Entdecke die Kanäle der Creator.</p>
            </div>
            <div class="jc-hero-right">
                <div class="jc-hero-badge"><?php echo count($rows); ?> CREATORS</div>
            </div>
        </div>

        <div class="jc-toolbar">
            <div class="jc-search">
                <svg class="jc-search-icon" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="9" r="7"/><path d="m15 15 3 3"/></svg>
                <input type="text" id="jc-search-input" placeholder="Suchen...">
            </div>
        </div>

        <div class="jc-grid" id="jc-grid">
            <?php foreach ( $rows as $t ) : 
                $chs = json_decode( $t->social_channels, true ) ?: [];
                $meta = jc_teilnehmer_resolve_social_meta( $chs, $t->display_name );
                $card_name = $meta['title'] ?: $t->display_name;
                $card_image = $meta['image'];
                $search = strtolower( $card_name );
            ?>
            <div class="jc-card" data-search="<?php echo esc_attr( $search ); ?>">
                <div class="jc-head">
                    <img src="<?php echo esc_url( $card_image ); ?>" class="jc-av" alt="">
                    <div>
                        <h3 class="jc-name"><?php echo esc_html( $card_name ); ?></h3>
                    </div>
                </div>
                <div class="jc-socials">
                    <?php foreach ( $chs as $c ) : 
                        $plat = $c['platform'] ?? 'web';
                        $url = $c['url'] ?? '#';
                        $label = ucfirst($plat);
                        // Icons
                        $svg = '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z"/></svg>';
                        if($plat=='youtube') $svg='<svg viewBox="0 0 24 24" fill="#ff6b6b"><path d="M23.5 6.2c-.3-1.1-1.2-2-2.3-2.3C19.2 3.5 12 3.5 12 3.5s-7.2 0-9.2.4c-1.1.3-2 1.2-2.3 2.3C0 8.2 0 12 0 12s0 3.8.5 5.8c.3 1.1 1.2 2 2.3 2.3 2 .4 9.2.4 9.2.4s7.2 0 9.2-.4c1.1-.3 2-1.2 2.3-2.3.5-2 .5-5.8.5-5.8s0-3.8-.5-5.8zM9.5 15.6V8.4l6.3 3.6-6.3 3.6z"/></svg>';
                        if($plat=='twitch') $svg='<svg viewBox="0 0 24 24" fill="#bf9dff"><path d="M11.6 4.7h1.7v5.1h-1.7zm4.7 0H18v5.1h-1.7zM6 0L1.7 4.3v15.4h5.1V24l4.3-4.3h3.4L22.3 12V0zm14.6 11.1l-3.4 3.4h-3.4l-3 3v-3H6.9V1.7h13.7z"/></svg>';
                        if($plat=='tiktok') $svg='<svg viewBox="0 0 24 24" fill="white"><path d="M19.6 6.7c-1.3-1.1-2.2-2.6-2.6-4.2V2h-3.5v13.7c0 2.1-1.7 3.7-3.7 3.7-2.1 0-3.7-1.7-3.7-3.7s1.7-3.7 3.7-3.7c.3 0 .6 0 .9.1V9.4c-3.5-.2-6.3 2.7-6.3 6.3 0 3.5 2.8 6.3 6.3 6.3 3.5 0 6.3-2.8 6.3-6.3v-7c1.4.9 3 1.4 4.8 1.5v-3.4c-1 0-1.8 0-2.1-.1z"/></svg>';
                        if($plat=='instagram') $svg='<svg viewBox="0 0 24 24" fill="#ff87a8"><path d="M12 2.2c3.2 0 3.6 0 4.9.1 3.3.1 4.8 1.7 4.9 4.9.1 1.3.1 1.6.1 4.8s0 3.6-.1 4.9c-.1 3.2-1.7 4.8-4.9 4.9-1.3.1-1.6.1-4.9.1s-3.6 0-4.9-.1c-3.2-.1-4.8-1.7-4.9-4.9-.1-1.3-.1-1.6-.1-4.9s0-3.6.1-4.9c.1-3.2 1.7-4.8 4.9-4.9 1.3-.1 1.6-.1 4.9-.1zM12 0C8.7 0 8.3 0 7 .1c-4.4.2-6.8 2.6-7 7C0 8.3 0 8.7 0 12s0 3.7.1 4.9c.2 4.4 2.6 6.8 7 7 1.3.1 1.7.1 4.9.1s3.7 0 4.9-.1c4.4-.2 6.8-2.6 7-7 .1-1.3.1-1.7.1-4.9s0-3.7-.1-4.9c-.2-4.4-2.6-6.8-7-7C15.7 0 15.3 0 12 0zm0 5.8c-3.4 0-6.2 2.8-6.2 6.2S8.6 18.2 12 18.2s6.2-2.8 6.2-6.2S15.4 5.8 12 5.8zm0 10.2c-2.2 0-4-1.8-4-4s1.8-4 4-4 4 1.8 4 4-1.8 4-4 4zm6.4-11.8c-.8 0-1.4.6-1.4 1.4s.6 1.4 1.4 1.4 1.4-.6 1.4-1.4-.6-1.4-1.4-1.4z"/></svg>';
                        if($plat=='twitter') $svg='<svg viewBox="0 0 24 24" fill="#6db6f7"><path d="M18.2 2.2h3.3l-7.2 8.3 8.5 11.2H16.2l-5.2-6.8-6 6.8H1.7l7.7-8.8L1.3 2.2H8l4.7 6.2zm-1.2 17.5h1.8L7.1 4.1H5.1z"/></svg>';
                    ?>
                    <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener" class="jc-btn"><?php echo $svg; ?> <span><?php echo esc_html($label); ?></span></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <script>document.addEventListener("DOMContentLoaded",function(){const i=document.getElementById("jc-search-input"),g=document.getElementById("jc-grid");if(i&&g){const c=g.querySelectorAll(".jc-card");i.addEventListener("input",e=>{const v=e.target.value.toLowerCase();c.forEach(el=>{el.style.display=el.dataset.search.includes(v)?"block":"none"})})}});</script>
    <?php
    return ob_get_clean();
}

/**
 * 7. API EINSTELLUNGEN SEITE
 */
function jc_teilnehmer_render_api_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Keine Berechtigung' );
    
    $client_id = get_option( 'jc_twitch_client_id', '' );
    $client_secret = get_option( 'jc_twitch_client_secret', '' );
    $has_credentials = !empty( $client_id ) && !empty( $client_secret );
    
    settings_errors( 'jc_teilnehmer' );
    ?>
    <div class="wrap">
        <h1>🔑 API Einstellungen</h1>
        <p>Konfiguriere die Twitch API, um echte Profilbilder zu laden.</p>
        
        <?php if ( $has_credentials ) : ?>
            <div class="notice notice-success">
                <p>✅ Twitch API Credentials sind konfiguriert!</p>
            </div>
        <?php else : ?>
            <div class="notice notice-warning">
                <p>⚠️ Noch keine Twitch API Credentials konfiguriert. Profilbilder werden als Platzhalter angezeigt.</p>
            </div>
        <?php endif; ?>

        <div class="card" style="max-width: 800px;">
            <h2>Twitch API Setup</h2>
            <ol>
                <li>Gehe zu <a href="https://dev.twitch.tv/console" target="_blank">dev.twitch.tv/console</a></li>
                <li>Melde dich an und klicke auf "Register Your Application"</li>
                <li>
                    <strong>Name:</strong> z.B. "JustCreators WP Plugin"<br>
                    <strong>OAuth Redirect URL:</strong> <code>http://localhost</code><br>
                    <strong>Category:</strong> Website Integration
                </li>
                <li>Nach dem Erstellen: Klicke auf "Manage" und kopiere die <strong>Client ID</strong></li>
                <li>Klicke auf "New Secret" und kopiere das <strong>Client Secret</strong></li>
                <li>Trage beide Werte unten ein</li>
            </ol>
            
            <form method="post" action="">
                <?php wp_nonce_field( 'jc_teilnehmer_api_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="twitch_client_id">Twitch Client ID</label></th>
                        <td>
                            <input type="text" id="twitch_client_id" name="twitch_client_id" 
                                   value="<?php echo esc_attr( $client_id ); ?>" 
                                   class="regular-text" placeholder="abc123xyz...">
                            <p class="description">Deine Twitch Application Client ID</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="twitch_client_secret">Twitch Client Secret</label></th>
                        <td>
                            <input type="password" id="twitch_client_secret" name="twitch_client_secret" 
                                   value="<?php echo esc_attr( $client_secret ); ?>" 
                                   class="regular-text" placeholder="xyz789abc...">
                            <p class="description">Dein Twitch Application Client Secret (wird verschlüsselt gespeichert)</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" name="jc_teilnehmer_save_api" class="button button-primary">💾 Speichern</button>
                </p>
            </form>
        </div>
        
        <?php if ( $has_credentials ) : ?>
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>🧪 API Test</h2>
            <p>Teste ob die API funktioniert:</p>
            <form method="post" action="">
                <?php wp_nonce_field( 'jc_teilnehmer_api_test' ); ?>
                <input type="text" name="test_username" placeholder="z.B. lampireloaded" class="regular-text">
                <button type="submit" name="jc_teilnehmer_test_api" class="button">Test</button>
            </form>
            
            <?php
            if ( isset( $_POST['jc_teilnehmer_test_api'] ) ) {
                check_admin_referer( 'jc_teilnehmer_api_test' );
                $test_user = sanitize_text_field( $_POST['test_username'] ?? '' );
                if ( $test_user ) {
                    $user_data = jc_teilnehmer_fetch_twitch_user( $test_user );
                    if ( $user_data ) {
                        echo '<div class="notice notice-success"><p>✅ API funktioniert!</p></div>';
                        echo '<p><strong>Username:</strong> ' . esc_html( $user_data['display_name'] ) . '</p>';
                        echo '<p><strong>Profilbild:</strong></p>';
                        echo '<img src="' . esc_url( $user_data['profile_image_url'] ) . '" style="width:150px;height:150px;border-radius:50%;">';
                    } else {
                        echo '<div class="notice notice-error"><p>❌ Fehler: User nicht gefunden oder API Problem</p></div>';
                    }
                }
            }
            ?>
        </div>
        <?php endif; ?>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>🔄 Cache leeren</h2>
            <p>Wenn du die Profilbilder aktualisieren möchtest, lösche den Cache:</p>
            <form method="post" action="">
                <?php wp_nonce_field( 'jc_teilnehmer_clear_cache' ); ?>
                <button type="submit" name="jc_teilnehmer_clear_cache" class="button button-secondary">🗑️ Alle Bilder-Cache löschen</button>
            </form>
            
            <?php
            if ( isset( $_POST['jc_teilnehmer_clear_cache'] ) ) {
                check_admin_referer( 'jc_teilnehmer_clear_cache' );
                global $wpdb;
                $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_jc_img_%' OR option_name LIKE '_transient_jc_meta_%'" );
                delete_transient( 'jc_twitch_access_token' );
                echo '<div class="notice notice-success"><p>✅ Cache gelöscht!</p></div>';
            }
            ?>
        </div>
    </div>
    <?php
}