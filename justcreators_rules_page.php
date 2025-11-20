<?php
/**
 * JustCreators Regeln-Seite - Komplett
 * Enthält: Discord OAuth, Regeln, Formular, REST API
 * Version: 3.0

 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ========================================
// DISCORD OAUTH KONFIGURATION
// ========================================

define( 'JC_RULES_CLIENT_ID', '1436449319849824480' );
define( 'JC_RULES_CLIENT_SECRET', 'KTPe1JrmSRzvyKV_jbvmacQCLTwunDla' );
define( 'JC_RULES_REDIRECT_URI', 'https://just-creators.de/regeln' );
define( 'JC_RULES_API_SECRET', 'rootofant' );
// ========================================

function jc_rules_ensure_session() {
    if ( ! session_id() && ! headers_sent() ) {
        session_start();
        error_log( 'JC Rules: Session manuell gestartet - ID: ' . session_id() );
    }
}

// Session IMMER vor OAuth Check starten
add_action( 'wp', 'jc_rules_ensure_session', 1 );


// ========================================
// DISCORD OAUTH CALLBACK HANDLER
// ========================================

add_action( 'template_redirect', function() {
    // WICHTIG: Session zuerst starten!
    jc_rules_ensure_session();
    
    // Nur auf /regeln Seite
    if ( ! is_page( 'regeln' ) ) {
        return;
    }
    
    // DEBUG: Session Status
    $session_has_user = isset( $_SESSION['jc_discord_user'] ) ? 'JA' : 'NEIN';
    $cookie_has_user = isset( $_COOKIE['jc_discord_id'] ) ? 'JA' : 'NEIN';
    error_log( "JC Rules: Page loaded - Session ID: " . session_id() . ", Session User: {$session_has_user}, Cookie User: {$cookie_has_user}" );
    
    // OAuth Callback verarbeiten
    if ( isset( $_GET['code'] ) ) {
        $code = sanitize_text_field( $_GET['code'] );
        
        error_log( 'JC OAuth: Code empfangen: ' . substr( $code, 0, 10 ) . '...' );
        
        // Token holen
        $token_response = wp_remote_post( 'https://discord.com/api/oauth2/token', array(
            'body' => array(
                'client_id' => JC_RULES_CLIENT_ID,
                'client_secret' => JC_RULES_CLIENT_SECRET,
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => JC_RULES_REDIRECT_URI
            ),
            'timeout' => 15
        ) );
        
        if ( is_wp_error( $token_response ) ) {
            error_log( 'JC OAuth: Token Error - ' . $token_response->get_error_message() );
            wp_redirect( home_url( '/regeln?error=token' ) );
            exit;
        }
        
        $token_data = json_decode( wp_remote_retrieve_body( $token_response ), true );
        
        if ( ! isset( $token_data['access_token'] ) ) {
            error_log( 'JC OAuth: FULL TOKEN RESPONSE: ' . print_r( $token_data, true ) );
            error_log( 'JC OAuth: CLIENT_ID: ' . JC_RULES_CLIENT_ID );
            error_log( 'JC OAuth: CLIENT_SECRET: ' . substr( JC_RULES_CLIENT_SECRET, 0, 10 ) . '...' );
            error_log( 'JC OAuth: REDIRECT_URI: ' . JC_RULES_REDIRECT_URI );
            
            // Zeige Fehler direkt im Browser
            echo '<html><body style="background:#1e1f26;color:#fff;font-family:monospace;padding:40px;">';
            echo '<h1 style="color:#f44336;">Discord OAuth Debug</h1>';
            echo '<div style="background:#2a2c36;padding:20px;border-radius:10px;margin:20px 0;">';
            echo '<h3 style="color:#5865F2;">Token Response:</h3>';
            echo '<pre style="color:#dcddde;">' . print_r( $token_data, true ) . '</pre>';
            echo '</div>';
            echo '<div style="background:#2a2c36;padding:20px;border-radius:10px;margin:20px 0;">';
            echo '<h3 style="color:#5865F2;">Configuration:</h3>';
            echo '<pre style="color:#dcddde;">';
            echo 'Client ID: ' . JC_RULES_CLIENT_ID . "\n";
            echo 'Client Secret: ' . substr( JC_RULES_CLIENT_SECRET, 0, 10 ) . '...' . "\n";
            echo 'Redirect URI: ' . JC_RULES_REDIRECT_URI . "\n";
            echo '</pre>';
            echo '</div>';
            echo '<a href="/regeln" style="color:#5865F2;">← Zurück</a>';
            echo '</body></html>';
            exit;
        }
        
        error_log( 'JC OAuth: Access Token OK' );
        
        // User Daten holen
        $user_response = wp_remote_get( 'https://discord.com/api/users/@me', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token_data['access_token']
            ),
            'timeout' => 15
        ) );
        
        if ( is_wp_error( $user_response ) ) {
            error_log( 'JC OAuth: User fetch error - ' . $user_response->get_error_message() );
            wp_redirect( home_url( '/regeln?error=user' ) );
            exit;
        }
        
        $user_data = json_decode( wp_remote_retrieve_body( $user_response ), true );
        
        if ( ! isset( $user_data['id'] ) ) {
            error_log( 'JC OAuth: Invalid user data - ' . print_r( $user_data, true ) );
            wp_redirect( home_url( '/regeln?error=invalid' ) );
            exit;
        }
        
        // In Session UND Cookie speichern
        $_SESSION['jc_discord_user'] = $user_data;
        
        // Cookie als Backup (1 Stunde gültig)
        setcookie( 
            'jc_discord_id', 
            $user_data['id'], 
            time() + 3600, 
            '/', 
            '', // Leer = current domain
            true, // HTTPS only
            true  // HTTP only
        );
        setcookie( 
            'jc_discord_name', 
            $user_data['username'], 
            time() + 3600, 
            '/', 
            '', 
            true, 
            true
        );
        
        error_log( "JC OAuth: SUCCESS! User: " . $user_data['username'] . " (" . $user_data['id'] . ") - Session: " . session_id() . " + Cookies gesetzt" );
        
        // Redirect zurück (ohne ?code)
        wp_redirect( home_url( '/regeln' ) );
        exit;
    }
}, 5 );

// ========================================
// SHORTCODE
// ========================================

add_shortcode( 'jc_rules', 'jc_rules_render_page' );

function jc_rules_render_page() {
    ob_start();
    
    // STEP 1: Login-Check - Session ODER Cookie
    if ( ! isset( $_SESSION['jc_discord_user'] ) ) {
        // Fallback: Versuche aus Cookie wiederherzustellen
        if ( isset( $_COOKIE['jc_discord_id'] ) && isset( $_COOKIE['jc_discord_name'] ) ) {
            $_SESSION['jc_discord_user'] = array(
                'id' => sanitize_text_field( $_COOKIE['jc_discord_id'] ),
                'username' => sanitize_text_field( $_COOKIE['jc_discord_name'] )
            );
            error_log( 'JC Rules: User aus Cookie wiederhergestellt - ' . $_SESSION['jc_discord_user']['username'] . ' (' . $_SESSION['jc_discord_user']['id'] . ')' );
        } else {
            // Kein Login
            error_log( 'JC Rules: Kein User in Session oder Cookie - Zeige Login' );
            jc_rules_login_screen();
            jc_rules_styles();
            return ob_get_clean();
        }
    } else {
        error_log( 'JC Rules: User in Session gefunden - ' . $_SESSION['jc_discord_user']['username'] );
    }
    
    $discord_user = $_SESSION['jc_discord_user'];
    $discord_id = sanitize_text_field( $discord_user['id'] );
    $discord_name = esc_html( $discord_user['username'] );
    
    error_log( "JC Rules: Rendering für User: {$discord_name} ({$discord_id})" );
    
    // STEP 2: Bewerbungsstatus prüfen
    global $wpdb;
    $app_table = $wpdb->prefix . 'jc_discord_applications';
    $application = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$app_table} WHERE discord_id = %s",
        $discord_id
    ) );
    
    error_log( "JC Rules: Bewerbungsstatus für {$discord_id}: " . ( $application ? $application->status : 'NICHT GEFUNDEN' ) );
    
    // Keine Bewerbung
    if ( ! $application ) {
        jc_rules_no_application( $discord_name );
        jc_rules_styles();
        return ob_get_clean();
    }
    
    // Nicht angenommen
    if ( $application->status !== 'accepted' ) {
        jc_rules_not_accepted( $application, $discord_name );
        jc_rules_styles();
        return ob_get_clean();
    }
    
    // STEP 3: Member Status
    $member_table = $wpdb->prefix . 'jc_members';
    $member = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$member_table} WHERE discord_id = %s",
        $discord_id
    ) );
    
    error_log( "JC Rules: Member Status - Existiert: " . ( $member ? 'JA' : 'NEIN' ) . 
               ", Regeln: " . ( $member && $member->rules_accepted ? 'AKZEPTIERT' : 'NICHT AKZEPTIERT' ) . 
               ", Minecraft: " . ( $member && $member->minecraft_name ? $member->minecraft_name : 'FEHLT' ) );
    
    // STEP 4: Formular verarbeiten
    if ( isset( $_POST['jc_accept_rules'] ) ) {
        error_log( 'JC Rules: Formular Submit erkannt' );
        $result = jc_rules_process_form( $discord_id, $discord_name );
        
        if ( $result['success'] ) {
            echo '<div class="jc-msg jc-success">✅ ' . esc_html( $result['message'] ) . '</div>';
            // Member neu laden
            $member = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$member_table} WHERE discord_id = %s",
                $discord_id
            ) );
            error_log( 'JC Rules: Member nach Submit neu geladen' );
        } else {
            echo '<div class="jc-msg jc-error">❌ ' . esc_html( $result['message'] ) . '</div>';
            error_log( 'JC Rules: Formular Submit Fehler - ' . $result['message'] );
        }
    }
    
    // STEP 5: Richtige Ansicht
    if ( $member && $member->rules_accepted && $member->minecraft_name ) {
        error_log( 'JC Rules: Zeige Discord Invite' );
        jc_rules_discord_invite( $member, $discord_name );
    } else {
        error_log( 'JC Rules: Zeige Regeln-Formular' );
        jc_rules_form( $discord_name, $member );
    }
    
    jc_rules_styles();
    
    return ob_get_clean();
}

// ========================================
// VIEWS
// ========================================

function jc_rules_login_screen() {
    $params = array(
        'client_id' => JC_RULES_CLIENT_ID,
        'redirect_uri' => JC_RULES_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'identify'
    );
    $auth_url = 'https://discord.com/api/oauth2/authorize?' . http_build_query( $params );
    ?>
    <div class="jc-wrap">
        <div class="jc-card" style="text-align: center;">
            <div style="font-size: 64px; margin-bottom: 20px;">🔒</div>
            <h2 class="jc-h">Regeln & Richtlinien</h2>
            <p style="color: #a0a8b8; line-height: 1.8; margin: 20px 0 30px;">
                Diese Seite ist nur für <strong style="color: #5865F2;">akzeptierte JustCreators Bewerber</strong> zugänglich.<br>
                Bitte melde dich mit deinem Discord Account an.
            </p>
            <a class="jc-btn" href="<?php echo esc_url( $auth_url ); ?>">
                Mit Discord anmelden
            </a>
        </div>
    </div>
    <?php
}

function jc_rules_no_application( $discord_name ) {
    ?>
    <div class="jc-wrap">
        <div class="jc-card" style="text-align: center;">
            <div style="font-size: 64px; margin-bottom: 20px;">📝</div>
            <h2 class="jc-h">Keine Bewerbung gefunden</h2>
            <p style="color: #a0a8b8; margin: 20px 0;">
                Hallo <strong><?php echo $discord_name; ?></strong>,<br>
                wir konnten keine Bewerbung von dir finden.
            </p>
            <a href="<?php echo home_url( '/bewerbung' ); ?>" class="jc-btn">
                📝 Jetzt bewerben
            </a>
        </div>
    </div>
    <?php
}

function jc_rules_not_accepted( $app, $discord_name ) {
    ?>
    <div class="jc-wrap">
        <div class="jc-card" style="text-align: center;">
            <?php if ( $app->status === 'pending' ): ?>
                <div style="font-size: 64px; margin-bottom: 20px;">⏳</div>
                <h2 class="jc-h">Bewerbung wird geprüft</h2>
                <p style="color: #ffa500; font-size: 16px; margin: 20px 0;">
                    Hallo <strong><?php echo $discord_name; ?></strong>,<br>
                    deine Bewerbung wird gerade geprüft.<br>
                    Wir melden uns innerhalb von 1-2 Tagen!
                </p>
            <?php else: ?>
                <div style="font-size: 64px; margin-bottom: 20px;">😔</div>
                <h2 class="jc-h">Bewerbung abgelehnt</h2>
                <p style="color: #f44336; font-size: 16px; margin: 20px 0;">
                    Leider wurde deine Bewerbung nicht angenommen.
                </p>
            <?php endif; ?>
            <a href="<?php echo home_url( '/' ); ?>" class="jc-btn" style="background: rgba(88, 101, 242, 0.2); border: 2px solid #5865F2;">
                🏠 Zur Startseite
            </a>
        </div>
    </div>
    <?php
}

function jc_rules_form( $discord_name, $member ) {
    ?>
    <div class="jc-wrap">
        <div class="jc-card">
            <h1 class="jc-h" style="font-size: 32px;">🎉 Willkommen, <?php echo $discord_name; ?>!</h1>
            <p style="color: #a0a8b8; font-size: 16px; line-height: 1.8; margin-bottom: 30px;">
                Deine Bewerbung wurde <strong style="color: #4ade80;">✅ angenommen</strong>!<br>
                Lies dir bitte die Regeln durch und vervollständige dein Profil.
            </p>
            
            <!-- REGELN -->
            <div style="background: rgba(0,0,0,0.3); padding: 35px; border-radius: 12px; margin: 30px 0;">
                <h2 style="color: #5865F2; margin: 0 0 25px 0; font-size: 24px; text-align: center;">
                    📜 JustCreators Season 2 - Regeln
                </h2>
                
                <div class="jc-rule-box" style="border-left-color: #5865F2;">
                    <h3 style="color: #5865F2;">🎮 Content Pflichten</h3>
                    <ul>
                        <li><strong>31.01.2026 (19:45):</strong> Projekt Start → Alle streamen/1 Video</li>
                        <li><strong>31.01 - 10.02:</strong> Min. 2 Streams und/oder 1 Video</li>
                        <li><strong>10.02.2026 (14:30):</strong> End-Eröffnung</li>
                        <li><strong>10.02 - 16.02:</strong> Min. 1 Stream und/oder 1 Video</li>
                        <li><strong>16.02.2026 (15:00):</strong> Shopping District Eröffnung</li>
                        <li><strong>16.02 - 20.03:</strong> Min. 8 Streams und/oder 3 Videos</li>
                        <li><strong>20.03.2026:</strong> Content-Ende → Keine Pflicht mehr! 🎊</li>
                    </ul>
                </div>
                
                <div class="jc-rule-box" style="border-left-color: #4ade80;">
                    <h3 style="color: #4ade80;">🤝 Verhalten & Kommunikation</h3>
                    <ul>
                        <li>Respektvoller Umgang mit allen Mitgliedern</li>
                        <li>Keine Werbung für andere Projekte während JustCreators</li>
                        <li>Aktive Kommunikation über Discord ist Pflicht</li>
                        <li>Bei Problemen sofort das Team kontaktieren</li>
                        <li>Regelverstöße können zum Ausschluss führen</li>
                    </ul>
                </div>
                
                <div class="jc-rule-box" style="border-left-color: #5865F2;">
                    <h3 style="color: #5865F2;">⛏️ Minecraft Server Regeln</h3>
                    <ul>
                        <li>Kein Griefing oder absichtliches Zerstören von Builds</li>
                        <li>Keine Hacks, Mods oder X-Ray (nur erlaubte Client-Mods)</li>
                        <li>Fair Play: Kein Ausnutzen von Bugs oder Glitches</li>
                        <li>Respektiere die Builds anderer Member</li>
                        <li>Server-IP erhältst du nach Discord-Beitritt</li>
                    </ul>
                </div>
            </div>
            
            <!-- FORMULAR -->
            <form method="POST" style="margin-top: 40px;">
                <?php wp_nonce_field( 'jc_rules_action' ); ?>
                
                <div style="background: rgba(88, 101, 242, 0.08); padding: 25px; border-radius: 10px; border: 1px solid rgba(88, 101, 242, 0.2); margin-bottom: 25px;">
                    <label class="jc-label" style="margin-top: 0;">
                        🎮 Dein Minecraft Name (Java Edition) *
                        <input 
                            class="jc-input" 
                            type="text" 
                            name="minecraft_name" 
                            required 
                            placeholder="z.B. Steve123" 
                            pattern="[a-zA-Z0-9_]{3,16}" 
                            title="3-16 Zeichen, nur Buchstaben, Zahlen und Unterstriche"
                            value="<?php echo esc_attr( $member->minecraft_name ?? '' ); ?>" 
                            style="margin-top: 10px;"
                        />
                        <small style="color: #8a8f9b; display: block; margin-top: 10px; line-height: 1.5;">
                            ⚠️ <strong>Wichtig:</strong> Gib genau den Namen deines Minecraft Java Accounts an!<br>
                            Dieser wird auf die Whitelist gesetzt.
                        </small>
                    </label>
                </div>
                
                <label style="display: flex; align-items: flex-start; gap: 15px; margin: 25px 0; cursor: pointer; padding: 20px; background: rgba(244, 67, 54, 0.08); border-radius: 10px; border: 1px solid rgba(244, 67, 54, 0.2);">
                    <input type="checkbox" name="accept_rules" required style="width: 24px; height: 24px; cursor: pointer; margin-top: 2px; flex-shrink: 0;" />
                    <span style="color: #dcddde; font-size: 15px; line-height: 1.7;">
                        <strong style="color: #f44336; font-size: 16px;">Ich habe die Regeln vollständig gelesen und akzeptiere sie</strong><br>
                        <small style="color: #a0a8b8;">
                            Ich verpflichte mich, die Content-Pflichten einzuhalten und die Regeln zu respektieren.<br>
                            Bei Regelverstößen kann meine Teilnahme beendet werden.
                        </small>
                    </span>
                </label>
                
                <button type="submit" name="jc_accept_rules" class="jc-btn" style="width: 100%; font-size: 18px; padding: 16px;">
                    🚀 Regeln akzeptieren & fortfahren
                </button>
            </form>
        </div>
    </div>
    <?php
}

function jc_rules_discord_invite( $member, $discord_name ) {
    $discord_invite = 'https://discord.gg/jvCa5ENxeJ';
    ?>
    <div class="jc-wrap">
        <div class="jc-card" style="text-align: center;">
            <div style="font-size: 72px; margin-bottom: 20px;">🎉</div>
            <h1 class="jc-h" style="font-size: 36px;">Willkommen im Projekt!</h1>
            <p style="color: #a0a8b8; font-size: 17px; line-height: 1.8; margin: 20px 0 30px;">
                Danke <strong style="color: #fff;"><?php echo $discord_name; ?></strong>, dass du JustCreators unterstützt!
            </p>
            
            <!-- Profil Info -->
            <div style="background: rgba(88, 101, 242, 0.08); padding: 30px; border-radius: 12px; border: 2px solid rgba(88, 101, 242, 0.3); margin-bottom: 30px;">
                <h3 style="margin: 0 0 20px 0; color: #5865F2; font-size: 20px;">✅ Dein Profil</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                    <div style="background: rgba(255,255,255,0.03); padding: 18px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
                        <div style="font-size: 13px; color: #a0a8b8; margin-bottom: 6px;">👤 Discord</div>
                        <div style="font-size: 18px; font-weight: 700; color: #dcddde;"><?php echo $discord_name; ?></div>
                    </div>
                    <div style="background: rgba(255,255,255,0.03); padding: 18px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
                        <div style="font-size: 13px; color: #a0a8b8; margin-bottom: 6px;">🎮 Minecraft</div>
                        <div style="font-size: 18px; font-weight: 700; color: #8a8f9b;"><?php echo esc_html( $member->minecraft_name ); ?></div>
                    </div>
                    <div style="background: rgba(255,255,255,0.03); padding: 18px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08);">
                        <div style="font-size: 13px; color: #a0a8b8; margin-bottom: 6px;">📜 Regeln</div>
                        <div style="font-size: 18px; font-weight: 700; color: #4ade80;">✅ Akzeptiert</div>
                    </div>
                </div>
            </div>
            
            <!-- Discord Invite -->
            <div style="background: rgba(88, 101, 242, 0.1); padding: 40px; border-radius: 12px; border: 2px solid rgba(88, 101, 242, 0.3); margin-bottom: 25px;">
                <h3 style="color: #5865F2; margin: 0 0 15px 0; font-size: 24px;">📱 Nächster Schritt</h3>
                <p style="color: #dcddde; line-height: 1.8; margin-bottom: 30px; font-size: 16px;">
                    Klicke auf den Button, um dem <strong>JustCreators Discord Server</strong> beizutreten.<br>
                </p>
                <a 
                    href="<?php echo esc_url( $discord_invite ); ?>" 
                    target="_blank" 
                    rel="noopener"
                    class="jc-btn" 
                    style="font-size: 18px; padding: 18px 36px; display: inline-flex; box-shadow: 0 6px 20px rgba(88, 101, 242, 0.3);"
                >
                    Discord Server beitreten →
                </a>
            </div>
            
            <!-- Info -->
            <div style="background: rgba(74, 222, 128, 0.08); padding: 25px; border-radius: 10px; border-left: 4px solid #4ade80; text-align: left;">
                <h3 style="color: #4ade80; margin: 0 0 15px 0; font-size: 18px;">📋 Was passiert als nächstes?</h3>
                <ul style="color: #a0a8b8; line-height: 2; margin: 0; padding-left: 25px;">
                    <li>Du trittst dem Discord Server bei</li>
                    <li>Du bekommst Zugriff auf alle Teilnehmer-Bereiche</li>
                    <li>Minecraft Server-IP und Details findest du im Discord</li>
                    <li>Warte gespannt auf den Projektstart am 31.1.2026</li>
                </ul>
            </div>
        </div>
    </div>
    <?php
}

// ========================================
// FORMULAR VERARBEITUNG
// ========================================

function jc_rules_process_form( $discord_id, $discord_name ) {
    // Nonce Check
    if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'jc_rules_action' ) ) {
        return array( 'success' => false, 'message' => 'Sicherheitsprüfung fehlgeschlagen' );
    }
    
    $minecraft_name = sanitize_text_field( $_POST['minecraft_name'] ?? '' );
    
    // Validierung
    if ( empty( $minecraft_name ) ) {
        return array( 'success' => false, 'message' => 'Minecraft Name fehlt' );
    }
    
    if ( ! preg_match( '/^[a-zA-Z0-9_]{3,16}$/', $minecraft_name ) ) {
        return array( 'success' => false, 'message' => 'Ungültiger Minecraft Name (3-16 Zeichen, nur Buchstaben, Zahlen, Unterstriche)' );
    }
    
    if ( ! isset( $_POST['accept_rules'] ) ) {
        return array( 'success' => false, 'message' => 'Du musst die Regeln akzeptieren' );
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'jc_members';
    
    // Tabelle erstellen falls nicht vorhanden
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        discord_id varchar(100) NOT NULL,
        discord_name varchar(255) NOT NULL,
        minecraft_name varchar(50) DEFAULT NULL,
        rules_accepted tinyint(1) DEFAULT 0,
        rules_accepted_at datetime DEFAULT NULL,
        profile_completed tinyint(1) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY discord_id (discord_id)
    ) {$charset};";
    
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
    
    // Member erstellen/updaten
    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE discord_id = %s",
        $discord_id
    ) );
    
    if ( $existing ) {
        $wpdb->update( $table, array(
            'minecraft_name' => $minecraft_name,
            'rules_accepted' => 1,
            'rules_accepted_at' => current_time( 'mysql' ),
            'profile_completed' => 1
        ), array( 'discord_id' => $discord_id ), array( '%s', '%d', '%s', '%d' ), array( '%s' ) );
    } else {
        $wpdb->insert( $table, array(
            'discord_id' => $discord_id,
            'discord_name' => $discord_name,
            'minecraft_name' => $minecraft_name,
            'rules_accepted' => 1,
            'rules_accepted_at' => current_time( 'mysql' ),
            'profile_completed' => 1
        ), array( '%s', '%s', '%s', '%d', '%s', '%d' ) );
    }
    
    error_log( "JC Rules: {$discord_name} ({$discord_id}) akzeptiert - Minecraft: {$minecraft_name}" );
    
    return array( 'success' => true, 'message' => 'Willkommen im Projekt! Du kannst jetzt dem Discord beitreten.' );
}

// ========================================
// REST API - MEMBER EXPORT
// ========================================

add_action( 'rest_api_init', function() {
    register_rest_route( 'jc/v1', '/export-members', array(
        'methods' => 'GET',
        'callback' => 'jc_rules_api_export',
        'permission_callback' => 'jc_rules_api_auth'
    ) );
    
    register_rest_route( 'jc/v1', '/check-member/(?P<discord_id>[a-zA-Z0-9]+)', array(
        'methods' => 'GET',
        'callback' => 'jc_rules_api_check',
        'permission_callback' => 'jc_rules_api_auth'
    ) );
}, 40 );

function jc_rules_api_export() {
    global $wpdb;
    $table = $wpdb->prefix . 'jc_members';
    
    $members = $wpdb->get_results( 
        "SELECT discord_id, discord_name, minecraft_name, rules_accepted 
         FROM {$table} 
         WHERE rules_accepted = 1 
         ORDER BY discord_name ASC" 
    );
    
    error_log( "JC API Export: " . count( $members ) . " members" );
    
    return new WP_REST_Response( array(
        'success' => true,
        'members' => $members
    ), 200 );
}

function jc_rules_api_check( $request ) {
    $discord_id = sanitize_text_field( $request['discord_id'] );
    
    global $wpdb;
    $table = $wpdb->prefix . 'jc_members';
    
    $member = $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE discord_id = %s",
        $discord_id
    ) );
    
    if ( ! $member ) {
        return new WP_REST_Response( array( 'is_member' => false ), 200 );
    }
    
    return new WP_REST_Response( array(
        'is_member' => true,
        'rules_accepted' => (bool) $member->rules_accepted,
        'minecraft_name' => $member->minecraft_name,
        'profile_completed' => (bool) $member->profile_completed
    ), 200 );
}

function jc_rules_api_auth( $request ) {
    $auth = $request->get_header( 'authorization' );
    $secret = JC_RULES_API_SECRET;
    
    return ( $auth === 'Bearer ' . $secret );
}

// ========================================
// STYLES
// ========================================

function jc_rules_styles() {
    ?>
    <style>
        .jc-wrap { max-width: 900px; margin: 40px auto; padding: 40px 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        .jc-card { background: #2a2c36; padding: 40px; border-radius: 14px; box-shadow: 0 4px 20px rgba(0,0,0,0.4); }
        .jc-h { font-size: 28px; font-weight: 700; color: #fff; margin: 0 0 20px 0; line-height: 1.3; }
        .jc-label { display: block; color: #dcddde; font-weight: 600; margin: 20px 0 8px; font-size: 14px; }
        .jc-input { width: 100%; padding: 14px 18px; background: #1e1f26; border: 2px solid #3a3c46; border-radius: 8px; color: #fff; font-size: 16px; transition: all 0.3s; box-sizing: border-box; font-family: inherit; }
        .jc-input:focus { border-color: #5865F2; outline: none; box-shadow: 0 0 0 3px rgba(88, 101, 242, 0.15); }
        .jc-btn { display: inline-flex; align-items: center; justify-content: center; gap: 10px; padding: 14px 32px; background: #5865F2; color: #fff; border: none; border-radius: 8px; font-weight: 700; font-size: 16px; cursor: pointer; text-decoration: none; transition: all 0.3s; }
        .jc-btn:hover { background: #4752c4; transform: translateY(-2px); color: #fff; text-decoration: none; }
        .jc-rule-box { background: rgba(88, 101, 242, 0.08); padding: 25px; border-radius: 10px; border-left: 4px solid #5865F2; margin-bottom: 20px; }
        .jc-rule-box h3 { margin: 0 0 15px 0; font-size: 18px; }
        .jc-rule-box ul { color: #a0a8b8; line-height: 2; margin: 0; padding-left: 25px; }
        .jc-rule-box ul li strong { color: #dcddde; }
        .jc-msg { padding: 18px 24px; border-radius: 10px; margin: 20px 0; font-weight: 600; font-size: 15px; }
        .jc-success { background: rgba(74, 222, 128, 0.12); color: #4ade80; border-left: 4px solid #4ade80; }
        .jc-error { background: rgba(244, 67, 54, 0.12); color: #f44336; border-left: 4px solid #f44336; }
        .jc-input:invalid {background-color: #3a3c46 !important; /* dein grauer Hintergrund */border-color: #5a5e69 !important;    /* dezente graue Border */
}

        @media (max-width: 768px) {
            .jc-wrap { padding: 20px 15px; }
            .jc-card { padding: 25px 20px; }
            .jc-h { font-size: 24px; }
        }
    </style>
    <?php
}