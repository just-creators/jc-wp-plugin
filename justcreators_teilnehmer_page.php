<?php
/**
 * Plugin Name: JustCreators Teilnehmer
 * Description: Teilnehmer-Verwaltung mit Social Media Integration im JustCreators Design.
 * Version: 1.0.2
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
        application_id bigint(20) DEFAULT NULL,
        display_name varchar(255) NOT NULL,
        title varchar(255) DEFAULT '',
        title_color varchar(7) DEFAULT '#6c7bff',
        social_channels longtext DEFAULT NULL,
        profile_image_url varchar(500) DEFAULT '',
        sort_order int(11) DEFAULT 0,
        is_active tinyint(1) DEFAULT 1,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY application_id (application_id),
        KEY sort_order (sort_order),
        KEY is_active (is_active)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );

    update_option( 'jc_teilnehmer_db_version', JC_TEILNEHMER_VERSION );
}

// Sicherstellen, dass neue Spalten/Indizes existieren (Migration für Bestandsinstallationen)
add_action( 'admin_init', function() {
    global $wpdb;
    $table_name = $wpdb->prefix . JC_TEILNEHMER_TABLE;
    
    // application_id Spalte
    $has_column = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table_name LIKE %s", 'application_id' ) );
    if ( ! $has_column ) {
        $wpdb->query( "ALTER TABLE $table_name ADD COLUMN application_id bigint(20) DEFAULT NULL" );
        $wpdb->query( "ALTER TABLE $table_name ADD UNIQUE KEY application_id (application_id)" );
    }
    
    // title_color Spalte
    $has_color = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM $table_name LIKE %s", 'title_color' ) );
    if ( ! $has_color ) {
        $wpdb->query( "ALTER TABLE $table_name ADD COLUMN title_color varchar(7) DEFAULT '#6c7bff'" );
    }
});

/**
 * 2. ADMIN MENU
 */
function jc_teilnehmer_register_menu() {
    add_submenu_page(
        JC_ADMIN_PARENT_SLUG,
        'JustCreators Teilnehmer',
        'Teilnehmer',
        'manage_options',
        'jc-teilnehmer',
        'jc_teilnehmer_render_admin_page'
    );
    
    add_submenu_page(
        JC_ADMIN_PARENT_SLUG,
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
        update_option( 'jc_youtube_api_key', sanitize_text_field( $_POST['youtube_api_key'] ?? '' ) );
        
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
                'title_color' => sanitize_hex_color( $_POST['title_color'] ?? '#6c7bff' ),
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
            'title_color' => sanitize_hex_color( $_POST['title_color'] ?? '#6c7bff' ),
            'social_channels' => wp_json_encode( $channels ),
            'profile_image_url' => jc_teilnehmer_get_profile_image( $channels ), // Bild aktualisieren
            'is_active' => isset( $_POST['is_active'] ) ? 1 : 0
        ), array( 'id' => $id ) );
        
        add_settings_error( 'jc_teilnehmer', 'updated', 'Gespeichert.', 'updated' );
    }

    // Löschen
    if ( isset( $_POST['jc_teilnehmer_delete'], $_POST['teilnehmer_id'] ) ) {
        $id = intval( $_POST['teilnehmer_id'] );
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
    
    // Alle Profilbilder zurücksetzen
    if ( isset( $_POST['jc_teilnehmer_reset_images'] ) ) {
        check_admin_referer( 'jc_teilnehmer_reset_images' );
        
        // Cache löschen (wichtig!)
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_jc_img_%' OR option_name LIKE '_transient_jc_meta_%'" );
        delete_transient( 'jc_twitch_access_token' );
        
        // Alle Bilder neu laden
        $rows = $wpdb->get_results( "SELECT id, social_channels FROM $table" );
        $updated = 0;
        foreach ( $rows as $row ) {
            $channels = json_decode( $row->social_channels, true );
            if ( is_array( $channels ) ) {
                $new_image = jc_teilnehmer_get_profile_image( $channels );
                $wpdb->update( $table, array( 'profile_image_url' => $new_image ), array( 'id' => $row->id ) );
                $updated++;
            }
        }
        
        add_settings_error( 'jc_teilnehmer', 'images_reset', "✅ Alle Profilbilder aktualisiert ($updated Einträge).", 'updated' );
    }
    
    // Kompletter API Reload (Namen + Bilder)
    if ( isset( $_POST['jc_teilnehmer_full_reload'] ) ) {
        check_admin_referer( 'jc_teilnehmer_full_reload' );
        
        // Cache komplett löschen
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_jc_img_%' OR option_name LIKE '_transient_jc_meta_%'" );
        delete_transient( 'jc_twitch_access_token' );
        
        // Alle Teilnehmer durchgehen und Namen + Bilder neu laden
        $rows = $wpdb->get_results( "SELECT id, social_channels FROM $table" );
        $updated = 0;
        foreach ( $rows as $row ) {
            $channels = json_decode( $row->social_channels, true );
            if ( is_array( $channels ) && !empty( $channels ) ) {
                // Hole Meta-Daten (Name + Bild) von der API
                $meta = jc_teilnehmer_resolve_social_meta( $channels, '' );
                $new_name = !empty( $meta['title'] ) ? $meta['title'] : null;
                $new_image = !empty( $meta['image'] ) ? $meta['image'] : jc_teilnehmer_get_profile_image( $channels );
                
                $update_data = array( 'profile_image_url' => $new_image );
                if ( $new_name ) {
                    $update_data['display_name'] = $new_name;
                }
                
                $wpdb->update( $table, $update_data, array( 'id' => $row->id ) );
                $updated++;
            }
        }
        
        add_settings_error( 'jc_teilnehmer', 'full_reload', "✅ Alle Daten von APIs neu geladen ($updated Einträge: Namen + Bilder aktualisiert).", 'updated' );
    }
    
    // Alle Rollen/Titel entfernen
    if ( isset( $_POST['jc_teilnehmer_clear_all_titles'] ) ) {
        check_admin_referer( 'jc_teilnehmer_clear_all_titles' );
        
        $wpdb->query( "UPDATE $table SET title = '', title_color = '#6c7bff'" );
        
        add_settings_error( 'jc_teilnehmer', 'titles_cleared', "✅ Alle Rollen/Titel entfernt.", 'updated' );
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
    // Format: [image_only] URL
    $lines = explode( "\n", $input );
    $channels = array();
    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( empty( $line ) ) continue;
        
        $image_only = false;
        // Prüfe auf [image_only] Marker am Anfang
        if ( strpos( $line, '[image_only]' ) === 0 ) {
            $image_only = true;
            $line = trim( substr( $line, strlen( '[image_only]' ) ) );
        }
        
        $platform = 'unknown';
        if ( stripos( $line, 'youtube' ) !== false || stripos( $line, 'youtu.be' ) !== false ) $platform = 'youtube';
        elseif ( stripos( $line, 'twitch' ) !== false ) $platform = 'twitch';
        elseif ( stripos( $line, 'tiktok' ) !== false ) $platform = 'tiktok';
        elseif ( stripos( $line, 'instagram' ) !== false ) $platform = 'instagram';
        elseif ( stripos( $line, 'twitter' ) !== false || stripos( $line, 'x.com' ) !== false ) $platform = 'twitter';

        $channels[] = array( 'platform' => $platform, 'url' => $line, 'image_only' => $image_only );
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
 * YouTube Channel ID aus URL extrahieren
 */
function jc_teilnehmer_extract_youtube_channel_id( $url ) {
    // Channel URL: youtube.com/channel/UCxxxxx oder youtube.com/@username
    if ( preg_match( '#youtube\.com/channel/([a-zA-Z0-9_-]+)#i', $url, $m ) ) {
        return $m[1];
    }
    
    // Handle @username format - wir müssen die API verwenden um die ID zu bekommen
    if ( preg_match( '#youtube\.com/@([a-zA-Z0-9_-]+)#i', $url, $m ) ) {
        return '@' . $m[1]; // Marker dass es ein Handle ist
    }
    
    // Video URL - Channel ID aus oEmbed holen
    if ( preg_match( '#(?:youtube\.com/watch\?v=|youtu\.be/)([a-zA-Z0-9_-]+)#i', $url, $m ) ) {
        return 'video:' . $m[1]; // Marker dass es eine Video-URL ist
    }
    
    return false;
}

/**
 * YouTube Channel Daten über API abrufen
 */
function jc_teilnehmer_fetch_youtube_channel( $identifier ) {
    $api_key = defined( 'JC_YOUTUBE_API_KEY' ) ? JC_YOUTUBE_API_KEY : get_option( 'jc_youtube_api_key', '' );
    if ( empty( $api_key ) ) return false;
    
    $api_url = 'https://www.googleapis.com/youtube/v3/channels?part=snippet';
    
    // Handle verschiedene ID-Typen
    if ( strpos( $identifier, '@' ) === 0 ) {
        // @username Format
        $api_url .= '&forHandle=' . urlencode( substr( $identifier, 1 ) );
    } elseif ( strpos( $identifier, 'video:' ) === 0 ) {
        // Video URL - erst Video-Info holen, dann Channel
        $video_id = substr( $identifier, 6 );
        $video_url = 'https://www.googleapis.com/youtube/v3/videos?part=snippet&id=' . urlencode( $video_id ) . '&key=' . $api_key;
        $response = wp_remote_get( $video_url, array( 'timeout' => 10 ) );
        if ( is_wp_error( $response ) ) return false;
        
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( isset( $data['items'][0]['snippet']['channelId'] ) ) {
            $channel_id = $data['items'][0]['snippet']['channelId'];
            $api_url .= '&id=' . urlencode( $channel_id );
        } else {
            return false;
        }
    } else {
        // Direkte Channel ID
        $api_url .= '&id=' . urlencode( $identifier );
    }
    
    $api_url .= '&key=' . $api_key;
    
    $response = wp_remote_get( $api_url, array( 'timeout' => 10 ) );
    if ( is_wp_error( $response ) ) return false;
    
    $data = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( isset( $data['items'][0] ) ) {
        return $data['items'][0];
    }
    
    return false;
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
        // Versuche YouTube Data API für Kanal-Profilbild
        $channel_id = jc_teilnehmer_extract_youtube_channel_id( $url );
        if ( $channel_id ) {
            $channel_data = jc_teilnehmer_fetch_youtube_channel( $channel_id );
            if ( $channel_data && isset( $channel_data['snippet']['thumbnails'] ) ) {
                // Höchste Auflösung nehmen
                $thumbnails = $channel_data['snippet']['thumbnails'];
                if ( isset( $thumbnails['high']['url'] ) ) {
                    $img = $thumbnails['high']['url'];
                } elseif ( isset( $thumbnails['medium']['url'] ) ) {
                    $img = $thumbnails['medium']['url'];
                } elseif ( isset( $thumbnails['default']['url'] ) ) {
                    $img = $thumbnails['default']['url'];
                }
            }
        }
        
        // Fallback: oEmbed für Video-Thumbnail
        if ( empty( $img ) ) {
            $res = wp_remote_get( 'https://www.youtube.com/oembed?url=' . urlencode( $url ) . '&format=json' );
            if ( !is_wp_error( $res ) ) {
                $data = json_decode( wp_remote_retrieve_body( $res ), true );
                $img = $data['thumbnail_url'] ?? '';
            }
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
        // Versuche YouTube Data API für Kanal-Daten
        $channel_id = jc_teilnehmer_extract_youtube_channel_id( $url );
        if ( $channel_id ) {
            $channel_data = jc_teilnehmer_fetch_youtube_channel( $channel_id );
            if ( $channel_data && isset( $channel_data['snippet'] ) ) {
                $snippet = $channel_data['snippet'];
                $meta['title'] = $snippet['title'] ?? '';
                // Höchste Auflösung
                if ( isset( $snippet['thumbnails']['high']['url'] ) ) {
                    $meta['image'] = $snippet['thumbnails']['high']['url'];
                } elseif ( isset( $snippet['thumbnails']['medium']['url'] ) ) {
                    $meta['image'] = $snippet['thumbnails']['medium']['url'];
                } elseif ( isset( $snippet['thumbnails']['default']['url'] ) ) {
                    $meta['image'] = $snippet['thumbnails']['default']['url'];
                }
            }
        }
        
        // Fallback: oEmbed
        if ( empty( $meta['title'] ) || empty( $meta['image'] ) ) {
            $res = wp_remote_get( 'https://www.youtube.com/oembed?url=' . urlencode( $url ) . '&format=json', array( 'timeout' => 10 ) );
            if ( ! is_wp_error( $res ) && wp_remote_retrieve_response_code( $res ) === 200 ) {
                $data = json_decode( wp_remote_retrieve_body( $res ), true );
                if ( is_array( $data ) ) {
                    if ( empty( $meta['title'] ) ) $meta['title'] = $data['title'] ?? '';
                    if ( empty( $meta['image'] ) ) $meta['image'] = $data['thumbnail_url'] ?? '';
                }
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
        // Nur neue Einträge basierend auf application_id einfügen
        $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $target WHERE application_id = %d", $app->id ) );
        if ( $exists ) continue;

        jc_teilnehmer_add_from_application( $app );
        $count++;
    }
    return array( 'message' => "$count importiert." );
}

// Fügt einen Teilnehmer aus einer Bewerbung hinzu, wenn noch nicht vorhanden
function jc_teilnehmer_add_from_application( $app ) {
    if ( ! $app || ! isset( $app->id ) ) return false;
    global $wpdb;
    $target = $wpdb->prefix . JC_TEILNEHMER_TABLE;

    // Doppelte anhand application_id verhindern
    $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $target WHERE application_id = %d", $app->id ) );
    if ( $exists ) return false;

    $ch = json_decode( $app->social_channels, true );
    if ( ! is_array( $ch ) ) $ch = array();

    $name = $app->applicant_name;
    // Meta-Daten versuchen zu holen (kann Name überschreiben, falls API-Titel vorhanden)
    $meta = jc_teilnehmer_resolve_social_meta( $ch, $name );
    if ( ! empty( $meta['title'] ) ) {
        $name = $meta['title'];
    }

    $wpdb->insert( $target, array(
        'application_id' => $app->id,
        'display_name' => $name,
        'title' => 'Creator',
        'social_channels' => wp_json_encode( array_values( array_filter( $ch ) ) ),
        'profile_image_url' => ! empty( $meta['image'] ) ? $meta['image'] : jc_teilnehmer_get_profile_image( $ch ),
        'is_active' => 1
    ) );
    return true;
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
            if ( is_array( $chs ) ) {
                foreach( $chs as $c ) {
                    if ( isset( $c['image_only'] ) && $c['image_only'] ) {
                        $txt .= '[image_only] ' . $c['url'] . "\n";
                    } else {
                        $txt .= $c['url'] . "\n";
                    }
                }
            }
            ?>
            <div class="wrap">
                <h1>Bearbeiten: <?php echo esc_html( $row->display_name ); ?></h1>
                <form method="post">
                    <?php wp_nonce_field( 'jc_teilnehmer_edit_' . $row->id ); ?>
                    <input type="hidden" name="teilnehmer_id" value="<?php echo $row->id; ?>">
                    <table class="form-table">
                        <tr><th>Name</th><td><input name="display_name" value="<?php echo esc_attr( $row->display_name ); ?>" class="regular-text"></td></tr>
                        <tr><th>Titel</th><td><input name="title" value="<?php echo esc_attr( $row->title ); ?>" class="regular-text"></td></tr>
                        <tr><th>Farbe der Rolle</th><td><input type="color" name="title_color" value="<?php echo esc_attr( $row->title_color ?? '#6c7bff' ); ?>" style="width:50px;height:40px;cursor:pointer;"></td></tr>
                        <tr><th>Links</th><td>
                            <textarea name="social_channels" rows="5" class="large-text code" placeholder="Pro Zeile ein Link. Mit [image_only] markieren um nur für Profilbild zu nutzen."><?php echo esc_textarea( $txt ); ?></textarea>
                            <p style="font-size:12px;color:#999;">Beispiel: <code>[image_only] https://youtube.com/@mychannel</code> - Link wird nur für Profilbild verwendet</p>
                        </td></tr>
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
                <p><input name="display_name" placeholder="Name *" required class="regular-text"> <input name="title" placeholder="Titel / Rolle (optional)" class="regular-text"></p>
                <p style="display:flex;gap:10px;align-items:center;">
                    <label>Farbe der Rolle:</label>
                    <input type="color" name="title_color" value="#6c7bff" style="width:50px;height:40px;cursor:pointer;">
                </p>
                <p>
                    <textarea name="social_channels" rows="3" class="large-text code" placeholder="Links (optional) - Pro Zeile ein Link. Mit [image_only] nur für Profilbild.&#10;Beispiel: [image_only] https://youtube.com/@mychannel"></textarea>
                    <p style="font-size:12px;color:#999;">Kanäle sind optional. Mit <code>[image_only]</code> vor einem Link wird dieser nur für das Profilbild verwendet und nicht im Frontend angezeigt.</p>
                </p>
                <button type="submit" name="jc_teilnehmer_add" class="button button-primary">Hinzufügen</button>
            </form>
        </div>

        <div style="margin-bottom:20px; display:flex; gap:10px; flex-wrap:wrap;">
            <form method="post" style="margin:0;">
                <?php wp_nonce_field( 'jc_teilnehmer_import_db' ); ?>
                <button type="submit" name="jc_teilnehmer_import_from_db" class="button">📥 Aus Bewerbungs-DB importieren</button>
            </form>
            
            <form method="post" style="margin:0;" onsubmit="return confirm('🔄 Alle Profilbilder werden neu von den Social Media Plattformen geladen.\n\nDies kann einen Moment dauern. Fortfahren?')">
                <?php wp_nonce_field( 'jc_teilnehmer_reset_images' ); ?>
                <button type="submit" name="jc_teilnehmer_reset_images" class="button">🔄 Nur Bilder aktualisieren</button>
            </form>
            
            <form method="post" style="margin:0;" onsubmit="return confirm('⚡ VOLLSTÄNDIGER API RELOAD\n\nDies lädt ALLE Daten neu von den APIs:\n• Channel-Namen\n• Profilbilder\n\nDies kann länger dauern. Fortfahren?')">
                <?php wp_nonce_field( 'jc_teilnehmer_full_reload' ); ?>
                <button type="submit" name="jc_teilnehmer_full_reload" class="button button-primary">⚡ Kompletter API Reload</button>
            </form>
            
            <form method="post" style="margin:0;" onsubmit="return confirm('⚠️ Alle Rollen/Titel werden entfernt!\n\nFortfahren?')">
                <?php wp_nonce_field( 'jc_teilnehmer_clear_all_titles' ); ?>
                <button type="submit" name="jc_teilnehmer_clear_all_titles" class="button" style="color:#cc0000;">✖️ Alle Rollen entfernen</button>
            </form>
        </div>

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
                        <td>
                            <?php if ( $r->title ) : ?>
                                <span style="background-color:<?php echo esc_attr( $r->title_color ?? '#6c7bff' ); ?>;color:white;padding:4px 8px;border-radius:4px;font-size:12px;font-weight:bold;">
                                    <?php echo esc_html( $r->title ); ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $cc; ?></td>
                        <td><input type="number" name="order[<?php echo $r->id; ?>]" value="<?php echo $r->sort_order; ?>" style="width:50px"></td>
                        <td>
                            <a href="?page=jc-teilnehmer&edit=<?php echo $r->id; ?>" class="button button-small">Edit</a>
                            <form method="post" style="display:inline; margin-left:4px;" onsubmit="return confirm('Löschen?');">
                                <?php wp_nonce_field( 'jc_teilnehmer_delete_' . $r->id ); ?>
                                <input type="hidden" name="teilnehmer_id" value="<?php echo $r->id; ?>">
                                <button type="submit" name="jc_teilnehmer_delete" class="button button-small" style="color:red">X</button>
                            </form>
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
                <div class="jc-hero-badge"><?php echo count($rows); ?> CREATOR</div>
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
                // Verwende das gespeicherte Profilbild aus der DB (wie im Admin-Panel)
                $card_name = $t->display_name;
                $card_image = $t->profile_image_url ?: 'https://via.placeholder.com/300x300/1e2740/6c7bff?text=JC';
                $search = strtolower( $card_name );
            ?>
            <div class="jc-card" data-search="<?php echo esc_attr( $search ); ?>">
                <div class="jc-head">
                    <img src="<?php echo esc_url( $card_image ); ?>" class="jc-av" alt="">
                    <div>
                        <h3 class="jc-name"><?php echo esc_html( $card_name ); ?></h3>
                        <?php if ( $t->title ) : ?>
                            <span style="display:inline-block;background-color:<?php echo esc_attr( $t->title_color ?? '#6c7bff' ); ?>;color:white;padding:4px 8px;border-radius:4px;font-size:11px;font-weight:bold;margin-top:4px;">
                                <?php echo esc_html( $t->title ); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="jc-socials">
                    <?php foreach ( $chs as $c ) : 
                        // Überspringe Kanäle, die nur für das Profilbild sind
                        if ( isset( $c['image_only'] ) && $c['image_only'] ) continue;
                        
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
    
    $twitch_client_id = get_option( 'jc_twitch_client_id', '' );
    $twitch_client_secret = get_option( 'jc_twitch_client_secret', '' );
    $youtube_api_key = get_option( 'jc_youtube_api_key', '' );
    
    $has_twitch = !empty( $twitch_client_id ) && !empty( $twitch_client_secret );
    $has_youtube = !empty( $youtube_api_key );
    
    settings_errors( 'jc_teilnehmer' );
    ?>
    <div class="wrap">
        <h1>🔑 API Einstellungen</h1>
        <p>Konfiguriere die APIs, um echte Profilbilder zu laden.</p>
        
        <div class="notice notice-info">
            <p>
                <strong>Status:</strong>
                <?php if ( $has_twitch ) : ?>✅ Twitch<?php else : ?>❌ Twitch<?php endif; ?>
                &nbsp;|&nbsp;
                <?php if ( $has_youtube ) : ?>✅ YouTube<?php else : ?>❌ YouTube<?php endif; ?>
            </p>
        </div>

        <div class="card" style="max-width: 800px;">
            <h2>🎮 Twitch API Setup</h2>
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
                                   value="<?php echo esc_attr( $twitch_client_id ); ?>" 
                                   class="regular-text" placeholder="abc123xyz...">
                            <p class="description">Deine Twitch Application Client ID</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="twitch_client_secret">Twitch Client Secret</label></th>
                        <td>
                            <input type="password" id="twitch_client_secret" name="twitch_client_secret" 
                                   value="<?php echo esc_attr( $twitch_client_secret ); ?>" 
                                   class="regular-text" placeholder="xyz789abc...">
                            <p class="description">Dein Twitch Application Client Secret</p>
                        </td>
                    </tr>
                </table>
        </div>
        
        <div class="card" style="max-width: 800px; margin-top: 20px;">
            <h2>📺 YouTube API Setup</h2>
            <ol>
                <li>Gehe zu <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a></li>
                <li>Erstelle ein neues Projekt oder wähle ein bestehendes aus</li>
                <li>Aktiviere die <strong>YouTube Data API v3</strong></li>
                <li>Gehe zu "APIs & Services" → "Credentials"</li>
                <li>Klicke "Create Credentials" → "API Key"</li>
                <li>Kopiere den API Key und trage ihn unten ein</li>
                <li><em>Optional:</em> Beschränke den Key auf "YouTube Data API v3" für mehr Sicherheit</li>
            </ol>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="youtube_api_key">YouTube API Key</label></th>
                    <td>
                        <input type="text" id="youtube_api_key" name="youtube_api_key" 
                               value="<?php echo esc_attr( $youtube_api_key ); ?>" 
                               class="regular-text" placeholder="AIzaSy...">
                        <p class="description">Dein Google/YouTube Data API v3 Key</p>
                    </td>
                </tr>
            </table>
                
                <p class="submit">
                    <button type="submit" name="jc_teilnehmer_save_api" class="button button-primary">💾 Alle Einstellungen speichern</button>
                </p>
            </form>
        </div>
        
        <?php if ( $has_twitch ) : ?>
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