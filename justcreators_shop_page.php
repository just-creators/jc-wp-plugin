<?php
/**
 * Plugin Name: JustCreators Shop Claim
 * Description: Claim-Shops Seite im Mods-Look mit Discord Login, Teilnehmer-Check und Item-Auswahl.
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Konstanten
define( 'JC_SHOP_TABLE', 'jc_shops' );
define( 'JC_SHOP_REDIRECT_SLUG', 'shops' ); // Passe den Slug an die Seite mit dem Shortcode an

// OAuth: nutze Werte aus wp-config (JC_DISCORD_CLIENT_ID/SECRET) wie die anderen Pages, fallback auf Regeln-Constants
if ( ! defined( 'JC_SHOP_CLIENT_ID' ) ) {
    if ( defined( 'JC_DISCORD_CLIENT_ID' ) ) {
        define( 'JC_SHOP_CLIENT_ID', JC_DISCORD_CLIENT_ID );
    } elseif ( defined( 'JC_RULES_CLIENT_ID' ) ) {
        define( 'JC_SHOP_CLIENT_ID', JC_RULES_CLIENT_ID );
    }
}
if ( ! defined( 'JC_SHOP_CLIENT_SECRET' ) ) {
    if ( defined( 'JC_DISCORD_CLIENT_SECRET' ) ) {
        define( 'JC_SHOP_CLIENT_SECRET', JC_DISCORD_CLIENT_SECRET );
    } elseif ( defined( 'JC_RULES_CLIENT_SECRET' ) ) {
        define( 'JC_SHOP_CLIENT_SECRET', JC_RULES_CLIENT_SECRET );
    }
}

// Hooks
register_activation_hook( __FILE__, 'jc_shop_install' );
add_action( 'wp', 'jc_shop_ensure_session', 1 );
add_action( 'template_redirect', 'jc_shop_handle_oauth', 5 );
add_shortcode( 'jc_shop_claim', 'jc_shop_render_shortcode' );
add_action( 'admin_menu', 'jc_shop_admin_menu' );

function jc_shop_get_redirect_uri() {
    return home_url( '/' . JC_SHOP_REDIRECT_SLUG );
}

function jc_shop_ensure_session() {
    if ( session_status() === PHP_SESSION_NONE && ! headers_sent() ) {
        session_start();
    }
}

function jc_shop_install() {
    global $wpdb;
    $table = $wpdb->prefix . JC_SHOP_TABLE;
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        discord_id varchar(100) NOT NULL,
        discord_name varchar(255) NOT NULL,
        creator_name varchar(255) DEFAULT '',
        minecraft_name varchar(50) DEFAULT NULL,
        item_name varchar(120) DEFAULT '',
        item_icon varchar(20) DEFAULT '',
        item_key varchar(160) DEFAULT NULL,
        social_channels longtext DEFAULT NULL,
        claimed_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY discord_id (discord_id),
        UNIQUE KEY item_key (item_key)
    ) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

function jc_shop_handle_oauth() {
    if ( ! is_page( JC_SHOP_REDIRECT_SLUG ) ) {
        return;
    }

    jc_shop_ensure_session();

    if ( isset( $_GET['code'] ) ) {
        $code = sanitize_text_field( $_GET['code'] );

        $token_response = wp_remote_post( 'https://discord.com/api/oauth2/token', array(
            'body' => array(
                'client_id' => JC_SHOP_CLIENT_ID,
                'client_secret' => JC_SHOP_CLIENT_SECRET,
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => jc_shop_get_redirect_uri(),
            ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $token_response ) ) {
            wp_redirect( jc_shop_get_redirect_uri() . '?error=token' );
            exit;
        }

        $token_data = json_decode( wp_remote_retrieve_body( $token_response ), true );
        if ( empty( $token_data['access_token'] ) ) {
            wp_redirect( jc_shop_get_redirect_uri() . '?error=token' );
            exit;
        }

        $user_response = wp_remote_get( 'https://discord.com/api/users/@me', array(
            'headers' => array( 'Authorization' => 'Bearer ' . $token_data['access_token'] ),
            'timeout' => 15,
        ) );

        if ( is_wp_error( $user_response ) ) {
            wp_redirect( jc_shop_get_redirect_uri() . '?error=user' );
            exit;
        }

        $user_data = json_decode( wp_remote_retrieve_body( $user_response ), true );
        if ( empty( $user_data['id'] ) ) {
            wp_redirect( jc_shop_get_redirect_uri() . '?error=user' );
            exit;
        }

        $_SESSION['jc_shop_discord_user'] = $user_data;
        setcookie( 'jc_shop_discord_id', $user_data['id'], time() + 3600, '/', '', true, true );
        setcookie( 'jc_shop_discord_name', $user_data['username'], time() + 3600, '/', '', true, true );

        wp_redirect( jc_shop_get_redirect_uri() );
        exit;
    }
}

function jc_shop_get_current_user() {
    jc_shop_ensure_session();

    if ( isset( $_SESSION['jc_shop_discord_user'] ) ) {
        return $_SESSION['jc_shop_discord_user'];
    }

    if ( isset( $_COOKIE['jc_shop_discord_id'], $_COOKIE['jc_shop_discord_name'] ) ) {
        $_SESSION['jc_shop_discord_user'] = array(
            'id' => sanitize_text_field( $_COOKIE['jc_shop_discord_id'] ),
            'username' => sanitize_text_field( $_COOKIE['jc_shop_discord_name'] ),
        );
        return $_SESSION['jc_shop_discord_user'];
    }

    return null;
}

function jc_shop_fetch_application( $discord_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'jc_discord_applications';

    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
        return null;
    }

    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE discord_id = %s",
        $discord_id
    ) );
}

function jc_shop_fetch_member( $discord_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'jc_members';

    if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
        return null;
    }

    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE discord_id = %s",
        $discord_id
    ) );
}

function jc_shop_get_available_items() {
    return array(
        array( 'key' => 'netherite_ingot', 'name' => 'Netherit-Barren', 'icon' => '🪨' ),
        array( 'key' => 'elytra', 'name' => 'Elytra', 'icon' => '🪽' ),
        array( 'key' => 'rocket', 'name' => 'Raketen', 'icon' => '🚀' ),
        array( 'key' => 'beacon', 'name' => 'Beacon', 'icon' => '🔆' ),
        array( 'key' => 'totem', 'name' => 'Totem', 'icon' => '🛡️' ),
        array( 'key' => 'diamond_block', 'name' => 'Diamantblock', 'icon' => '💎' ),
        array( 'key' => 'mending_book', 'name' => 'Verzauberung: Mending', 'icon' => '📕' ),
        array( 'key' => 'golden_carrot', 'name' => 'Goldene Karotten', 'icon' => '🥕' ),
        array( 'key' => 'potion', 'name' => 'Tränke', 'icon' => '🧪' ),
        array( 'key' => 'farm_kit', 'name' => 'Farm-Kits', 'icon' => '🌾' ),
    );
}

function jc_shop_item_is_taken( $item_key, $shop_id = 0 ) {
    global $wpdb;
    $table = $wpdb->prefix . JC_SHOP_TABLE;

    $sql = "SELECT id FROM {$table} WHERE item_key = %s";
    $params = array( $item_key );
    if ( $shop_id ) {
        $sql .= " AND id != %d";
        $params[] = $shop_id;
    }

    $existing = $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );

    return ! empty( $existing );
}

function jc_shop_save_claim( $user, $application, $member ) {
    global $wpdb;
    $table = $wpdb->prefix . JC_SHOP_TABLE;

    $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE discord_id = %s", $user['id'] ) );
    if ( $existing ) {
        return $existing;
    }

    $social = $member && isset( $member->social_channels ) ? maybe_unserialize( $member->social_channels ) : null;

    $discriminator = isset( $user['discriminator'] ) && $user['discriminator'] !== '0' ? '#' . $user['discriminator'] : '';

    $wpdb->insert(
        $table,
        [
            'discord_id'      => $user['id'],
            'discord_name'    => $user['username'] . $discriminator,
            'creator_name'    => $application->creator_name ?? $user['username'],
            'minecraft_name'  => $member ? ( $member->minecraft_name ?? null ) : null,
            'social_channels' => is_array( $social ) ? serialize( $social ) : null,
        ],
        [ '%s', '%s', '%s', '%s', '%s' ]
    );

    return $wpdb->insert_id;
}

function jc_shop_update_item( $shop_id, $item_key ) {
    global $wpdb;
    $table = $wpdb->prefix . JC_SHOP_TABLE;
    $items = jc_shop_get_available_items();

    $selected = null;
    foreach ( $items as $it ) {
        if ( $it['key'] === $item_key ) {
            $selected = $it;
            break;
        }
    }

    if ( ! $selected ) {
        return [ 'error' => 'Ungültiges Item.' ];
    }

    if ( jc_shop_item_is_taken( $item_key, $shop_id ) ) {
        return [ 'error' => 'Item bereits vergeben.' ];
    }

    $updated = $wpdb->update(
        $table,
        [
            'item_key'  => $selected['key'],
            'item_name' => $selected['name'],
            'item_icon' => $selected['icon'],
        ],
        [ 'id' => $shop_id ],
        [ '%s', '%s', '%s' ],
        [ '%d' ]
    );

    if ( $updated === false ) {
        return [ 'error' => 'Speichern fehlgeschlagen.' ];
    }

    return [ 'message' => 'Item gespeichert!' ];
}

function jc_shop_get_social_names( $channels ) {
    $names = [];

    if ( ! is_array( $channels ) ) {
        return $names;
    }

    foreach ( $channels as $ch ) {
        $platform = $ch['platform'] ?? '';
        $url = $ch['url'] ?? '';
        if ( ! $platform || ! $url ) continue;

        if ( function_exists( 'jc_teilnehmer_fetch_channel_meta' ) ) {
            $meta = jc_teilnehmer_fetch_channel_meta( $platform, $url );
            if ( ! empty( $meta['title'] ) ) {
                $names[$platform] = $meta['title'];
                continue;
            }
        }

        if ( preg_match( '#/([A-Za-z0-9_\.-]+)/?$#', $url, $m ) ) {
            $names[$platform] = $m[1];
        }
    }

    return $names;
}

function jc_shop_render_shortcode() {
    ob_start();

    $user = jc_shop_get_current_user();

    // Login Screen
    if ( ! $user ) {
        jc_shop_render_login();
        jc_shop_inline_styles();
        return ob_get_clean();
    }

    $discord_id = sanitize_text_field( $user['id'] );
    $application = jc_shop_fetch_application( $discord_id );
    $member = jc_shop_fetch_member( $discord_id );

    if ( ! $application || $application->status !== 'accepted' ) {
        jc_shop_render_denied( $user['username'] );
        jc_shop_inline_styles();
        return ob_get_clean();
    }

    // Claim-Handling
    $message = '';
    $error = '';

    if ( isset( $_POST['jc_shop_claim'] ) && isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'jc_shop_claim' ) ) {
        $shop_id = jc_shop_save_claim( $user, $application, $member );
        if ( $shop_id ) {
                $message = 'Shop wurde für dich reserviert.';
        }
    }

    $current_shop = jc_shop_get_user_shop( $discord_id );

    if ( isset( $_POST['jc_shop_item'] ) && $current_shop && isset( $_POST['_wpnonce'] ) && wp_verify_nonce( $_POST['_wpnonce'], 'jc_shop_item' ) ) {
        $item_key = sanitize_text_field( $_POST['item_key'] ?? '' );
        $result = jc_shop_update_item( $current_shop->id, $item_key );
        if ( isset( $result['error'] ) ) {
            $error = $result['error'];
        } else {
            $message = $result['message'] ?? 'Item gespeichert.';
            $current_shop = jc_shop_get_user_shop( $discord_id );
        }
    }

    jc_shop_render_page( $user, $application, $member, $current_shop, $message, $error );
    jc_shop_inline_styles();

    return ob_get_clean();
}

function jc_shop_get_user_shop( $discord_id ) {
    global $wpdb;
    $table = $wpdb->prefix . JC_SHOP_TABLE;
    jc_shop_install();

    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE discord_id = %s",
        $discord_id
    ) );
}

function jc_shop_render_login() {
    // Check if constants are defined
    if ( ! defined( 'JC_SHOP_CLIENT_ID' ) || ! defined( 'JC_SHOP_CLIENT_SECRET' ) ) {
        ?>
        <div class="jc-wrap">
            <div class="jc-hero">
                <div class="jc-hero-left">
                    <div class="jc-kicker">⚠️ Konfigurationsfehler</div>
                    <h1 class="jc-hero-title">Discord OAuth nicht konfiguriert</h1>
                    <p class="jc-hero-sub">Bitte füge in wp-config.php folgende Zeilen hinzu:</p>
                    <pre style="background:#0b0f1d;padding:12px;border-radius:8px;overflow-x:auto;color:#e9ecf7;">define('JC_DISCORD_CLIENT_ID', 'DEINE_CLIENT_ID');
define('JC_DISCORD_CLIENT_SECRET', 'DEIN_CLIENT_SECRET');</pre>
                    <p class="jc-hero-sub">Redirect URI: <strong><?php echo esc_html( jc_shop_get_redirect_uri() ); ?></strong></p>
                </div>
            </div>
        </div>
        <?php
        return;
    }

    $auth_url = 'https://discord.com/api/oauth2/authorize?' . http_build_query( array(
        'client_id' => JC_SHOP_CLIENT_ID,
        'redirect_uri' => jc_shop_get_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'identify',
    ) );
    ?>
    <div class="jc-wrap">
        <div class="jc-hero">
            <div class="jc-hero-left">
                <div class="jc-kicker">🛍️ Shop Claim</div>
                <h1 class="jc-hero-title">Claim deinen Shop</h1>
                <p class="jc-hero-sub">Melde dich mit Discord an, um deinen Shop im Shopping District zu reservieren.</p>
                <a class="jc-btn" href="<?php echo esc_url( $auth_url ); ?>">Mit Discord anmelden</a>
            </div>
            <div class="jc-hero-right">
                <div class="jc-hero-badge">Login benötigt</div>
            </div>
        </div>
    </div>
    <?php
}

function jc_shop_render_denied( $discord_name ) {
    ?>
    <div class="jc-wrap">
        <div class="jc-card">
            <h2 class="jc-hero-title">Kein Zugriff</h2>
            <p class="jc-hero-sub">Hallo <?php echo esc_html( $discord_name ); ?>, du musst ein akzeptierter Teilnehmer sein, um einen Shop zu claimen.</p>
            <a class="jc-btn jc-btn-ghost" href="<?php echo esc_url( home_url( '/regeln' ) ); ?>">Status prüfen</a>
        </div>
    </div>
    <?php
}

function jc_shop_render_page( $user, $application, $member, $shop, $message, $error ) {
    $items = jc_shop_get_available_items();
    $social_channels = $application && isset( $application->social_channels ) ? maybe_unserialize( $application->social_channels ) : [];
    $social_names = jc_shop_get_social_names( $social_channels );
    ?>
    <div class="jc-wrap">
        <div class="jc-hero">
            <div class="jc-hero-left">
                <div class="jc-kicker">🛍️ Shops</div>
                <h1 class="jc-hero-title">Claim deinen Shopplatz</h1>
                <p class="jc-hero-sub">Du bist verifiziert! Sichere dir deinen Slot und wähle das Item, das exklusiv in deinem Shop verkauft wird.</p>
                <div class="jc-hero-actions">
                    <span class="jc-pill">Angemeldet als <?php echo esc_html( $user['username'] ); ?></span>
                    <?php if ( $member && $member->minecraft_name ) : ?>
                        <span class="jc-pill jc-pill-ghost">MC: <?php echo esc_html( $member->minecraft_name ); ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="jc-hero-right">
                <div class="jc-hero-badge">Only 1 Item / Shop</div>
            </div>
        </div>

        <?php if ( $message ) : ?><div class="jc-msg jc-success">✅ <?php echo esc_html( $message ); ?></div><?php endif; ?>
        <?php if ( $error ) : ?><div class="jc-msg jc-error">❌ <?php echo esc_html( $error ); ?></div><?php endif; ?>

        <?php if ( ! $shop ) : ?>
            <div class="jc-card">
                <h3 class="jc-section-title">Schritt 1: Shop claimen</h3>
                <p class="jc-hero-sub">Reserviere deinen Shop-Platz im Shopping District.</p>
                <form method="post" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-top:14px;">
                    <?php wp_nonce_field( 'jc_shop_claim' ); ?>
                    <button type="submit" name="jc_shop_claim" class="jc-btn">🛍️ Shop claimen</button>
                </form>
            </div>
        <?php else : ?>
            <div class="jc-card">
                <div class="jc-claim-row">
                    <div>
                        <div class="jc-label">Dein Shop</div>
                        <div class="jc-shop-title"><?php echo esc_html( $shop->creator_name ); ?></div>
                        <?php if ( $member && $member->minecraft_name ) : ?>
                            <div class="jc-shop-sub">Skin: <?php echo esc_html( $member->minecraft_name ); ?></div>
                        <?php endif; ?>
                        <?php if ( ! empty( $social_names ) ) : ?>
                            <div class="jc-social-names">
                                <?php foreach ( $social_names as $plat => $name ) : ?>
                                    <span class="jc-pill jc-pill-ghost"><?php echo esc_html( ucfirst( $plat ) . ': ' . $name ); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="jc-claim-actions">
                        <?php if ( $shop->item_key ) : ?>
                            <div class="jc-label">Dein Item</div>
                            <div class="jc-item-chip"><?php echo esc_html( $shop->item_icon . ' ' . $shop->item_name ); ?></div>
                        <?php else : ?>
                            <div class="jc-label">Schritt 2</div>
                            <button type="button" class="jc-btn" onclick="document.getElementById('jc-item-modal').classList.add('open');">📦 Item hinzufügen</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="jc-section">
            <div class="jc-section-head">
                <div>
                    <div class="jc-label">Öffentliche Übersicht</div>
                    <h3 class="jc-section-title">Shops im Shopping District</h3>
                </div>
                <div class="jc-pill jc-pill-ghost">1 Item pro Shop</div>
            </div>
            <div class="jc-shops-grid">
                <?php jc_shop_render_public_grid(); ?>
            </div>
        </div>
    </div>

    <?php if ( $shop && ! $shop->item_key ) : ?>
    <div id="jc-item-modal" class="jc-modal">
        <div class="jc-modal-content">
            <div class="jc-modal-head">
                <h4>📦 Item hinzufügen</h4>
                <button type="button" class="jc-close" onclick="document.getElementById('jc-item-modal').classList.remove('open');">×</button>
            </div>
            <form method="post" class="jc-items-grid">
                <?php wp_nonce_field( 'jc_shop_item' ); ?>
                <input type="hidden" name="jc_shop_item" value="1">
                <?php foreach ( $items as $item ) : ?>
                    <?php $taken = jc_shop_item_is_taken( $item['key'], $shop->id ); ?>
                    <label class="jc-item-card <?php echo $taken ? 'disabled' : ''; ?>">
                        <input type="radio" name="item_key" value="<?php echo esc_attr( $item['key'] ); ?>" <?php disabled( $taken ); ?> required>
                        <div class="jc-item-icon"><?php echo esc_html( $item['icon'] ); ?></div>
                        <div class="jc-item-name"><?php echo esc_html( $item['name'] ); ?></div>
                        <?php if ( $taken ) : ?><span class="jc-tag">vergeben</span><?php endif; ?>
                    </label>
                <?php endforeach; ?>
                <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:10px;margin-top:12px;">
                    <button type="button" class="jc-btn jc-btn-ghost" onclick="document.getElementById('jc-item-modal').classList.remove('open');">Abbrechen</button>
                    <button type="submit" class="jc-btn">Speichern</button>
                </div>
            </form>
        </div>
    </div>
    <script>document.addEventListener('keydown',function(e){if(e.key==='Escape'){var m=document.getElementById('jc-item-modal');if(m)m.classList.remove('open');}});</script>
    <?php endif; ?>
    <?php
}

function jc_shop_render_public_grid() {
    global $wpdb;
    $table = $wpdb->prefix . JC_SHOP_TABLE;
    jc_shop_install();

    $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY claimed_at ASC" );

    if ( empty( $rows ) ) {
        echo '<div class="jc-hero-sub">Noch keine Shops vergeben.</div>';
        return;
    }

    foreach ( $rows as $row ) {
        $skin = $row->minecraft_name ? 'https://mc-heads.net/head/' . rawurlencode( $row->minecraft_name ) . '/200.png' : 'https://via.placeholder.com/200x200/0b0f1d/6c7bff?text=?';
        $item = $row->item_key ? $row->item_icon . ' ' . $row->item_name : 'Noch kein Item';
        echo '<div class="jc-shop-card">';
        echo '<div class="jc-shop-skin"><img src="' . esc_url( $skin ) . '" alt="Skin"></div>';
        echo '<div class="jc-shop-body">';
        echo '<div class="jc-shop-name">' . esc_html( $row->creator_name ) . '</div>';
        echo '<div class="jc-shop-item">' . esc_html( $item ) . '</div>';
        echo '</div>';
        echo '</div>';
    }
}

function jc_shop_inline_styles() {
    ?>
    <style>
        :root { --jc-bg:#050712; --jc-panel:#0b0f1d; --jc-border:#1e2740; --jc-text:#e9ecf7; --jc-muted:#9eb3d5; --jc-accent:#6c7bff; --jc-accent-2:#56d8ff; }
        .jc-wrap { max-width: 1220px; margin: 26px auto; padding: 0 18px 40px; color: var(--jc-text); font-family: "Space Grotesk", "Inter", system-ui, -apple-system, sans-serif; }
        .jc-hero { display:grid; grid-template-columns:2fr 1fr; gap:22px; background: radial-gradient(120% 140% at 10% 10%, rgba(108,123,255,0.12), transparent 50%), radial-gradient(110% 120% at 90% 20%, rgba(86,216,255,0.1), transparent 45%), var(--jc-panel); border:1px solid var(--jc-border); border-radius:20px; padding:28px; position:relative; overflow:hidden; box-shadow:0 22px 60px rgba(0,0,0,0.45); }
        .jc-hero-left { position:relative; z-index:1; }
        .jc-kicker { display:inline-flex; align-items:center; gap:8px; padding:6px 12px; background:rgba(108,123,255,0.15); border:1px solid rgba(108,123,255,0.35); border-radius:999px; color:var(--jc-text); font-size:13px; letter-spacing:0.04em; text-transform:uppercase; }
        .jc-hero-title { margin:10px 0 6px; font-size:32px; line-height:1.2; color:var(--jc-text); }
        .jc-hero-sub { margin:0 0 14px; color:var(--jc-muted); line-height:1.6; max-width:780px; }
        .jc-hero-actions { display:flex; gap:10px; flex-wrap:wrap; }
        .jc-pill { background:rgba(108,123,255,0.15); border:1px solid rgba(108,123,255,0.35); color:var(--jc-text); padding:8px 12px; border-radius:999px; font-weight:700; }
        .jc-pill-ghost { background:transparent; border-color:var(--jc-border); color:var(--jc-muted); }
        .jc-hero-right { position:relative; min-height:120px; display:flex; align-items:center; justify-content:flex-end; }
        .jc-hero-badge { position:relative; z-index:1; padding:12px 18px; border-radius:14px; background:linear-gradient(135deg,var(--jc-accent),var(--jc-accent-2)); color:#040510; font-weight:800; letter-spacing:0.08em; box-shadow:0 18px 40px rgba(108,123,255,0.45); }
        .jc-card { background:var(--jc-panel); border:1px solid var(--jc-border); border-radius:18px; padding:22px; box-shadow:0 20px 54px rgba(0,0,0,0.4); margin-top:20px; }
        .jc-section { margin-top:28px; }
        .jc-section-head { display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap; }
        .jc-section-title { margin:4px 0 0; font-size:22px; color:var(--jc-text); }
        .jc-label { color:var(--jc-muted); font-size:13px; letter-spacing:0.05em; text-transform:uppercase; }
        .jc-msg { margin-top:16px; padding:12px 14px; border-radius:12px; font-weight:700; border:1px solid var(--jc-border); }
        .jc-success { background:rgba(86,216,255,0.08); border-color:rgba(86,216,255,0.4); color:#d1f2ff; }
        .jc-error { background:rgba(255,105,105,0.12); border-color:rgba(255,105,105,0.35); color:#ffb3b3; }
        .jc-btn { display:inline-flex; align-items:center; gap:8px; justify-content:center; padding:12px 16px; border-radius:12px; background:linear-gradient(135deg,var(--jc-accent),var(--jc-accent-2)); color:#050712; text-decoration:none; font-weight:800; letter-spacing:0.01em; box-shadow:0 14px 34px rgba(108,123,255,0.45); transition:transform .2s, box-shadow .2s; border:none; cursor:pointer; }
        .jc-btn:hover { transform:translateY(-2px); box-shadow:0 16px 40px rgba(86,216,255,0.5); color:#050712; }
        .jc-btn-ghost { background:transparent; color:var(--jc-text); border:1px solid var(--jc-border); box-shadow:none; }
        .jc-claim-row { display:flex; align-items:center; justify-content:space-between; gap:20px; flex-wrap:wrap; }
        .jc-shop-title { font-size:24px; font-weight:800; color:var(--jc-text); }
        .jc-shop-sub { color:var(--jc-muted); margin-top:6px; }
        .jc-claim-actions { display:flex; flex-direction:column; gap:8px; align-items:flex-start; }
        .jc-item-chip { display:inline-flex; align-items:center; gap:8px; padding:10px 14px; background:rgba(108,123,255,0.15); border:1px solid rgba(108,123,255,0.35); border-radius:12px; font-weight:700; }
        .jc-shops-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:16px; margin-top:14px; }
        .jc-shop-card { background:var(--jc-panel); border:1px solid var(--jc-border); border-radius:16px; overflow:hidden; box-shadow:0 16px 40px rgba(0,0,0,0.35); display:flex; flex-direction:column; }
        .jc-shop-skin { padding:16px; display:flex; justify-content:center; background:linear-gradient(135deg, rgba(108,123,255,0.05), rgba(86,216,255,0.05)); }
        .jc-shop-skin img { width:120px; height:120px; display:block; background:#050712; border-radius:12px; image-rendering: pixelated; box-shadow:0 8px 20px rgba(0,0,0,0.3); }
        .jc-shop-body { padding:14px 16px 18px; display:flex; flex-direction:column; gap:6px; }
        .jc-shop-name { font-weight:800; font-size:18px; color:var(--jc-text); }
        .jc-shop-item { color:var(--jc-muted); font-weight:700; }
        .jc-social-names { display:flex; gap:8px; flex-wrap:wrap; margin-top:8px; }
        .jc-modal { position:fixed; inset:0; background:rgba(0,0,0,0.6); display:flex; align-items:center; justify-content:center; opacity:0; pointer-events:none; transition:opacity .2s; z-index:999; }
        .jc-modal.open { opacity:1; pointer-events:auto; }
        .jc-modal-content { background:var(--jc-panel); border:1px solid var(--jc-border); border-radius:18px; padding:18px; max-width:720px; width:calc(100% - 40px); box-shadow:0 24px 60px rgba(0,0,0,0.6); }
        .jc-modal-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; }
        .jc-close { background:transparent; border:none; color:var(--jc-text); font-size:22px; cursor:pointer; }
        .jc-items-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:12px; }
        .jc-item-card { border:1px solid var(--jc-border); border-radius:14px; padding:12px; display:flex; flex-direction:column; align-items:flex-start; gap:8px; cursor:pointer; position:relative; background:rgba(255,255,255,0.02); }
        .jc-item-card input { display:none; }
        .jc-item-card:hover { border-color:rgba(108,123,255,0.6); }
        .jc-item-card input:checked + .jc-item-icon, .jc-item-card input:checked ~ .jc-item-name { color:var(--jc-text); }
        .jc-item-icon { font-size:24px; }
        .jc-item-name { font-weight:700; color:var(--jc-text); }
        .jc-tag { position:absolute; top:10px; right:10px; background:rgba(255,105,105,0.15); color:#ffb3b3; padding:4px 8px; border-radius:999px; font-size:11px; text-transform:uppercase; letter-spacing:0.04em; }
        .jc-item-card.disabled { opacity:0.5; cursor:not-allowed; }
        .jc-item-card.disabled:hover { border-color:var(--jc-border); }
        @media (max-width: 900px) { .jc-hero { grid-template-columns:1fr; } .jc-hero-right { justify-content:flex-start; min-height:120px; } }
        @media (max-width: 640px) { .jc-claim-row { align-items:flex-start; } }
    </style>
    <?php
}

// ============================================
// ADMIN PAGE
// ============================================

function jc_shop_admin_menu() {
    add_menu_page(
        'Shop Verwaltung',
        'Shops',
        'manage_options',
        'jc-shops',
        'jc_shop_admin_page',
        'dashicons-store',
        30
    );
}

function jc_shop_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix . JC_SHOP_TABLE;
    jc_shop_install();

    // Handle Delete
    if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete' && isset( $_GET['id'] ) && check_admin_referer( 'jc_shop_delete_' . $_GET['id'] ) ) {
        $id = intval( $_GET['id'] );
        $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
        echo '<div class="notice notice-success"><p>Shop gelöscht.</p></div>';
    }

    // Handle Edit
    if ( isset( $_POST['jc_shop_edit'] ) && check_admin_referer( 'jc_shop_edit' ) ) {
        $id = intval( $_POST['shop_id'] );
        $item_key = sanitize_text_field( $_POST['item_key'] );
        $creator_name = sanitize_text_field( $_POST['creator_name'] );
        $minecraft_name = sanitize_text_field( $_POST['minecraft_name'] );

        $items = jc_shop_get_available_items();
        $selected = null;
        foreach ( $items as $it ) {
            if ( $it['key'] === $item_key ) {
                $selected = $it;
                break;
            }
        }

        if ( $selected ) {
            $wpdb->update(
                $table,
                [
                    'creator_name' => $creator_name,
                    'minecraft_name' => $minecraft_name,
                    'item_key' => $selected['key'],
                    'item_name' => $selected['name'],
                    'item_icon' => $selected['icon'],
                ],
                [ 'id' => $id ],
                [ '%s', '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );
            echo '<div class="notice notice-success"><p>Shop aktualisiert.</p></div>';
        }
    }

    // Get all shops
    $shops = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY claimed_at DESC" );
    $items = jc_shop_get_available_items();

    ?>
    <div class="wrap">
        <h1>🛍️ Shop Verwaltung</h1>
        <p>Verwalte alle geclaimten Shops im Shopping District.</p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:60px;">ID</th>
                    <th>Creator Name</th>
                    <th>Minecraft Name</th>
                    <th>Discord</th>
                    <th>Item</th>
                    <th style="width:100px;">Datum</th>
                    <th style="width:150px;">Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $shops ) ) : ?>
                    <tr><td colspan="7">Keine Shops vorhanden.</td></tr>
                <?php else : ?>
                    <?php foreach ( $shops as $shop ) : ?>
                        <tr>
                            <td><?php echo esc_html( $shop->id ); ?></td>
                            <td><strong><?php echo esc_html( $shop->creator_name ); ?></strong></td>
                            <td><?php echo esc_html( $shop->minecraft_name ?: '—' ); ?></td>
                            <td><?php echo esc_html( $shop->discord_name ); ?></td>
                            <td>
                                <?php if ( $shop->item_key ) : ?>
                                    <span style="background:#f0f0f1;padding:4px 8px;border-radius:4px;display:inline-block;">
                                        <?php echo esc_html( $shop->item_icon . ' ' . $shop->item_name ); ?>
                                    </span>
                                <?php else : ?>
                                    <span style="color:#999;">Kein Item</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( date( 'd.m.Y', strtotime( $shop->claimed_at ) ) ); ?></td>
                            <td>
                                <button class="button button-small" onclick="jcEditShop(<?php echo esc_js( json_encode( $shop ) ); ?>)">Bearbeiten</button>
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=jc-shops&action=delete&id=' . $shop->id ), 'jc_shop_delete_' . $shop->id ) ); ?>" 
                                   class="button button-small" 
                                   onclick="return confirm('Shop wirklich löschen?');" 
                                   style="color:#b32d2e;">Löschen</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Edit Modal -->
    <div id="jc-edit-modal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.7);z-index:100000;align-items:center;justify-content:center;">
        <div style="background:#fff;padding:24px;border-radius:8px;max-width:500px;width:calc(100% - 40px);box-shadow:0 8px 24px rgba(0,0,0,0.3);">
            <h2 style="margin-top:0;">Shop bearbeiten</h2>
            <form method="post">
                <?php wp_nonce_field( 'jc_shop_edit' ); ?>
                <input type="hidden" name="shop_id" id="edit-shop-id">
                
                <p>
                    <label><strong>Creator Name:</strong></label><br>
                    <input type="text" name="creator_name" id="edit-creator-name" class="regular-text" required>
                </p>
                
                <p>
                    <label><strong>Minecraft Name:</strong></label><br>
                    <input type="text" name="minecraft_name" id="edit-minecraft-name" class="regular-text">
                </p>
                
                <p>
                    <label><strong>Item:</strong></label><br>
                    <select name="item_key" id="edit-item-key" class="regular-text">
                        <option value="">Kein Item</option>
                        <?php foreach ( $items as $item ) : ?>
                            <option value="<?php echo esc_attr( $item['key'] ); ?>">
                                <?php echo esc_html( $item['icon'] . ' ' . $item['name'] ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </p>
                
                <p style="display:flex;gap:10px;">
                    <button type="submit" name="jc_shop_edit" class="button button-primary">Speichern</button>
                    <button type="button" class="button" onclick="jcCloseEdit()">Abbrechen</button>
                </p>
            </form>
        </div>
    </div>

    <script>
    function jcEditShop(shop) {
        document.getElementById('edit-shop-id').value = shop.id;
        document.getElementById('edit-creator-name').value = shop.creator_name;
        document.getElementById('edit-minecraft-name').value = shop.minecraft_name || '';
        document.getElementById('edit-item-key').value = shop.item_key || '';
        document.getElementById('jc-edit-modal').style.display = 'flex';
    }
    function jcCloseEdit() {
        document.getElementById('jc-edit-modal').style.display = 'none';
    }
    </script>
    <?php
}

// END
