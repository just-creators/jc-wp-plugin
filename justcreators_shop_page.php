<?php
/**
 * JustCreators Shopping District
 * Version: 1.0.0
 * Beschreibung: Shop-Verwaltung für Season 2 Shopping District mit Discord OAuth
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ========================================
// DISCORD OAUTH KONFIGURATION
// ========================================
define( 'JC_SHOP_CLIENT_ID', '1436449319849824480' );
define( 'JC_SHOP_CLIENT_SECRET', 'KTPe1JrmSRzvyKV_jbvmacQCLTwunDla' );
define( 'JC_SHOP_REDIRECT_URI', 'https://just-creators.de/shopping-district' ); // WICHTIG: Anpassen!
define( 'JC_SHOP_WEBHOOK_URL', 'https://discord.com/api/webhooks/1467539825304404225/u8X6wrVdVBCGFqQpriuCjsilmFMAiUHpukiECf0nzPPuMpYxSgAzP7iyo3i9FB5p-WPC' );

// ========================================
// SESSION & INSTALLATION
// ========================================

function jc_shop_ensure_session() {
    if ( ! session_id() && ! headers_sent() ) {
        session_start();
    }
}
add_action( 'wp', 'jc_shop_ensure_session', 1 );

register_activation_hook( __FILE__, 'jc_shop_install' );

function jc_shop_install() {
    global $wpdb;
    $table = $wpdb->prefix . 'jc_shops';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        discord_id varchar(100) NOT NULL,
        discord_name varchar(255) NOT NULL,
        shop_name varchar(255) NOT NULL,
        items text NOT NULL,
        status varchar(50) DEFAULT 'draft',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY discord_id (discord_id),
        KEY status (status)
    ) {$charset};";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

// ========================================
// ADMIN MENU
// ========================================

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

add_action( 'admin_menu', 'jc_shop_register_menu' );

function jc_shop_register_menu() {
    add_submenu_page(
        JC_ADMIN_PARENT_SLUG,
        'Shopping District',
        'Shopping District',
        'manage_options',
        'jc-shopping-district',
        'jc_shop_render_admin_page'
    );
}

// ========================================
// DISCORD OAUTH CALLBACK
// ========================================

add_action( 'template_redirect', function() {
    jc_shop_ensure_session();

    if ( ! is_page( 'shopping-district' ) ) {
        return;
    }

    // OAuth Callback verarbeiten
    if ( isset( $_GET['code'] ) ) {
        $code = sanitize_text_field( $_GET['code'] );

        // Token holen
        $token_response = wp_remote_post( 'https://discord.com/api/oauth2/token', array(
            'body' => array(
                'client_id' => JC_SHOP_CLIENT_ID,
                'client_secret' => JC_SHOP_CLIENT_SECRET,
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => JC_SHOP_REDIRECT_URI
            ),
            'timeout' => 15
        ) );

        if ( is_wp_error( $token_response ) ) {
            wp_redirect( home_url( '/shopping-district?error=token' ) );
            exit;
        }

        $token_data = json_decode( wp_remote_retrieve_body( $token_response ), true );

        if ( ! isset( $token_data['access_token'] ) ) {
            wp_redirect( home_url( '/shopping-district?error=invalid' ) );
            exit;
        }

        // User Daten holen
        $user_response = wp_remote_get( 'https://discord.com/api/users/@me', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token_data['access_token']
            ),
            'timeout' => 15
        ) );

        if ( is_wp_error( $user_response ) ) {
            wp_redirect( home_url( '/shopping-district?error=user' ) );
            exit;
        }

        $user_data = json_decode( wp_remote_retrieve_body( $user_response ), true );

        if ( ! isset( $user_data['id'] ) ) {
            wp_redirect( home_url( '/shopping-district?error=invalid' ) );
            exit;
        }

        // In Session UND Cookie speichern
        $_SESSION['jc_shop_discord_user'] = $user_data;

        setcookie(
            'jc_shop_discord_id',
            $user_data['id'],
            time() + 3600,
            '/',
            '',
            true,
            true
        );
        setcookie(
            'jc_shop_discord_name',
            $user_data['username'],
            time() + 3600,
            '/',
            '',
            true,
            true
        );

        // Redirect zurück (ohne ?code)
        wp_redirect( home_url( '/shopping-district' ) );
        exit;
    }
}, 5 );

// ========================================
// SHORTCODE
// ========================================

add_shortcode( 'jc_shopping_district', 'jc_shop_render_page' );

function jc_shop_render_page() {
    ob_start();

    global $wpdb;
    $table = $wpdb->prefix . 'jc_shops';
    $member_table = $wpdb->prefix . 'jc_members';

    // Session/Cookie Check
    $discord_user = null;
    $is_logged_in = false;

    if ( isset( $_SESSION['jc_shop_discord_user'] ) ) {
        $discord_user = $_SESSION['jc_shop_discord_user'];
        $is_logged_in = true;
    } elseif ( isset( $_COOKIE['jc_shop_discord_id'] ) && isset( $_COOKIE['jc_shop_discord_name'] ) ) {
        $_SESSION['jc_shop_discord_user'] = array(
            'id' => sanitize_text_field( $_COOKIE['jc_shop_discord_id'] ),
            'username' => sanitize_text_field( $_COOKIE['jc_shop_discord_name'] )
        );
        $discord_user = $_SESSION['jc_shop_discord_user'];
        $is_logged_in = true;
    }

    // Formular verarbeiten (Shop erstellen)
    if ( $is_logged_in && isset( $_POST['jc_shop_create'] ) ) {
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'jc_shop_create' ) ) {
            echo '<div class="jc-msg jc-error">❌ Sicherheitsprüfung fehlgeschlagen</div>';
        } else {
            $discord_id = sanitize_text_field( $discord_user['id'] );
            $discord_name = sanitize_text_field( $discord_user['username'] );
            $shop_name = sanitize_text_field( $_POST['shop_name'] ?? '' );
            $items = sanitize_textarea_field( $_POST['items'] ?? '' );

            if ( empty( $shop_name ) || empty( $items ) ) {
                echo '<div class="jc-msg jc-error">❌ Shop Name und Items sind erforderlich</div>';
            } else {
                // Prüfe ob User bereits einen Shop hat
                $existing_shop = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE discord_id = %s",
                    $discord_id
                ) );

                if ( $existing_shop > 0 ) {
                    echo '<div class="jc-msg jc-error">❌ Du hast bereits einen Shop eingereicht. Bei Fragen öffne ein Ticket im Discord!</div>';
                } else {
                    // Prüfe ob User Member ist
                    $is_member = $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$member_table} WHERE discord_id = %s AND rules_accepted = 1",
                        $discord_id
                    ) );

                    if ( ! $is_member ) {
                        echo '<div class="jc-msg jc-error">❌ Du musst erst die Regeln akzeptieren um einen Shop zu erstellen</div>';
                    } else {
                        // Shop erstellen
                        $wpdb->insert( $table, array(
                            'discord_id' => $discord_id,
                            'discord_name' => $discord_name,
                            'shop_name' => $shop_name,
                            'items' => $items,
                            'status' => 'draft'
                        ), array( '%s', '%s', '%s', '%s', '%s' ) );

                        // Discord Webhook senden
                        jc_shop_send_webhook_notification( $discord_name, $shop_name, $items );

                        echo '<div class="jc-msg jc-success">✅ Shop eingereicht! Ein Admin wird ihn bald überprüfen.</div>';
                    }
                }
            }
        }
    }

    // Logout Handler
    if ( isset( $_GET['logout'] ) ) {
        unset( $_SESSION['jc_shop_discord_user'] );
        setcookie( 'jc_shop_discord_id', '', time() - 3600, '/', '', true, true );
        setcookie( 'jc_shop_discord_name', '', time() - 3600, '/', '', true, true );
        wp_redirect( home_url( '/shopping-district' ) );
        exit;
    }

    // Alle akzeptierten Shops laden
    $accepted_shops = $wpdb->get_results(
        "SELECT * FROM {$table} WHERE status = 'accepted' ORDER BY created_at DESC"
    );

    // User's eigenen Shop laden (falls angemeldet)
    $user_shop = null;
    if ( $is_logged_in ) {
        $user_shop = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE discord_id = %s ORDER BY created_at DESC LIMIT 1",
            $discord_user['id']
        ) );
    }

    ?>
    <div class="jc-wrap">
        <!-- Header mit Login Button -->
        <div class="jc-hero">
            <div class="jc-hero-left">
                <div class="jc-kicker"><span>🏪</span><span>Season 2</span></div>
                <h1 class="jc-hero-title">Shopping District</h1>
                <p class="jc-hero-sub">Entdecke die Shops der Creator und erstelle deinen eigenen.</p>
            </div>
            <div class="jc-hero-right">
                <?php if ( $is_logged_in ) : ?>
                    <div style="text-align: right;">
                        <div style="color: #dcddde; margin-bottom: 8px; font-size: 14px;">
                            👋 <?php echo esc_html( $discord_user['username'] ); ?>
                        </div>
                        <a href="?logout=1" class="jc-btn-small" style="background: rgba(244, 67, 54, 0.2) !important; border: 1px solid #f44336 !important;">
                            Abmelden
                        </a>
                    </div>
                <?php else : ?>
                    <?php
                    $params = array(
                        'client_id' => JC_SHOP_CLIENT_ID,
                        'redirect_uri' => JC_SHOP_REDIRECT_URI,
                        'response_type' => 'code',
                        'scope' => 'identify'
                    );
                    $auth_url = 'https://discord.com/api/oauth2/authorize?' . http_build_query( $params );
                    ?>
                    <a class="jc-btn" href="<?php echo esc_url( $auth_url ); ?>" style="font-size: 14px !important; padding: 10px 20px !important;">
                        <svg style="width: 20px; height: 20px; fill: #fff;" viewBox="0 0 71 55" xmlns="http://www.w3.org/2000/svg">
                            <path d="M60.1045 4.8978C55.5792 2.8214 50.7265 1.2916 45.6527 0.41542C45.5603 0.39851 45.468 0.440769 45.4204 0.525289C44.7963 1.6353 44.105 3.0834 43.6209 4.2216C38.1637 3.4046 32.7345 3.4046 27.3892 4.2216C26.905 3.0581 26.1886 1.6353 25.5617 0.525289C25.5141 0.443589 25.4218 0.40133 25.3294 0.41542C20.2584 1.2888 15.4057 2.8186 10.8776 4.8978C10.8384 4.9147 10.8048 4.9429 10.7825 4.9795C1.57795 18.7309 -0.943561 32.1443 0.293408 45.3914C0.299005 45.4562 0.335386 45.5182 0.385761 45.5576C6.45866 50.0174 12.3413 52.7249 18.1147 54.5195C18.2071 54.5477 18.305 54.5139 18.3638 54.4378C19.7295 52.5728 20.9469 50.6063 21.9907 48.5383C22.0523 48.4172 21.9935 48.2735 21.8676 48.2256C19.9366 47.4931 18.0979 46.6 16.3292 45.5858C16.1893 45.5041 16.1781 45.304 16.3068 45.2082C16.679 44.9293 17.0513 44.6391 17.4067 44.3461C17.471 44.2926 17.5606 44.2813 17.6362 44.3151C29.2558 49.6202 41.8354 49.6202 53.3179 44.3151C53.3935 44.2785 53.4831 44.2898 53.5502 44.3433C53.9057 44.6363 54.2779 44.9293 54.6529 45.2082C54.7816 45.304 54.7732 45.5041 54.6333 45.5858C52.8646 46.6197 51.0259 47.4931 49.0921 48.2228C48.9662 48.2707 48.9102 48.4172 48.9718 48.5383C50.038 50.6034 51.2554 52.5699 52.5959 54.435C52.6519 54.5139 52.7526 54.5477 52.845 54.5195C58.6464 52.7249 64.529 50.0174 70.6019 45.5576C70.6551 45.5182 70.6887 45.459 70.6943 45.3942C72.1747 30.0791 68.2147 16.7757 60.1968 4.9823C60.1772 4.9429 60.1437 4.9147 60.1045 4.8978ZM23.7259 37.3253C20.2276 37.3253 17.3451 34.1136 17.3451 30.1693C17.3451 26.225 20.1717 23.0133 23.7259 23.0133C27.308 23.0133 30.1626 26.2532 30.1066 30.1693C30.1066 34.1136 27.28 37.3253 23.7259 37.3253ZM47.3178 37.3253C43.8196 37.3253 40.9371 34.1136 40.9371 30.1693C40.9371 26.225 43.7636 23.0133 47.3178 23.0133C50.9 23.0133 53.7545 26.2532 53.6986 30.1693C53.6986 34.1136 50.9 37.3253 47.3178 37.3253Z"/>
                        </svg>
                        Anmelden
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ( $is_logged_in && $user_shop && $user_shop->status === 'draft' ) : ?>
            <!-- User hat bereits einen Shop eingereicht (Draft) -->
            <div class="jc-card" style="margin-top: 30px; background: rgba(255, 193, 7, 0.1) !important; border: 1px solid rgba(255, 193, 7, 0.3) !important;">
                <div style="text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 15px;">⏳</div>
                    <h2 class="jc-h" style="justify-content: center; margin-bottom: 10px;">Shop wird geprüft</h2>
                    <p style="color: #dcddde; font-size: 15px; line-height: 1.8;">
                        Dein Shop <strong style="color: #ffc107;">"<?php echo esc_html( $user_shop->shop_name ); ?>"</strong> wurde eingereicht.<br>
                        Ein Admin wird ihn bald überprüfen und freischalten.
                    </p>
                    <p style="color: #a0a8b8; font-size: 14px; margin-top: 15px;">
                        💡 <strong>Weitere Shops?</strong> Erstelle ein Ticket im Discord!
                    </p>
                </div>
            </div>
        <?php elseif ( $is_logged_in && $user_shop && $user_shop->status === 'rejected' ) : ?>
            <!-- User's Shop wurde abgelehnt -->
            <div class="jc-card" style="margin-top: 30px; background: rgba(244, 67, 54, 0.1) !important; border: 1px solid rgba(244, 67, 54, 0.3) !important;">
                <div style="text-align: center;">
                    <div style="font-size: 48px; margin-bottom: 15px;">❌</div>
                    <h2 class="jc-h" style="justify-content: center; margin-bottom: 10px;">Shop abgelehnt</h2>
                    <p style="color: #dcddde; font-size: 15px; line-height: 1.8;">
                        Dein Shop <strong style="color: #f44336;">"<?php echo esc_html( $user_shop->shop_name ); ?>"</strong> wurde leider abgelehnt.<br>
                        Bitte erstelle ein Ticket im Discord für weitere Informationen.
                    </p>
                </div>
            </div>
        <?php elseif ( $is_logged_in && ( ! $user_shop || $user_shop->status === 'rejected' ) ) : ?>
            <!-- Shop Erstellungsformular -->
            <div class="jc-card" style="margin-top: 30px;">
                <h2 class="jc-h">🏪 Erstelle deinen Shop</h2>
                <p style="color: #a0a8b8; margin-bottom: 25px; line-height: 1.6;">
                    Fülle das Formular aus, um deinen Shop für den Shopping District einzureichen.
                </p>

                <form method="POST" style="margin-top: 20px;">
                    <?php wp_nonce_field( 'jc_shop_create' ); ?>

                    <div style="margin-bottom: 20px;">
                        <label class="jc-label">Shop Name *</label>
                        <input
                            class="jc-input"
                            type="text"
                            name="shop_name"
                            required
                            placeholder="z.B. Meine Redstone Werkstatt"
                            maxlength="100"
                        />
                    </div>

                    <div style="margin-bottom: 25px;">
                        <label class="jc-label">Was verkaufst du? *</label>
                        <textarea
                            class="jc-input"
                            name="items"
                            required
                            rows="4"
                            placeholder="z.B. Redstone-Komponenten, Schaltungen, Farmen-Designs"
                            style="resize: vertical;"
                        ></textarea>
                        <small style="color: #a0a8b8; display: block; margin-top: 8px; font-size: 13px;">
                            Beschreibe deine Items oder Gruppen von Items
                        </small>
                    </div>

                    <div style="background: rgba(74, 222, 128, 0.08); padding: 18px; border-radius: 10px; border-left: 4px solid #4ade80; margin-bottom: 25px;">
                        <p style="color: #dcddde; margin: 0; font-size: 14px; line-height: 1.7;">
                            💡 <strong>Hinweis:</strong> Weitere Shops können später über ein Ticket im Discord erstellt werden.
                        </p>
                    </div>

                    <button type="submit" name="jc_shop_create" class="jc-btn" style="width: 100%; font-size: 16px; padding: 14px;">
                        🚀 Shop einreichen
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <!-- Alle akzeptierten Shops anzeigen -->
        <div style="margin-top: 40px;">
            <h2 class="jc-h" style="font-size: 28px; margin-bottom: 20px;">
                🛒 Aktive Shops
                <span style="background: rgba(88, 101, 242, 0.2); color: #5865F2; padding: 4px 12px; border-radius: 20px; font-size: 14px; margin-left: 12px;">
                    <?php echo count( $accepted_shops ); ?>
                </span>
            </h2>

            <?php if ( empty( $accepted_shops ) ) : ?>
                <div class="jc-card" style="text-align: center; padding: 50px 30px !important;">
                    <div style="font-size: 64px; margin-bottom: 15px; opacity: 0.5;">🏪</div>
                    <p style="color: #a0a8b8; font-size: 16px;">
                        Noch keine Shops vorhanden.<br>
                        Sei der Erste und erstelle einen!
                    </p>
                </div>
            <?php else : ?>
                <div class="jc-grid">
                    <?php foreach ( $accepted_shops as $shop ) :
                        // Hole Profilbild vom Teilnehmer
                        $teilnehmer_table = $wpdb->prefix . 'jc_teilnehmer';
                        $teilnehmer = $wpdb->get_row( $wpdb->prepare(
                            "SELECT profile_image_url, display_name FROM {$teilnehmer_table}
                             WHERE application_id IN (
                                 SELECT id FROM {$wpdb->prefix}jc_discord_applications
                                 WHERE discord_id = %s
                             ) LIMIT 1",
                            $shop->discord_id
                        ) );

                        $creator_name = $teilnehmer ? $teilnehmer->display_name : $shop->discord_name;
                        $profile_image = $teilnehmer && $teilnehmer->profile_image_url
                            ? $teilnehmer->profile_image_url
                            : 'https://via.placeholder.com/300x300/1e2740/6c7bff?text=' . urlencode( substr( $creator_name, 0, 2 ) );
                    ?>
                    <div class="jc-shop-card">
                        <div class="jc-shop-header">
                            <img src="<?php echo esc_url( $profile_image ); ?>" class="jc-shop-avatar" alt="">
                            <div class="jc-shop-meta">
                                <h3 class="jc-shop-name"><?php echo esc_html( $shop->shop_name ); ?></h3>
                                <p class="jc-shop-owner">von <?php echo esc_html( $creator_name ); ?></p>
                            </div>
                        </div>
                        <div class="jc-shop-items">
                            <div class="jc-shop-items-label">📦 Verkauft:</div>
                            <p class="jc-shop-items-text"><?php echo nl2br( esc_html( $shop->items ) ); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php
    jc_shop_styles();
    return ob_get_clean();
}

// ========================================
// DISCORD WEBHOOK
// ========================================

function jc_shop_send_webhook_notification( $discord_name, $shop_name, $items ) {
    $webhook_url = JC_SHOP_WEBHOOK_URL;

    $embed = array(
        'title' => '🏪 Neuer Shop eingereicht!',
        'description' => "**Shop:** {$shop_name}\n**Creator:** {$discord_name}\n\n**Verkauft:**\n{$items}",
        'color' => hexdec( '5865F2' ),
        'timestamp' => date( 'c' ),
        'footer' => array(
            'text' => 'JustCreators Shopping District'
        )
    );

    $payload = array(
        'embeds' => array( $embed )
    );

    wp_remote_post( $webhook_url, array(
        'headers' => array( 'Content-Type' => 'application/json' ),
        'body' => wp_json_encode( $payload ),
        'timeout' => 10
    ) );
}

// ========================================
// ADMIN PAGE
// ========================================

add_action( 'admin_init', 'jc_shop_handle_admin_actions' );

function jc_shop_handle_admin_actions() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    global $wpdb;
    $table = $wpdb->prefix . 'jc_shops';

    // Shop annehmen
    if ( isset( $_POST['jc_shop_accept'] ) && isset( $_POST['shop_id'] ) ) {
        $id = intval( $_POST['shop_id'] );
        check_admin_referer( 'jc_shop_accept_' . $id );

        $wpdb->update( $table, array( 'status' => 'accepted' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
        add_settings_error( 'jc_shop', 'accepted', 'Shop akzeptiert!', 'updated' );
    }

    // Shop ablehnen
    if ( isset( $_POST['jc_shop_reject'] ) && isset( $_POST['shop_id'] ) ) {
        $id = intval( $_POST['shop_id'] );
        check_admin_referer( 'jc_shop_reject_' . $id );

        $wpdb->update( $table, array( 'status' => 'rejected' ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
        add_settings_error( 'jc_shop', 'rejected', 'Shop abgelehnt.', 'updated' );
    }

    // Shop löschen
    if ( isset( $_POST['jc_shop_delete'] ) && isset( $_POST['shop_id'] ) ) {
        $id = intval( $_POST['shop_id'] );
        check_admin_referer( 'jc_shop_delete_' . $id );

        $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
        add_settings_error( 'jc_shop', 'deleted', 'Shop gelöscht.', 'updated' );
    }

    // Shop bearbeiten
    if ( isset( $_POST['jc_shop_edit_save'] ) && isset( $_POST['shop_id'] ) ) {
        $id = intval( $_POST['shop_id'] );
        check_admin_referer( 'jc_shop_edit_' . $id );

        $wpdb->update( $table, array(
            'shop_name' => sanitize_text_field( $_POST['shop_name'] ),
            'items' => sanitize_textarea_field( $_POST['items'] ),
            'status' => sanitize_text_field( $_POST['status'] )
        ), array( 'id' => $id ), array( '%s', '%s', '%s' ), array( '%d' ) );

        add_settings_error( 'jc_shop', 'updated', 'Shop aktualisiert!', 'updated' );
    }
}

function jc_shop_render_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Keine Berechtigung' );
    }

    global $wpdb;
    $table = $wpdb->prefix . 'jc_shops';

    // Sicherstellen, dass die Tabelle existiert
    jc_shop_install();

    settings_errors( 'jc_shop' );

    // Edit Mode
    if ( isset( $_GET['edit'] ) ) {
        $shop = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $_GET['edit'] ) );
        if ( $shop ) {
            ?>
            <div class="wrap">
                <h1>Shop bearbeiten</h1>
                <form method="post">
                    <?php wp_nonce_field( 'jc_shop_edit_' . $shop->id ); ?>
                    <input type="hidden" name="shop_id" value="<?php echo $shop->id; ?>">
                    <table class="form-table">
                        <tr>
                            <th>Shop Name</th>
                            <td><input name="shop_name" value="<?php echo esc_attr( $shop->shop_name ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th>Items</th>
                            <td><textarea name="items" rows="4" class="large-text"><?php echo esc_textarea( $shop->items ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th>Status</th>
                            <td>
                                <select name="status">
                                    <option value="draft" <?php selected( $shop->status, 'draft' ); ?>>Entwurf</option>
                                    <option value="accepted" <?php selected( $shop->status, 'accepted' ); ?>>Akzeptiert</option>
                                    <option value="rejected" <?php selected( $shop->status, 'rejected' ); ?>>Abgelehnt</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th>Creator</th>
                            <td><?php echo esc_html( $shop->discord_name ); ?> (<?php echo esc_html( $shop->discord_id ); ?>)</td>
                        </tr>
                        <tr>
                            <th>Erstellt am</th>
                            <td><?php echo esc_html( $shop->created_at ); ?></td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" name="jc_shop_edit_save" class="button button-primary">Speichern</button>
                        <a href="?page=jc-shopping-district" class="button">Zurück</a>
                    </p>
                </form>
            </div>
            <?php
            return;
        }
    }

    // List Mode
    $shops = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC" );

    // Debug: Prüfe ob Tabelle existiert
    $table_exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;

    ?>
    <div class="wrap">
        <h1>🏪 Shopping District Verwaltung</h1>

        <div class="notice notice-info" style="margin-top: 20px;">
            <p><strong>Redirect URL für Discord OAuth:</strong> <code><?php echo JC_SHOP_REDIRECT_URI; ?></code></p>
            <p style="margin-top: 10px;"><strong>Webhook URL:</strong> <code><?php echo substr( JC_SHOP_WEBHOOK_URL, 0, 50 ); ?>...</code></p>
            <p style="margin-top: 10px;"><strong>Debug:</strong> Tabelle existiert: <?php echo $table_exists ? '✅ Ja' : '❌ Nein'; ?> | Shops gefunden: <?php echo count( $shops ); ?> | Tabelle: <code><?php echo $table; ?></code></p>
        </div>

        <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
            <thead>
                <tr>
                    <th>Shop Name</th>
                    <th>Creator</th>
                    <th>Items</th>
                    <th>Status</th>
                    <th>Erstellt</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $shops ) ) : ?>
                    <tr><td colspan="6" style="text-align: center; padding: 30px;">Keine Shops vorhanden</td></tr>
                <?php else : ?>
                    <?php foreach ( $shops as $shop ) : ?>
                        <tr>
                            <td><strong><?php echo esc_html( $shop->shop_name ); ?></strong></td>
                            <td><?php echo esc_html( $shop->discord_name ); ?></td>
                            <td style="max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?php echo esc_html( substr( $shop->items, 0, 100 ) ); ?>...
                            </td>
                            <td>
                                <?php if ( $shop->status === 'draft' ) : ?>
                                    <span style="background: #ffc107; color: #000; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: bold;">⏳ Entwurf</span>
                                <?php elseif ( $shop->status === 'accepted' ) : ?>
                                    <span style="background: #4ade80; color: #000; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: bold;">✅ Akzeptiert</span>
                                <?php else : ?>
                                    <span style="background: #f44336; color: #fff; padding: 4px 10px; border-radius: 4px; font-size: 12px; font-weight: bold;">❌ Abgelehnt</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( date( 'd.m.Y H:i', strtotime( $shop->created_at ) ) ); ?></td>
                            <td>
                                <a href="?page=jc-shopping-district&edit=<?php echo $shop->id; ?>" class="button button-small">Bearbeiten</a>

                                <?php if ( $shop->status === 'draft' ) : ?>
                                    <form method="post" style="display: inline; margin-left: 4px;">
                                        <?php wp_nonce_field( 'jc_shop_accept_' . $shop->id ); ?>
                                        <input type="hidden" name="shop_id" value="<?php echo $shop->id; ?>">
                                        <button type="submit" name="jc_shop_accept" class="button button-small button-primary">✅ Annehmen</button>
                                    </form>
                                    <form method="post" style="display: inline; margin-left: 4px;">
                                        <?php wp_nonce_field( 'jc_shop_reject_' . $shop->id ); ?>
                                        <input type="hidden" name="shop_id" value="<?php echo $shop->id; ?>">
                                        <button type="submit" name="jc_shop_reject" class="button button-small" style="color: #f44336;">❌ Ablehnen</button>
                                    </form>
                                <?php endif; ?>

                                <form method="post" style="display: inline; margin-left: 4px;" onsubmit="return confirm('Wirklich löschen?');">
                                    <?php wp_nonce_field( 'jc_shop_delete_' . $shop->id ); ?>
                                    <input type="hidden" name="shop_id" value="<?php echo $shop->id; ?>">
                                    <button type="submit" name="jc_shop_delete" class="button button-small" style="color: red;">🗑️ Löschen</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <p style="margin-top: 20px;">
            <strong>Shortcode für die Seite:</strong> <code>[jc_shopping_district]</code>
        </p>
    </div>
    <?php
}

// ========================================
// STYLES
// ========================================

function jc_shop_styles() {
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

        .jc-wrap {
            background: linear-gradient(135deg, #1e1f26 0%, #2a2c36 100%);
            max-width: 1200px;
            width: min(1200px, calc(100% - 40px));
            margin: 50px auto;
            padding: 50px;
            border-radius: 16px;
            font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, Helvetica, sans-serif;
            box-shadow: 0 10px 40px rgba(0,0,0,0.4);
            animation: jc-fadeIn 0.6s ease-out;
        }

        .jc-hero {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 22px;
            background: radial-gradient(120% 140% at 10% 10%, rgba(108, 123, 255, 0.12), transparent 50%), #2a2c36;
            border: 1px solid #1e2740;
            border-radius: 20px;
            padding: 28px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 22px 60px rgba(0,0,0,0.45);
            margin-bottom: 30px;
        }

        .jc-hero-left {
            position: relative;
            z-index: 1;
        }

        .jc-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            background: rgba(108, 123, 255, 0.15);
            border: 1px solid rgba(108, 123, 255, 0.35);
            border-radius: 99px;
            font-size: 13px;
            text-transform: uppercase;
            font-weight: 600;
            color: #dcddde;
        }

        .jc-hero-title {
            margin: 10px 0 6px;
            font-size: 32px;
            color: #f0f0f0;
            font-weight: 700;
        }

        .jc-hero-sub {
            color: #9eb3d5;
            line-height: 1.6;
            max-width: 680px;
            font-size: 15px;
        }

        .jc-hero-right {
            display: flex;
            align-items: center;
            justify-content: flex-end;
        }

        .jc-card {
            background: #2a2c36 !important;
            padding: 35px !important;
            border-radius: 14px !important;
            box-shadow: 0 4px 20px rgba(0,0,0,0.4);
            animation: jc-fadeIn 0.8s ease-out 0.2s both !important;
            border: 1px solid #1e2740 !important;
        }

        .jc-h {
            font-size: 24px;
            font-weight: 700;
            color: #f0f0f0;
            margin: 0 0 15px 0;
            line-height: 1.3;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .jc-label {
            display: block !important;
            color: #f0f0f0 !important;
            font-weight: 600 !important;
            margin: 0 0 8px !important;
            font-size: 15px !important;
        }

        .jc-input {
            width: 100% !important;
            padding: 12px !important;
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

        .jc-btn {
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            gap: 10px !important;
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
        }

        .jc-btn:hover {
            transform: translateY(-2px) !important;
            box-shadow: 0 6px 20px rgba(88, 101, 242, 0.6) !important;
            background: linear-gradient(135deg, #6470f3 0%, #5865F2 100%) !important;
            color: #fff !important;
            text-decoration: none !important;
        }

        .jc-btn-small {
            display: inline-flex !important;
            align-items: center !important;
            gap: 6px !important;
            padding: 8px 16px !important;
            border-radius: 8px !important;
            background: rgba(88, 101, 242, 0.2) !important;
            color: #dcddde !important;
            text-decoration: none !important;
            font-weight: 600 !important;
            font-size: 13px !important;
            border: 1px solid rgba(88, 101, 242, 0.3) !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
        }

        .jc-btn-small:hover {
            background: rgba(88, 101, 242, 0.3) !important;
            text-decoration: none !important;
        }

        .jc-msg {
            padding: 16px 20px !important;
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

        .jc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        .jc-shop-card {
            background: #2a2c36;
            border: 1px solid #1e2740;
            border-radius: 14px;
            padding: 20px;
            transition: all 0.3s ease;
            animation: jc-fadeIn 0.6s ease-out;
        }

        .jc-shop-card:hover {
            transform: translateY(-4px);
            border-color: #5865F2;
            box-shadow: 0 8px 24px rgba(88, 101, 242, 0.2);
        }

        .jc-shop-header {
            display: flex;
            gap: 14px;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid #1e2740;
        }

        .jc-shop-avatar {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #1e2740;
        }

        .jc-shop-meta {
            flex: 1;
            min-width: 0;
        }

        .jc-shop-name {
            margin: 0;
            font-size: 18px;
            font-weight: 700;
            color: #f0f0f0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .jc-shop-owner {
            margin: 4px 0 0;
            font-size: 13px;
            color: #9eb3d5;
        }

        .jc-shop-items {
            background: rgba(108, 123, 255, 0.06);
            padding: 14px;
            border-radius: 8px;
            border: 1px solid rgba(108, 123, 255, 0.15);
        }

        .jc-shop-items-label {
            font-size: 12px;
            font-weight: 700;
            color: #5865F2;
            text-transform: uppercase;
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .jc-shop-items-text {
            margin: 0;
            color: #dcddde;
            font-size: 14px;
            line-height: 1.6;
        }

        @media (max-width: 768px) {
            .jc-wrap {
                padding: 30px 20px !important;
                margin: 20px auto !important;
            }

            .jc-hero {
                grid-template-columns: 1fr;
            }

            .jc-hero-right {
                justify-content: flex-start;
            }

            .jc-card {
                padding: 25px 20px !important;
            }

            .jc-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <?php
}
?>
