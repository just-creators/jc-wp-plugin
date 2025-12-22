<?php
/**
 * Plugin Name: JustCreators Video Tracker
 * Description: Verwaltet Video/Stream-Submissions von Teilnehmern mit automatischer Längenvalidierung
 * Version: 1.0.0
 * Author: JustCreators Team
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Gemeinsamer Admin-Parent
if ( ! defined( 'JC_ADMIN_PARENT_SLUG' ) ) {
    define( 'JC_ADMIN_PARENT_SLUG', 'justcreators-hub' );
}

// Konstanten
define( 'JC_VIDEO_TABLE', 'jc_video_tracker' );
define( 'JC_VIDEO_VERSION', '1.0.0' );

// Hooks
register_activation_hook( __FILE__, 'jc_video_install' );
add_action( 'admin_menu', 'jc_video_register_menu' );
add_action( 'admin_init', 'jc_video_handle_actions' );
add_action( 'rest_api_init', 'jc_video_register_rest_api' );

// ========================================
// 1. DATENBANK INSTALLATION
// ========================================
function jc_video_install() {
    global $wpdb;
    $table_name = $wpdb->prefix . JC_VIDEO_TABLE;
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        discord_id varchar(64) NOT NULL,
        discord_name varchar(255) NOT NULL,
        video_url varchar(500) NOT NULL,
        platform varchar(50) NOT NULL COMMENT 'youtube, twitch',
        video_title varchar(255) DEFAULT '',
        video_duration int DEFAULT 0 COMMENT 'Dauer in Sekunden',
        status varchar(50) DEFAULT 'pending' COMMENT 'pending, approved, rejected',
        submission_date datetime DEFAULT CURRENT_TIMESTAMP,
        admin_notes text DEFAULT '',
        PRIMARY KEY (id),
        KEY discord_id (discord_id),
        KEY status (status),
        KEY submission_date (submission_date)
    ) $charset_collate;";
    
    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
    
    // Content Plan Meta-Daten in wp_options speichern
    if ( ! get_option( 'jc_content_plan' ) ) {
        $content_plan = array(
            array(
                'phase' => 1,
                'name' => 'Projekt Start',
                'start' => '2026-01-31 19:45',
                'end' => '2026-02-10 18:30',
                'min_streams' => 2,
                'min_videos' => 1
            ),
            array(
                'phase' => 2,
                'name' => 'End-Eröffnung bis Shopping District',
                'start' => '2026-02-10 18:30',
                'end' => '2026-02-16 15:00',
                'min_streams' => 1,
                'min_videos' => 1
            ),
            array(
                'phase' => 3,
                'name' => 'Shopping District bis Content-Ende',
                'start' => '2026-02-16 15:00',
                'end' => '2026-03-20 23:59',
                'min_streams' => 8,
                'min_videos' => 3
            )
        );
        update_option( 'jc_content_plan', $content_plan );
    }
}

// ========================================
// 2. ADMIN MENÜ
// ========================================
function jc_video_register_menu() {
    add_submenu_page(
        JC_ADMIN_PARENT_SLUG,
        'Video Tracker',
        'Video Tracker',
        'manage_options',
        'jc-video-tracker',
        'jc_video_render_admin_page'
    );
}

function jc_video_render_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . JC_VIDEO_TABLE;
    
    // Handle Aktionen
    $action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';
    $video_id = isset( $_GET['video_id'] ) ? intval( $_GET['video_id'] ) : 0;
    
    if ( $action === 'approve' && $video_id > 0 ) {
        check_admin_referer( 'jc_video_approve_' . $video_id );
        $wpdb->update( $table_name, array( 'status' => 'approved' ), array( 'id' => $video_id ), array( '%s' ), array( '%d' ) );
        echo '<div class="notice notice-success"><p>Video genehmigt!</p></div>';
    }
    
    if ( $action === 'reject' && $video_id > 0 ) {
        check_admin_referer( 'jc_video_reject_' . $video_id );
        $wpdb->update( $table_name, array( 'status' => 'rejected' ), array( 'id' => $video_id ), array( '%s' ), array( '%d' ) );
        echo '<div class="notice notice-warning"><p>Video abgelehnt!</p></div>';
    }
    
    // Hole alle Videos
    $videos = $wpdb->get_results( "SELECT * FROM $table_name ORDER BY submission_date DESC" );
    
    // Hole Content Plan
    $content_plan = get_option( 'jc_content_plan', array() );
    
    ?>
    <div class="wrap">
        <h1>JustCreators Video Tracker</h1>
        
        <div style="background: #f1f1f1; padding: 20px; border-radius: 5px; margin-bottom: 20px;">
            <h2>Content Plan Übersicht</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Phase</th>
                        <th>Zeitraum</th>
                        <th>Anforderung</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $content_plan as $plan ): ?>
                    <tr>
                        <td><strong><?php echo esc_html( $plan['name'] ); ?></strong></td>
                        <td><?php echo esc_html( $plan['start'] ); ?> bis <?php echo esc_html( $plan['end'] ); ?></td>
                        <td><?php echo esc_html( $plan['min_streams'] ); ?> Streams + <?php echo esc_html( $plan['min_videos'] ); ?> Videos</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <h2>Eingereichte Videos</h2>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Discord User</th>
                    <th>Titel</th>
                    <th>Plattform</th>
                    <th>Dauer</th>
                    <th>Eingereicht</th>
                    <th>Status</th>
                    <th>Aktionen</th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $videos ) ): ?>
                <tr><td colspan="7" style="text-align: center; padding: 20px;">Keine Videos eingereicht</td></tr>
                <?php else: ?>
                    <?php foreach ( $videos as $video ): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $video->discord_name ); ?></strong><br>
                            <small style="color: #666;"><?php echo esc_html( $video->discord_id ); ?></small>
                        </td>
                        <td>
                            <a href="<?php echo esc_url( $video->video_url ); ?>" target="_blank">
                                <?php echo esc_html( $video->video_title ?: 'Untitled' ); ?>
                            </a>
                        </td>
                        <td>
                            <span class="badge" style="background: <?php echo $video->platform === 'youtube' ? '#FF0000' : '#9146FF'; ?>; color: white; padding: 5px 10px; border-radius: 3px;">
                                <?php echo esc_html( strtoupper( $video->platform ) ); ?>
                            </span>
                        </td>
                        <td>
                            <?php 
                            $mins = intdiv( $video->video_duration, 60 );
                            $secs = $video->video_duration % 60;
                            echo esc_html( sprintf( '%d:%02d Min', $mins, $secs ) );
                            ?>
                        </td>
                        <td><?php echo esc_html( date_i18n( 'Y-m-d H:i', strtotime( $video->submission_date ) ) ); ?></td>
                        <td>
                            <span class="badge" style="background: <?php 
                                echo $video->status === 'approved' ? '#28a745' : ( $video->status === 'rejected' ? '#dc3545' : '#ffc107' );
                            ?>; color: white; padding: 5px 10px; border-radius: 3px;">
                                <?php echo esc_html( $video->status ); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ( $video->status === 'pending' ): ?>
                                <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=jc-video-tracker&action=approve&video_id=' . $video->id ), 'jc_video_approve_' . $video->id ); ?>" class="button button-primary button-small">✓ Genehmigen</a>
                                <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=jc-video-tracker&action=reject&video_id=' . $video->id ), 'jc_video_reject_' . $video->id ); ?>" class="button button-secondary button-small">✗ Ablehnen</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// ========================================
// 3. REST API ENDPOINTS
// ========================================
function jc_video_register_rest_api() {
    // Vom Discord Bot: Video einreichen
    register_rest_route( 'jc/v1', '/video/submit', array(
        'methods' => 'POST',
        'callback' => 'jc_video_handle_submit',
        'permission_callback' => 'jc_video_verify_api_secret'
    ));
    
    // Für Dashboard: User-Status abrufen
    register_rest_route( 'jc/v1', '/video/user-status', array(
        'methods' => 'GET',
        'callback' => 'jc_video_handle_user_status',
        'permission_callback' => '__return_true'
    ));
    
    // Für Bot: Alle Videos des Users abrufen
    register_rest_route( 'jc/v1', '/video/user-videos', array(
        'methods' => 'GET',
        'callback' => 'jc_video_handle_user_videos',
        'permission_callback' => 'jc_video_verify_api_secret'
    ));
}

function jc_video_verify_api_secret( $request ) {
    $auth_header = $request->get_header( 'authorization' );
    $expected = 'Bearer ' . get_option( 'jc_bot_api_secret', '' );
    return $auth_header === $expected;
}

/**
 * Discord Bot sendet ein Video
 */
function jc_video_handle_submit( $request ) {
    $params = $request->get_json_params();
    global $wpdb;
    $table_name = $wpdb->prefix . JC_VIDEO_TABLE;
    
    // Validierung
    $required = array( 'discord_id', 'discord_name', 'video_url', 'platform' );
    foreach ( $required as $field ) {
        if ( empty( $params[$field] ) ) {
            return new WP_Error( 'missing_field', "Feld erforderlich: $field", array( 'status' => 400 ) );
        }
    }
    
    $discord_id = sanitize_text_field( $params['discord_id'] );
    $discord_name = sanitize_text_field( $params['discord_name'] );
    $video_url = esc_url_raw( $params['video_url'] );
    $platform = sanitize_text_field( $params['platform'] );
    $video_title = sanitize_text_field( $params['video_title'] ?? '' );
    $video_duration = intval( $params['video_duration'] ?? 0 );
    
    if ( ! in_array( $platform, array( 'youtube', 'twitch' ) ) ) {
        return new WP_Error( 'invalid_platform', 'Plattform muss youtube oder twitch sein', array( 'status' => 400 ) );
    }
    
    // Doppeltes Hochladen verhindern
    $existing = $wpdb->get_row( $wpdb->prepare(
        "SELECT id FROM $table_name WHERE video_url = %s",
        $video_url
    ));
    
    if ( $existing ) {
        return new WP_Error( 'duplicate', 'Diese URL wurde bereits eingereicht', array( 'status' => 400 ) );
    }
    
    // In Datenbank einfügen
    $inserted = $wpdb->insert( $table_name, array(
        'discord_id' => $discord_id,
        'discord_name' => $discord_name,
        'video_url' => $video_url,
        'platform' => $platform,
        'video_title' => $video_title,
        'video_duration' => $video_duration,
        'status' => 'pending'
    ), array(
        '%s', '%s', '%s', '%s', '%s', '%d', '%s'
    ));
    
    if ( ! $inserted ) {
        return new WP_Error( 'db_error', 'Fehler beim Speichern', array( 'status' => 500 ) );
    }
    
    return array(
        'success' => true,
        'message' => 'Video eingereicht und wartet auf Genehmigung',
        'video_id' => $wpdb->insert_id
    );
}

/**
 * Hole Compliance-Status eines Users
 */
function jc_video_handle_user_status( $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . JC_VIDEO_TABLE;
    
    $discord_id = $request->get_param( 'discord_id' );
    if ( ! $discord_id ) {
        return new WP_Error( 'missing_param', 'discord_id erforderlich', array( 'status' => 400 ) );
    }
    
    $discord_id = sanitize_text_field( $discord_id );
    $current_time = current_time( 'mysql' );
    $content_plan = get_option( 'jc_content_plan', array() );
    
    // Finde aktuelle Phase
    $current_phase = null;
    foreach ( $content_plan as $phase ) {
        if ( strtotime( $phase['start'] ) <= strtotime( $current_time ) && 
             strtotime( $current_time ) <= strtotime( $phase['end'] ) ) {
            $current_phase = $phase;
            break;
        }
    }
    
    // Hole genehmigte Videos für diesen User in dieser Phase
    if ( $current_phase ) {
        $videos = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table_name 
             WHERE discord_id = %s 
             AND status = 'approved'
             AND submission_date >= %s
             AND submission_date <= %s
             ORDER BY submission_date DESC",
            $discord_id,
            $current_phase['start'],
            $current_phase['end']
        ));
        
        $streams_count = count( array_filter( $videos, function( $v ) { return $v->platform === 'twitch'; }) );
        $videos_count = count( array_filter( $videos, function( $v ) { return $v->platform === 'youtube'; }) );
        
        $status = array(
            'discord_id' => $discord_id,
            'current_phase' => $current_phase['name'],
            'phase_start' => $current_phase['start'],
            'phase_end' => $current_phase['end'],
            'streams_count' => $streams_count,
            'videos_count' => $videos_count,
            'min_required_streams' => $current_phase['min_streams'],
            'min_required_videos' => $current_phase['min_videos'],
            'streams_complete' => $streams_count >= $current_phase['min_streams'],
            'videos_complete' => $videos_count >= $current_phase['min_videos'],
            'is_compliant' => $streams_count >= $current_phase['min_streams'] && $videos_count >= $current_phase['min_videos']
        );
    } else {
        $status = array(
            'discord_id' => $discord_id,
            'current_phase' => null,
            'is_compliant' => true, // Keine Phase = keine Anforderungen
            'message' => 'Aktuell keine aktive Phase'
        );
    }
    
    return $status;
}

/**
 * Hole alle Videos eines Users
 */
function jc_video_handle_user_videos( $request ) {
    global $wpdb;
    $table_name = $wpdb->prefix . JC_VIDEO_TABLE;
    
    $discord_id = $request->get_param( 'discord_id' );
    if ( ! $discord_id ) {
        return new WP_Error( 'missing_param', 'discord_id erforderlich', array( 'status' => 400 ) );
    }
    
    $discord_id = sanitize_text_field( $discord_id );
    
    $videos = $wpdb->get_results( $wpdb->prepare(
        "SELECT id, video_url, video_title, platform, video_duration, status, submission_date 
         FROM $table_name 
         WHERE discord_id = %s
         ORDER BY submission_date DESC",
        $discord_id
    ));
    
    return array(
        'success' => true,
        'discord_id' => $discord_id,
        'total_videos' => count( $videos ),
        'videos' => $videos
    );
}

// ========================================
// 4. ADMIN AKTIONEN
// ========================================
function jc_video_handle_actions() {
    // Wird in der Admin-Funktion oben behandelt
}
