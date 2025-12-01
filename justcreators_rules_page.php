<?php
/**
 * JustCreators Regeln-Seite - Komplett
 * Enthält: Discord OAuth, Regeln, Formular, REST API, Dynamische Invites
 * Version: 6.18 (Mit Modifikationen-Reiter)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ========================================
// DISCORD OAUTH KONFIGURATION
// ========================================
// Diese Werte müssen mit deiner Discord App übereinstimmen
define( 'JC_RULES_CLIENT_ID', '1436449319849824480' );
define( 'JC_RULES_CLIENT_SECRET', 'KTPe1JrmSRzvyKV_jbvmacQCLTwunDla' );
define( 'JC_RULES_REDIRECT_URI', 'https://just-creators.de/regeln' );
define( 'JC_RULES_API_SECRET', 'rootofant' ); // Secret für die interne API (Export)
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
            <div style="font-size: 64px; margin-bottom: 20px; animation: jc-pulse 2s infinite;">🔒</div>
            <h2 class="jc-h" style="justify-content: center;">Regeln & Richtlinien</h2>
            <p style="color: #a0a8b8; line-height: 1.8; margin: 20px 0 30px; font-size: 16px;">
                Diese Seite ist nur für <strong style="color: #5865F2;">akzeptierte JustCreators Bewerber</strong> zugänglich.<br>
                Bitte melde dich mit deinem Discord Account an.
            </p>
            <a class="jc-btn" href="<?php echo esc_url( $auth_url ); ?>">
                <svg style="width: 24px; height: 24px; fill: #fff;" viewBox="0 0 71 55" xmlns="http://www.w3.org/2000/svg">
                    <path d="M60.1045 4.8978C55.5792 2.8214 50.7265 1.2916 45.6527 0.41542C45.5603 0.39851 45.468 0.440769 45.4204 0.525289C44.7963 1.6353 44.105 3.0834 43.6209 4.2216C38.1637 3.4046 32.7345 3.4046 27.3892 4.2216C26.905 3.0581 26.1886 1.6353 25.5617 0.525289C25.5141 0.443589 25.4218 0.40133 25.3294 0.41542C20.2584 1.2888 15.4057 2.8186 10.8776 4.8978C10.8384 4.9147 10.8048 4.9429 10.7825 4.9795C1.57795 18.7309 -0.943561 32.1443 0.293408 45.3914C0.299005 45.4562 0.335386 45.5182 0.385761 45.5576C6.45866 50.0174 12.3413 52.7249 18.1147 54.5195C18.2071 54.5477 18.305 54.5139 18.3638 54.4378C19.7295 52.5728 20.9469 50.6063 21.9907 48.5383C22.0523 48.4172 21.9935 48.2735 21.8676 48.2256C19.9366 47.4931 18.0979 46.6 16.3292 45.5858C16.1893 45.5041 16.1781 45.304 16.3068 45.2082C16.679 44.9293 17.0513 44.6391 17.4067 44.3461C17.471 44.2926 17.5606 44.2813 17.6362 44.3151C29.2558 49.6202 41.8354 49.6202 53.3179 44.3151C53.3935 44.2785 53.4831 44.2898 53.5502 44.3433C53.9057 44.6363 54.2779 44.9293 54.6529 45.2082C54.7816 45.304 54.7732 45.5041 54.6333 45.5858C52.8646 46.6197 51.0259 47.4931 49.0921 48.2228C48.9662 48.2707 48.9102 48.4172 48.9718 48.5383C50.038 50.6034 51.2554 52.5699 52.5959 54.435C52.6519 54.5139 52.7526 54.5477 52.845 54.5195C58.6464 52.7249 64.529 50.0174 70.6019 45.5576C70.6551 45.5182 70.6887 45.459 70.6943 45.3942C72.1747 30.0791 68.2147 16.7757 60.1968 4.9823C60.1772 4.9429 60.1437 4.9147 60.1045 4.8978ZM23.7259 37.3253C20.2276 37.3253 17.3451 34.1136 17.3451 30.1693C17.3451 26.225 20.1717 23.0133 23.7259 23.0133C27.308 23.0133 30.1626 26.2532 30.1066 30.1693C30.1066 34.1136 27.28 37.3253 23.7259 37.3253ZM47.3178 37.3253C43.8196 37.3253 40.9371 34.1136 40.9371 30.1693C40.9371 26.225 43.7636 23.0133 47.3178 23.0133C50.9 23.0133 53.7545 26.2532 53.6986 30.1693C53.6986 34.1136 50.9 37.3253 47.3178 37.3253Z"/>
                </svg>
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
            <div style="font-size: 64px; margin-bottom: 20px; animation: jc-pulse 2s infinite;">📝</div>
            <h2 class="jc-h" style="justify-content: center;">Keine Bewerbung gefunden</h2>
            <p style="color: #a0a8b8; margin: 20px 0; font-size: 16px; line-height: 1.8;">
                Hallo <strong style="color: #f0f0f0;"><?php echo $discord_name; ?></strong>,<br>
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
                <div style="font-size: 64px; margin-bottom: 20px; animation: jc-pulse 2s infinite;">⏳</div>
                <h2 class="jc-h" style="justify-content: center;">Bewerbung wird geprüft</h2>
                
                <p style="color: #f0f0f0; font-size: 16px; margin: 20px 0; line-height: 1.8;">
                    Hallo <strong><?php echo $discord_name; ?></strong>,<br>
                    deine Bewerbung wird gerade geprüft.<br>
                    Wir melden uns innerhalb von 1-2 Tagen!
                </p>
                <?php else: ?>
                <div style="font-size: 64px; margin-bottom: 20px;">😔</div>
                <h2 class="jc-h" style="justify-content: center;">Bewerbung abgelehnt</h2>
                <p style="color: #f44336; font-size: 16px; margin: 20px 0; line-height: 1.8;">
                    Leider wurde deine Bewerbung nicht angenommen.
                </p>
            <?php endif; ?>
            <a href="<?php echo home_url( '/' ); ?>" class="jc-btn" style="background: rgba(88, 101, 242, 0.2) !important; border: 2px solid #5865F2 !important;">
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
            
            <div style="background: rgba(0,0,0,0.3); padding: 35px; border-radius: 12px; margin: 30px 0; animation: jc-fadeIn 0.6s ease-out;">
                <h2 style="color: #5865F2; margin: 0 0 25px 0; font-size: 24px; text-align: center; font-weight: 700;">
                    📜 JustCreators Season 2 - Regeln
                </h2>
                
                <div class="jc-rule-box" style="border-left-color: #5865F2;">
                    <h3 style="color: #5865F2;">🎮 Content Pflichten</h3>
                    <ul>
                        <li><strong>31.01.2026 (19:45):</strong> Projekt Start → Alle streamen/1 Video</li>
                        <li><strong>31.01 - 10.02:</strong> Min. 2 Streams und/oder 1 Video</li>
                        <li><strong>10.02.2026 (18:30):</strong> End-Eröffnung</li>
                        <li><strong>10.02 - 16.02:</strong> Min. 1 Stream und/oder 1 Video</li>
                        <li><strong>16.02.2026 (15:00):</strong> Shopping District Eröffnung</li>
                        <li><strong>16.02 - 20.03:</strong> Min. 8 Streams und/oder 3 Videos</li>
                        <li><strong>20.03.2026:</strong> Content-Ende → Keine Pflicht mehr!</li>
                        <li><strong>Ausnahmen:</strong> Ausnahmen können mit unserem Team besprochen werden!</li>
                    </ul>
                </div>
                
                <div class="jc-rule-box" style="border-left-color: #4ade80;">
                    <h3 style="color: #4ade80;">🤝 Verhalten & Kommunikation</h3>
                    <ul>
                        <li>Keine Beleidigungen gegenüber Teammitgliedern / Spielern</li>
                        <li>Angemessenes Verhalten im Discord und im Chat</li>
                        <li>AKeine GIFs, Memes oder Sticker in den Discord schicken.</li>
                        <li>Spammen in jeder Art ist untersagt.</li>
                        <li>Rassistische Äußerungen in jeder Art sind strengstens verboten!</li>
                    </ul>
                </div>
                
                <div class="jc-rule-box" style="border-left-color: #5865F2;">
                    <h3 style="color: #5865F2;">⛏️ Minecraft Server Regeln</h3>
                    <ul>
                        <li>Die Verwendung jeglicher Hack Clients oder Modifikationen welche nicht durch uns freigegeben wurden, sind strengstens untersagt.</li>
                        <li>Jegliche Modifikationen, die die Verbindung zu unseren Server verändern (z.B. VPN, WTFast, Proxy-Server), werden nicht unterstützt</li>
                        <li>Spiel/Plugin -fehler (Bugs) ausnutzen ist verboten. (Über Ausnahmen beim Support informieren)</li>
                        <li>Das Verfolgen von Absichten mit dem vorrangigen Ziel, anderen den Spielspaß zu nehmen oder das Nutzungserlebnis zu mindern, ist verboten.</li>
                        <li>Das Umgehen sämtlicher Strafen, beispielsweise durch ein VPN oder einen anderen Account ist untersagt.</li>
                        <li>DDoS ist verboten und wird dementsprechend zur Anzeige gebracht.</li>
                        <li>Jegliche Art von Lag produzierenden Maschinen und Aktivitäten sind verboten. (Informationen beim Support)</li>
                        <li>Kein unnötiges Töten von Spielern.</li>
                        <li>Griefen und/oder klauen ist strengstens untersagt!</li>
                        <li>Im Shopping District muss der angeschriebene Betrag bezahlt werden. Diebstahl ist verboten.</li>
                        <li>Es dürfen nur Items verkauft/gehandelt werden, welche auch im Discord von einem selber geclaimt wurden.</li>
                        <li>Das End darf erst beim offiziellen End Event (10.02.2026 um 18:30) betreten werden.</li>
                        <li>Das verändern, griefen und verwüsten vom Spawn ist strengstens untersagt</li>
                        <li>Regelmäßiges Streamen / Video Uploads sind Pflicht! (Informationen stehen im Upload/Stream Plan im Discord)</li>
                        <li>Bei einem Ban oder einer Konsequenz, darf der Bann nicht von einem selber/anderen Spielern in einem Video erwähnt werden</li>
                        <li>Öffentliche Kritiken über den Server zu verbreiten folgt zu einem Projekt Ausschluss</li>
                    </ul>
                </div>
                
                <div class="jc-rule-box" style="border-left-color: #ffc107;">
                    <h3 style="color: #ffc107;">🛠️ Erlaubte Modifikationen</h3>
                    <ul>
                        <li>Alle Modifikationen, die nicht explizit auf der offiziellen Liste stehen, sind <strong style="color: #f44336;">strikt verboten</strong>.</li>
                        <li>Eine vollständige Liste aller erlaubten Modifikationen findest du hier: <a href="https://just-creators.de/mods" target="_blank" style="color: #5865F2; text-decoration: none; font-weight: 600;">just-creators.de/mods</a></li>
                        <li>In diesem Projekt wird Simple Voice Chat verpfliichtend verwendet. Mit dem akzetieren der Regeln, verpflichtest du dich für die Beutzung!</li>
                        <li>Du kannst jederzeit neue Mods über den Support im Discord einreichen, um sie zur Liste hinzufügen zu lassen.</li>
                    </ul>
                </div>
                </div>
            
            <form method="POST" style="margin-top: 40px;">
                <?php wp_nonce_field( 'jc_rules_action' ); ?>
                
                <div style="background: rgba(88, 101, 242, 0.08); padding: 25px; border-radius: 10px; border: 1px solid rgba(88, 101, 242, 0.2); margin-bottom: 25px; animation: jc-fadeIn 0.6s ease-out;">
                    <label class="jc-label" style="margin-top: 0;">
                        Dein Minecraft Name (Java Edition) *
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
                        <small style="color: #a0a8b8; display: block; margin-top: 10px; line-height: 1.5; font-size: 14px;">
                            ⚠️ <strong style="color: #dcddde;">Wichtig:</strong> Gib genau den Namen deines Minecraft Java Accounts an!<br>
                            Dieser wird auf die Whitelist gesetzt.
                        </small>
                    </label>
                </div>
                
                <label style="display: flex; align-items: flex-start; gap: 15px; margin: 25px 0; cursor: pointer; padding: 20px; background: rgba(244, 67, 54, 0.08); border-radius: 10px; border: 1px solid rgba(244, 67, 54, 0.2); transition: all 0.3s ease; animation: jc-fadeIn 0.6s ease-out;">
                    <input type="checkbox" name="accept_rules" required style="width: 24px; height: 24px; cursor: pointer; margin-top: 2px; flex-shrink: 0;" />
                    <span style="color: #dcddde; font-size: 15px; line-height: 1.7;">
                        <strong style="color: #f44336; font-size: 16px;">Ich habe die Regeln vollständig gelesen und akzeptiere sie</strong><br>
                        <small style="color: #a0a8b8; font-size: 14px;">
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

// ########## START: ANPASSUNG INVITE-LOGIK (v6.12) ##########
function jc_rules_discord_invite( $member, $discord_name ) {
    
    // Rufe den dynamischen, einmaligen Invite-Link vom Bot ab
    $discord_invite = jc_rules_get_invite_link( $member->discord_id );
    
    ?>
    <div class="jc-wrap">
        <div class="jc-card" style="text-align: center;">
            <div style="font-size: 72px; margin-bottom: 20px; animation: jc-success-pop 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);">🎉</div>
            <h1 class="jc-h" style="font-size: 36px; justify-content: center;">Willkommen im Projekt!</h1>
            <p style="color: #a0a8b8; font-size: 17px; line-height: 1.8; margin: 20px 0 30px;">
                Danke <strong style="color: #f0f0f0;"><?php echo $discord_name; ?></strong>, dass du JustCreators unterstützt!
            </p>
            
            <div style="background: rgba(88, 101, 242, 0.08); padding: 30px; border-radius: 12px; border: 2px solid rgba(88, 101, 242, 0.3); margin-bottom: 30px; animation: jc-fadeIn 0.8s ease-out;">
                <h3 style="margin: 0 0 20px 0; color: #5865F2; font-size: 20px; font-weight: 700;">✅ Dein Profil</h3>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px;">
                    <div style="background: rgba(255,255,255,0.03); padding: 18px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08); animation: jc-slideIn 0.5s ease-out;">
                        <div style="font-size: 13px; color: #a0a8b8; margin-bottom: 6px;">👤 Discord</div>
                        <div style="font-size: 18px; font-weight: 700; color: #dcddde;"><?php echo $discord_name; ?></div>
                    </div>
                    <div style="background: rgba(255,255,255,0.03); padding: 18px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08); animation: jc-slideIn 0.5s ease-out 0.1s both;">
                        <div style="font-size: 13px; color: #a0a8b8; margin-bottom: 6px;">🎮 Minecraft</div>
                        <div style="font-size: 18px; font-weight: 700; color: #dcddde;"><?php echo esc_html( $member->minecraft_name ); ?></div>
                    </div>
                    <div style="background: rgba(255,255,255,0.03); padding: 18px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.08); animation: jc-slideIn 0.5s ease-out 0.2s both;">
                        <div style="font-size: 13px; color: #a0a8b8; margin-bottom: 6px;">📜 Regeln</div>
                        <div style="font-size: 18px; font-weight: 700; color: #4ade80;">✅ Akzeptiert</div>
                    </div>
                </div>
            </div>
            
            <?php if ( $discord_invite ): ?>
                <div style="background: rgba(88, 101, 242, 0.1); padding: 40px; border-radius: 12px; border: 2px solid rgba(88, 101, 242, 0.3); margin-bottom: 25px; animation: jc-fadeIn 0.8s ease-out 0.3s both;">
                    <h3 style="color: #5865F2; margin: 0 0 15px 0; font-size: 24px; font-weight: 700;">📱 Dein Persönlicher Invite</h3>
                    <p style="color: #dcddde; line-height: 1.8; margin-bottom: 30px; font-size: 16px;">
                        Klicke auf den Button, um dem <strong>JustCreators Discord Server</strong> beizutreten.<br>
                        <strong style="color: #ffc107;">⚠️ Dieser Link ist nur 1x gültig und läuft in 15 Minuten ab!</strong>
                    </p>
                    <a 
                        href="<?php echo esc_url( $discord_invite ); ?>" 
                        target="_blank" 
                        rel="noopener"
                        class="jc-btn" 
                        style="font-size: 18px; padding: 18px 36px; display: inline-flex; box-shadow: 0 6px 20px rgba(88, 101, 242, 0.3);"
                    >
                        <svg style="width: 24px; height: 24px; fill: #fff;" viewBox="0 0 71 55" xmlns="http://www.w3.org/2000/svg">
                            <path d="M60.1045 4.8978C55.5792 2.8214 50.7265 1.2916 45.6527 0.41542C45.5603 0.39851 45.468 0.440769 45.4204 0.525289C44.7963 1.6353 44.105 3.0834 43.6209 4.2216C38.1637 3.4046 32.7345 3.4046 27.3892 4.2216C26.905 3.0581 26.1886 1.6353 25.5617 0.525289C25.5141 0.443589 25.4218 0.40133 25.3294 0.41542C20.2584 1.2888 15.4057 2.8186 10.8776 4.8978C10.8384 4.9147 10.8048 4.9429 10.7825 4.9795C1.57795 18.7309 -0.943561 32.1443 0.293408 45.3914C0.299005 45.4562 0.335386 45.5182 0.385761 45.5576C6.45866 50.0174 12.3413 52.7249 18.1147 54.5195C18.2071 54.5477 18.305 54.5139 18.3638 54.4378C19.7295 52.5728 20.9469 50.6063 21.9907 48.5383C22.0523 48.4172 21.9935 48.2735 21.8676 48.2256C19.9366 47.4931 18.0979 46.6 16.3292 45.5858C16.1893 45.5041 16.1781 45.304 16.3068 45.2082C16.679 44.9293 17.0513 44.6391 17.4067 44.3461C17.471 44.2926 17.5606 44.2813 17.6362 44.3151C29.2558 49.6202 41.8354 49.6202 53.3179 44.3151C53.3935 44.2785 53.4831 44.2898 53.5502 44.3433C53.9057 44.6363 54.2779 44.9293 54.6529 45.2082C54.7816 45.304 54.7732 45.5041 54.6333 45.5858C52.8646 46.6197 51.0259 47.4931 49.0921 48.2228C48.9662 48.2707 48.9102 48.4172 48.9718 48.5383C50.038 50.6034 51.2554 52.5699 52.5959 54.435C52.6519 54.5139 52.7526 54.5477 52.845 54.5195C58.6464 52.7249 64.529 50.0174 70.6019 45.5576C70.6551 45.5182 70.6887 45.459 70.6943 45.3942C72.1747 30.0791 68.2147 16.7757 60.1968 4.9823C60.1772 4.9429 60.1437 4.9147 60.1045 4.8978ZM23.7259 37.3253C20.2276 37.3253 17.3451 34.1136 17.3451 30.1693C17.3451 26.225 20.1717 23.0133 23.7259 23.0133C27.308 23.0133 30.1626 26.2532 30.1066 30.1693C30.1066 34.1136 27.28 37.3253 23.7259 37.3253ZM47.3178 37.3253C43.8196 37.3253 40.9371 34.1136 40.9371 30.1693C40.9371 26.225 43.7636 23.0133 47.3178 23.0133C50.9 23.0133 53.7545 26.2532 53.6986 30.1693C53.6986 34.1136 50.9 37.3253 47.3178 37.3253Z"/>
                        </svg>
                        Discord Server beitreten →
                    </a>
                </div>
            <?php else: ?>
                <div style="background: rgba(244, 67, 54, 0.1); padding: 40px; border-radius: 12px; border: 2px solid rgba(244, 67, 54, 0.3); margin-bottom: 25px; animation: jc-fadeIn 0.8s ease-out 0.3s both;">
                    <h3 style="color: #f44336; margin: 0 0 15px 0; font-size: 24px; font-weight: 700;">❌ Fehler</h3>
                    <p style="color: #dcddde; line-height: 1.8; margin-bottom: 30px; font-size: 16px;">
                        Dein Profil ist vollständig, aber wir konnten gerade keinen persönlichen Einladungslink generieren.<br>
                        Bitte kontaktiere einen Admin im JustCreators Team-Discord.
                    </p>
                </div>
            <?php endif; ?>
            <div style="background: rgba(74, 222, 128, 0.08); padding: 25px; border-radius: 10px; border-left: 4px solid #4ade80; text-align: left; animation: jc-fadeIn 0.8s ease-out 0.4s both;">
                <h3 style="color: #4ade80; margin: 0 0 15px 0; font-size: 18px; font-weight: 700;">📋 Was passiert als nächstes?</h3>
                <ul style="color: #a0a8b8; line-height: 2; margin: 0; padding-left: 25px; font-size: 15px;">
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
// ########## ENDE: ANPASSUNG INVITE-LOGIK (v6.12) ##########


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
    
    return array( 'success' => true, 'message' => 'Willkommen im Projekt! Dein persönlicher Discord-Link wird jetzt geladen...' );
}

// ========================================
// HELPER: BOT API AUFRUF (v6.12)
// ========================================

function jc_rules_get_invite_link( $discord_id ) {
    // Diese Funktionen kommen aus der Haupt-Plugin-Datei (justcreators-bewerbungsportal.php)
    // Stelle sicher, dass die Haupt-Datei geladen wird!
    if ( ! function_exists( 'jc_get_bot_api_url' ) ) {
        error_log( 'JC Rules Invite: FATAL - Haupt-Plugin-Funktionen nicht gefunden!' );
        return false;
    }
    
    $api_url = jc_get_bot_api_url();
    $api_secret = jc_get_bot_api_secret();
   
    if ( empty( $api_url ) || empty( $api_secret ) ) {
        error_log( 'JC Rules Invite: Bot API nicht konfiguriert' );
        return false;
    }
   
    $response = wp_remote_post( $api_url . '/api/generate-invite', array(
        'headers' => array(
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $api_secret,
        ),
        'body' => wp_json_encode( array( 'discord_id' => $discord_id ) ),
        'timeout' => 20
    ) );
   
    if ( is_wp_error( $response ) ) {
        error_log( 'JC Rules Invite: Bot API Error - ' . $response->get_error_message() );
        return false;
    }
   
    $response_code = wp_remote_retrieve_response_code( $response );
    $response_body = json_decode( wp_remote_retrieve_body( $response ), true );
   
    if ( $response_code === 200 && isset( $response_body['success'] ) && $response_body['success'] && isset( $response_body['invite_url'] ) ) {
        error_log( 'JC Rules Invite: Einmal-Link für ' . $discord_id . ' erfolgreich geholt.' );
        return $response_body['invite_url'];
    }
   
    error_log( 'JC Rules Invite: Bot hat keinen Link zurückgegeben. Response: ' . wp_remote_retrieve_body( $response ) );
    return false;
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
        "SELECT discord_id, discord_name, minecraft_name, rules_accepted, rules_accepted_at, created_at 
         FROM {$table} 
         ORDER BY created_at ASC" 
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
        @keyframes jc-fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @keyframes jc-slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        @keyframes jc-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        @keyframes jc-success-pop {
            0% { transform: scale(0.8); opacity: 0; }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); opacity: 1; }
        }
        
        .jc-wrap { 
            background: linear-gradient(135deg, #1e1f26 0%, #2a2c36 100%);
            max-width: 900px; 
            margin: 50px auto; 
            padding: 50px; 
            border-radius: 16px;
            font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, Helvetica, sans-serif;
            box-shadow: 0 10px 40px rgba(0,0,0,0.4);
            animation: jc-fadeIn 0.6s ease-out;
        }
        
        .jc-card { 
            background: #2a2c36 !important;
            padding: 35px !important;
            border-radius: 14px !important;
            box-shadow: 0 4px 20px rgba(0,0,0,0.4);
            animation: jc-fadeIn 0.8s ease-out 0.2s both !important;
        }
        
        .jc-h { 
            font-size: 28px;
            font-weight: 700; 
            color: #f0f0f0; 
            margin: 0 0 20px 0; 
            line-height: 1.3;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: jc-slideIn 0.6s ease-out;
        }
        
        .jc-label { 
            display: block !important;
            color: #f0f0f0 !important; 
            font-weight: 600 !important; 
            margin: 20px 0 8px !important; 
            font-size: 15px !important;
        }
        
        .jc-input { 
            width: 100% !important;
            padding: 14px !important;
            margin-top: 8px !important;
            background: #3a3c4a !important;
            border: 2px solid #4a4c5a !important;
            border-radius: 10px !important;
            color: #ffffff !important;
            -webkit-text-fill-color: #ffffff !important;
            font-family: inherit !important;
            font-size: 15px !important;
            transition: all 0.3s ease !important;
            box-sizing: border-box !important;
        }
        
        .jc-input::placeholder {
            color: #a0a8b8 !important;
        }
        
        .jc-input:focus { 
            outline: none !important;
            border-color: #5865F2 !important;
            box-shadow: 0 0 0 3px rgba(88, 101, 242, 0.2) !important;
            transform: translateY(-1px);
        }
        
        .jc-input:invalid {
            background-color: #3a3c4a !important;
            border-color: #4a4c5a !important;
        }
        
        .jc-input:valid {
            background-color: #3a3c4a !important;
            border-color: #4a4c5a !important;
        }
        
        .jc-btn { 
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
        
        .jc-btn::before {
            content: '' !important;
            position: absolute !important;
            top: 0 !important;
            left: -100% !important;
            width: 100% !important;
            height: 100% !important;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent) !important;
            transition: left 0.5s !important;
        }
        
        .jc-btn:hover::before {
            left: 100% !important;
        }
        
        .jc-btn:hover { 
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 20px rgba(88, 101, 242, 0.6) !important;
            background: linear-gradient(135deg, #6470f3 0%, #5865F2 100%) !important;
            color: #fff !important;
            text-decoration: none !important;
        }
        
        .jc-rule-box { 
            background: rgba(88, 101, 242, 0.08) !important;
            padding: 25px !important;
            border-radius: 10px !important;
            border-left: 4px solid #5865F2 !important;
            margin-bottom: 20px !important;
            animation: jc-slideIn 0.5s ease-out;
            transition: all 0.3s ease !important;
        }
        
        .jc-rule-box:hover {
            background: rgba(88, 101, 242, 0.12) !important;
            transform: translateX(5px) !important;
        }
        
        .jc-rule-box h3 { 
            margin: 0 0 15px 0 !important;
            font-size: 18px !important;
            color: #dcddde !important;
            animation: jc-fadeIn 0.4s ease-out;
        }
        
        .jc-rule-box ul { 
            color: #a0a8b8 !important;
            line-height: 2 !important;
            margin: 0 !important;
            padding-left: 25px !important;
        }
        
        .jc-rule-box ul li { 
            animation: jc-fadeIn 0.4s ease-out;
        }
        
        .jc-rule-box ul li strong { 
            color: #dcddde !important;
        }
        
        .jc-msg { 
            padding: 18px 24px !important;
            border-radius: 10px !important;
            margin: 20px 0 !important;
            font-weight: 600 !important;
            font-size: 15px !important;
            animation: jc-fadeIn 0.4s ease-out;
        }
        
        .jc-success { 
            background: rgba(74, 222, 128, 0.12) !important;
            color: #4ade80 !important;
            border-left: 4px solid #4ade80 !important;
        }
        
        .jc-error { 
            background: rgba(244, 67, 54, 0.12) !important;
            color: #f44336 !important;
            border-left: 4px solid #f44336 !important;
        }
        
        input[type="checkbox"] {
            width: 24px !important;
            height: 24px !important;
            cursor: pointer !important;
            accent-color: #5865F2 !important;
            border-radius: 4px !important;
        }

        @media (max-width: 768px) {
            .jc-wrap { 
                padding: 30px 20px !important;
                margin: 20px auto !important;
            }
            .jc-card { 
                padding: 25px 20px !important;
            }
            .jc-h { 
                font-size: 24px !important;
            }
        }
    </style>
    <?php
}
?>