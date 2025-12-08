<?php
/**
 * Plugin Name: JustCreators Bewerbungsportal Pro
 * Description: Erweiterte Version mit Link-Validierung, Auto-Sync und Discord Tags
 * Version: 6.17 (Datenschutz-Checkbox)
 * Author: JustCreators Team
 * License: GPL2
 */
if ( ! defined( 'ABSPATH' ) ) exit;
// ========================================
// KONFIGURATION
// ========================================
if ( ! defined( 'JC_DISCORD_CLIENT_ID' ) ) {
    define( 'JC_DISCORD_CLIENT_ID', 'YOUR_CLIENT_ID_HERE' );
}
if ( ! defined( 'JC_DISCORD_CLIENT_SECRET' ) ) {
    define( 'JC_DISCORD_CLIENT_SECRET', 'YOUR_CLIENT_SECRET_HERE' );
}
if ( ! defined( 'JC_REDIRECT_URI' ) ) {
    define( 'JC_REDIRECT_URI', site_url('/bewerbung?discord_oauth=1') );
}
if ( ! defined( 'JC_TEMP_DISCORD_INVITE' ) ) {
    define( 'JC_TEMP_DISCORD_INVITE', 'https://discord.gg/TEjEc6F3GW' ); // Füge deinen Temp-Server Invite-Link hier ein
}
function jc_get_bot_api_url() {
    return get_option( 'jc_bot_api_url', 'http://localhost:3000' );
}
function jc_get_bot_api_secret() {
    return get_option( 'jc_bot_api_secret', '' );
}
// ========================================
// REST API für Status-Sync (Discord → WordPress)
// ========================================
// Session GLOBAL starten - für ALLE Seiten
add_action( 'init', function() {
    if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
        // Session mit Cookie-Optionen starten
        session_set_cookie_params([
            'lifetime' => 0, // Browser-Session
            'path' => '/',
            'domain' => '.just-creators.de',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        session_start();
        error_log( 'JC: Session gestartet - ID: ' . session_id() . ', Cookie Domain: .just-creators.de' );
    }
   
    // DEBUG: Session Status auf jeder Seite
    if ( is_page( 'regeln' ) || is_page( 'bewerbung' ) ) {
        $has_user = isset( $_SESSION['jc_discord_user'] ) ? 'JA' : 'NEIN';
        $username = isset( $_SESSION['jc_discord_user']['username'] ) ? $_SESSION['jc_discord_user']['username'] : 'N/A';
        error_log( "JC Session Check - Page: " . get_the_title() . ", Session ID: " . session_id() . ", User: {$has_user} ({$username})" );
    }
}, 1 );
function jc_verify_api_secret( $request ) {
    $auth_header = $request->get_header( 'authorization' );
    $expected = 'Bearer ' . jc_get_bot_api_secret();
   
    error_log( "JC API Auth: Header=" . substr($auth_header, 0, 20) . "..., Expected=" . substr($expected, 0, 20) . "..." );
    error_log( "JC API Auth Match: " . ($auth_header === $expected ? 'YES' : 'NO') );
   
    return $auth_header === $expected;
}
add_action('rest_api_init', function() {
    register_rest_route('jc/v1', '/status-sync', array(
        'methods' => 'POST',
        'callback' => 'jc_handle_status_sync',
        'permission_callback' => 'jc_verify_api_secret'
    ));
    
    register_rest_route('jc/v1', '/check-discord-join', array(
        'methods' => 'POST',
        'callback' => 'jc_handle_check_discord_join',
        'permission_callback' => '__return_true' // Öffentlich, aber nur für eingeloggte User
    ));
    
    register_rest_route('jc/v1', '/send-application', array(
        'methods' => 'POST',
        'callback' => 'jc_handle_send_application',
        'permission_callback' => '__return_true' // Öffentlich, aber nur für eingeloggte User
    ));
    
    // NEUER Endpunkt für ioBroker (LESEN)
    register_rest_route('jc/v1', '/applications', array(
        'methods' => 'GET',
        'callback' => 'jc_api_get_all_applications',
        'permission_callback' => 'jc_verify_api_secret' // Wir nutzen dieselbe Sicheit wie der Bot
    ));
    
    // NEUER Endpunkt für ioBroker (SCHREIBEN)
    register_rest_route('jc/v1', '/update-status', array(
        'methods' => 'POST', // Wichtig: POST, nicht GET
        'callback' => 'jc_api_update_status',
        'permission_callback' => 'jc_verify_api_secret' // Dieselbe Sicherheit
    ));
});
function jc_handle_status_sync( $request ) {
    $params = $request->get_json_params();
   
    error_log( "JC API: ========== NEW STATUS SYNC REQUEST ==========" );
    error_log( "JC API: Raw params: " . json_encode($params) );
   
    if ( empty( $params['discord_id'] ) || empty( $params['status'] ) ) {
        error_log( "JC API: ❌ FEHLER - Missing parameters!" );
        return new WP_Error( 'missing_params', 'discord_id und status erforderlich', array( 'status' => 400 ) );
    }
   
    $discord_id = sanitize_text_field( $params['discord_id'] );
    $status = sanitize_text_field( $params['status'] );
   
    error_log( "JC API: Sanitized - discord_id={$discord_id}, status={$status}" );
   
    global $wpdb;
    $table = $wpdb->prefix . 'jc_discord_applications';
   
    // Prüfen ob Eintrag existiert
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE discord_id = %s",
        $discord_id
    ) );
   
    error_log( "JC API: Entry exists in DB: " . ($exists ? 'YES' : 'NO') );
   
    if ( ! $exists ) {
        error_log( "JC API: ❌ FEHLER - Discord ID {$discord_id} not found in database!" );
        return new WP_Error( 'not_found', 'Bewerbung nicht gefunden', array( 'status' => 404 ) );
    }
   
    // Status aktualisieren
    error_log( "JC API: Attempting UPDATE on table={$table}" );
   
    $updated = $wpdb->update(
        $table,
        array( 'status' => $status ),
        array( 'discord_id' => $discord_id ),
        array( '%s' ),
        array( '%s' )
    );
   
    if ( $updated === false ) {
        error_log( "JC API: ❌ UPDATE FAILED! DB Error: " . $wpdb->last_error );
        return new WP_Error( 'update_failed', 'Datenbankfehler: ' . $wpdb->last_error, array( 'status' => 500 ) );
    }
   
    if ( $updated === 0 ) {
        error_log( "JC API: ⚠️ UPDATE returned 0 rows (status already same?)" );
    } else {
        error_log( "JC API: ✅✅✅ UPDATE SUCCESS! Rows affected: {$updated}" );
    }
   
    // Verify
    $new_status = $wpdb->get_var( $wpdb->prepare(
        "SELECT status FROM {$table} WHERE discord_id = %s",
        $discord_id
    ) );
   
    error_log( "JC API: Verification - New status in DB: {$new_status}" );
    error_log( "JC API: ========== END STATUS SYNC ==========" );
   
    return array(
        'success' => true,
        'message' => 'Status erfolgreich aktualisiert',
        'discord_id' => $discord_id,
        'old_status' => 'unknown',
        'new_status' => $new_status,
        'rows_affected' => $updated
    );
}
function jc_update_application_status( $discord_id, $status ) {
    global $wpdb;
    $table = $wpdb->prefix . 'jc_discord_applications';
   
    error_log( "JC DB: Updating table={$table}, discord_id={$discord_id}, status={$status}" );
   
    // Erst prüfen ob der Eintrag existiert
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE discord_id = %s",
        $discord_id
    ) );
   
    error_log( "JC DB: Entry exists: " . ($exists ? 'YES' : 'NO') );
   
    if ( ! $exists ) {
        error_log( "JC DB: ❌ Discord ID {$discord_id} NOT FOUND in database!" );
        return false;
    }
   
    $updated = $wpdb->update(
        $table,
        array( 'status' => $status ),
        array( 'discord_id' => $discord_id ),
        array( '%s' ),
        array( '%s' )
    );
   
    if ( $updated === false ) {
        error_log( "JC DB: ❌ UPDATE FAILED! Error: " . $wpdb->last_error );
        return false;
    }
   
    error_log( "JC DB: ✅ UPDATE SUCCESS! Rows affected: {$updated}" );
   
    return true;
}
function jc_get_application_status( $discord_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'jc_discord_applications';
    
    error_log( "JC DB: Fetching status for Discord ID: {$discord_id}" );
    
    $result = $wpdb->get_row( $wpdb->prepare(
        "SELECT status, applicant_name, created_at, forum_post_id FROM {$table} WHERE discord_id = %s",
        $discord_id
    ) );
    
    if ( $result ) {
        error_log( "JC DB: Found application with status: {$result->status}" );
    } else {
        error_log( "JC DB: No application found for Discord ID: {$discord_id}" );
    }
    
    return $result;
}
function jc_handle_check_discord_join( $request ) {
    $params = $request->get_json_params();
    
    if ( empty( $params['discord_id'] ) ) {
        return new WP_Error( 'missing_params', 'discord_id erforderlich', array( 'status' => 400 ) );
    }
    
    $discord_id = sanitize_text_field( $params['discord_id'] );
    
    // Prüfe ob User in Session ist (Sicherheit)
    if ( ! isset( $_SESSION['jc_discord_user'] ) || $_SESSION['jc_discord_user']['id'] !== $discord_id ) {
        return new WP_Error( 'unauthorized', 'Nicht autorisiert', array( 'status' => 401 ) );
    }
    
    $check_result = jc_check_user_on_temp_server( $discord_id );
    
    return array(
        'success' => $check_result['success'],
        'is_on_temp_server' => $check_result['is_on_temp_server']
    );
}

// ########## START: AKTUALISIERTE FUNKTION (v6.17) ##########
// Fügt 'privacy_accepted_at' zur Übertragung hinzu
function jc_handle_send_application( $request ) {
    $params = $request->get_json_params();
    
    if ( empty( $params['discord_id'] ) ) {
        return new WP_Error( 'missing_params', 'discord_id erforderlich', array( 'status' => 400 ) );
    }
    
    $discord_id = sanitize_text_field( $params['discord_id'] );
    
    // Prüfe ob User in Session ist (Sicherheit)
    if ( ! isset( $_SESSION['jc_discord_user'] ) || $_SESSION['jc_discord_user']['id'] !== $discord_id ) {
        return new WP_Error( 'unauthorized', 'Nicht autorisiert', array( 'status' => 401 ) );
    }
    
    // Prüfe ob User wirklich auf Temp-Server ist
    $check_result = jc_check_user_on_temp_server( $discord_id );
    if ( ! $check_result['success'] || ! $check_result['is_on_temp_server'] ) {
        return new WP_Error( 'not_on_server', 'User ist nicht auf dem temporären Server', array( 'status' => 400 ) );
    }
    
    global $wpdb;
    $temp_table = $wpdb->prefix . 'jc_discord_applications_temp';
    $main_table = $wpdb->prefix . 'jc_discord_applications';
    
    // Hole Bewerbung aus temporärer Tabelle
    $temp_application = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$temp_table} WHERE discord_id = %s",
        $discord_id
    ) );
    
    if ( ! $temp_application ) {
        return new WP_Error( 'not_found', 'Bewerbung nicht gefunden oder abgelaufen', array( 'status' => 404 ) );
    }
    
    // Prüfe ob bereits abgelaufen
    if ( strtotime( $temp_application->expires_at ) < time() ) {
        $wpdb->delete( $temp_table, array( 'discord_id' => $discord_id ), array( '%s' ) );
        return new WP_Error( 'expired', 'Bewerbung ist abgelaufen. Bitte starte eine neue Bewerbung.', array( 'status' => 410 ) );
    }
    
    // Prüfe ob bereits in Haupttabelle vorhanden (redundant, aber sicher)
    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT id, forum_post_id FROM {$main_table} WHERE discord_id = %s",
        $discord_id
    ) );
    
    if ( $existing && ! empty( $existing->forum_post_id ) ) {
        $wpdb->delete( $temp_table, array( 'discord_id' => $discord_id ), array( '%s' ) );
        return array(
            'success' => true,
            'message' => 'Bewerbung wurde bereits verarbeitet',
            'already_sent' => true
        );
    }

    // 1. ZUERST in die Haupt-DB einfügen, um die ID zu bekommen
    $inserted = $wpdb->insert( $main_table, array(
        'discord_id' => $temp_application->discord_id,
        'discord_name' => $temp_application->discord_name,
        'applicant_name' => $temp_application->applicant_name,
        'age' => $temp_application->age,
        'social_channels' => $temp_application->social_channels,
        'social_activity' => $temp_application->social_activity,
        'motivation' => $temp_application->motivation,
        'privacy_accepted_at' => $temp_application->privacy_accepted_at, // <-- NEU (v6.17)
        'status' => 'pending'
    ), array(
        '%s','%s','%s','%s','%s','%s','%s',
        '%s', // <-- NEU (v6.17) für privacy_accepted_at
        '%s'
    ) );

    if ( ! $inserted ) {
        // Wenn das Einfügen fehlschlägt (z.B. DB-Problem), abbrechen.
        error_log("JC Handle Send: ❌ DB INSERT FAILED. " . $wpdb->last_error);
        return new WP_Error( 'db_error', 'Fehler beim Speichern in Haupttabelle: ' . $wpdb->last_error, array( 'status' => 500 ) );
    }
    
    // 2. Die neue, echte DB ID holen
    $real_database_id = $wpdb->insert_id;
    error_log("JC Handle Send: ✅ Eintrag in DB erstellt. Neue ID: " . $real_database_id);

    // 3. Bewerbung an Bot senden, MIT der echten DB ID
    $bot_data = array(
        'discord_id' => $temp_application->discord_id,
        'discord_name' => $temp_application->discord_name,
        'applicant_name' => $temp_application->applicant_name,
        'age' => $temp_application->age,
        'social_channels' => json_decode( $temp_application->social_channels, true ),
        'social_activity' => $temp_application->social_activity,
        'motivation' => $temp_application->motivation,
        'database_id' => $real_database_id // <-- HIER IST DER FIX
    );
    
    $bot_result = jc_send_application_to_bot( $bot_data );
    
    // 4. Bot-Antwort verarbeiten
    if ( $bot_result['success'] && isset( $bot_result['data']['post_id'] ) ) {
        
        // 5. Bot war erfolgreich, also die forum_post_id in der DB nachtragen
        $wpdb->update(
            $main_table,
            array( 'forum_post_id' => $bot_result['data']['post_id'] ),
            array( 'id' => $real_database_id ),
            array( '%s' ),
            array( '%d' )
        );
        
        // Temporäre Bewerbung löschen
        $wpdb->delete( $temp_table, array( 'discord_id' => $discord_id ), array( '%s' ) );
        
        // Session aufräumen
        unset( $_SESSION['jc_pending_application'] );
        unset( $_SESSION['jc_discord_user'] );
        
        error_log("JC Handle Send: ✅ Bot-Post erstellt und DB-Eintrag $real_database_id aktualisiert.");

        return array(
            'success' => true,
            'message' => 'Bewerbung erfolgreich verarbeitet',
            'post_id' => $bot_result['data']['post_id']
        );

    } else {
        // 6. Bot ist FEHLGESCHLAGEN. Rollback!
        error_log("JC Handle Send: ❌ Bot ist fehlgeschlagen. Rollback von DB-Eintrag $real_database_id.");
        
        // Lösche den Eintrag, den wir in Schritt 1 gemacht haben, da der Bot-Post nicht erstellt werden konnte.
        $wpdb->delete( $main_table, array( 'id' => $real_database_id ), array( '%d' ) );
        
        return new WP_Error( 'bot_error', $bot_result['message'] ?? 'Fehler beim Senden an Bot', array( 'status' => 500 ) );
    }
}
// ########## ENDE: AKTUALISIERTE FUNKTION (v6.17) ##########


// ########## START: NEUE IOBROKER API FUNKTIONEN (v6.14) ##########
/**
 * NEUE API-FUNKTION FÜR IOBROKER (LESEN)
 * Gibt alle Bewerbungen und eine Zusammenfassung zurück.
 * Gesichert durch jc_verify_api_secret.
 */
function jc_api_get_all_applications( $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'jc_discord_applications';
    
    // Hole alle Bewerbungen
    $applications = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" );
    
    if ( is_wp_error( $applications ) ) {
        return new WP_Error( 'db_error', 'Fehler beim Abrufen der Bewerbungen', array( 'status' => 500 ) );
    }
    
    // Nützliche Statistiken für ioBroker-Dashboards
    $total = count($applications);
    $pending = 0;
    $accepted = 0;
    $rejected = 0;
    
    foreach ( $applications as $app ) {
        if ( $app->status === 'pending' ) {
            $pending++;
        } elseif ( $app->status === 'accepted' ) {
            $accepted++;
        } elseif ( $app->status === 'rejected' ) {
            $rejected++;
        }
        
        // Bonus: social_channels als JSON-Objekt statt als String ausgeben
        // ioBroker kann das direkt als Objekt parsen.
        $app->social_channels = json_decode($app->social_channels);
    }
    
    // Datenpaket für ioBroker
    $data = array(
        'success' => true,
        'summary' => array(
            'total' => $total,
            'pending' => $pending,
            'accepted' => $accepted,
            'rejected' => $rejected
        ),
        'applications' => $applications // Die komplette Liste
    );
    
    return new WP_REST_Response( $data, 200 );
}

/**
 * NEUE API-FUNKTION FÜR IOBROKER (SCHREIBEN)
 * Aktualisiert den Status einer Bewerbung.
 * Akzeptiert JSON: { "discord_id": "12345", "new_status": "accepted" }
 */
function jc_api_update_status( $request ) {
    global $wpdb;
    $table = $wpdb->prefix . 'jc_discord_applications';
    
    // Daten aus dem ioBroker POST-Request holen
    $discord_id = sanitize_text_field( $request['discord_id'] );
    $new_status = sanitize_text_field( $request['new_status'] );
    
    // Validierung
    if ( empty($discord_id) || empty($new_status) ) {
        return new WP_Error( 'missing_params', 'discord_id und new_status sind erforderlich', array( 'status' => 400 ) );
    }
    
    // Prüfen, ob der Status gültig ist
    if ( ! in_array( $new_status, ['pending', 'accepted', 'rejected'] ) ) {
        return new WP_Error( 'invalid_status', 'Ungültiger Status. Erlaubt sind: pending, accepted, rejected', array( 'status' => 400 ) );
    }

    // Update in der Datenbank durchführen
    $updated = $wpdb->update(
        $table,
        array( 'status' => $new_status ), // SET
        array( 'discord_id' => $discord_id ), // WHERE
        array( '%s' ), // Format für SET
        array( '%s' )  // Format für WHERE
    );

    if ( $updated === false ) {
        return new WP_Error( 'db_error', 'Fehler beim Update des Status', array( 'status' => 500 ) );
    }
    
    if ( $updated === 0 ) {
        return new WP_REST_Response( array(
            'success' => false,
            'message' => 'Keine Bewerbung mit dieser Discord ID gefunden.'
        ), 404 );
    }
    
    // Erfolg zurück an ioBroker senden
    return new WP_REST_Response( array(
        'success' => true,
        'message' => "Status für $discord_id auf $new_status gesetzt."
    ), 200 );
}
// ########## ENDE: NEUE IOBROKER API FUNKTIONEN (v6.14) ##########


// ========================================
// ADMIN EINSTELLUNGEN
// ========================================
add_action( 'admin_menu', function() {
    add_options_page(
        'JustCreators Bot Setup',
        'JC Bot Setup',
        'manage_options',
        'jc-bot-setup',
        'jc_bot_setup_page'
    );
   
    add_menu_page(
        'Bewerbungen',
        'Bewerbungen',
        'manage_options',
        'jc-bewerbungen',
        'jc_admin_bewerbungen_page',
        'dashicons-list-view',
        26
    );
});
add_action( 'admin_init', function() {
    register_setting( 'jc_bot_settings', 'jc_bot_api_url' );
    register_setting( 'jc_bot_settings', 'jc_bot_api_secret' );
});
function jc_bot_setup_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
   
    if ( isset( $_POST['jc_test_bot'] ) && check_admin_referer( 'jc_test_bot' ) ) {
        $test_result = jc_test_bot_connection();
        echo '<div class="notice notice-' . ($test_result['success'] ? 'success' : 'error') . ' is-dismissible">';
        echo '<p>' . esc_html( $test_result['message'] ) . '</p>';
        echo '</div>';
    }
   
    ?>
    <div class="wrap">
        <h1>🤖 JustCreators Bot Setup</h1>
       
        <div style="background: #fff; padding: 20px; border-radius: 8px; margin-top: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2>📋 Features</h2>
            <ul style="font-size: 15px; line-height: 2;">
                <li>✅ Automatische Social Media Link-Validierung mit Icons</li>
                <li>✅ Auto-Sync bei Löschung (WP → Discord)</li>
                <li>✅ Forum Tags für Bewerbungsstatus</li>
                <li>✅ Slash Commands: <code>/accept</code>, <code>/reject</code></li>
                <li>✅ Status-Sync: Discord → WordPress (✓ aktiv)</li>
                <li>✅ Live Status-Anzeige für Bewerber</li>
                <li>✅ Responsive Design mit Animationen</li>
            </ul>
           
            <form method="post" action="options.php" style="margin-top: 30px;">
                <?php settings_fields( 'jc_bot_settings' ); ?>
               
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="jc_bot_api_url">Bot API URL</label>
                        </th>
                        <td>
                            <input type="url"
                                   id="jc_bot_api_url"
                                   name="jc_bot_api_url"
                                   value="<?php echo esc_attr( jc_get_bot_api_url() ); ?>"
                                   class="regular-text"
                                   placeholder="http://localhost:3000" />
                            <p class="description">URL wo der Bot läuft</p>
                        </td>
                    </tr>
                   
                    <tr>
                        <th scope="row">
                            <label for="jc_bot_api_secret">API Secret</label>
                        </th>
                        <td>
                            <input type="password"
                                   id="jc_bot_api_secret"
                                   name="jc_bot_api_secret"
                                   value="<?php echo esc_attr( jc_get_bot_api_secret() ); ?>"
                                   class="regular-text"
                                   placeholder="Gleiches Secret wie in .env" />
                            <p class="description">Muss mit API_SECRET in .env übereinstimmen</p>
                        </td>
                    </tr>
                </table>
               
                <?php submit_button( 'Einstellungen speichern', 'primary' ); ?>
            </form>
           
            <hr style="margin: 30px 0;">
           
            <form method="post" style="margin-top: 20px;">
                <?php wp_nonce_field( 'jc_test_bot' ); ?>
                <button type="submit" name="jc_test_bot" class="button button-secondary">
                    🧪 Bot-Verbindung testen
                </button>
            </form>
        </div>
    </div>
    <?php
}
// ========================================
// SESSION & DATABASE
// ========================================

// Cleanup für abgelaufene temporäre Bewerbungen
add_action( 'jc_cleanup_temp_applications', function() {
    global $wpdb;
    $temp_table = $wpdb->prefix . 'jc_discord_applications_temp';
    $now = current_time( 'mysql' );
    
    $deleted = $wpdb->query( $wpdb->prepare(
        "DELETE FROM {$temp_table} WHERE expires_at < %s",
        $now
    ) );
    
    if ( $deleted > 0 ) {
        error_log( "JC Cleanup: {$deleted} abgelaufene temporäre Bewerbungen gelöscht" );
    }
});
register_activation_hook( __FILE__, function() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    // ########## START: AKTUALISIERTE TABELLE (v6.17) ##########
    // Haupttabelle für Bewerbungen
    $table_name = $wpdb->prefix . 'jc_discord_applications';
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        discord_id varchar(64) NOT NULL UNIQUE,
        discord_name varchar(255) NOT NULL,
        applicant_name varchar(255) NOT NULL,
        age varchar(20) DEFAULT '',
        social_channels text DEFAULT '',
        social_activity varchar(255) DEFAULT '',
        motivation text DEFAULT '',
        forum_post_id varchar(64) DEFAULT '',
        status varchar(50) DEFAULT 'pending',
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        privacy_accepted_at datetime DEFAULT NULL, -- NEU (v6.17)
        PRIMARY KEY (id),
        KEY status (status),
        KEY discord_id (discord_id)
    ) $charset_collate;";
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
    
    // Temporäre Tabelle für Pending-Bewerbungen (vor Discord-Join)
    $temp_table_name = $wpdb->prefix . 'jc_discord_applications_temp';
    $sql_temp = "CREATE TABLE $temp_table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        discord_id varchar(64) NOT NULL UNIQUE,
        discord_name varchar(255) NOT NULL,
        applicant_name varchar(255) NOT NULL,
        age varchar(20) DEFAULT '',
        social_channels text DEFAULT '',
        social_activity varchar(255) DEFAULT '',
        motivation text DEFAULT '',
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        expires_at datetime NOT NULL,
        privacy_accepted_at datetime DEFAULT NULL, -- NEU (v6.17)
        PRIMARY KEY (id),
        KEY discord_id (discord_id),
        KEY expires_at (expires_at)
    ) $charset_collate;";
    dbDelta( $sql_temp );
    // ########## ENDE: AKTUALISIERTE TABELLE (v6.17) ##########
    
    // Cron Job für Cleanup
    if ( ! wp_next_scheduled( 'jc_cleanup_temp_applications' ) ) {
        wp_schedule_event( time(), 'hourly', 'jc_cleanup_temp_applications' );
    }
});
// ========================================
// DISCORD OAUTH2
// ========================================
add_action( 'init', function() {
    if ( isset( $_GET['discord_oauth'] ) && $_GET['discord_oauth'] == '1' && isset( $_GET['code'] ) ) {
        $code = sanitize_text_field( wp_unslash( $_GET['code'] ) );
        $token = jc_exchange_code_for_token( $code );
       
        if ( $token && isset( $token['access_token'] ) ) {
            $user = jc_get_discord_user( $token['access_token'] );
           
            if ( $user && isset( $user['id'] ) ) {
                $_SESSION['jc_discord_user'] = array(
                    'id' => sanitize_text_field( $user['id'] ),
                    'username' => sanitize_text_field( $user['username'] ),
                    'discriminator' => isset( $user['discriminator'] ) ? sanitize_text_field( $user['discriminator'] ) : '0',
                    'avatar' => isset( $user['avatar'] ) ? sanitize_text_field( $user['avatar'] ) : ''
                );
               
                $redirect = remove_query_arg( array('code','state','discord_oauth'), wp_unslash( $_SERVER['REQUEST_URI'] ) );
                wp_safe_redirect( $redirect );
                exit;
            }
        }
       
        wp_safe_redirect( remove_query_arg( array('code','state'), wp_unslash( $_SERVER['REQUEST_URI'] ) ) . '?jc_oauth_error=1' );
        exit;
    }
} );
function jc_exchange_code_for_token( $code ) {
    $response = wp_remote_post( 'https://discord.com/api/oauth2/token', array(
        'body' => array(
            'client_id' => JC_DISCORD_CLIENT_ID,
            'client_secret' => JC_DISCORD_CLIENT_SECRET,
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => JC_REDIRECT_URI,
        ),
    ) );
   
    if ( is_wp_error( $response ) ) return false;
   
    $body = wp_remote_retrieve_body( $response );
    return json_decode( $body, true );
}
function jc_get_discord_user( $access_token ) {
    $response = wp_remote_get( 'https://discord.com/api/users/@me', array(
        'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
    ) );
   
    if ( is_wp_error( $response ) ) return false;
   
    $body = wp_remote_retrieve_body( $response );
    return json_decode( $body, true );
}
function jc_get_discord_authorize_url() {
    return 'https://discord.com/api/oauth2/authorize?client_id=' . JC_DISCORD_CLIENT_ID .
           '&redirect_uri=' . rawurlencode( JC_REDIRECT_URI ) .
           '&response_type=code&scope=identify';
}
// ========================================
// SOCIAL MEDIA VALIDIERUNG
// ========================================
function jc_validate_social_link( $url ) {
    $url = trim( $url );
   
    // Handles (@username) sind nicht mehr erlaubt
    if ( strpos( $url, '@' ) === 0 ) {
        return array( 'valid' => false, 'error' => 'Handles sind nicht erlaubt. Bitte gib eine vollständige URL ein.' );
    }
   
    if ( ! preg_match( '/^https?:\/\//i', $url ) ) {
        $url = 'https://' . $url;
    }
   
    $parsed = parse_url( $url );
    if ( ! $parsed || ! isset( $parsed['host'] ) ) {
        return array( 'valid' => false, 'error' => 'Ungültige URL' );
    }
   
    $host = strtolower( $parsed['host'] );
    $host = str_replace( 'www.', '', $host );
   
    $platform = 'unknown';
    // Nur YouTube, Twitch und TikTok sind erlaubt
    if ( strpos( $host, 'youtube.com' ) !== false || strpos( $host, 'youtu.be' ) !== false ) {
        $platform = 'youtube';
    } elseif ( strpos( $host, 'tiktok.com' ) !== false ) {
        $platform = 'tiktok';
    } elseif ( strpos( $host, 'twitch.tv' ) !== false ) {
        $platform = 'twitch';
    } else {
        // Alle anderen Plattformen sind nicht erlaubt
        return array( 'valid' => false, 'error' => 'Nur YouTube, Twitch und TikTok sind erlaubt.' );
    }
   
    return array(
        'valid' => true,
        'url' => $url,
        'platform' => $platform,
        'host' => $host
    );
}
function jc_get_platform_icon( $platform ) {
    $icons = array(
        'youtube' => '🎥',
        'tiktok' => '🎵',
        'twitch' => '🎮',
        'twitter' => '🐦',
        'instagram' => '📸',
        'handle' => '👤',
        'unknown' => '🔗'
    );
   
    return isset( $icons[$platform] ) ? $icons[$platform] : $icons['unknown'];
}
// ========================================
// BOT API FUNKTIONEN
// ========================================
function jc_send_application_to_bot( $data ) {
    $api_url = jc_get_bot_api_url();
    $api_secret = jc_get_bot_api_secret();
   
    if ( empty( $api_url ) || empty( $api_secret ) ) {
        error_log( 'JC Plugin: Bot API nicht konfiguriert' );
        return array( 'success' => false, 'message' => 'Bot API nicht konfiguriert' );
    }
   
    $response = wp_remote_post( $api_url . '/api/application', array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_secret,
        ),
        'body' => wp_json_encode( $data ),
        'timeout' => 30
    ) );
   
    if ( is_wp_error( $response ) ) {
        error_log( 'JC Plugin Bot API Error: ' . $response->get_error_message() );
        return array( 'success' => false, 'message' => $response->get_error_message() );
    }
   
    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = json_decode( wp_remote_retrieve_body( $response ), true );
   
    if ( $response_code >= 200 && $response_code < 300 ) {
        return array(
            'success' => true,
            'data' => $response_body
        );
    }
   
    return array(
        'success' => false,
        'message' => isset( $response_body['error'] ) ? $response_body['error'] : 'Unbekannter Fehler'
    );
}
function jc_delete_discord_post( $post_id ) {
    $api_url = jc_get_bot_api_url();
    $api_secret = jc_get_bot_api_secret();
   
    if ( empty( $api_url ) || empty( $api_secret ) || empty( $post_id ) ) {
        return false;
    }
   
    $response = wp_remote_request( $api_url . '/api/application/' . $post_id, array(
        'method' => 'DELETE',
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_secret,
        ),
        'timeout' => 15
    ) );
   
    return ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200;
}
function jc_test_bot_connection() {
    $api_url = jc_get_bot_api_url();
    $api_secret = jc_get_bot_api_secret();
    
    if ( empty( $api_url ) || empty( $api_secret ) ) {
        return array(
            'success' => false,
            'message' => '⚠️ Bot API URL oder Secret nicht konfiguriert'
        );
    }
    
    $response = wp_remote_get( $api_url . '/api/health', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_secret,
        ),
        'timeout' => 10
    ) );
    
    if ( is_wp_error( $response ) ) {
        return array(
            'success' => false,
            'message' => '❌ Bot nicht erreichbar: ' . $response->get_error_message()
        );
    }
    
    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = json_decode( wp_remote_retrieve_body( $response ), true );
    
    if ( $response_code === 200 && isset( $response_body['status'] ) ) {
        return array(
            'success' => true,
            'message' => '✅ Bot ist online und bereit! (' . $response_body['bot_username'] . ')'
        );
    }
    
    return array(
        'success' => false,
        'message' => '❌ Bot antwortet nicht korrekt (Code: ' . $response_code . ')'
    );
}
function jc_check_user_on_temp_server( $discord_id ) {
    $api_url = jc_get_bot_api_url();
    $api_secret = jc_get_bot_api_secret();
    
    if ( empty( $api_url ) || empty( $api_secret ) ) {
        return array( 'success' => false, 'is_on_temp_server' => false );
    }
    
    $response = wp_remote_get( $api_url . '/api/check-user/' . urlencode( $discord_id ), array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $api_secret,
        ),
        'timeout' => 10
    ) );
    
    if ( is_wp_error( $response ) ) {
        return array( 'success' => false, 'is_on_temp_server' => false );
    }
    
    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = json_decode( wp_remote_retrieve_body( $response ), true );
    
    if ( $response_code === 200 && isset( $response_body['is_on_temp_server'] ) ) {
        return array(
            'success' => true,
            'is_on_temp_server' => (bool) $response_body['is_on_temp_server']
        );
    }
    
    return array( 'success' => false, 'is_on_temp_server' => false );
}
// ========================================
// BEWERBUNGSFORMULAR SHORTCODE
// ========================================
add_shortcode( 'discord_application_form', function( $atts ) {
    ob_start();
    ?>
   
    <style>
        @keyframes jc-fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
       
        @keyframes jc-slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
       
        @keyframes jc-success-pop {
            0% { transform: scale(0.8); opacity: 0; }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); opacity: 1; }
        }
       
        @keyframes jc-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        @keyframes jc-dot-bounce {
            0%, 80%, 100% { 
                transform: scale(0);
                opacity: 0.5;
            }
            40% { 
                transform: scale(1);
                opacity: 1;
            }
        }
        
        .jc-bewerbung-wrap {
            background: linear-gradient(135deg, #1e1f26 0%, #2a2c36 100%);
            color: #e1e3e8;
            padding: 50px;
            border-radius: 16px;
            max-width: 900px;
            margin: 50px auto;
            font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, Helvetica, sans-serif;
            box-shadow: 0 10px 40px rgba(0,0,0,0.4);
            animation: jc-fadeIn 0.6s ease-out;
        }
       
        .jc-card {
            background: #2a2c36 !important;
            padding: 35px !important;
            border-radius: 14px !important;
            animation: jc-fadeIn 0.8s ease-out 0.2s both !important;
        }
       
        .jc-h {
            font-size: 28px;
            margin-bottom: 15px;
            color: #f0f0f0;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
       
        /* ########## START: ANPASSUNG ICON (v6.15) ########## */
        .jc-h::before {
            content: ''; /* Wichtig: Inhalt leeren */
            display: inline-block; /* Wichtig für Höhe/Breite */
            width: 32px;  /* Deine gewünschte Breite */
            height: 32px; /* Deine gewünschte Höhe */
            background-image: url('https://just-creators.de/wp-content/uploads/2025/11/cropped-WordPress-Favicon-removebg-preview-2.png');
            background-size: contain; /* Stellt sicher, dass das Bild hineinpasst */
            background-repeat: no-repeat;
            background-position: center;
            margin-top: 4px; /* Verschiebt es 4px nach unten */
        }
        /* ########## ENDE: ANPASSUNG ICON (v6.15) ########## */

        .jc-status-box {
            background: #2a2c36 !important;
            padding: 40px !important;
            border-radius: 14px !important;
            text-align: center !important;
            box-shadow: 0 2px 12px rgba(0,0,0,0.3) !important;
            animation: jc-fadeIn 0.8s ease-out !important;
        }
       
        .jc-status-pending {
            border-left: 6px solid #ffc107;
        }
       
        .jc-status-accepted {
            border-left: 6px solid #4caf50;
        }
       
        .jc-status-rejected {
            border-left: 6px solid #f44336;
        }
       
        .jc-status-icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: jc-pulse 2s infinite;
        }
       
        .jc-status-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 15px;
            color: #f0f0f0;
        }
       
        .jc-status-desc {
            font-size: 17px;
            color: #f0f0f0 !important; /* Auf helles Weiß geändert */
            line-height: 1.8;
            margin: 15px 0;
        }
        
        .jc-status-desc * {
            color: inherit !important;
        }
       
        .jc-status-meta {
            font-size: 14px;
            color: #8a8f9e;
            padding: 25px; /* Padding hierher verschoben */
        }
       
        .jc-discord-btn {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 12px !important;
            padding: 14px 28px !important;
            border-radius: 10px !important;
            background: linear-gradient(135deg, #5865F2 0%, #4752c4 100%) !important;
            color: #fff !important;
            text-decoration: none !important;
            font-weight: 600 !important;
            font-size: 16px !important;
            border: none !important;
            cursor: pointer !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            box-shadow: 0 4px 12px rgba(88, 101, 242, 0.4) !important;
            position: relative !important;
            overflow: hidden !important;
            box-sizing: border-box !important;
        }
       
        .jc-discord-btn::before {
            content: '' !important;
            position: absolute !important;
            top: 0 !important;
            left: -100% !important;
            width: 100% !important;
            height: 100% !important;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent) !important;
            transition: left 0.5s !important;
        }
       
        .jc-discord-btn:hover::before {
            left: 100% !important;
        }
       
        .jc-discord-btn:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 20px rgba(88, 101, 242, 0.6) !important;
            background: linear-gradient(135deg, #6470f3 0%, #5865F2 100%) !important;
        }
       
        .jc-discord-logo {
            width: 24px !important;
            height: 24px !important;
            fill: #fff !important;
        }
       
        .jc-label {
            display: block !important;
            margin-top: 20px !important;
            margin-bottom: 8px !important;
            font-weight: 600 !important;
            color: #f0f0f0 !important;
            font-size: 15px !important;
        }
       
        .jc-input, .jc-textarea {
            width: 100% !important;
            padding: 14px !important;
            margin-top: 8px !important;
            background: #3a3c4a !important;
            border: 2px solid #4a4c5a !important;
            color: #ffffff !important;
            border-radius: 10px !important;
            -webkit-text-fill-color: #ffffff !important;
            font-family: inherit !important;
            font-size: 15px !important;
            transition: all 0.3s ease !important;
            box-sizing: border-box !important;
        }
       
        .jc-input::placeholder, .jc-textarea::placeholder {
            color: #a0a8b8 !important;
        }
       
        .jc-input:focus, .jc-textarea:focus {
            outline: none !important;
            border-color: #5865F2 !important;
            box-shadow: 0 0 0 3px rgba(88, 101, 242, 0.2) !important;
            transform: translateY(-1px) !important;
        }
       
        .jc-social-field-group {
            display: flex !important;
            gap: 12px !important;
            margin-bottom: 15px !important;
            align-items: center !important;
            animation: jc-slideIn 0.3s ease-out !important;
            position: relative !important;
            padding: 12px !important;
            background: rgba(58, 60, 74, 0.2) !important;
            border-radius: 10px !important;
            border: 1px solid rgba(74, 76, 90, 0.3) !important;
            transition: all 0.3s ease !important;
        }
       
        .jc-social-field-group:hover {
            background: rgba(58, 60, 74, 0.35) !important;
            border-color: rgba(88, 101, 242, 0.2) !important;
        }
       
        .jc-social-field-wrapper {
            flex: 1 !important;
            position: relative !important;
        }
       
        .jc-social-field-group input {
            margin-top: 0 !important;
            padding-right: 50px !important;
            background: #2a2c36 !important;
        }
       
        .jc-platform-icon {
            position: absolute !important;
            right: 14px !important;
            top: 50% !important;
            transform: translateY(-50%) !important;
            font-size: 24px !important;
            pointer-events: none !important;
            opacity: 0 !important;
            transition: opacity 0.3s ease !important;
        }
       
        .jc-platform-icon.visible {
            opacity: 1 !important;
        }
       
        .jc-add-social-btn, .jc-remove-social-btn {
            padding: 12px 20px !important;
            border: 2px solid transparent !important;
            border-radius: 10px !important;
            cursor: pointer !important;
            font-weight: 600 !important;
            font-size: 14px !important;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1) !important;
            white-space: nowrap !important;
            box-sizing: border-box !important;
            display: inline-flex !important;
            align-items: center !important;
            gap: 8px !important;
        }
       
        .jc-add-social-btn {
            background: rgba(88, 101, 242, 0.15) !important;
            color: #5865F2 !important;
            margin-top: 15px !important;
            border: 1px solid rgba(88, 101, 242, 0.3) !important;
            box-shadow: none !important;
        }
       
        .jc-add-social-btn::before {
            content: '➕' !important;
            font-size: 16px !important;
        }
       
        .jc-add-social-btn:hover {
            background: linear-gradient(135deg, rgba(88, 101, 242, 0.25) 0%, rgba(88, 101, 242, 0.35) 100%) !important;
            transform: translateY(-2px) !important;
            box-shadow: 0 4px 12px rgba(88, 101, 242, 0.3) !important;
            border-color: rgba(88, 101, 242, 0.5) !important;
        }
       
        .jc-remove-social-btn {
            background: linear-gradient(135deg, rgba(244, 67, 54, 0.15) 0%, rgba(244, 67, 54, 0.25) 100%) !important;
            color: #f44336 !important;
            padding: 12px 16px !important;
            margin-top: 8px !important;
            border: 2px solid rgba(244, 67, 54, 0.3) !important;
            box-shadow: 0 2px 8px rgba(244, 67, 54, 0.15) !important;
            font-size: 18px !important;
            line-height: 1 !important;
            min-width: 44px !important;
        }
       
        .jc-remove-social-btn:hover {
            background: rgba(244, 67, 54, 0.25) !important;
            transform: scale(1.05) !important;
            border-color: rgba(244, 67, 54, 0.5) !important;
        }
       
        .jc-add-social-btn:hover {
            background: rgba(88, 101, 242, 0.25) !important;
            transform: translateY(-1px) !important;
            border-color: rgba(88, 101, 242, 0.5) !important;
        }
       
        .jc-remove-social-btn {
            background: rgba(244, 67, 54, 0.15) !important;
            color: #f44336 !important;
            padding: 10px 14px !important;
            margin: 0 !important;
            border: 1px solid rgba(244, 67, 54, 0.3) !important;
            box-shadow: none !important;
            font-size: 16px !important;
            line-height: 1 !important;
            min-width: 42px !important;
            height: 42px !important;
        }
       
        .jc-remove-social-btn:hover {
            background: rgba(255, 107, 107, 0.3) !important;
        }
       
        .jc-note {
            font-size: 14px !important;
            color: #a0a8b8 !important;
            margin-top: 12px !important;
            padding: 12px !important;
            background: rgba(88, 101, 242, 0.1) !important;
            border-left: 3px solid #5865F2 !important;
            border-radius: 6px !important;
        }
       
        .jc-error {
            color: #ffb4b4 !important;
            background: #3a2323 !important;
            padding: 16px !important;
            border-radius: 10px !important;
            border-left: 4px solid #ff6b6b !important;
            margin: 15px 0 !important;
            animation: jc-fadeIn 0.4s ease-out !important;
        }
       
        .jc-success {
            background: linear-gradient(135deg, #1a3a1a 0%, #2d5a2d 100%) !important;
            padding: 30px !important;
            border-radius: 12px !important;
            margin-top: 20px !important;
            text-align: center !important;
            animation: jc-success-pop 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
            border: 2px solid #4ade80 !important;
            box-shadow: 0 8px 24px rgba(74, 222, 128, 0.2) !important;
        }
       
        .jc-success-icon {
            width: 80px !important;
            height: 80px !important;
            margin: 0 auto 20px !important;
        }
       
        .jc-success-icon svg {
            width: 100% !important;
            height: 100% !important;
        }
       
        .jc-success-icon circle {
            fill: #4ade80 !important;
            animation: jc-success-pop 0.6s cubic-bezier(0.34, 1.56, 0.64, 1) !important;
        }
       
        .jc-success-icon path {
            stroke: #fff !important;
            stroke-width: 3 !important;
            stroke-dasharray: 100 !important;
        }
       
        .jc-success h3 {
            color: #d1f7d1 !important;
            font-size: 24px !important;
            margin: 0 0 15px 0 !important;
            font-weight: 700 !important;
        }
       
        .jc-success p {
            color: #b8e6b8 !important;
            font-size: 16px !important;
            line-height: 1.6 !important;
            margin: 10px 0 !important;
        }
       
        .jc-user-badge {
            display: inline-flex !important;
            align-items: center !important;
            gap: 12px !important;
            background: rgba(88, 101, 242, 0.15) !important;
            padding: 12px 20px !important;
            border-radius: 10px !important;
            margin: 15px 0 !important;
            border: 2px solid rgba(88, 101, 242, 0.3) !important;
        }
       
        .jc-user-badge strong {
            color: #5865F2 !important;
            font-size: 16px !important;
        }
        
        .jc-field-error {
            color: #ff6b6b !important;
            font-size: 13px !important;
            margin-top: 6px !important;
            display: block !important;
            padding-left: 4px !important;
            animation: jc-fadeIn 0.3s ease-out !important;
        }
        
        .jc-input.error, .jc-textarea.error {
            border-color: #ff6b6b !important;
            box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.2) !important;
        }
        
        .jc-waiting-screen {
            background: linear-gradient(135deg, #1e1f26 0%, #2a2c36 100%);
            color: #e1e3e8;
            padding: 50px;
            border-radius: 16px;
            max-width: 900px;
            margin: 50px auto;
            font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, Helvetica, sans-serif;
            box-shadow: 0 10px 40px rgba(0,0,0,0.4);
            animation: jc-fadeIn 0.6s ease-out;
        }
        
        .jc-waiting-content {
            text-align: center;
            padding: 40px 20px;
        }
        
        .jc-waiting-icon {
            font-size: 80px;
            margin-bottom: 20px;
            animation: jc-pulse 2s infinite;
        }
        
        .jc-waiting-title {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 15px;
            color: #f0f0f0;
        }
        
        .jc-waiting-desc {
            font-size: 17px;
            color: #a0a8b8;
            line-height: 1.8;
            margin: 15px 0 30px;
        }
        
        .jc-waiting-animation {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            margin: 30px 0;
        }
        
        .jc-dot {
            width: 12px;
            height: 12px;
            background: #5865F2;
            border-radius: 50%;
            animation: jc-dot-bounce 1.4s infinite ease-in-out;
        }
        
        .jc-dot:nth-child(1) {
            animation-delay: -0.32s;
        }
        
        .jc-dot:nth-child(2) {
            animation-delay: -0.16s;
        }
        
        .jc-dot:nth-child(3) {
            animation-delay: 0s;
        }
        
        .jc-discord-invite-box {
            margin: 30px 0;
        }
        
        .jc-waiting-btn {
            font-size: 18px;
            padding: 16px 32px;
            margin: 20px 0;
        }
        
        .jc-waiting-hint {
            margin-top: 30px;
            padding: 15px;
            background: rgba(88, 101, 242, 0.1);
            border-radius: 8px;
            border-left: 3px solid #5865F2;
            color: #a0a8b8;
            font-size: 14px;
            line-height: 1.6;
        }
        
        @media (max-width: 768px) {
            .jc-bewerbung-wrap {
                padding: 30px 20px !important;
                margin: 20px auto !important;
            }
           
            .jc-card {
                padding: 25px 20px !important;
            }
           
            .jc-h {
                font-size: 22px !important;
            }
           
            .jc-social-field-group {
                flex-direction: column !important;
            }
            
            .jc-waiting-screen {
                padding: 30px 20px !important;
                margin: 20px auto !important;
            }
            
            .jc-waiting-title {
                font-size: 24px !important;
            }
            
            .jc-waiting-icon {
                font-size: 64px !important;
            }
        }
    </style>
   
    <div class="jc-bewerbung-wrap">
        <div class="jc-card">
            <h2 class="jc-h">Bewerbung — JustCreators</h2>
           
            <?php
            $discord_user = isset( $_SESSION['jc_discord_user'] ) ? $_SESSION['jc_discord_user'] : false;
           
            // NICHT ANGEMELDET
            if ( ! $discord_user ) {
                $auth_url = jc_get_discord_authorize_url();
                ?>
                <p style="line-height: 1.7; margin-bottom: 20px;">
                    Es ist soweit, die <strong>2. Staffel von JustCreators</strong> beginnt. Wir werden uns innerhalb 24-48 Stunden bei dir über Discord melden.
                    <br><br>
                    <strong>Wichtig:</strong> Stelle sicher, dass du Direktnachrichten von Servermitgliedern aktiviert hast, sowie Nachrichtenanfragen <strong>deaktiviert</strong> hast.
                    <br><br>
                    Wir wünschen dir viel Glück!<br>
                    <em>Dein JustCreators Team</em>
                </p>
               
                <?php if ( isset( $_GET['jc_oauth_error'] ) ): ?>
                    <div class="jc-error">❌ <strong>Fehler bei der Discord-Authentifizierung.</strong> Bitte versuche es erneut.</div>
                <?php endif; ?>
               
                <a class="jc-discord-btn" href="<?php echo esc_url( $auth_url ); ?>">
                    <svg class="jc-discord-logo" viewBox="0 0 71 55" xmlns="http://www.w3.org/2000/svg">
                        <path d="M60.1045 4.8978C55.5792 2.8214 50.7265 1.2916 45.6527 0.41542C45.5603 0.39851 45.468 0.440769 45.4204 0.525289C44.7963 1.6353 44.105 3.0834 43.6209 4.2216C38.1637 3.4046 32.7345 3.4046 27.3892 4.2216C26.905 3.0581 26.1886 1.6353 25.5617 0.525289C25.5141 0.443589 25.4218 0.40133 25.3294 0.41542C20.2584 1.2888 15.4057 2.8186 10.8776 4.8978C10.8384 4.9147 10.8048 4.9429 10.7825 4.9795C1.57795 18.7309 -0.943561 32.1443 0.293408 45.3914C0.299005 45.4562 0.335386 45.5182 0.385761 45.5576C6.45866 50.0174 12.3413 52.7249 18.1147 54.5195C18.2071 54.5477 18.305 54.5139 18.3638 54.4378C19.7295 52.5728 20.9469 50.6063 21.9907 48.5383C22.0523 48.4172 21.9935 48.2735 21.8676 48.2256C19.9366 47.4931 18.0979 46.6 16.3292 45.5858C16.1893 45.5041 16.1781 45.304 16.3068 45.2082C16.679 44.9293 17.0513 44.6391 17.4067 44.3461C17.471 44.2926 17.5606 44.2813 17.6362 44.3151C29.2558 49.6202 41.8354 49.6202 53.3179 44.3151C53.3935 44.2785 53.4831 44.2898 53.5502 44.3433C53.9057 44.6363 54.2779 44.9293 54.6529 45.2082C54.7816 45.304 54.7732 45.5041 54.6333 45.5858C52.8646 46.6197 51.0259 47.4931 49.0921 48.2228C48.9662 48.2707 48.9102 48.4172 48.9718 48.5383C50.038 50.6034 51.2554 52.5699 52.5959 54.435C52.6519 54.5139 52.7526 54.5477 52.845 54.5195C58.6464 52.7249 64.529 50.0174 70.6019 45.5576C70.6551 45.5182 70.6887 45.459 70.6943 45.3942C72.1747 30.0791 68.2147 16.7757 60.1968 4.9823C60.1772 4.9429 60.1437 4.9147 60.1045 4.8978ZM23.7259 37.3253C20.2276 37.3253 17.3451 34.1136 17.3451 30.1693C17.3451 26.225 20.1717 23.0133 23.7259 23.0133C27.308 23.0133 30.1626 26.2532 30.1066 30.1693C30.1066 34.1136 27.28 37.3253 23.7259 37.3253ZM47.3178 37.3253C43.8196 37.3253 40.9371 34.1136 40.9371 30.1693C40.9371 26.225 43.7636 23.0133 47.3178 23.0133C50.9 23.0133 53.7545 26.2532 53.6986 30.1693C53.6986 34.1136 50.9 37.3253 47.3178 37.3253Z"/>
                    </svg>
                    Mit Discord anmelden
                </a>
                <?php
                echo '</div></div>';
                return ob_get_clean();
            }
           
            $discord_id = sanitize_text_field( $discord_user['id'] );
            $discord_display = esc_html( $discord_user['username'] );
           
            // STATUS PRÜFEN
            $application = jc_get_application_status( $discord_id );
           
            // BEWERBUNG EXISTIERT - STATUS ANZEIGEN
            if ( $application ) {
                $status_config = array(
                    'pending' => array(
                        'icon' => '⏳',
                        'title' => 'Bewerbung in Bearbeitung',
                        'desc' => 'Deine Bewerbung wird gerade von unserem Team geprüft. Wir melden uns innerhalb von <strong>1-2 Tagen</strong> bei dir über Discord!',
                        'class' => 'jc-status-pending'
                    ),
                    'accepted' => array(
                        'icon' => '🎉',
                        'title' => 'Bewerbung angenommen!',
                        'desc' => 'Herzlichen Glückwunsch! Du bist jetzt Teil von <strong>JustCreators Season 2</strong>! Unser Team wird sich in Kürze bei dir melden.',
                        'class' => 'jc-status-accepted'
                    ),
                    'rejected' => array(
                        'icon' => '😔',
                        'title' => 'Bewerbung abgelehnt',
                        'desc' => 'Leider können wir deine Bewerbung diesmal nicht berücksichtigen. Vielen Dank für dein Interesse an JustCreators!',
                        'class' => 'jc-status-rejected'
                    )
                );
               
                $current = isset( $status_config[$application->status] ) ? $status_config[$application->status] : $status_config['pending'];
                ?>
               
                <div class="jc-status-box <?php echo $current['class']; ?>">
                    <div class="jc-status-icon"><?php echo $current['icon']; ?></div>
                    <h2 class="jc-status-title"><?php echo $current['title']; ?></h2>
                    <p class="jc-status-desc"><?php echo $current['desc']; ?></p>
                   
                    <div class="jc-status-info-wrapper" style="margin-top: 30px; border-radius: 10px; overflow: hidden; background: rgba(0,0,0,0.15); text-align: left;"> 
                        <div class="jc-status-meta">
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                <div style="background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px;">
                                    <div style="font-size: 12px; opacity: 0.7; margin-bottom: 5px;">📝 BEWERBER</div>
                                    <div style="font-weight: 600; font-size: 16px;"><?php echo esc_html( $application->applicant_name ); ?></div>
                                    <div style="font-size: 13px; opacity: 0.8; margin-top: 4px;"><?php echo $discord_display; ?></div>
                                </div>
                               
                                <div style="background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px;">
                                    <div style="font-size: 12px; opacity: 0.7; margin-bottom: 5px;">📅 EINGEREICHT AM</div>
                                    <div style="font-weight: 600; font-size: 16px;"><?php echo esc_html( date_i18n( 'd.m.Y', strtotime( $application->created_at ) ) ); ?></div>
                                    <div style="font-size: 13px; opacity: 0.8; margin-top: 4px;"><?php echo esc_html( date_i18n( 'H:i', strtotime( $application->created_at ) ) ); ?> Uhr</div>
                                </div>
                               
                                <div style="background: rgba(0,0,0,0.2); padding: 15px; border-radius: 8px;">
                                    <div style="font-size: 12px; opacity: 0.7; margin-bottom: 5px;">📊 STATUS</div>
                                    <div style="font-weight: 600; font-size: 16px; text-transform: uppercase;">
                                        <?php
                                        $status_names = array(
                                            'pending' => '⏳ In Bearbeitung',
                                            'accepted' => '✅ Angenommen',
                                            'rejected' => '❌ Abgelehnt'
                                        );
                                        echo isset($status_names[$application->status]) ? $status_names[$application->status] : $application->status;
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                   
                        <?php if ( $application->status === 'accepted' ): ?>
                            <div style="padding: 20px; border-left: 4px solid #4ade80; border-top: 1px solid rgba(255, 255, 255, 0.1);">
                                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 10px;">
                                    <span style="font-size: 24px;">🎊</span>
                                    <strong style="font-size: 16px; color: #f0f0f0;">Nächste Schritte</strong>
                                </div>
                                <p style="color: #a0a8b8; font-size: 14px; line-height: 1.6; margin: 0;">
                                    Willkommen im Projekt! Klicke nun unten um die Regeln zu akzeptieren so kannst du;
                                    <br>• Alle Wichtigen Informationen bekommen
                                    <br>• Dich über das Regelwerk informieren
                                    <br>• Im Projekt mitspielen und Spaß haben
                                    <br><br>
                                    <strong>Wir freuen deine Teilnhame im Projekt! 🚀</strong>
                                </p>
                            </div>
                        <?php endif; ?>

                    </div>
                   
                    <?php if ( $application->status === 'accepted' ): ?>
                        <a href="https://just-creators.de/regeln" class="jc-discord-btn" style="margin-top: 25px;">
                            ✅ Akzeptiere die Regeln
                        </a>
                    <?php else: ?>
                        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="jc-discord-btn" style="margin-top: 25px; background: #3a3c4a !important; box-shadow: none !important;">
                            🏠 Zurück zur Startseite
                        </a>
                    <?php endif; ?>
                   
                </div>
               
                <?php
                echo '</div></div>';
                return ob_get_clean();
            }
           
            // ########## START: AKTUALISIERTE FORMULAR-VERARBEITUNG (v6.17) ##########
            // Fügt 'privacy_accepted_at' zur Verarbeitung hinzu
            $form_submitted = false;
            $validation_errors = array();
           
            if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['jc_bewerbung_nonce'] ) ) {
                $nonce = sanitize_text_field( wp_unslash( $_POST['jc_bewerbung_nonce'] ) );
                if ( ! wp_verify_nonce( $nonce, 'jc_bewerbung_action' ) ) {
                    error_log( "JC Nonce: FAILED - Nonce={$nonce}, User={$discord_id}" );
                    $validation_errors[] = 'Sicherheitsüberprüfung fehlgeschlagen. Bitte lade die Seite neu (F5) und versuche es erneut.';
                
                // NEUE PRÜFUNG (v6.17)
                } elseif ( ! isset( $_POST['jc_privacy_accept'] ) ) {
                    $validation_errors[] = 'Du musst die Datenschutzerklärung akzeptieren, um fortzufahren.';
                
                } else {
                    $applicant_name = sanitize_text_field( wp_unslash( $_POST['applicant_name'] ) );
                    $age = sanitize_text_field( wp_unslash( $_POST['age'] ) );
                    $social_activity = sanitize_text_field( wp_unslash( $_POST['social_activity'] ) );
                    $motivation = sanitize_textarea_field( wp_unslash( $_POST['motivation'] ) );
                   
                    $social_channels = array();
                    if ( isset( $_POST['social_channels'] ) && is_array( $_POST['social_channels'] ) ) {
                        foreach ( $_POST['social_channels'] as $channel ) {
                            $channel_clean = sanitize_text_field( wp_unslash( $channel ) );
                            if ( ! empty( $channel_clean ) ) {
                                $validation = jc_validate_social_link( $channel_clean );
                                if ( $validation['valid'] ) {
                                    $social_channels[] = array(
                                        'url' => $validation['url'],
                                        'platform' => $validation['platform']
                                    );
                                } else {
                                    $validation_errors[] = 'Ungültiger Link: ' . esc_html( $channel_clean );
                                }
                            }
                        }
                    }
                   
                    if ( empty( $social_channels ) ) {
                        $validation_errors[] = 'Bitte gib mindestens einen Social Media Kanal an.';
                    }
                   
                    if ( empty( $validation_errors ) ) {
                        global $wpdb;
                        $temp_table = $wpdb->prefix . 'jc_discord_applications_temp';
                        
                        $social_channels_json = wp_json_encode( $social_channels );
                        
                        // Bewerbung in temporäre Tabelle speichern (20 Minuten Gültigkeit)
                        $expires_at = date( 'Y-m-d H:i:s', time() + (20 * 60) ); // 20 Minuten
                        $privacy_accepted_at = current_time( 'mysql' ); // NEU (v6.17)
                        
                        if ( empty($discord_id) ) {
                            error_log("JC: ❌ FEHLER: Discord ID ist LEER. Session-Problem besteht weiterhin. Abbruch.");
                            $validation_errors[] = 'Deine Discord-Sitzung ist abgelaufen. Bitte lade die Seite neu und melde dich erneut an.';
                        } else {
                            
                            // SCHRITT 1: Lösche eine eventuell vorhandene, alte temporäre Bewerbung
                            $wpdb->delete(
                                $temp_table,
                                array( 'discord_id' => $discord_id ),
                                array( '%s' )
                            );
                            
                            // SCHRITT 2: Füge die neue Bewerbung ein
                            $inserted = $wpdb->insert( $temp_table, array(
                                'discord_id' => $discord_id,
                                'discord_name' => $discord_display,
                                'applicant_name' => $applicant_name,
                                'age' => $age,
                                'social_channels' => $social_channels_json,
                                'social_activity' => $social_activity,
                                'motivation' => $motivation,
                                'expires_at' => $expires_at,
                                'privacy_accepted_at' => $privacy_accepted_at // <-- NEU (v6.17)
                            ), array(
                                '%s','%s','%s','%s','%s','%s','%s','%s',
                                '%s' // <-- NEU (v6.17) für privacy_accepted_at
                            ) );
                            
                            if ( $inserted ) {
                                error_log("JC: ✅ Neue temp Bewerbung für $discord_id gespeichert. Zeige Warte-Bildschirm.");
                                
                                $form_submitted = true;
                                $waiting_for_discord = true;
                                $application_data = array(
                                    'discord_id' => $discord_id,
                                    'discord_name' => $discord_display,
                                    'applicant_name' => $applicant_name,
                                    'age' => $age,
                                    'social_channels' => $social_channels,
                                    'social_activity' => $social_activity,
                                    'motivation' => $motivation,
                                    'temp_id' => $wpdb->insert_id
                                );
                                $_SESSION['jc_pending_application'] = $application_data;
                            } else {
                                error_log("JC: ❌ DB INSERT FEHLGESCHLAGEN. DB-Fehler: " . $wpdb->last_error);
                                error_log("JC: ❌ FEHLER: Lade Formular neu.");
                            }
                        }
                    }
                }
            }
            // ########## ENDE: AKTUALISIERTE FORMULAR-VERARBEITUNG (v6.17) ##########
           
            if ( ! empty( $validation_errors ) ) {
                foreach ( $validation_errors as $error ) {
                    echo '<div class="jc-error">❌ ' . esc_html( $error ) . '</div>';
                }
            }
           
            if ( $form_submitted && isset( $waiting_for_discord ) && $waiting_for_discord ) {
                // Warte-Screen mit Animation
                ?>
                <div class="jc-waiting-screen">
                    <div class="jc-waiting-content">
                        <div class="jc-waiting-icon">🔗</div>
                        <h2 class="jc-waiting-title">Warte auf Discord-Join</h2>
                        <p class="jc-waiting-desc">
                            Bitte join unserem temporären Discord-Server, damit wir deine Bewerbung weiterverarbeiten können.
                        </p>
                        
                        <div class="jc-waiting-animation">
                            <span class="jc-dot"></span>
                            <span class="jc-dot"></span>
                            <span class="jc-dot"></span>
                        </div>
                        
                        <div class="jc-discord-invite-box">
                            <a href="<?php echo esc_url( JC_TEMP_DISCORD_INVITE ); ?>" target="_blank" class="jc-discord-btn jc-waiting-btn">
                                <svg class="jc-discord-logo" viewBox="0 0 71 55" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M60.1045 4.8978C55.5792 2.8214 50.7265 1.2916 45.6527 0.41542C45.5603 0.39851 45.468 0.440769 45.4204 0.525289C44.7963 1.6353 44.105 3.0834 43.6209 4.2216C38.1637 3.4046 32.7345 3.4046 27.3892 4.2216C26.905 3.0581 26.1886 1.6353 25.5617 0.525289C25.5141 0.443589 25.4218 0.40133 25.3294 0.41542C20.2584 1.2888 15.4057 2.8186 10.8776 4.8978C10.8384 4.9147 10.8048 4.9429 10.7825 4.9795C1.57795 18.7309 -0.943561 32.1443 0.293408 45.3914C0.299005 45.4562 0.335386 45.5182 0.385761 45.5576C6.45866 50.0174 12.3413 52.7249 18.1147 54.5195C18.2071 54.5477 18.305 54.5139 18.3638 54.4378C19.7295 52.5728 20.9469 50.6063 21.9907 48.5383C22.0523 48.4172 21.9935 48.2735 21.8676 48.2256C19.9366 47.4931 18.0979 46.6 16.3292 45.5858C16.1893 45.5041 16.1781 45.304 16.3068 45.2082C16.679 44.9293 17.0513 44.6391 17.4067 44.3461C17.471 44.2926 17.5606 44.2813 17.6362 44.3151C29.2558 49.6202 41.8354 49.6202 53.3179 44.3151C53.3935 44.2785 53.4831 44.2898 53.5502 44.3433C53.9057 44.6363 54.2779 44.9293 54.6529 45.2082C54.7816 45.304 54.7732 45.5041 54.6333 45.5858C52.8646 46.6197 51.0259 47.4931 49.0921 48.2228C48.9662 48.2707 48.9102 48.4172 48.9718 48.5383C50.038 50.6034 51.2554 52.5699 52.5959 54.435C52.6519 54.5139 52.7526 54.5477 52.845 54.5195C58.6464 52.7249 64.529 50.0174 70.6019 45.5576C70.6551 45.5182 70.6887 45.459 70.6943 45.3942C72.1747 30.0791 68.2147 16.7757 60.1968 4.9823C60.1772 4.9429 60.1437 4.9147 60.1045 4.8978ZM23.7259 37.3253C20.2276 37.3253 17.3451 34.1136 17.3451 30.1693C17.3451 26.225 20.1717 23.0133 23.7259 23.0133C27.308 23.0133 30.1626 26.2532 30.1066 30.1693C30.1066 34.1136 27.28 37.3253 23.7259 37.3253ZM47.3178 37.3253C43.8196 37.3253 40.9371 34.1136 40.9371 30.1693C40.9371 26.225 43.7636 23.0133 47.3178 23.0133C50.9 23.0133 53.7545 26.2532 53.6986 30.1693C53.6986 34.1136 50.9 37.3253 47.3178 37.3253Z"/>
                                </svg>
                                Discord Server beitreten
                            </a>
                        </div>
                    </div>
                </div>
                
                <script>
                (function() {
                    const discordId = '<?php echo esc_js( $discord_id ); ?>';
                    const applicationData = <?php echo wp_json_encode( $application_data ); ?>;
                    let checkCount = 0;
                    const maxChecks = 600; // 20 Minuten (600 * 2 Sekunden)
                    
                    function checkDiscordJoin() {
                        checkCount++;
                        
                        if (checkCount > maxChecks) {
                            document.querySelector('.jc-waiting-content').innerHTML = `
                                <div class="jc-waiting-icon" style="font-size: 64px;">⏱️</div>
                                <h2 class="jc-waiting-title">Zeitüberschreitung</h2>
                                <p class="jc-waiting-desc">
                                    Die Wartezeit ist abgelaufen. Bitte lade die Seite neu und versuche es erneut.
                                </p>
                                <a href="<?php echo esc_url( remove_query_arg( 'jc_waiting' ) ); ?>" class="jc-discord-btn">
                                    Seite neu laden
                                </a>
                            `;
                            return;
                        }
                        
                        fetch('<?php echo esc_url( rest_url( 'jc/v1/check-discord-join' ) ); ?>', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                discord_id: discordId
                            })
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success && data.is_on_temp_server) {
                                // User ist auf Temp-Server! Bewerbung an Bot senden
                                sendApplicationToBot();
                            } else {
                                // Noch nicht auf Server, weiter warten
                                setTimeout(checkDiscordJoin, 2000); // Alle 2 Sekunden prüfen
                            }
                        })
                        .catch(error => {
                            console.error('Error checking Discord join:', error);
                            setTimeout(checkDiscordJoin, 3000); // Bei Fehler alle 3 Sekunden
                        });
                    }
                    
                    function sendApplicationToBot() {
                        // Zeige "Verarbeite Bewerbung..." Nachricht
                        document.querySelector('.jc-waiting-content').innerHTML = `
                            <div class="jc-waiting-icon" style="font-size: 64px;">⚙️</div>
                            <h2 class="jc-waiting-title">Verarbeite Bewerbung</h2>
                            <p class="jc-waiting-desc">
                                Perfekt! Du bist auf dem Server. Deine Bewerbung wird jetzt verarbeitet...
                            </p>
                            <div class="jc-waiting-animation">
                                <span class="jc-dot"></span>
                                <span class="jc-dot"></span>
                                <span class="jc-dot"></span>
                            </div>
                        `;
                        
                        fetch('<?php echo esc_url( rest_url( 'jc/v1/send-application' ) ); ?>', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify(applicationData)
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                // Erfolg! Zeige Erfolgsmeldung
                                showSuccessMessage();
                            } else {
                                // Fehler
                                document.querySelector('.jc-waiting-content').innerHTML = `
                                    <div class="jc-error" style="margin: 20px 0;">
                                        ❌ Fehler beim Verarbeiten der Bewerbung: ${data.message || 'Unbekannter Fehler'}
                                    </div>
                                    <a href="<?php echo esc_url( remove_query_arg( 'jc_waiting' ) ); ?>" class="jc-discord-btn">
                                        Seite neu laden
                                    </a>
                                `;
                            }
                        })
                        .catch(error => {
                            console.error('Error sending application:', error);
                            document.querySelector('.jc-waiting-content').innerHTML = `
                                <div class="jc-error" style="margin: 20px 0;">
                                    ❌ Fehler beim Senden der Bewerbung. Bitte versuche es erneut.
                                </div>
                                <a href="<?php echo esc_url( remove_query_arg( 'jc_waiting' ) ); ?>" class="jc-discord-btn">
                                    Seite neu laden
                                </a>
                            `;
                        });
                    }
                    
                    function showSuccessMessage() {
                        document.querySelector('.jc-waiting-screen').innerHTML = `
                            <div class="jc-success">
                                <div class="jc-success-icon">
                                    <svg viewBox="0 0 100 100">
                                        <circle cx="50" cy="50" r="45"/>
                                        <path d="M30 50 L45 65 L70 35" fill="none"/>
                                    </svg>
                                </div>
                                <h3>🎉 Bewerbung erfolgreich!</h3>
                                <p><strong>Vielen Dank für deine Bewerbung!</strong></p>
                                <p>📬 Wir melden uns innerhalb von <strong>1-2 Tagen</strong> bei dir via Discord.</p>
                            </div>
                        `;
                    }
                    
                    // Starte Prüfung nach 2 Sekunden
                    setTimeout(checkDiscordJoin, 2000);
                })();
                </script>
                <?php
            } elseif ( $form_submitted && ! isset( $waiting_for_discord ) ) {
                // Fallback: Alte Erfolgsmeldung (sollte nicht mehr vorkommen)
                ?>
                <div class="jc-success">
                    <div class="jc-success-icon">
                        <svg viewBox="0 0 100 100">
                            <circle cx="50" cy="50" r="45"/>
                            <path d="M30 50 L45 65 L70 35" fill="none"/>
                        </svg>
                    </div>
                    <h3>🎉 Bewerbung erfolgreich!</h3>
                    <p><strong>Vielen Dank für deine Bewerbung!</strong></p>
                    <p>📬 Wir melden uns innerhalb von <strong>1-2 Tagen</strong> bei dir via Discord.</p>
                </div>
                <?php
            } else {
                // FORMULAR
                ?>
                <p style="line-height: 1.7; margin-bottom: 20px;">
                    Fülle das Formular aus um dich bei der <strong>2. Season von JustCreators</strong> zu bewerben.
                </p>
               
                <div class="jc-user-badge">
                    👤 Angemeldet als <strong><?php echo $discord_display; ?></strong>
                </div>
               
                <form method="post" id="jc-application-form">
                    <?php wp_nonce_field( 'jc_bewerbung_action', 'jc_bewerbung_nonce' ); ?>
                   
                    <label class="jc-label">📝 Name *</label>
                    <input class="jc-input" type="text" name="applicant_name" id="jc-name-input" required placeholder="Dein vollständiger Name" />
                    <span class="jc-field-error" id="jc-name-error" style="display: none;"></span>
                   
                    <label class="jc-label">🎂 Alter *</label>
                    <input class="jc-input" type="number" name="age" id="jc-age-input" required placeholder="z. B. 18" />
                    <span class="jc-field-error" id="jc-age-error" style="display: none;"></span>
                   
                    <label class="jc-label">🌐 Social Media Kanäle *</label>
                    <div class="jc-note" style="margin-top: 8px;">
                        Gib deine Social Media Kanäle an. <strong>Nur YouTube, Twitch und TikTok sind erlaubt.</strong> Die Links werden automatisch validiert.
                    </div>
                    <div class="jc-social-fields" id="jc-social-fields">
                        <div class="jc-social-field-group">
                            <div class="jc-social-field-wrapper">
                                <input class="jc-input jc-social-input" type="text" name="social_channels[]" required placeholder="z. B. youtube.com/@username" data-index="0" />
                                <span class="jc-platform-icon" data-index="0"></span>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="jc-add-social-btn" onclick="jcAddSocialField()">
                        Weiteren Kanal hinzufügen
                    </button>
                    <span class="jc-field-error" id="jc-social-error" style="display: none;"></span>
                    
                    <label class="jc-label">📊 Wie aktiv bist du? *</label>
                    <input class="jc-input" type="text" name="social_activity" id="jc-activity-input" placeholder="z. B. täglich" required />
                    <span class="jc-field-error" id="jc-activity-error" style="display: none;"></span>
                    <label class="jc-label">💭 Warum JustCreators? *</label>
                    <textarea class="jc-textarea" name="motivation" id="jc-motivation-input" rows="6" required placeholder="Erzähle uns..."></textarea>
                    <span class="jc-field-error" id="jc-motivation-error" style="display: none;"></span>

                   
                    <div class="jc-note">
                        ℹ️ <strong>Hinweis:</strong> Überprüfe deine Bewerbung bevor du sie einreichst!
                    </div>
                   
                    <label style="display: flex; align-items: flex-start; gap: 15px; margin: 25px 0; cursor: pointer; padding: 20px; background: rgba(88, 101, 242, 0.08); border-radius: 10px; border: 1px solid rgba(88, 101, 242, 0.2);">
                        <input type="checkbox" name="jc_privacy_accept" required style="width: 24px; height: 24px; cursor: pointer; margin-top: 2px; flex-shrink: 0;" />
                        <span style="color: #dcddde; font-size: 15px; line-height: 1.7;">
                            <strong style="color: #f0f0f0; font-size: 16px;">Ich habe die <a href="<?php echo esc_url( home_url('/datenschutz') ); ?>" target="_blank" style="color: #5865F2; text-decoration: none;">Datenschutzerklärung</a> gelesen und akzeptiere sie.</strong><br>
                            <small style="color: #a0a8b8; font-size: 14px;">
                                Mir ist bewusst, dass meine Bewerbungsdaten (inkl. Discord-ID) zur Prüfung an das JustCreators-Team übermittelt werden.
                            </small>
                        </span>
                    </label>
                    <button type="submit" class="jc-discord-btn" style="width: 100%;">
                        <svg class="jc-discord-logo" viewBox="0 0 71 55" xmlns="http://www.w3.org/2000/svg">
                            <path d="M60.1045 4.8978C55.5792 2.8214 50.7265 1.2916 45.6527 0.41542C45.5603 0.39851 45.468 0.440769 45.4204 0.525289C44.7963 1.6353 44.105 3.0834 43.6209 4.2216C38.1637 3.4046 32.7345 3.4046 27.3892 4.2216C26.905 3.0581 26.1886 1.6353 25.5617 0.525289C25.5141 0.443589 25.4218 0.40133 25.3294 0.41542C20.2584 1.2888 15.4057 2.8186 10.8776 4.8978C10.8384 4.9147 10.8048 4.9429 10.7825 4.9795C1.57795 18.7309 -0.943561 32.1443 0.293408 45.3914C0.299005 45.4562 0.335386 45.5182 0.385761 45.5576C6.45866 50.0174 12.3413 52.7249 18.1147 54.5195C18.2071 54.5477 18.305 54.5139 18.3638 54.4378C19.7295 52.5728 20.9469 50.6063 21.9907 48.5383C22.0523 48.4172 21.9935 48.2735 21.8676 48.2256C19.9366 47.4931 18.0979 46.6 16.3292 45.5858C16.1893 45.5041 16.1781 45.304 16.3068 45.2082C16.679 44.9293 17.0513 44.6391 17.4067 44.3461C17.471 44.2926 17.5606 44.2813 17.6362 44.3151C29.2558 49.6202 41.8354 49.6202 53.3179 44.3151C53.3935 44.2785 53.4831 44.2898 53.5502 44.3433C53.9057 44.6363 54.2779 44.9293 54.6529 45.2082C54.7816 45.304 54.7732 45.5041 54.6333 45.5858C52.8646 46.6197 51.0259 47.4931 49.0921 48.2228C48.9662 48.2707 48.9102 48.4172 48.9718 48.5383C50.038 50.6034 51.2554 52.5699 52.5959 54.435C52.6519 54.5139 52.7526 54.5477 52.845 54.5195C58.6464 52.7249 64.529 50.0174 70.6019 45.5576C70.6551 45.5182 70.6887 45.459 70.6943 45.3942C72.1747 30.0791 68.2147 16.7757 60.1968 4.9823C60.1772 4.9429 60.1437 4.9147 60.1045 4.8978ZM23.7259 37.3253C20.2276 37.3253 17.3451 34.1136 17.3451 30.1693C17.3451 26.225 20.1717 23.0133 23.7259 23.0133C27.308 23.0133 30.1626 26.2532 30.1066 30.1693C30.1066 34.1136 27.28 37.3253 23.7259 37.3253ZM47.3178 37.3253C43.8196 37.3253 40.9371 34.1136 40.9371 30.1693C40.9371 26.225 43.7636 23.0133 47.3178 23.0133C50.9 23.0133 53.7545 26.2532 53.6986 30.1693C53.6986 34.1136 50.9 37.3253 47.3178 37.3253Z"/>
                        </svg>
                        Bewerbung jetzt absenden
                    </button>
                </form>
               
                <script>
                let socialFieldCount = 1;
                const maxSocialFields = 5;
               
                const platformIcons = {
                    'youtube': '🎥',
                    'tiktok': '🎵',
                    'twitch': '🎮',
                    'twitter': '🐦',
                    'instagram': '📸',
                    'handle': '👤',
                    'unknown': '🔗'
                };
                function detectPlatform(url) {
                    url = url.toLowerCase();
                    if (url.includes('youtube.com') || url.includes('youtu.be')) return 'youtube';
                    if (url.includes('tiktok.com')) return 'tiktok';
                    if (url.includes('twitch.tv')) return 'twitch';
                    if (url.includes('twitter.com') || url.includes('x.com')) return 'twitter';
                    if (url.includes('instagram.com')) return 'instagram';
                    if (url.startsWith('@')) return 'handle';
                    return 'unknown';
                }
                function updatePlatformIcon(input, index) {
                    const url = input.value.trim();
                    const iconElement = document.querySelector('.jc-platform-icon[data-index="' + index + '"]');
                    if (!iconElement) return;
                   
                    if (url.length > 3) {
                        const platform = detectPlatform(url);
                        iconElement.textContent = platformIcons[platform];
                        iconElement.classList.add('visible');
                    } else {
                        iconElement.classList.remove('visible');
                    }
                }
                document.addEventListener('input', function(e) {
                    if (e.target.classList.contains('jc-social-input')) {
                        const index = e.target.getAttribute('data-index');
                        updatePlatformIcon(e.target, index);
                    }
                });
                
                function jcAddSocialField() {
                    if (socialFieldCount >= maxSocialFields) {
                        alert('Du kannst maximal 5 Social Media Kanäle hinzufügen.');
                        return;
                    }
                   
                    const container = document.getElementById('jc-social-fields');
                    if (!container) {
                        console.error('Container jc-social-fields nicht gefunden');
                        return;
                    }
                    
                    const fieldGroup = document.createElement('div');
                    fieldGroup.className = 'jc-social-field-group';
                    fieldGroup.innerHTML = '<div class="jc-social-field-wrapper">' +
                        '<input class="jc-input jc-social-input" type="text" name="social_channels[]" ' +
                        'placeholder="z. B. youtube.com/@username" data-index="' + socialFieldCount + '" />' +
                        '<span class="jc-platform-icon" data-index="' + socialFieldCount + '"></span>' +
                        '</div>' +
                        '<button type="button" class="jc-remove-social-btn" onclick="jcRemoveSocialField(this)">✕</button>';
                   
                    container.appendChild(fieldGroup);
                    socialFieldCount++;
                   
                    const addBtn = document.querySelector('.jc-add-social-btn');
                    if (socialFieldCount >= maxSocialFields && addBtn) {
                        addBtn.style.display = 'none';
                    }
                }
                
                function jcRemoveSocialField(button) {
                    const fieldGroup = button.closest('.jc-social-field-group');
                    if (fieldGroup) {
                        fieldGroup.remove();
                        socialFieldCount--;
                       
                        const addBtn = document.querySelector('.jc-add-social-btn');
                        if (socialFieldCount < maxSocialFields && addBtn) {
                            addBtn.style.display = 'inline-block';
                        }
                    }
                }
                
                // Validierung für Alter
                function validateAge() {
                    const ageInput = document.getElementById('jc-age-input');
                    const ageError = document.getElementById('jc-age-error');
                    
                    if (!ageInput || !ageError) return true;
                    
                    const ageValue = ageInput.value.trim();
                    
                    // Wenn leer, keine Validierung (required wird vom Browser gehandhabt)
                    if (ageValue === '') {
                        ageError.style.display = 'none';
                        ageError.textContent = '';
                        ageInput.classList.remove('error');
                        return true;
                    }
                    
                    const age = parseInt(ageValue, 10);
                    
                    // Prüfe ob es eine gültige Zahl ist und im erlaubten Bereich
                    if (isNaN(age) || age < 11 || age > 99) {
                        ageError.textContent = '❌ Das Alter muss zwischen 11 und 99 Jahren liegen.';
                        ageError.style.display = 'block';
                        ageInput.classList.add('error');
                        return false;
                    }
                    
                    // Alles OK
                    ageError.style.display = 'none';
                    ageError.textContent = '';
                    ageInput.classList.remove('error');
                    return true;
                }
                
                // Validierung für Motivation
                function validateMotivation() {
                    const motivationInput = document.getElementById('jc-motivation-input');
                    const motivationError = document.getElementById('jc-motivation-error');
                    
                    if (!motivationInput || !motivationError) return true;
                    
                    const text = motivationInput.value;
                    // Entfernt ALLE Leerzeichen (auch zwischen Wörtern) vor dem Zählen
                    const textWithoutSpaces = text.replace(/\s/g, ''); 
                    const length = textWithoutSpaces.length; // Zählt nur "echte" Zeichen
                    
                    // Wenn nach dem Entfernen aller Leerzeichen leer
                    if (length === 0) {
                        if (motivationInput.hasAttribute('required')) {
                             motivationError.textContent = '❌ Bitte gib eine Motivation ein.';
                             motivationError.style.display = 'block';
                             motivationInput.classList.add('error');
                             return false;
                        }
                        // Falls es nicht required ist
                        motivationError.style.display = 'none';
                        motivationError.textContent = '';
                        motivationInput.classList.remove('error');
                        return true;
                    }
                    
                    // Prüfe ob mindestens 100 Zeichen
                    if (length >= 100) {
                        // Alles OK - mindestens 100 Zeichen
                        motivationError.style.display = 'none';
                        motivationError.textContent = '';
                        motivationInput.classList.remove('error');
                        return true;
                    } else {
                        // Zu wenig Zeichen
                        const remaining = 100 - length;
                        // Angepasste Fehlermeldung
                        motivationError.textContent = '❌ Bitte gib mindestens 100 Zeichen ein (Leerzeichen zählen nicht). (Noch ' + remaining + ' Zeichen)';
                        motivationError.style.display = 'block';
                        motivationInput.classList.add('error');
                        return false;
                    }
                }
                
                // Validierung für Name
                function validateName() {
                    const nameInput = document.getElementById('jc-name-input');
                    const nameError = document.getElementById('jc-name-error');
                    
                    if (!nameInput || !nameError) return true;
                    
                    const name = nameInput.value.trim();
                    
                    if (name === '') {
                        nameError.textContent = '❌ Bitte gib deinen Namen ein.';
                        nameError.style.display = 'block';
                        nameInput.classList.add('error');
                        return false;
                    }
                    
                    if (name.length < 2) {
                        nameError.textContent = '❌ Der Name muss mindestens 2 Zeichen lang sein.';
                        nameError.style.display = 'block';
                        nameInput.classList.add('error');
                        return false;
                    }
                    
                    // Alles OK
                    nameError.style.display = 'none';
                    nameError.textContent = '';
                    nameInput.classList.remove('error');
                    return true;
                }
                
                // Validierung für Social Media Links
                function validateSocialLinks() {
                    const socialError = document.getElementById('jc-social-error');
                    const socialInputs = document.querySelectorAll('.jc-social-input');
                    
                    if (!socialError) return true;
                    
                    let hasValidLink = false;
                    let invalidLinks = [];
                    
                    // Prüfe ob mindestens ein Link eingegeben wurde
                    const filledInputs = Array.from(socialInputs).filter(input => input.value.trim() !== '');
                    
                    if (filledInputs.length === 0) {
                        socialError.textContent = '❌ Bitte gib mindestens einen Social Media Kanal an.';
                        socialError.style.display = 'block';
                        socialInputs.forEach(input => input.classList.add('error'));
                        return false;
                    }
                    
                    // Validiere jeden Link - nur YouTube, Twitch und TikTok erlaubt
                    filledInputs.forEach(function(input, index) {
                        const value = input.value.trim();
                        if (value === '') return;
                        
                        let isValid = false;
                        let platform = '';
                        
                        // Handle-Format (@username) - nicht erlaubt, nur URLs
                        if (value.startsWith('@')) {
                            isValid = false;
                        } else {
                            // URL-Format - prüfe ob es eine gültige URL ist
                            try {
                                let urlToCheck = value;
                                // Wenn kein Protokoll, füge https:// hinzu für Validierung
                                if (!/^https?:\/\//i.test(urlToCheck)) {
                                    urlToCheck = 'https://' + urlToCheck;
                                }
                                const url = new URL(urlToCheck);
                                const hostname = url.hostname.toLowerCase().replace('www.', '');
                                
                                // Prüfe ob es eine Domain hat und zu erlaubten Plattformen gehört
                                if (hostname && hostname.includes('.')) {
                                    // Erlaubte Plattformen: YouTube, Twitch, TikTok
                                    if (hostname.includes('youtube.com') || hostname.includes('youtu.be')) {
                                        platform = 'youtube';
                                        isValid = true;
                                    } else if (hostname.includes('twitch.tv')) {
                                        platform = 'twitch';
                                        isValid = true;
                                    } else if (hostname.includes('tiktok.com')) {
                                        platform = 'tiktok';
                                        isValid = true;
                                    } else {
                                        isValid = false;
                                    }
                                }
                            } catch (e) {
                                // Fallback: Prüfe ob es wie eine Domain aussieht und zu erlaubten Plattformen gehört
                                const valueLower = value.toLowerCase();
                                if (valueLower.includes('youtube.com') || valueLower.includes('youtu.be')) {
                                    platform = 'youtube';
                                    isValid = true;
                                } else if (valueLower.includes('twitch.tv')) {
                                    platform = 'twitch';
                                    isValid = true;
                                } else if (valueLower.includes('tiktok.com')) {
                                    platform = 'tiktok';
                                    isValid = true;
                                } else {
                                    isValid = false;
                                }
                            }
                        }
                        
                        if (isValid) {
                            hasValidLink = true;
                            input.classList.remove('error');
                        } else {
                            invalidLinks.push('Link ' + (index + 1));
                            input.classList.add('error');
                        }
                    });
                    
                    if (invalidLinks.length > 0) {
                        socialError.textContent = '❌ Ungültiger Link: ' + invalidLinks.join(', ') + '. Es sind nur YouTube, Twitch und TikTok erlaubt. Bitte gib eine gültige URL ein (z.B. youtube.com/@username, twitch.tv/username, tiktok.com/@username).';
                        socialError.style.display = 'block';
                        return false;
                    }
                    
                    if (!hasValidLink) {
                        socialError.textContent = '❌ Bitte gib mindestens einen gültigen Social Media Kanal an.';
                        socialError.style.display = 'block';
                        return false;
                    }
                    
                    // Alles OK
                    socialError.style.display = 'none';
                    socialError.textContent = '';
                    socialInputs.forEach(input => input.classList.remove('error'));
                    return true;
                }
                
                // Validierung für "Wie aktiv bist du?"
                function validateActivity() {
                    const activityInput = document.getElementById('jc-activity-input');
                    const activityError = document.getElementById('jc-activity-error');
                    
                    if (!activityInput || !activityError) return true;
                    
                    const activity = activityInput.value.trim();
                    
                    if (activity === '') {
                        activityError.textContent = '❌ Bitte gib an, wie aktiv du bist.';
                        activityError.style.display = 'block';
                        activityInput.classList.add('error');
                        return false;
                    }
                    
                    if (activity.length < 2) {
                        activityError.textContent = '❌ Bitte gib eine aussagekräftige Antwort ein (mindestens 2 Zeichen).';
                        activityError.style.display = 'block';
                        activityInput.classList.add('error');
                        return false;
                    }
                    
                    // Alles OK
                    activityError.style.display = 'none';
                    activityError.textContent = '';
                    activityInput.classList.remove('error');
                    return true;
                }
                
                // Event-Listener für Echtzeit-Validierung
                function initValidation() {
                    const ageInput = document.getElementById('jc-age-input');
                    const motivationInput = document.getElementById('jc-motivation-input');
                    const form = document.getElementById('jc-application-form');
                    
                    if (ageInput) {
                        ageInput.addEventListener('input', validateAge);
                        ageInput.addEventListener('blur', validateAge);
                        ageInput.addEventListener('change', validateAge);
                    }
                    
                    if (motivationInput) {
                        motivationInput.addEventListener('input', validateMotivation);
                        motivationInput.addEventListener('blur', validateMotivation);
                    }
                    
                    // Form-Submit-Validierung
                    if (form) {
                        form.addEventListener('submit', function(e) {
                            const nameValid = validateName();
                            const ageValid = validateAge();
                            const socialValid = validateSocialLinks();
                            const activityValid = validateActivity();
                            const motivationValid = validateMotivation();
                            
                            if (!nameValid || !ageValid || !socialValid || !activityValid || !motivationValid) {
                                e.preventDefault();
                                e.stopPropagation();
                                
                                // Scroll zum ersten Fehler
                                const nameInput = document.getElementById('jc-name-input');
                                const activityInput = document.getElementById('jc-activity-input');
                                if (!nameValid && nameInput) {
                                    nameInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    nameInput.focus();
                                } else if (!ageValid && ageInput) {
                                    ageInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    ageInput.focus();
                                } else if (!socialValid) {
                                    const firstSocialInput = document.querySelector('.jc-social-input');
                                    if (firstSocialInput) {
                                        firstSocialInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                        firstSocialInput.focus();
                                    }
                                } else if (!activityValid && activityInput) {
                                    activityInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    activityInput.focus();
                                } else if (!motivationValid && motivationInput) {
                                    motivationInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    motivationInput.focus();
                                }
                                
                                return false;
                            }
                        });
                    }
                }
                
                // Initialisierung - sowohl bei DOMContentLoaded als auch sofort
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initValidation);
                } else {
                    initValidation();
                }
                </script>
                <?php
            }
            ?>
        </div>
    </div>
   
    <?php
    return ob_get_clean();
} );
// ========================================
// ADMIN BEWERBUNGEN ÜBERSICHT
// ========================================
function jc_admin_bewerbungen_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
   
    global $wpdb;
    $table = $wpdb->prefix . 'jc_discord_applications';
   
    // STATUS MANUELL ÄNDERN
    if ( isset( $_POST['jc_change_status'] ) && isset( $_POST['application_id'] ) && isset( $_POST['new_status'] ) ) {
        check_admin_referer( 'jc_change_status_' . intval( $_POST['application_id'] ) );
       
        $app_id = intval( $_POST['application_id'] );
        $new_status = sanitize_text_field( $_POST['new_status'] );
       
        $updated = $wpdb->update(
            $table,
            array( 'status' => $new_status ),
            array( 'id' => $app_id ),
            array( '%s' ),
            array( '%d' )
        );
       
        if ( $updated !== false ) {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Status erfolgreich geändert auf: <strong>' . esc_html( $new_status ) . '</strong></p></div>';
           
            // Optional: Bot benachrichtigen
            $app = $wpdb->get_row( $wpdb->prepare( "SELECT discord_id FROM $table WHERE id = %d", $app_id ) );
            if ( $app ) {
                error_log( "JC Admin: Status manuell geändert für Discord ID {$app->discord_id} auf {$new_status}" );
            }
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>❌ Fehler beim Ändern des Status!</p></div>';
        }
    }
   
    // Löschung mit Discord Sync
    if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete' && isset( $_GET['id'] ) && isset( $_GET['_wpnonce'] ) ) {
        if ( wp_verify_nonce( $_GET['_wpnonce'], 'jc_delete_application_' . intval( $_GET['id'] ) ) ) {
            $id = intval( $_GET['id'] );
            $application = $wpdb->get_row( $wpdb->prepare( "SELECT forum_post_id FROM $table WHERE id = %d", $id ) );
           
            $deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
           
            if ( $deleted ) {
                if ( ! empty( $application->forum_post_id ) ) {
                    jc_delete_discord_post( $application->forum_post_id );
                }
                echo '<div class="notice notice-success is-dismissible"><p>✅ Bewerbung gelöscht (inkl. Discord).</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>❌ Fehler beim Löschen.</p></div>';
            }
        }
    }
   
    $rows = $wpdb->get_results( "SELECT * FROM $table ORDER BY created_at DESC" );
   
    // Statistiken berechnen
    $total = count($rows);
    $pending = count(array_filter($rows, function($r) { return $r->status === 'pending'; }));
    $accepted = count(array_filter($rows, function($r) { return $r->status === 'accepted'; }));
    $rejected = count(array_filter($rows, function($r) { return $r->status === 'rejected'; }));
   
    echo '<div class="wrap jc-admin-wrap">';
    echo '<h1 class="jc-admin-title">🎮 Bewerbungen <span class="jc-count-badge">' . $total . '</span></h1>';
    
    // Statistik-Boxen
    echo '<div class="jc-stats-grid">';
    
    echo '<div class="jc-stat-card jc-stat-total">';
    echo '<div class="jc-stat-number">' . $total . '</div>';
    echo '<div class="jc-stat-label">📊 Gesamt</div>';
    echo '</div>';
    
    echo '<div class="jc-stat-card jc-stat-pending">';
    echo '<div class="jc-stat-number">⏳ ' . $pending . '</div>';
    echo '<div class="jc-stat-label">In Bearbeitung</div>';
    echo '</div>';
    
    echo '<div class="jc-stat-card jc-stat-accepted">';
    echo '<div class="jc-stat-number">✅ ' . $accepted . '</div>';
    echo '<div class="jc-stat-label">Angenommen</div>';
    echo '</div>';
    
    echo '<div class="jc-stat-card jc-stat-rejected">';
    echo '<div class="jc-stat-number">❌ ' . $rejected . '</div>';
    echo '<div class="jc-stat-label">Abgelehnt</div>';
    echo '</div>';
    
    echo '</div>';
   
    if ( empty( $rows ) ) {
        echo '<div class="jc-empty-state">';
        echo '<div class="jc-empty-icon">📭</div>';
        echo '<h2 class="jc-empty-title">Keine Bewerbungen vorhanden</h2>';
        echo '<p class="jc-empty-desc">Sobald jemand sich bewirbt, erscheint die Bewerbung hier.</p>';
        echo '</div>';
    } else {
        echo '<div class="jc-table-wrapper">';
        echo '<table class="jc-applications-table"><thead><tr>';
        echo '<th>👤 Discord</th>';
        echo '<th>📝 Name</th>';
        echo '<th>🎂 Alter</th>';
        echo '<th>🌐 Social</th>';
        echo '<th>💭 Motivation</th>';
        echo '<th>📅 Datum</th>';
        echo '<th class="jc-status-col">🏷️ Status</th>';
        echo '<th>⚙️</th>';
        echo '</tr></thead><tbody>';
       
        foreach ( $rows as $r ) {
            $delete_url = wp_nonce_url(
                admin_url( 'admin.php?page=jc-bewerbungen&action=delete&id=' . $r->id ),
                'jc_delete_application_' . $r->id
            );
           
            // Social Channels anzeigen
            $social_channels = json_decode( $r->social_channels, true );
            $social_display = '';
           
            if ( is_array( $social_channels ) ) {
                foreach ( $social_channels as $channel ) {
                    if ( is_string( $channel ) ) {
                        $social_display .= esc_html( $channel ) . '<br>';
                    } else if ( is_array( $channel ) && isset( $channel['url'] ) ) {
                        $platform_icon = jc_get_platform_icon( $channel['platform'] ?? 'unknown' );
                        $social_display .= $platform_icon . ' ' . esc_html( $channel['url'] ) . '<br>';
                    }
                }
            } else {
                $social_display = esc_html( $r->social_channels );
            }
           
            $status_class = 'jc-status-' . $r->status;
            echo '<tr class="jc-table-row ' . $status_class . '">';
            echo '<td class="jc-cell-discord"><strong class="jc-discord-name">' . esc_html( $r->discord_name ) . '</strong><br><small class="jc-discord-id">' . esc_html( $r->discord_id ) . '</small></td>';
            echo '<td class="jc-cell-name"><strong>' . esc_html( $r->applicant_name ) . '</strong></td>';
            echo '<td class="jc-cell-age">' . esc_html( $r->age ) . '</td>';
            echo '<td class="jc-cell-social"><small>' . $social_display . '</small></td>';
            echo '<td class="jc-cell-motivation"><div class="jc-motivation-preview" title="' . esc_attr($r->motivation) . '">' . esc_html( substr($r->motivation, 0, 15) ) . (strlen($r->motivation) > 15 ? '...' : '') . '</div></td>';
            
            // NEU (v6.17): Zeige Datenschutz-Zeitstempel
            $created_date = esc_html( date_i18n( 'd.m.Y', strtotime( $r->created_at ) ) );
            $created_time = esc_html( date_i18n( 'H:i', strtotime( $r->created_at ) ) ) . ' Uhr';
            
            if ( ! empty( $r->privacy_accepted_at ) && $r->privacy_accepted_at !== '0000-00-00 00:00:00' ) {
                $privacy_time = esc_html( date_i18n( 'd.m. H:i', strtotime( $r->privacy_accepted_at ) ) );
                $date_display = "<small>{$created_date}<br>{$created_time}</small><br><small style='color: #4ade80; font-size: 11px; margin-top: 5px; display: block;'>✓ DS {$privacy_time}</small>";
            } else {
                $date_display = "<small>{$created_date}<br>{$created_time}</small><br><small style='color: #f44336; font-size: 11px; margin-top: 5px; display: block;'>✗ DS FEHLT</small>";
            }
            echo '<td class="jc-cell-date">' . $date_display . '</td>';
            
            // STATUS ÄNDERN DROPDOWN
            echo '<td class="jc-cell-status">';
            echo '<form method="POST" class="jc-status-form">';
            wp_nonce_field( 'jc_change_status_' . $r->id );
            echo '<input type="hidden" name="application_id" value="' . esc_attr( $r->id ) . '" />';
            echo '<select name="new_status" class="jc-status-select">';
            echo '<option value="pending" ' . selected( $r->status, 'pending', false ) . '>⏳ In Bearbeitung</option>';
            echo '<option value="accepted" ' . selected( $r->status, 'accepted', false ) . '>✅ Angenommen</option>';
            echo '<option value="rejected" ' . selected( $r->status, 'rejected', false ) . '>❌ Abgelehnt</option>';
            echo '</select>';
            echo '<button type="submit" name="jc_change_status" class="jc-save-btn">💾 Speichern</button>';
            echo '</form>';
            echo '</td>';
            
            echo '<td class="jc-cell-actions"><a href="' . esc_url( $delete_url ) . '" class="jc-delete-btn" onclick="return confirm(\'⚠️ Wirklich löschen?\\n\\nDies entfernt auch den Discord Post!\');">🗑️</a></td>';
            echo '</tr>';
        }
       
        echo '</tbody></table>';
        echo '</div>';
    }
   
    echo '</div>';
   
    // CSS für die Admin-Seite
    echo '<style>
        @keyframes jc-fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes jc-slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        .jc-admin-wrap {
            font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", Arial, Helvetica, sans-serif;
            background: linear-gradient(135deg, #1e1f26 0%, #2a2c36 100%);
            padding: 30px;
            margin: 20px 20px 0 0;
            border-radius: 16px;
            min-height: calc(100vh - 100px);
        }
        
        .jc-admin-title {
            display: flex;
            align-items: center;
            gap: 15px;
            font-size: 32px;
            font-weight: 700;
            color: #f0f0f0;
            margin: 0 0 30px 0;
            animation: jc-fadeIn 0.6s ease-out;
        }
        
        .jc-count-badge {
            background: linear-gradient(135deg, #5865F2 0%, #4752c4 100%);
            color: #fff;
            padding: 8px 16px;
            border-radius: 20px;
            font-size: 18px;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(88, 101, 242, 0.4);
        }
        
        .jc-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin: 0 0 30px 0;
        }
        
        .jc-stat-card {
            background: #2a2c36;
            padding: 15px 25px 20px 25px; /* Top-Padding weiter reduziert */
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.4);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            animation: jc-fadeIn 0.6s ease-out;
            border-left: 4px solid;
        }
        
        .jc-stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.5);
        }
        
        .jc-stat-total {
            border-left-color: #5865F2;
            background: linear-gradient(135deg, rgba(88, 101, 242, 0.1) 0%, #2a2c36 100%);
        }
        
        .jc-stat-pending {
            border-left-color: #ffc107;
            background: linear-gradient(135deg, rgba(255, 193, 7, 0.1) 0%, #2a2c36 100%);
        }
        
        .jc-stat-accepted {
            border-left-color: #4ade80;
            background: linear-gradient(135deg, rgba(74, 222, 128, 0.1) 0%, #2a2c36 100%);
        }
        
        .jc-stat-rejected {
            border-left-color: #f44336;
            background: linear-gradient(135deg, rgba(244, 67, 54, 0.1) 0%, #2a2c36 100%);
        }
        
        .jc-stat-number {
            font-size: 42px;
            font-weight: 700;
            color: #f0f0f0;
            margin-bottom: 2px; /* Von 4px auf 2px reduziert */
        }
        
        .jc-stat-label {
            font-size: 15px;
            color: #a0a8b8;
            font-weight: 600;
        }
        
        .jc-empty-state {
            background: #2a2c36;
            padding: 60px;
            text-align: center;
            border-radius: 14px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.4);
            animation: jc-fadeIn 0.6s ease-out;
        }
        
        .jc-empty-icon {
            font-size: 64px;
            margin-bottom: 20px;
            animation: jc-fadeIn 0.8s ease-out;
        }
        
        .jc-empty-title {
            color: #f0f0f0;
            margin: 0 0 10px 0;
            font-size: 24px;
            font-weight: 700;
        }
        
        .jc-empty-desc {
            color: #a0a8b8;
            font-size: 16px;
        }
        
        .jc-table-wrapper {
            background: #2a2c36;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.4);
            animation: jc-fadeIn 0.8s ease-out;
        }
        
        .jc-applications-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        
        .jc-applications-table thead {
            background: rgba(88, 101, 242, 0.1);
        }
        
        .jc-applications-table th {
            padding: 18px 15px;
            font-weight: 700;
            color: #f0f0f0;
            font-size: 14px;
            text-align: left;
            border-bottom: 2px solid rgba(88, 101, 242, 0.3);
        }
        
        .jc-applications-table tbody tr {
            border-bottom: 1px solid rgba(255,255,255,0.05);
            transition: all 0.3s ease;
        }
        
        .jc-applications-table tbody tr:hover {
            background: rgba(88, 101, 242, 0.05);
            transform: scale(1.01);
        }
        
        .jc-table-row.jc-status-pending {
            border-left: 3px solid #ffc107;
        }
        
        .jc-table-row.jc-status-accepted {
            border-left: 3px solid #4ade80;
        }
        
        .jc-table-row.jc-status-rejected {
            border-left: 3px solid #f44336;
        }
        
        .jc-applications-table td {
            padding: 18px 15px;
            vertical-align: top;
            color: #dcddde;
            font-size: 14px;
        }
        
        .jc-cell-discord {
            min-width: 200px;
        }
        
        .jc-discord-name {
            color: #f0f0f0;
            font-size: 15px;
            display: block;
            margin-bottom: 5px;
        }
        
        .jc-discord-id {
            color: #8a8f9b;
            font-family: monospace;
            font-size: 12px;
        }
        
        .jc-cell-name strong {
            color: #f0f0f0;
            font-size: 15px;
        }
        
        .jc-cell-age {
            color: #dcddde;
        }
        
        .jc-cell-social small {
            color: #a0a8b8;
            line-height: 1.8;
            display: block;
        }
        
        .jc-cell-activity {
            color: #dcddde;
        }
        
        .jc-motivation-preview {
            color: #a0a8b8;
            font-size: 13px;
            font-style: italic;
        }
        
        .jc-cell-date small {
            color: #8a8f9b;
            font-size: 12px;
        }
        
        .jc-status-col {
            min-width: 180px;
        }
        
        .jc-status-form {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .jc-status-select {
            padding: 10px 12px;
            background: #3a3c4a;
            border: 2px solid #4a4c5a;
            border-radius: 8px;
            color: #fff;
            font-weight: 600;
            font-size: 13px;
            width: 100%;
            cursor: pointer;
            transition: all 0.3s ease;
            -webkit-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' viewBox=\'0 0 12 12\'%3E%<path fill=\'%23fff\' d=\'M6 9L1 4h10z\'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 35px;
        }
        
        .jc-status-select:focus {
            outline: none;
            border-color: #5865F2;
            box-shadow: 0 0 0 3px rgba(88, 101, 242, 0.2);
        }
        
        .jc-save-btn {
            padding: 10px 16px;
            background: linear-gradient(135deg, #5865F2 0%, #4752c4 100%);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 8px rgba(88, 101, 242, 0.3);
            width: 100%;
        }
        
        .jc-save-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(88, 101, 242, 0.5);
            background: linear-gradient(135deg, #6470f3 0%, #5865F2 100%);
        }
        
        .jc-delete-btn {
            display: inline-block;
            padding: 10px 14px;
            background: linear-gradient(135deg, #f44336 0%, #d32f2f 100%);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 16px;
            text-decoration: none;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 2px 8px rgba(244, 67, 54, 0.3);
            cursor: pointer;
        }
        
        .jc-delete-btn:hover {
            transform: translateY(-2px) scale(1.05);
            box-shadow: 0 4px 12px rgba(244, 67, 54, 0.5);
            background: linear-gradient(135deg, #ff5252 0%, #f44336 100%);
        }
        
        @media (max-width: 1200px) {
            .jc-applications-table {
                font-size: 12px;
            }
            
            .jc-applications-table th,
            .jc-applications-table td {
                padding: 12px 10px;
            }
        }
    </style>';
}
// ========================================
// MEMBER DASHBOARD LADEN
// ========================================
require_once plugin_dir_path( __FILE__ ) . 'justcreators_rules_page.php';

// ========================================
// COUNTDOWN SHORTCODE
// ========================================
add_shortcode( 'jc_countdown', 'jc_application_countdown_shortcode' );
function jc_application_countdown_shortcode() {
    
    // Zieldatum: 1. Dezember 2025, 00:00:00 Uhr
    // WICHTIG: Das Format MUSS YYYY-MM-DDTHH:MM:SS sein
    $target_date_string = '2025-12-01T00:00:00';

    ob_start();
    ?>
    
    <style>
        .jc-countdown-wrap {
            background: linear-gradient(135deg, #1e1f26 0%, #2a2c36 100%);
            border-radius: 16px;
            padding: 40px 30px;
            max-width: 900px;
            margin: 40px auto;
            box-shadow: 0 10px 40px rgba(0,0,0,0.4);
            font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, Helvetica, sans-serif;
            animation: jc-fadeIn 0.6s ease-out;
        }
        
        .jc-countdown-title {
            font-size: 28px;
            font-weight: 700;
            color: #f0f0f0;
            text-align: center;
            margin: 0 0 30px 0;
        }
        
        #jc-countdown-timer {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 30px;
        }
        
        .jc-countdown-box {
            background: #2a2c36;
            border-radius: 14px;
            padding: 25px 35px;
            text-align: center;
            min-width: 120px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            border: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .jc-countdown-number {
            font-size: 64px;
            font-weight: 700;
            color: #f0f0f0;
            line-height: 1.1;
            display: block;
            /* Dieser Textschatten gibt den Look eures Designs */
            text-shadow: 0 0 15px rgba(88, 101, 242, 0.5);
        }
        
        .jc-countdown-label {
            font-size: 16px;
            color: #a0a8b8;
            margin-top: 10px;
            display: block;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        #jc-countdown-expired {
            text-align: center;
        }
        
        /* Responsive Anpassungen */
        @media (max-width: 768px) {
            .jc-countdown-wrap {
                padding: 30px 20px;
            }
            #jc-countdown-timer {
                gap: 15px;
            }
            .jc-countdown-box {
                padding: 20px;
                min-width: 100px;
            }
            .jc-countdown-number {
                font-size: 48px;
            }
            .jc-countdown-label {
                font-size: 14px;
                margin-top: 5px;
            }
        }
        
        @media (max-width: 480px) {
             #jc-countdown-timer {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 15px;
            }
            .jc-countdown-box {
                min-width: auto;
                padding: 20px 10px;
            }
            .jc-countdown-number {
                font-size: 40px;
            }
        }

    </style>
    
    <div class="jc-countdown-wrap">
        
        <div id="jc-countdown-timer-wrap">
            <h2 class="jc-countdown-title">Bewerbungsphase beginnt in...</h2>
            <div id="jc-countdown-timer">
                <div class="jc-countdown-box">
                    <span id="jc-days" class="jc-countdown-number">--</span>
                    <span class="jc-countdown-label">Tage</span>
                </div>
                <div class="jc-countdown-box">
                    <span id="jc-hours" class="jc-countdown-number">--</span>
                    <span class="jc-countdown-label">Stunden</span>
                </div>
                <div class="jc-countdown-box">
                    <span id="jc-minutes" class="jc-countdown-number">--</span>
                    <span class="jc-countdown-label">Minuten</span>
                </div>
                <div class="jc-countdown-box">
                    <span id="jc-seconds" class="jc-countdown-number">--</span>
                    <span class="jc-countdown-label">Sekunden</span>
                </div>
            </div>
        </div>

        <div id="jc-countdown-expired" style="display:none;">
            <h2 class="jc-countdown-title">Die Bewerbungsphase ist jetzt geöffnet!</h2>
            <a href="<?php echo esc_url( home_url('/bewerbung') ); ?>" class="jc-discord-btn" style="margin-top: 20px;">
                <svg class="jc-discord-logo" viewBox="0 0 71 55" xmlns="http://www.w3.org/2000/svg"><path d="M60.1045 4.8978C55.5792 2.8214 50.7265 1.2916 45.6527 0.41542C45.5603 0.39851 45.468 0.440769 45.4204 0.525289C44.7963 1.6353 44.105 3.0834 43.6209 4.2216C38.1637 3.4046 32.7345 3.4046 27.3892 4.2216C26.905 3.0581 26.1886 1.6353 25.5617 0.525289C25.5141 0.443589 25.4218 0.40133 25.3294 0.41542C20.2584 1.2888 15.4057 2.8186 10.8776 4.8978C10.8384 4.9147 10.8048 4.9429 10.7825 4.9795C1.57795 18.7309 -0.943561 32.1443 0.293408 45.3914C0.299005 45.4562 0.335386 45.5182 0.385761 45.5576C6.45866 50.0174 12.3413 52.7249 18.1147 54.5195C18.2071 54.5477 18.305 54.5139 18.3638 54.4378C19.7295 52.5728 20.9469 50.6063 21.9907 48.5383C22.0523 48.4172 21.9935 48.2735 21.8676 48.2256C19.9366 47.4931 18.0979 46.6 16.3292 45.5858C16.1893 45.5041 16.1781 45.304 16.3068 45.2082C16.679 44.9293 17.0513 44.6391 17.4067 44.3461C17.471 44.2926 17.5606 44.2813 17.6362 44.3151C29.2558 49.6202 41.8354 49.6202 53.3179 44.3151C53.3935 44.2785 53.4831 44.2898 53.5502 44.3433C53.9057 44.6363 54.2779 44.9293 54.6529 45.2082C54.7816 45.304 54.7732 45.5041 54.6333 45.5858C52.8646 46.6197 51.0259 47.4931 49.0921 48.2228C48.9662 48.2707 48.9102 48.4172 48.9718 48.5383C50.038 50.6034 51.2554 52.5699 52.5959 54.435C52.6519 54.5139 52.7526 54.5477 52.845 54.5195C58.6464 52.7249 64.529 50.0174 70.6019 45.5576C70.6551 45.5182 70.6887 45.459 70.6943 45.3942C72.1747 30.0791 68.2147 16.7757 60.1968 4.9823C60.1772 4.9429 60.1437 4.9147 60.1045 4.8978ZM23.7259 37.3253C20.2276 37.3253 17.3451 34.1136 17.3451 30.1693C17.3451 26.225 20.1717 23.0133 23.7259 23.0133C27.308 23.0133 30.1626 26.2532 30.1066 30.1693C30.1066 34.1136 27.28 37.3253 23.7259 37.3253ZM47.3178 37.3253C43.8196 37.3253 40.9371 34.1136 40.9371 30.1693C40.9371 26.225 43.7636 23.0133 47.3178 23.0133C50.9 23.0133 53.7545 26.2532 53.6986 30.1693C53.6986 34.1136 50.9 37.3253 47.3178 37.3253Z"/></svg>
                Jetzt bewerben
            </a>
        </div>
    </div>
    
    <script>
    (function() {
        // Zieldatum (aus PHP übernommen)
        const countDownDate = new Date("<?php echo $target_date_string; ?>").getTime();

        // Helfer-Funktion: Fügt eine führende Null hinzu (z.B. 9 -> 09)
        function formatTime(time) {
            return time < 10 ? "0" + time : time;
        }

        // Update den Countdown jede Sekunde
        const x = setInterval(function() {
            const now = new Date().getTime();
            const distance = countDownDate - now;

            // Zeitberechnungen
            const days = Math.floor(distance / (1000 * 60 * 60 * 24));
            const hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            const minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            const seconds = Math.floor((distance % (1000 * 60)) / 1000);

            // HTML-Elemente aktualisieren
            document.getElementById("jc-days").innerHTML = formatTime(days);
            document.getElementById("jc-hours").innerHTML = formatTime(hours);
            document.getElementById("jc-minutes").innerHTML = formatTime(minutes);
            document.getElementById("jc-seconds").innerHTML = formatTime(seconds);

            // Wenn der Countdown abgelaufen ist
            if (distance < 0) {
                clearInterval(x);
                // Verstecke den Timer
                document.getElementById("jc-countdown-timer-wrap").style.display = "none";
                // Zeige die "Bewerbung geöffnet"-Nachricht an
                document.getElementById("jc-countdown-expired").style.display = "block";
            }
        }, 1000);
    })();
    </script>
    
    <?php
    return ob_get_clean();
}
?>