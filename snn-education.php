<?php
/** 
 * Plugin Name: SNN Education
 * Description: Complete education platform with video tracking, certificates, strikes, and course management
 * Version: 1.2
 * Author: sinanisler
 * Author URI: https://sinanisler.com
 * Text Domain: snn
 * Requires PHP: 8.0
 * GitHub: https://github.com/sinanisler/snn-education
 */

if (!defined('ABSPATH')) exit;

// Constants
define('SNN_EDU_VERSION', '1.2');
define('SNN_EDU_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SNN_EDU_PLUGIN_URL', plugin_dir_url(__FILE__));

// ===========================================================
// DATABASE SETUP
// ===========================================================

function snn_edu_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    $table1 = $wpdb->prefix . 'snn_edu_data';
    $table2 = $wpdb->prefix . 'snn_edu_certificates';
    
    $sql1 = "CREATE TABLE $table1 (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        course_id bigint(20) NOT NULL,
        lesson_id bigint(20) NOT NULL,
        status varchar(20) NOT NULL,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_course_lesson (user_id, course_id, lesson_id),
        KEY user_id (user_id),
        KEY course_id (course_id)
    ) $charset_collate;";
    
    $sql2 = "CREATE TABLE $table2 (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        course_id bigint(20) NOT NULL,
        certificate_id varchar(255) NOT NULL,
        completion_date datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY user_course (user_id, course_id),
        KEY user_id (user_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);
}

register_activation_hook(__FILE__, 'snn_edu_activate');
register_deactivation_hook(__FILE__, 'snn_edu_deactivate');

function snn_edu_activate() {
    snn_edu_create_tables();
    snn_edu_setup_author_rewrite();
    flush_rewrite_rules();
}

function snn_edu_deactivate() {
    flush_rewrite_rules();
}

// ===========================================================
// HELPER FUNCTIONS
// ===========================================================

function snn_edu_get_option($key, $default = '') {
    return get_option('snn_edu_' . $key, $default);
}

function snn_edu_update_option($key, $value) {
    return update_option('snn_edu_' . $key, $value);
}

function snn_edu_get_top_level_course($post_id) {
    $current_id = $post_id;
    $parent_id = wp_get_post_parent_id($current_id);
    
    while ($parent_id) {
        $current_id = $parent_id;
        $parent_id = wp_get_post_parent_id($current_id);
    }
    
    return $current_id;
}

function snn_edu_get_all_ancestors($post_id) {
    $ancestors = [];
    $current_id = $post_id;
    
    while ($parent_id = wp_get_post_parent_id($current_id)) {
        $ancestors[] = $parent_id;
        $current_id = $parent_id;
    }
    
    return $ancestors;
}

function snn_edu_get_first_lesson_in_chapter($chapter_id) {
    $args = [
        'post_parent' => $chapter_id,
        'post_type' => snn_edu_get_allowed_post_types(),
        'posts_per_page' => 1,
        'orderby' => 'menu_order',
        'order' => 'ASC',
        'post_status' => 'publish'
    ];
    
    $lessons = get_posts($args);
    return !empty($lessons) ? $lessons[0]->ID : null;
}

function snn_edu_get_allowed_post_types() {
    $allowed = snn_edu_get_option('allowed_post_types', ['page']);
    return is_array($allowed) ? $allowed : ['page'];
}

function snn_edu_is_chapter($post_id) {
    $parent = wp_get_post_parent_id($post_id);
    if (!$parent) return false;
    
    $grandparent = wp_get_post_parent_id($parent);
    return !$grandparent; // Has parent but no grandparent = chapter
}

function snn_edu_is_lesson($post_id) {
    $parent = wp_get_post_parent_id($post_id);
    if (!$parent) return false;
    
    $grandparent = wp_get_post_parent_id($parent);
    return (bool)$grandparent; // Has both parent and grandparent = lesson
}

function snn_edu_track_activity($user_id, $course_id, $lesson_id, $status) {
    global $wpdb;
    $table = $wpdb->prefix . 'snn_edu_data';
    
    $wpdb->query($wpdb->prepare(
        "INSERT INTO $table (user_id, course_id, lesson_id, status, updated_at) 
         VALUES (%d, %d, %d, %s, NOW()) 
         ON DUPLICATE KEY UPDATE status = %s, updated_at = NOW()",
        $user_id, $course_id, $lesson_id, $status, $status
    ));
}

function snn_edu_auto_enroll($user_id, $lesson_id) {
    $ancestors = snn_edu_get_all_ancestors($lesson_id);
    $course_id = snn_edu_get_top_level_course($lesson_id);
    
    // Enroll in lesson
    snn_edu_track_activity($user_id, $course_id, $lesson_id, 'started');
    
    // Enroll in all ancestors
    foreach ($ancestors as $ancestor_id) {
        snn_edu_track_activity($user_id, $course_id, $ancestor_id, 'started');
    }
}

function snn_edu_get_course_progress($user_id, $course_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'snn_edu_data';
    
    // Get all lessons (grandchildren only)
    $all_lessons = get_posts([
        'post_type' => snn_edu_get_allowed_post_types(),
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'meta_query' => [
            [
                'key' => '_wp_page_template',
                'compare' => 'EXISTS'
            ]
        ]
    ]);
    
    $lesson_ids = [];
    foreach ($all_lessons as $post) {
        if (snn_edu_get_top_level_course($post->ID) == $course_id && snn_edu_is_lesson($post->ID)) {
            $lesson_ids[] = $post->ID;
        }
    }
    
    if (empty($lesson_ids)) return 0;
    
    $placeholders = implode(',', array_fill(0, count($lesson_ids), '%d'));
    $completed = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table 
         WHERE user_id = %d AND course_id = %d AND lesson_id IN ($placeholders) AND status = 'completed'",
        array_merge([$user_id, $course_id], $lesson_ids)
    ));
    
    return round(($completed / count($lesson_ids)) * 100);
}

function snn_edu_generate_certificate_id($user_id, $course_id, $completion_date) {
    $string = $user_id . '-' . $course_id . '-' . $completion_date . '-' . AUTH_KEY;
    return base64_encode(hash('sha256', $string, true));
}

function snn_edu_issue_certificate($user_id, $course_id) {
    global $wpdb;
    $table = $wpdb->prefix . 'snn_edu_certificates';
    
    // Check if already issued
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table WHERE user_id = %d AND course_id = %d",
        $user_id, $course_id
    ));
    
    if ($existing) return;
    
    $completion_date = current_time('mysql');
    $certificate_id = snn_edu_generate_certificate_id($user_id, $course_id, $completion_date);
    
    $wpdb->insert($table, [
        'user_id' => $user_id,
        'course_id' => $course_id,
        'certificate_id' => $certificate_id,
        'completion_date' => $completion_date
    ]);
}

// ===========================================================
// REST API
// ===========================================================

add_action('rest_api_init', 'snn_edu_register_routes');

function snn_edu_register_routes() {
    register_rest_route('snn-edu/v1', '/track', [
        'methods' => 'POST',
        'callback' => 'snn_edu_rest_track',
        'permission_callback' => 'is_user_logged_in'
    ]);
    
    register_rest_route('snn-edu/v1', '/enroll', [
        'methods' => 'POST',
        'callback' => 'snn_edu_rest_enroll',
        'permission_callback' => 'is_user_logged_in'
    ]);
    
    register_rest_route('snn-edu/v1', '/unenroll', [
        'methods' => 'POST',
        'callback' => 'snn_edu_rest_unenroll',
        'permission_callback' => 'is_user_logged_in'
    ]);
    
    register_rest_route('snn-edu/v1', '/enrollments', [
        'methods' => 'GET',
        'callback' => 'snn_edu_rest_enrollments',
        'permission_callback' => 'is_user_logged_in'
    ]);
    
    register_rest_route('snn-edu/v1', '/complete', [
        'methods' => 'POST',
        'callback' => 'snn_edu_rest_complete',
        'permission_callback' => 'is_user_logged_in'
    ]);
    
    register_rest_route('snn-edu/v1', '/completions', [
        'methods' => 'GET',
        'callback' => 'snn_edu_rest_completions',
        'permission_callback' => 'is_user_logged_in'
    ]);
    
    register_rest_route('snn-edu/v1', '/user-name/(?P<id>\d+)', [
        'methods' => 'GET',
        'callback' => 'snn_edu_rest_user_name',
        'permission_callback' => '__return_true'
    ]);
}

function snn_edu_rest_track($request) {
    $user_id = get_current_user_id();
    $lesson_id = intval($request->get_param('lesson_id'));
    $status = sanitize_text_field($request->get_param('status'));
    
    if (!$lesson_id || !in_array($status, ['started', 'completed'])) {
        return new WP_Error('invalid_params', __('Invalid parameters', 'snn'), ['status' => 400]);
    }
    
    $course_id = snn_edu_get_top_level_course($lesson_id);
    snn_edu_auto_enroll($user_id, $lesson_id);
    
    if ($status === 'completed') {
        snn_edu_track_activity($user_id, $course_id, $lesson_id, 'completed');
        
        // Check if course is 100% complete
        $progress = snn_edu_get_course_progress($user_id, $course_id);
        if ($progress >= 100) {
            snn_edu_issue_certificate($user_id, $course_id);
        }
    }
    
    return ['success' => true, 'progress' => snn_edu_get_course_progress($user_id, $course_id)];
}

function snn_edu_rest_enroll($request) {
    $user_id = get_current_user_id();
    $post_id = intval($request->get_param('post_id'));
    
    if (!$post_id) {
        return new WP_Error('invalid_params', __('Invalid parameters', 'snn'), ['status' => 400]);
    }
    
    snn_edu_auto_enroll($user_id, $post_id);
    return ['success' => true];
}

function snn_edu_rest_unenroll($request) {
    return new WP_Error('disabled', __('Unenrollment is disabled to protect data', 'snn'), ['status' => 403]);
}

function snn_edu_rest_enrollments($request) {
    global $wpdb;
    $user_id = get_current_user_id();
    $table = $wpdb->prefix . 'snn_edu_data';
    
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT DISTINCT course_id FROM $table WHERE user_id = %d",
        $user_id
    ), ARRAY_A);
    
    return ['enrollments' => array_column($results, 'course_id')];
}

function snn_edu_rest_complete($request) {
    $user_id = get_current_user_id();
    $course_id = intval($request->get_param('course_id'));
    
    if (!$course_id) {
        return new WP_Error('invalid_params', __('Invalid parameters', 'snn'), ['status' => 400]);
    }
    
    $progress = snn_edu_get_course_progress($user_id, $course_id);
    if ($progress >= 100) {
        snn_edu_issue_certificate($user_id, $course_id);
        return ['success' => true];
    }
    
    return new WP_Error('incomplete', __('Course not 100% complete', 'snn'), ['status' => 400]);
}

function snn_edu_rest_completions($request) {
    global $wpdb;
    $user_id = get_current_user_id();
    $table = $wpdb->prefix . 'snn_edu_certificates';
    
    $results = $wpdb->get_results($wpdb->prepare(
        "SELECT course_id, completion_date FROM $table WHERE user_id = %d ORDER BY completion_date DESC",
        $user_id
    ), ARRAY_A);
    
    return ['completions' => $results];
}

function snn_edu_rest_user_name($request) {
    $user_id = intval($request->get_param('id'));
    $user = get_userdata($user_id);
    
    if (!$user) {
        return new WP_Error('not_found', __('User not found', 'snn'), ['status' => 404]);
    }
    
    return ['name' => $user->display_name ?: $user->user_login];
}

// ===========================================================
// SHORTCODES
// ===========================================================

// Video Player Shortcode
add_shortcode('snn_video_player', 'snn_edu_video_player_shortcode');

function snn_edu_video_player_shortcode($atts) {
    if (!is_user_logged_in()) {
        return '<p>' . __('Please log in to view this content.', 'snn') . '</p>';
    }

    static $player_instance = 0;
    $player_instance++;

    $atts = shortcode_atts([
        'field'       => 'video_url',
        'poster'      => '',
        'autoplay'    => 'false',
        'muted'       => 'false',
        'loop'        => 'false',
        'events'      => 'both',
        'subtitles'   => '',
        'chapters'    => 'chapters',
        'width'       => '100%',
        'aspectratio' => '16/9',
        'height'      => '',
    ], $atts);

    $post_id   = get_the_ID();
    $video_url = get_post_meta($post_id, $atts['field'], true);

    if (!$video_url) {
        return '<p>' . __('No video available.', 'snn') . '</p>';
    }

    // Auto-enroll on load
    if (snn_edu_is_lesson($post_id)) {
        snn_edu_auto_enroll(get_current_user_id(), $post_id);
    }

    $player_id = 'snn-video-' . $post_id . '-' . $player_instance;

    // Poster
    $poster_url = '';
    if ($atts['poster']) {
        $poster_url = filter_var($atts['poster'], FILTER_VALIDATE_URL)
            ? $atts['poster']
            : get_post_meta($post_id, $atts['poster'], true);
    }
    if (!$poster_url && has_post_thumbnail($post_id)) {
        $poster_url = get_the_post_thumbnail_url($post_id, 'full');
    }

    // Subtitles — field stores ['en' => 'url', 'tr' => 'url']
    $subtitles = [];
    if ($atts['subtitles']) {
        $subtitle_data = get_post_meta($post_id, $atts['subtitles'], true);
        if (is_array($subtitle_data)) {
            foreach ($subtitle_data as $lang => $url) {
                if (!empty($url) && filter_var($url, FILTER_VALIDATE_URL)) {
                    $subtitles[] = [
                        'url'        => $url,
                        'label'      => strtoupper(sanitize_key($lang)),
                        'srclang'    => sanitize_key($lang),
                        'is_default' => false,
                    ];
                }
            }
        }
    }

    // Chapters — field stores [['time','title'], ...] or [['time'=>...,'title'=>...], ...]
    $chapters = [];
    if ($atts['chapters']) {
        $chapters_data = get_post_meta($post_id, $atts['chapters'], true);
        if (empty($chapters_data)) {
            $chapters_data = get_post_meta($post_id, 'chapter', true);
        }
        if (is_array($chapters_data)) {
            foreach ($chapters_data as $item) {
                if (!is_array($item) || count($item) < 2) continue;
                if (isset($item['time'], $item['title'])) {
                    $chapters[] = ['time' => $item['time'], 'title' => $item['title']];
                } elseif (isset($item[0], $item[1])) {
                    $chapters[] = ['time' => $item[0], 'title' => $item[1]];
                }
            }
        }
    }

    $autoplay     = ($atts['autoplay'] === 'true');
    $muted        = ($atts['muted'] === 'true');
    $loop         = ($atts['loop'] === 'true');
    $threshold    = snn_edu_get_option('video_threshold', 3);
    $require_full = snn_edu_get_option('require_full_video', false);

    $has_subtitles = !empty($subtitles);
    $pid           = esc_attr($player_id);

    ob_start();
    ?>
    <div id="<?php echo $pid; ?>"
         class="snn-player-wrapper"
         data-lesson-id="<?php echo esc_attr($post_id); ?>"
         data-events="<?php echo esc_attr($atts['events']); ?>"
         data-threshold="<?php echo esc_attr($threshold); ?>"
         data-require-full="<?php echo $require_full ? 'true' : 'false'; ?>"
         data-muted="<?php echo $muted ? 'true' : 'false'; ?>"
         data-chapters="<?php echo esc_attr(wp_json_encode($chapters)); ?>">

        <style>
            #<?php echo $pid; ?> {
                --primary-accent-color: rgba(255,214,79,1);
                --thumb-color: rgba(255,255,255,1);
                --text-color: rgba(255,255,255,1);
                --slider-track-color: rgba(255,255,255,0.3);
                --chapter-dot-color: rgba(255,255,255,1);
                --button-hover-background: rgba(255,255,255,0.2);
                --button-color: rgba(255,255,255,1);
                --tooltip-text-color: rgba(255,255,255,1);
                --controls-bar-bg: rgba(0,0,0,0.8);
                width: 100%;
                max-width: <?php echo esc_attr($atts['width']); ?>;
                margin-left: auto; margin-right: auto;
            }
            #<?php echo $pid; ?> .snn-video-container {
                position: relative; background: #000; overflow: hidden;
                <?php if ($atts['height']): ?>height: <?php echo esc_attr($atts['height']); ?>;
                <?php else: ?>aspect-ratio: <?php echo esc_attr($atts['aspectratio']); ?>;<?php endif; ?>
            }
            #<?php echo $pid; ?> .snn-video-container video { width:100%; height:100%; display:block; object-fit:contain; }
            #<?php echo $pid; ?> .snn-video-container:fullscreen { width:100vw; height:100vh; max-width:100%; border-radius:0; }
            #<?php echo $pid; ?> .snn-controls-overlay { position:absolute; inset:0; display:flex; flex-direction:column; justify-content:flex-end; opacity:0; transition:opacity 0.3s ease-in-out; }
            #<?php echo $pid; ?> .snn-video-container.snn-controls-visible .snn-controls-overlay { opacity:1; }
            #<?php echo $pid; ?> .snn-controls-hidden .snn-controls-overlay { cursor:none; opacity:0; pointer-events:none; }
            #<?php echo $pid; ?> .snn-controls-bar-container { padding:0; background:linear-gradient(to top, var(--controls-bar-bg) 0%, rgba(0,0,0,0.2) 100%); }
            #<?php echo $pid; ?> .snn-progress-container { position:relative; margin:0 11px 4px 12px; height:5px; }
            #<?php echo $pid; ?> .snn-progress-tooltip { position:absolute; background-color:var(--primary-accent-color); color:var(--tooltip-text-color); font-size:14px; border-radius:4px; padding:4px 8px; bottom:100%; margin-bottom:8px; pointer-events:none; opacity:0; transition:opacity 0.2s; white-space:nowrap; transform:translateX(-50%); max-width:260px; z-index:10; line-height:1.4; }
            #<?php echo $pid; ?> .snn-chapter-dots-container { position:absolute; width:100%; height:100%; top:0; left:0; pointer-events:none; z-index:5; }
            #<?php echo $pid; ?> .snn-chapter-sections-container { position:absolute; width:100%; height:100%; top:0; left:0; display:flex; z-index:3; pointer-events:all; gap:2px; }
            #<?php echo $pid; ?> .snn-chapter-section { position:relative; height:5px; background:transparent; cursor:pointer; display:flex; align-items:flex-end; transition:transform 0.15s ease; }
            #<?php echo $pid; ?> .snn-chapter-section:hover { transform:scaleY(1.6); }
            #<?php echo $pid; ?> .snn-chapter-section-fill { position:absolute; bottom:0; left:0; width:0%; height:100%; background:var(--primary-accent-color); transition:width 0.1s linear; pointer-events:none; }
            #<?php echo $pid; ?> .snn-chapter-section-bg { position:absolute; bottom:0; left:0; width:100%; height:100%; background:var(--slider-track-color); pointer-events:none; }
            #<?php echo $pid; ?> .snn-controls-bar { display:flex; align-items:center; justify-content:space-between; color:var(--text-color); padding:0 2px 2px 2px; }
            #<?php echo $pid; ?> .snn-controls-left, #<?php echo $pid; ?> .snn-controls-right { display:flex; align-items:center; gap:10px; }
            #<?php echo $pid; ?> .snn-control-button { background:none; border:none; color:var(--button-color); padding:5px; border-radius:9999px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:background-color 0.2s; filter:drop-shadow(0 0 2px #00000099); }
            #<?php echo $pid; ?> .snn-control-button:hover { background-color:var(--button-hover-background); }
            #<?php echo $pid; ?> .snn-control-button svg { width:30px; height:30px; fill:currentColor; }
            #<?php echo $pid; ?> .snn-volume-container { display:flex; align-items:center; }
            #<?php echo $pid; ?> .snn-volume-container .snn-volume-slider { width:0; transition:width 0.3s ease, opacity 0.3s ease; opacity:0; }
            #<?php echo $pid; ?> .snn-volume-container:hover .snn-volume-slider { width:75px; opacity:1; }
            #<?php echo $pid; ?> .snn-progress-bar.snn-video-slider { -webkit-appearance:none; appearance:none; width:100%; height:5px; background:transparent; cursor:pointer; border-radius:5px; position:absolute; top:0; left:0; z-index:2; }
            #<?php echo $pid; ?> .snn-progress-bar.snn-video-slider::-webkit-slider-thumb { -webkit-appearance:none; width:0; height:0; opacity:0; }
            #<?php echo $pid; ?> .snn-progress-bar.snn-video-slider::-moz-range-thumb { width:0; height:0; opacity:0; border:none; }
            #<?php echo $pid; ?> .snn-progress-thumb { position:absolute; top:50%; transform:translate(-50%,-50%); width:16px; height:16px; background:var(--thumb-color); border-radius:50%; cursor:pointer; border:2px solid var(--primary-accent-color); pointer-events:none; z-index:10; }
            #<?php echo $pid; ?> .snn-volume-slider { -webkit-appearance:none; appearance:none; height:5px; background:var(--slider-track-color); cursor:pointer; border-radius:5px; margin-left:7px; }
            #<?php echo $pid; ?> .snn-volume-slider::-webkit-slider-thumb { -webkit-appearance:none; width:16px; height:16px; background:var(--thumb-color); border-radius:50%; cursor:pointer; border:2px solid var(--primary-accent-color); }
            #<?php echo $pid; ?> .snn-volume-slider::-moz-range-thumb { width:16px; height:16px; background:var(--thumb-color); border-radius:50%; cursor:pointer; border:2px solid var(--primary-accent-color); }
            #<?php echo $pid; ?> .snn-chapter-dot { position:absolute; top:50%; transform:translate(-50%,-50%); width:5px; height:6px; background:var(--chapter-dot-color); border-radius:0; cursor:pointer; }
            #<?php echo $pid; ?> .snn-time-display { font-size:13px; white-space:nowrap; }
            #<?php echo $pid; ?> .snn-cc-container { position:relative; }
            #<?php echo $pid; ?> .snn-cc-menu { position:absolute; bottom:100%; right:0; margin-bottom:10px; background:rgba(0,0,0,0.9); border-radius:5px; min-width:200px; max-height:250px; overflow-y:auto; display:none; z-index:100; }
            #<?php echo $pid; ?> .snn-cc-menu.snn-show { display:block; }
            #<?php echo $pid; ?> .snn-cc-menu-item { padding:12px 16px; cursor:pointer; color:var(--text-color); font-size:14px; transition:background-color 0.2s; border:none; background:none; width:100%; text-align:left; display:flex; align-items:center; gap:8px; }
            #<?php echo $pid; ?> .snn-cc-menu-item:hover { background-color:var(--button-hover-background); }
            #<?php echo $pid; ?> .snn-cc-menu-item.snn-active { background-color:var(--primary-accent-color); color:var(--tooltip-text-color); }
            #<?php echo $pid; ?> .snn-cc-menu-item svg { width:16px; height:16px; fill:currentColor; flex-shrink:0; }
            #<?php echo $pid; ?> .snn-cc-settings-btn { display:flex; align-items:center; justify-content:space-between; border-top:1px solid rgba(255,255,255,0.1); }
            #<?php echo $pid; ?> .snn-cc-settings-panel { display:none; padding:8px 12px; }
            #<?php echo $pid; ?> .snn-cc-settings-panel.snn-show { display:block; }
            #<?php echo $pid; ?> .snn-cc-lang-list.snn-hidden { display:none; }
            #<?php echo $pid; ?> .snn-cc-back-btn { display:none; align-items:center; gap:8px; border-bottom:1px solid rgba(255,255,255,0.1); }
            #<?php echo $pid; ?> .snn-cc-back-btn.snn-show { display:flex; }
            #<?php echo $pid; ?> .snn-cc-settings-row { margin-bottom:4px; }
            #<?php echo $pid; ?> .snn-cc-settings-label { display:block; color:var(--text-color); font-size:13px; font-weight:500; }
            #<?php echo $pid; ?> .snn-cc-settings-input { width:100%; padding:4px 6px; background:rgba(255,255,255,0.1); border:1px solid rgba(255,255,255,0.2); border-radius:4px; color:var(--text-color); font-size:13px; box-sizing:border-box; }
            #<?php echo $pid; ?> .snn-cc-settings-input[type="color"] { height:24px; cursor:pointer; padding:0; }
            #<?php echo $pid; ?> .snn-cc-settings-input[type="range"] { padding:0; height:6px; }
            #<?php echo $pid; ?> .snn-settings-container { position:relative; }
            #<?php echo $pid; ?> .snn-settings-btn { min-width:48px; font-size:14px; font-weight:600; padding:5px 8px; }
            #<?php echo $pid; ?> .snn-settings-menu { position:absolute; bottom:100%; right:0; margin-bottom:10px; background:rgba(0,0,0,0.9); border-radius:5px; min-width:80px; display:none; z-index:100; }
            #<?php echo $pid; ?> .snn-settings-menu.snn-show { display:block; }
            #<?php echo $pid; ?> .snn-speed-option { padding:12px 20px; cursor:pointer; color:var(--text-color); font-size:14px; font-weight:500; transition:background-color 0.2s; border:none; background:none; width:100%; text-align:center; }
            #<?php echo $pid; ?> .snn-speed-option:hover { background-color:var(--button-hover-background); }
            #<?php echo $pid; ?> .snn-speed-option.snn-active { background-color:var(--primary-accent-color); color:var(--tooltip-text-color); }
            #<?php echo $pid; ?> video::cue { font-size:20px; }
            #<?php echo $pid; ?> .snn-hidden { display:none !important; }
        </style>

        <div class="snn-video-container">
            <video class="snn-video" playsinline crossorigin="anonymous"
                <?php echo $poster_url ? 'poster="' . esc_url($poster_url) . '"' : ''; ?>
                <?php echo $autoplay ? 'autoplay' : ''; ?>
                <?php echo $muted ? 'muted' : ''; ?>
                <?php echo $loop ? 'loop' : ''; ?>
            >
                <source src="<?php echo esc_url($video_url); ?>" type="video/mp4">
                <?php foreach ($subtitles as $index => $subtitle): ?>
                <track kind="subtitles"
                    label="<?php echo esc_attr($subtitle['label']); ?>"
                    srclang="<?php echo esc_attr($subtitle['srclang']); ?>"
                    src="<?php echo esc_url($subtitle['url']); ?>"
                    <?php echo $subtitle['is_default'] ? 'default' : ''; ?>>
                <?php endforeach; ?>
            </video>

            <div class="snn-controls-overlay">
                <div class="snn-controls-bar-container">
                    <div class="snn-progress-container">
                        <div class="snn-progress-tooltip">00:00</div>
                        <div class="snn-chapter-sections-container"></div>
                        <input type="range" class="snn-video-slider snn-progress-bar" min="0" max="100" step="0.1" value="0">
                        <div class="snn-progress-thumb"></div>
                        <div class="snn-chapter-dots-container"></div>
                    </div>

                    <div class="snn-controls-bar">
                        <div class="snn-controls-left">
                            <button class="snn-control-button snn-play-pause-btn" aria-label="Play/Pause"></button>
                            <div class="snn-volume-container">
                                <button class="snn-control-button snn-mute-btn" aria-label="Mute/Unmute"></button>
                                <input type="range" class="snn-video-slider snn-volume-slider" min="0" max="1" step="0.05" value="1" aria-label="Volume">
                            </div>
                            <div class="snn-time-display">00:00 / 00:00</div>
                        </div>
                        <div class="snn-controls-right">
                            <?php if ($has_subtitles): ?>
                            <div class="snn-cc-container">
                                <button class="snn-control-button snn-cc-btn" aria-label="Subtitles">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zM4 12h4v2H4v-2zm10 6H4v-2h10v2zm6 0h-4v-2h4v2zm0-4H10v-2h10v2z"/></svg>
                                </button>
                                <div class="snn-cc-menu">
                                    <button class="snn-cc-back-btn snn-cc-menu-item">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M20 11H7.83l5.59-5.59L12 4l-8 8 8 8 1.41-1.41L7.83 13H20v-2z"/></svg>
                                        Back
                                    </button>
                                    <div class="snn-cc-lang-list">
                                        <button class="snn-cc-menu-item" data-track="-1">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19.73 21 21 19.73l-9-9L4.27 3 3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21zM12 4 9.91 6.09 12 8.18V4z"/></svg>
                                            Off
                                        </button>
                                        <?php foreach ($subtitles as $index => $subtitle): ?>
                                        <button class="snn-cc-menu-item" data-track="<?php echo $index; ?>">
                                            <?php echo esc_html($subtitle['label']); ?>
                                        </button>
                                        <?php endforeach; ?>
                                        <button class="snn-cc-menu-item snn-cc-settings-btn">
                                            <span>Settings</span>
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg>
                                        </button>
                                    </div>
                                    <div class="snn-cc-settings-panel">
                                        <div class="snn-cc-settings-row">
                                            <label class="snn-cc-settings-label">Font Size <span class="snn-cc-font-size-value">20</span>px</label>
                                            <input type="range" class="snn-cc-settings-input snn-cc-font-size" min="10" max="100" value="20" step="2">
                                        </div>
                                        <div class="snn-cc-settings-row">
                                            <label class="snn-cc-settings-label">Text Color</label>
                                            <input type="color" class="snn-cc-settings-input snn-cc-text-color" value="#ffffff">
                                        </div>
                                        <div class="snn-cc-settings-row">
                                            <label class="snn-cc-settings-label">Background Color</label>
                                            <input type="color" class="snn-cc-settings-input snn-cc-bg-color" value="#000000">
                                        </div>
                                        <div class="snn-cc-settings-row">
                                            <label class="snn-cc-settings-label">Background Opacity <span class="snn-cc-bg-opacity-value">80</span>%</label>
                                            <input type="range" class="snn-cc-settings-input snn-cc-bg-opacity" min="0" max="100" value="80" step="5">
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="snn-settings-container">
                                <button class="snn-control-button snn-settings-btn" aria-label="Playback Speed">1x</button>
                                <div class="snn-settings-menu">
                                    <button class="snn-speed-option snn-active" data-speed="1">1x</button>
                                    <button class="snn-speed-option" data-speed="1.5">1.5x</button>
                                    <button class="snn-speed-option" data-speed="2">2x</button>
                                    <button class="snn-speed-option" data-speed="4">4x</button>
                                    <button class="snn-speed-option" data-speed="8">8x</button>
                                </div>
                            </div>

                            <button class="snn-control-button snn-fullscreen-btn" aria-label="Fullscreen">
                                <svg class="snn-fullscreen-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M7 14H5v5h5v-2H7v-3zm-2-4h2V7h3V5H5v5zm12 7h-3v2h5v-5h-2v3zM14 5v2h3v3h2V5h-5z"/></svg>
                                <svg class="snn-fullscreen-exit-icon snn-hidden" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M5 16h3v3h2v-5H5v2zm3-8H5v2h5V5H8v3zm6 11h2v-3h3v-2h-5v5zm2-11V5h-2v5h5V8h-3z"/></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

// Mark Complete Shortcode
add_shortcode('snn_mark_complete', 'snn_edu_mark_complete_shortcode');

function snn_edu_mark_complete_shortcode($atts) {
    if (!is_user_logged_in()) {
        return '';
    }
    
    $atts = shortcode_atts([
        'text' => __('Complete Lesson', 'snn')
    ], $atts);
    
    $post_id = get_the_ID();
    
    // Auto-enroll on load
    if (snn_edu_is_lesson($post_id)) {
        $user_id = get_current_user_id();
        snn_edu_auto_enroll($user_id, $post_id);
        
        // Check if already completed
        global $wpdb;
        $table = $wpdb->prefix . 'snn_edu_data';
        $course_id = snn_edu_get_top_level_course($post_id);
        
        $completed = $wpdb->get_var($wpdb->prepare(
            "SELECT status FROM $table WHERE user_id = %d AND course_id = %d AND lesson_id = %d",
            $user_id, $course_id, $post_id
        ));
        
        if ($completed === 'completed') {
            return '<p class="snn-completed-message">' . __('Lesson completed!', 'snn') . '</p>';
        }
    }
    
    return sprintf(
        '<button class="snn-mark-complete-btn" data-lesson-id="%d">%s</button>',
        $post_id,
        esc_html($atts['text'])
    );
}

// Certificate Button Shortcode
add_shortcode('snn_certificate_button', 'snn_edu_certificate_button_shortcode');

function snn_edu_certificate_button_shortcode($atts) {
    if (!is_user_logged_in()) {
        return '';
    }
    
    $atts = shortcode_atts([
        'course_id' => 0,
        'page_url' => '',
        'text' => __('Get Certificate', 'snn')
    ], $atts);
    
    $user_id = get_current_user_id();
    $course_id = $atts['course_id'] ?: snn_edu_get_top_level_course(get_the_ID());
    
    $progress = snn_edu_get_course_progress($user_id, $course_id);
    
    if ($progress < 100) {
        return '';
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'snn_edu_certificates';
    $cert = $wpdb->get_row($wpdb->prepare(
        "SELECT certificate_id, completion_date FROM $table WHERE user_id = %d AND course_id = %d",
        $user_id, $course_id
    ));
    
    if (!$cert) {
        return '';
    }
    
    $instructor_id = get_post_field('post_author', $course_id);
    $cert_url = sprintf(
        '%s/instructor/%d/?cid=%d&uid=%d&completion_date=%s&certificate_id=%s',
        home_url(),
        $instructor_id,
        $course_id,
        $user_id,
        urlencode($cert->completion_date),
        urlencode($cert->certificate_id)
    );
    
    if ($atts['page_url']) {
        $cert_url = add_query_arg([
            'cid' => $course_id,
            'uid' => $user_id,
            'completion_date' => urlencode($cert->completion_date),
            'certificate_id' => urlencode($cert->certificate_id)
        ], $atts['page_url']);
    }
    
    return sprintf(
        '<a href="%s" class="snn-certificate-btn">%s</a>',
        esc_url($cert_url),
        esc_html($atts['text'])
    );
}

// Course Progress Shortcode
add_shortcode('snn_course_progress', 'snn_edu_course_progress_shortcode');

function snn_edu_course_progress_shortcode($atts) {
    if (!is_user_logged_in()) {
        return '';
    }
    
    $atts = shortcode_atts([
        'course_id' => 0,
        'format' => 'number'
    ], $atts);
    
    $user_id = get_current_user_id();
    $course_id = $atts['course_id'] ?: snn_edu_get_top_level_course(get_the_ID());
    $progress = snn_edu_get_course_progress($user_id, $course_id);
    
    if ($atts['format'] === 'bar') {
        return sprintf(
            '<div class="snn-progress-bar-wrapper"><div class="snn-progress-bar-fill" style="width: %d%%;">%d%%</div></div>',
            $progress, $progress
        );
    }
    
    return '<span class="snn-course-progress">' . $progress . '%</span>';
}

// Strike Weekly Shortcode
add_shortcode('snn_strike_weekly', 'snn_edu_strike_weekly_shortcode');

function snn_edu_strike_weekly_shortcode($atts) {
    if (!is_user_logged_in()) {
        return '';
    }
    
    $user_id = get_current_user_id();
    global $wpdb;
    $table = $wpdb->prefix . 'snn_edu_data';
    
    $start_of_week = strtotime('monday this week');
    $days = ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
    
    $output = '<div class="snn-strike-weekly">';
    
    for ($i = 0; $i < 7; $i++) {
        $day_start = date('Y-m-d 00:00:00', $start_of_week + ($i * 86400));
        $day_end = date('Y-m-d 23:59:59', $start_of_week + ($i * 86400));
        
        $activity = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table 
             WHERE user_id = %d AND status = 'completed' 
             AND updated_at BETWEEN %s AND %s",
            $user_id, $day_start, $day_end
        ));
        
        $symbol = $activity > 0 ? '🔥' : '●';
        $output .= sprintf('<span class="snn-strike-day" title="%s">%s</span>', $days[$i], $symbol);
    }
    
    $output .= '</div>';
    return $output;
}

// Strike Count Shortcode
add_shortcode('snn_strike_count', 'snn_edu_strike_count_shortcode');

function snn_edu_strike_count_shortcode($atts) {
    if (!is_user_logged_in()) {
        return '';
    }
    
    $user_id = get_current_user_id();
    global $wpdb;
    $table = $wpdb->prefix . 'snn_edu_data';
    
    // Get all completion dates
    $dates = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT DATE(updated_at) as date FROM $table 
         WHERE user_id = %d AND status = 'completed' 
         ORDER BY date DESC",
        $user_id
    ));
    
    $streak = 0;
    $current_date = date('Y-m-d');
    
    foreach ($dates as $date) {
        $diff = abs(strtotime($current_date) - strtotime($date)) / 86400;
        
        if ($diff <= 1) {
            $streak++;
            $current_date = date('Y-m-d', strtotime($date . ' -1 day'));
        } else {
            break;
        }
    }
    
    return '<span class="snn-strike-count">' . $streak . '</span>';
}

// User Enrolled Courses
add_shortcode('snn_user_enrolled_courses', 'snn_edu_user_enrolled_courses_shortcode');

function snn_edu_user_enrolled_courses_shortcode($atts) {
    if (!is_user_logged_in()) {
        return '<p>' . __('Please log in.', 'snn') . '</p>';
    }
    
    $user_id = get_current_user_id();
    global $wpdb;
    $table = $wpdb->prefix . 'snn_edu_data';
    
    $course_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT course_id FROM $table WHERE user_id = %d",
        $user_id
    ));
    
    if (empty($course_ids)) {
        return '<p>' . __('No enrolled courses.', 'snn') . '</p>';
    }
    
    $output = '<ul class="snn-enrolled-courses">';
    foreach ($course_ids as $course_id) {
        $title = get_the_title($course_id);
        $url = get_permalink($course_id);
        $progress = snn_edu_get_course_progress($user_id, $course_id);
        
        $output .= sprintf(
            '<li><a href="%s">%s</a> - %d%%</li>',
            esc_url($url),
            esc_html($title),
            $progress
        );
    }
    $output .= '</ul>';
    
    return $output;
}

// User Completions
add_shortcode('snn_user_completions', 'snn_edu_user_completions_shortcode');

function snn_edu_user_completions_shortcode($atts) {
    if (!is_user_logged_in()) {
        return '<p>' . __('Please log in.', 'snn') . '</p>';
    }
    
    $user_id = get_current_user_id();
    global $wpdb;
    $table = $wpdb->prefix . 'snn_edu_certificates';
    
    $completions = $wpdb->get_results($wpdb->prepare(
        "SELECT course_id, completion_date FROM $table WHERE user_id = %d ORDER BY completion_date DESC",
        $user_id
    ));
    
    if (empty($completions)) {
        return '<p>' . __('No completed courses.', 'snn') . '</p>';
    }
    
    $output = '<ul class="snn-completions">';
    foreach ($completions as $completion) {
        $title = get_the_title($completion->course_id);
        $url = get_permalink($completion->course_id);
        $date = date_i18n(get_option('date_format'), strtotime($completion->completion_date));
        
        $output .= sprintf(
            '<li><a href="%s">%s</a> - %s</li>',
            esc_url($url),
            esc_html($title),
            esc_html($date)
        );
    }
    $output .= '</ul>';
    
    return $output;
}

// User Strikes
add_shortcode('snn_user_strikes', 'snn_edu_user_strikes_shortcode');

function snn_edu_user_strikes_shortcode($atts) {
    if (!is_user_logged_in()) {
        return '<p>' . __('Please log in.', 'snn') . '</p>';
    }
    
    $weekly = snn_edu_strike_weekly_shortcode([]);
    $count = snn_edu_strike_count_shortcode([]);
    
    return sprintf(
        '<div class="snn-user-strikes">%s<p>' . __('Streak: %s days', 'snn') . '</p></div>',
        $weekly,
        $count
    );
}

// User Certificates
add_shortcode('snn_user_certificates', 'snn_edu_user_certificates_shortcode');

function snn_edu_user_certificates_shortcode($atts) {
    if (!is_user_logged_in()) {
        return '<p>' . __('Please log in.', 'snn') . '</p>';
    }
    
    $user_id = get_current_user_id();
    global $wpdb;
    $table = $wpdb->prefix . 'snn_edu_certificates';
    
    $certificates = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE user_id = %d ORDER BY completion_date DESC",
        $user_id
    ));
    
    if (empty($certificates)) {
        return '<p>' . __('No certificates earned.', 'snn') . '</p>';
    }
    
    $output = '<ul class="snn-certificates">';
    foreach ($certificates as $cert) {
        $title = get_the_title($cert->course_id);
        $instructor_id = get_post_field('post_author', $cert->course_id);
        $cert_url = sprintf(
            '%s/instructor/%d/?cid=%d&uid=%d&completion_date=%s&certificate_id=%s',
            home_url(),
            $instructor_id,
            $cert->course_id,
            $user_id,
            urlencode($cert->completion_date),
            urlencode($cert->certificate_id)
        );
        
        $output .= sprintf(
            '<li><a href="%s">%s</a> - %s</li>',
            esc_url($cert_url),
            esc_html($title),
            esc_html(date_i18n(get_option('date_format'), strtotime($cert->completion_date)))
        );
    }
    $output .= '</ul>';
    
    return $output;
}

// Comment Form Shortcode
add_shortcode('snn_comment_form', 'snn_edu_comment_form_shortcode');

function snn_edu_comment_form_shortcode($atts) {
    $atts = shortcode_atts([
        'post_id' => 0,
        'title_reply' => __('Leave a Reply', 'snn'),
        'label_submit' => __('Post Comment', 'snn'),
    ], $atts);

    $post_id = $atts['post_id'] ? intval($atts['post_id']) : get_the_ID();

    if (!comments_open($post_id)) {
        return '<p class="snn-comments-closed">' . __('Comments are closed.', 'snn') . '</p>';
    }

    ob_start();

    $args = [
        'id_form'         => 'snn-comment-form',
        'class_form'      => 'snn-comment-form',
        'title_reply'     => esc_html($atts['title_reply']),
        'label_submit'    => esc_html($atts['label_submit']),
        'class_submit'    => 'snn-comment-submit button',
        'comment_field'   => '<p class="snn-comment-field"><label for="comment">' . __('Comment', 'snn') . '</label><textarea id="comment" name="comment" class="snn-comment-textarea" rows="6" required></textarea></p>',
        'fields' => [
            'author' => '<p class="snn-comment-author"><label for="author">' . __('Name', 'snn') . '</label><input id="author" name="author" type="text" class="snn-comment-input" required></p>',
            'email'  => '<p class="snn-comment-email"><label for="email">' . __('Email', 'snn') . '</label><input id="email" name="email" type="email" class="snn-comment-input" required></p>',
        ],
    ];

    if (is_user_logged_in()) {
        unset($args['fields']['author'], $args['fields']['email']);
    }

    echo '<div class="snn-comment-form-wrapper">';
    comment_form($args, $post_id);

    $comments = get_comments([
        'post_id' => $post_id,
        'status'  => 'approve',
        'order'   => 'ASC',
    ]);

    if (!empty($comments)) {
        echo '<div class="snn-comments-list">';
        echo '<h3 class="snn-comments-title">' . sprintf(_n('%d Comment', '%d Comments', count($comments), 'snn'), count($comments)) . '</h3>';
        echo '<ol class="snn-comments">';
        foreach ($comments as $comment) {
            $rating = get_comment_meta($comment->comment_ID, 'snn_education_rating_comment', true);
            $stars = $rating ? '<span class="snn-comment-rating">' . str_repeat('⭐', intval($rating)) . '</span>' : '';
            echo '<li class="snn-comment" id="snn-comment-' . $comment->comment_ID . '">';
            echo '<div class="snn-comment-meta">';
            echo '<span class="snn-comment-author">' . esc_html($comment->comment_author) . '</span>';
            echo '<span class="snn-comment-date">' . esc_html(date_i18n(get_option('date_format'), strtotime($comment->comment_date))) . '</span>';
            echo $stars;
            echo '</div>';
            echo '<div class="snn-comment-body">' . wpautop(esc_html($comment->comment_content)) . '</div>';
            echo '</li>';
        }
        echo '</ol>';
        echo '</div>';
    }

    echo '</div>';

    return ob_get_clean();
}

// Course Sidebar Shortcode
add_shortcode('snn_course_sidebar', 'snn_edu_course_sidebar_shortcode');

function snn_edu_course_sidebar_shortcode($atts) {
    $atts = shortcode_atts([
        'course_id' => 0,
    ], $atts);

    $course_id = $atts['course_id'] ? intval($atts['course_id']) : snn_edu_get_top_level_course(get_the_ID());

    if (!$course_id) {
        return '';
    }

    $user_id       = get_current_user_id();
    $current_id    = get_the_ID();
    $post_types    = snn_edu_get_allowed_post_types();

    // Get completed lesson IDs for this user/course
    $completed_ids = [];
    if ($user_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'snn_edu_data';
        $completed_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT lesson_id FROM $table WHERE user_id = %d AND course_id = %d AND status = 'completed'",
            $user_id, $course_id
        ));
        $completed_ids = array_map('intval', $completed_ids);
    }

    // Get chapters (direct children of course)
    $chapters = get_posts([
        'post_parent'    => $course_id,
        'post_type'      => $post_types,
        'posts_per_page' => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
        'post_status'    => 'publish',
    ]);

    if (empty($chapters)) {
        return '';
    }

    $output  = '<nav class="snn-course-sidebar" data-course-id="' . esc_attr($course_id) . '">';
    $output .= '<ul class="snn-course-sidebar-chapters">';

    foreach ($chapters as $chapter) {
        $chapter_completed = in_array($chapter->ID, $completed_ids);
        $chapter_classes   = 'snn-chapter';
        if ($chapter_completed) {
            $chapter_classes .= ' snn-chapter-completed';
        }

        $output .= '<li class="' . esc_attr($chapter_classes) . '" data-chapter-id="' . esc_attr($chapter->ID) . '">';
        $output .= '<span class="snn-chapter-title">' . esc_html($chapter->post_title) . '</span>';

        // Get lessons (children of chapter)
        $lessons = get_posts([
            'post_parent'    => $chapter->ID,
            'post_type'      => $post_types,
            'posts_per_page' => -1,
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
            'post_status'    => 'publish',
        ]);

        if (!empty($lessons)) {
            $output .= '<ul class="snn-chapter-lessons">';
            foreach ($lessons as $lesson) {
                $lesson_completed = in_array($lesson->ID, $completed_ids);
                $is_current       = ($lesson->ID === $current_id);

                $lesson_classes = 'snn-lesson';
                if ($lesson_completed) {
                    $lesson_classes .= ' snn-lesson-completed';
                }
                if ($is_current) {
                    $lesson_classes .= ' snn-lesson-current';
                }

                $output .= '<li class="' . esc_attr($lesson_classes) . '" data-lesson-id="' . esc_attr($lesson->ID) . '">';
                $output .= '<a href="' . esc_url(get_permalink($lesson->ID)) . '" class="snn-lesson-link">';
                $output .= esc_html($lesson->post_title);
                if ($lesson_completed) {
                    $output .= ' <span class="snn-lesson-checkmark" aria-label="' . esc_attr__('Completed', 'snn') . '">✓</span>';
                }
                $output .= '</a>';
                $output .= '</li>';
            }
            $output .= '</ul>';
        }

        $output .= '</li>';
    }

    $output .= '</ul>';
    $output .= '</nav>';

    return $output;
}

// ===========================================================
// CHAPTER REDIRECT
// ===========================================================

add_action('template_redirect', 'snn_edu_chapter_redirect');

function snn_edu_chapter_redirect() {
    if (!is_singular(snn_edu_get_allowed_post_types())) {
        return;
    }
    
    $post_id = get_the_ID();
    
    if (snn_edu_is_chapter($post_id)) {
        $first_lesson = snn_edu_get_first_lesson_in_chapter($post_id);
        
        if ($first_lesson) {
            // Mark chapter as started/completed when visited
            if (is_user_logged_in()) {
                $user_id = get_current_user_id();
                $course_id = snn_edu_get_top_level_course($post_id);
                snn_edu_track_activity($user_id, $course_id, $post_id, 'completed');
            }
            
            wp_redirect(get_permalink($first_lesson));
            exit;
        }
    }
}

// ===========================================================
// COMMENT RATINGS
// ===========================================================

add_action('add_meta_boxes_comment', 'snn_edu_add_comment_meta_box');
add_action('edit_comment', 'snn_edu_save_comment_rating');
add_filter('manage_edit-comments_columns', 'snn_edu_add_comments_column');
add_filter('manage_comments_custom_column', 'snn_edu_comments_column_content', 10, 2);
add_action('comment_form_logged_in_after', 'snn_edu_comment_rating_field');
add_action('comment_form_after_fields', 'snn_edu_comment_rating_field');
add_action('comment_post', 'snn_edu_save_comment_rating_frontend');

function snn_edu_add_comment_meta_box() {
    add_meta_box(
        'snn_comment_rating',
        __('Rating', 'snn'),
        'snn_edu_comment_rating_meta_box',
        'comment',
        'normal',
        'high'
    );
}

function snn_edu_comment_rating_meta_box($comment) {
    $rating = get_comment_meta($comment->comment_ID, 'snn_education_rating_comment', true);
    wp_nonce_field('snn_comment_rating', 'snn_comment_rating_nonce');
    ?>
    <p>
        <label for="snn_comment_rating"><?php _e('Rating:', 'snn'); ?></label>
        <select name="snn_comment_rating" id="snn_comment_rating">
            <option value=""><?php _e('No rating', 'snn'); ?></option>
            <?php for ($i = 1; $i <= 5; $i++): ?>
            <option value="<?php echo $i; ?>" <?php selected($rating, $i); ?>><?php echo $i; ?></option>
            <?php endfor; ?>
        </select>
    </p>
    <?php
}

function snn_edu_save_comment_rating($comment_id) {
    if (!isset($_POST['snn_comment_rating_nonce']) || !wp_verify_nonce($_POST['snn_comment_rating_nonce'], 'snn_comment_rating')) {
        return;
    }
    
    if (isset($_POST['snn_comment_rating'])) {
        $rating = intval($_POST['snn_comment_rating']);
        if ($rating >= 1 && $rating <= 5) {
            update_comment_meta($comment_id, 'snn_education_rating_comment', $rating);
        } else {
            delete_comment_meta($comment_id, 'snn_education_rating_comment');
        }
    }
}

function snn_edu_add_comments_column($columns) {
    if (!snn_edu_get_option('enable_comment_ratings', false)) {
        return $columns;
    }
    
    $columns['rating'] = __('Rating', 'snn');
    return $columns;
}

function snn_edu_comments_column_content($column, $comment_id) {
    if ($column === 'rating') {
        $rating = get_comment_meta($comment_id, 'snn_education_rating_comment', true);
        echo $rating ? str_repeat('⭐', intval($rating)) : '-';
    }
}

function snn_edu_comment_rating_field() {
    if (!snn_edu_get_option('enable_comment_ratings', false)) {
        return;
    }
    ?>
    <p class="comment-form-rating">
        <label for="snn_comment_rating"><?php _e('Rating', 'snn'); ?></label>
        <select name="snn_comment_rating" id="snn_comment_rating">
            <option value=""><?php _e('Select rating', 'snn'); ?></option>
            <?php for ($i = 1; $i <= 5; $i++): ?>
            <option value="<?php echo $i; ?>"><?php echo str_repeat('⭐', $i); ?></option>
            <?php endfor; ?>
        </select>
    </p>
    <?php
}

function snn_edu_save_comment_rating_frontend($comment_id) {
    if (isset($_POST['snn_comment_rating'])) {
        $rating = intval($_POST['snn_comment_rating']);
        if ($rating >= 1 && $rating <= 5) {
            add_comment_meta($comment_id, 'snn_education_rating_comment', $rating);
        }
    }
}

// ===========================================================
// ENLIGHTERJS INTEGRATION
// ===========================================================

add_action('wp_enqueue_scripts', 'snn_edu_enqueue_enlighterjs');

function snn_edu_enqueue_enlighterjs() {
    if (!is_singular(snn_edu_get_option('enlighterjs_post_types', []))) {
        return;
    }
    
    wp_enqueue_style('enlighterjs', SNN_EDU_PLUGIN_URL . 'assets/css/enlighterjs.min_.css', [], SNN_EDU_VERSION);
    wp_enqueue_script('enlighterjs', SNN_EDU_PLUGIN_URL . 'assets/js/enlighterjs.min_.js', [], SNN_EDU_VERSION, true);
    
    wp_add_inline_script('enlighterjs', "
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof EnlighterJS !== 'undefined') {
                EnlighterJS.init('pre#wp-block-snn-pre-code', 'code', {
                    language: 'generic',
                    theme: 'monokai',
                    indent: 1
                });
            }
        });
    ");
    
    wp_add_inline_style('enlighterjs', "
        .enlighter-btn-website,
        .enlighter-btn-collapse {
            display: none !important;
        }
    ");
}

// ===========================================================
// CUSTOM AUTHOR URLS
// ===========================================================

add_action('init', 'snn_edu_setup_author_rewrite');
add_filter('author_link', 'snn_edu_custom_author_link', 10, 3);
add_action('template_redirect', 'snn_edu_author_redirect');

function snn_edu_setup_author_rewrite() {
    if (!snn_edu_get_option('enable_custom_author_urls', false)) {
        return;
    }
    
    global $wp_rewrite;
    $wp_rewrite->author_base = 'user';
    
    add_rewrite_rule('^user/([0-9]+)/?$', 'index.php?author=$matches[1]', 'top');
    add_rewrite_rule('^instructor/([0-9]+)/?$', 'index.php?author=$matches[1]', 'top');
}

function snn_edu_custom_author_link($link, $author_id, $author_nicename) {
    if (!snn_edu_get_option('enable_custom_author_urls', false)) {
        return $link;
    }
    
    $user = get_userdata($author_id);
    if (!$user) {
        return $link;
    }
    
    $base = in_array('instructor', $user->roles) ? 'instructor' : 'user';
    return home_url("/$base/$author_id/");
}

function snn_edu_author_redirect() {
    if (!is_author() || !snn_edu_get_option('enable_custom_author_urls', false)) {
        return;
    }
    
    $author_id = get_queried_object_id();
    $user = get_userdata($author_id);
    
    if (!$user) {
        return;
    }
    
    $is_instructor = in_array('instructor', $user->roles);
    $current_path = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $path_parts = explode('/', $current_path);
    $base = $path_parts[0] ?? '';
    
    // Redirect old /author/ URLs
    if ($base === 'author') {
        $correct_base = $is_instructor ? 'instructor' : 'user';
        wp_redirect(home_url("/$correct_base/$author_id/"), 301);
        exit;
    }
    
    // Enforce correct base
    if ($base === 'instructor' && !$is_instructor) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
    } elseif ($base === 'user' && $is_instructor) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
    }
}

// ===========================================================
// WP-ADMIN RESTRICTIONS
// ===========================================================

add_action('admin_init', 'snn_edu_restrict_admin_access');
add_action('after_setup_theme', 'snn_edu_hide_admin_bar');

function snn_edu_restrict_admin_access() {
    if (!snn_edu_get_option('restrict_admin_access', false)) {
        return;
    }
    
    if (!current_user_can('manage_options') && !wp_doing_ajax()) {
        wp_redirect(home_url());
        exit;
    }
}

function snn_edu_hide_admin_bar() {
    if (!snn_edu_get_option('hide_admin_bar', false)) {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        show_admin_bar(false);
    }
}

// ===========================================================
// FRONTEND SCRIPTS
// ===========================================================

add_action('wp_enqueue_scripts', 'snn_edu_enqueue_frontend_scripts');

function snn_edu_enqueue_frontend_scripts() {
    if (!is_user_logged_in()) {
        return;
    }
    
    wp_enqueue_style('snn-education', SNN_EDU_PLUGIN_URL . 'assets/css/snn-education.css', [], SNN_EDU_VERSION);
    wp_enqueue_script('snn-education', SNN_EDU_PLUGIN_URL . 'assets/js/snn-education.js', ['jquery'], SNN_EDU_VERSION, true);
    
    wp_localize_script('snn-education', 'snnEduData', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'restUrl' => rest_url('snn-edu/'),
        'nonce' => wp_create_nonce('wp_rest'),
        'userId' => get_current_user_id(),
        'postId' => get_the_ID()
    ]);
}

// ===========================================================
// ADMIN MENU
// ===========================================================

add_action('admin_menu', 'snn_edu_admin_menu');

function snn_edu_admin_menu() {
    add_menu_page(
        __('SNN Education', 'snn'),
        __('SNN Education', 'snn'),
        'manage_options',
        'snn-education',
        'snn_edu_dashboard_page',
        'dashicons-welcome-learn-more',
        3
    );
    
    add_submenu_page(
        'snn-education',
        __('Dashboard', 'snn'),
        __('Dashboard', 'snn'),
        'manage_options',
        'snn-education',
        'snn_edu_dashboard_page'
    );
    
    add_submenu_page(
        'snn-education',
        __('Settings', 'snn'),
        __('Settings', 'snn'),
        'manage_options',
        'snn-education-settings',
        'snn_edu_settings_page'
    );
    
    add_submenu_page(
        'snn-education',
        __('Shortcodes', 'snn'),
        __('Shortcodes', 'snn'),
        'manage_options',
        'snn-education-shortcodes',
        'snn_edu_shortcodes_page'
    );
}

// ===========================================================
// ADMIN DASHBOARD PAGE
// ===========================================================

function snn_edu_dashboard_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Access denied', 'snn'));
    }
    
    global $wpdb;
    $data_table = $wpdb->prefix . 'snn_edu_data';
    $cert_table = $wpdb->prefix . 'snn_edu_certificates';
    
    // Get stats
    $total_students = $wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM $data_table");
    $total_completions = $wpdb->get_var("SELECT COUNT(*) FROM $cert_table");
    
    // Most active courses
    $active_courses = $wpdb->get_results("
        SELECT course_id, COUNT(DISTINCT user_id) as student_count 
        FROM $data_table 
        GROUP BY course_id 
        ORDER BY student_count DESC 
        LIMIT 10
    ");
    
    // Handle manual actions
    if (isset($_POST['snn_manual_enroll']) && check_admin_referer('snn_manual_action')) {
        $user_id = intval($_POST['user_id']);
        $post_id = intval($_POST['post_id']);
        snn_edu_auto_enroll($user_id, $post_id);
        echo '<div class="notice notice-success"><p>' . __('User enrolled successfully.', 'snn') . '</p></div>';
    }
    
    // Get all data
    $filter_course = isset($_GET['filter_course']) ? intval($_GET['filter_course']) : 0;
    $filter_user = isset($_GET['filter_user']) ? intval($_GET['filter_user']) : 0;
    $filter_status = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : '';
    
    $where = ['1=1'];
    if ($filter_course) $where[] = $wpdb->prepare('course_id = %d', $filter_course);
    if ($filter_user) $where[] = $wpdb->prepare('user_id = %d', $filter_user);
    if ($filter_status) $where[] = $wpdb->prepare('status = %s', $filter_status);
    
    $where_sql = implode(' AND ', $where);
    $all_data = $wpdb->get_results("SELECT * FROM $data_table WHERE $where_sql ORDER BY updated_at DESC LIMIT 100");
    
    ?>
    <div class="wrap">
        <h1><?php _e('SNN Education Dashboard', 'snn'); ?></h1>
        
        <div class="snn-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
            <div class="snn-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #2271b1;">
                <h3><?php _e('Active Students', 'snn'); ?></h3>
                <p style="font-size: 32px; font-weight: bold; margin: 0;"><?php echo $total_students; ?></p>
            </div>
            <div class="snn-stat-card" style="background: #fff; padding: 20px; border-left: 4px solid #00a32a;">
                <h3><?php _e('Total Completions', 'snn'); ?></h3>
                <p style="font-size: 32px; font-weight: bold; margin: 0;"><?php echo $total_completions; ?></p>
            </div>
        </div>
        
        <h2><?php _e('Most Active Courses', 'snn'); ?></h2>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Course', 'snn'); ?></th>
                    <th><?php _e('Students', 'snn'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($active_courses as $course): ?>
                <tr>
                    <td><?php echo esc_html(get_the_title($course->course_id)); ?></td>
                    <td><?php echo intval($course->student_count); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <h2><?php _e('Manual Enrollment', 'snn'); ?></h2>
        <form method="post" style="background: #fff; padding: 20px; margin: 20px 0;">
            <?php wp_nonce_field('snn_manual_action'); ?>
            <p>
                <label><?php _e('User ID:', 'snn'); ?> <input type="number" name="user_id" required></label>
                <label><?php _e('Post ID:', 'snn'); ?> <input type="number" name="post_id" required></label>
                <button type="submit" name="snn_manual_enroll" class="button button-primary"><?php _e('Enroll', 'snn'); ?></button>
            </p>
        </form>
        
        <h2><?php _e('All Activity', 'snn'); ?></h2>
        <form method="get" style="margin: 20px 0;">
            <input type="hidden" name="page" value="snn-education">
            <label><?php _e('Course:', 'snn'); ?> <input type="number" name="filter_course" value="<?php echo $filter_course; ?>"></label>
            <label><?php _e('User:', 'snn'); ?> <input type="number" name="filter_user" value="<?php echo $filter_user; ?>"></label>
            <label><?php _e('Status:', 'snn'); ?> 
                <select name="filter_status">
                    <option value=""><?php _e('All', 'snn'); ?></option>
                    <option value="started" <?php selected($filter_status, 'started'); ?>><?php _e('Started', 'snn'); ?></option>
                    <option value="completed" <?php selected($filter_status, 'completed'); ?>><?php _e('Completed', 'snn'); ?></option>
                </select>
            </label>
            <button type="submit" class="button"><?php _e('Filter', 'snn'); ?></button>
            <a href="?page=snn-education" class="button"><?php _e('Clear', 'snn'); ?></a>
            <a href="<?php echo admin_url('admin.php?page=snn-education&export_csv=1'); ?>" class="button"><?php _e('Export CSV', 'snn'); ?></a>
        </form>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('User', 'snn'); ?></th>
                    <th><?php _e('Course', 'snn'); ?></th>
                    <th><?php _e('Lesson', 'snn'); ?></th>
                    <th><?php _e('Status', 'snn'); ?></th>
                    <th><?php _e('Date', 'snn'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_data as $row): ?>
                <tr>
                    <td><?php echo esc_html(get_userdata($row->user_id)->display_name ?? 'Unknown'); ?></td>
                    <td><?php echo esc_html(get_the_title($row->course_id)); ?></td>
                    <td><?php echo esc_html(get_the_title($row->lesson_id)); ?></td>
                    <td><?php echo esc_html($row->status); ?></td>
                    <td><?php echo esc_html($row->updated_at); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// CSV Export
add_action('admin_init', 'snn_edu_export_csv');

function snn_edu_export_csv() {
    if (!isset($_GET['page']) || $_GET['page'] !== 'snn-education' || !isset($_GET['export_csv'])) {
        return;
    }
    
    if (!current_user_can('manage_options')) {
        wp_die(__('Access denied', 'snn'));
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'snn_edu_data';
    $data = $wpdb->get_results("SELECT * FROM $table ORDER BY updated_at DESC", ARRAY_A);
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="snn-education-data-' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    if (!empty($data)) {
        fputcsv($output, array_keys($data[0]));
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit;
}

// ===========================================================
// SETTINGS PAGE
// ===========================================================

function snn_edu_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Access denied', 'snn'));
    }
    
    if (isset($_POST['snn_save_settings']) && check_admin_referer('snn_settings')) {
        snn_edu_update_option('allowed_post_types', isset($_POST['allowed_post_types']) ? $_POST['allowed_post_types'] : []);
        snn_edu_update_option('restrict_admin_access', isset($_POST['restrict_admin_access']));
        snn_edu_update_option('hide_admin_bar', isset($_POST['hide_admin_bar']));
        snn_edu_update_option('enable_custom_author_urls', isset($_POST['enable_custom_author_urls']));
        snn_edu_update_option('enable_comment_ratings', isset($_POST['enable_comment_ratings']));
        snn_edu_update_option('video_threshold', intval($_POST['video_threshold']));
        snn_edu_update_option('require_full_video', isset($_POST['require_full_video']));
        snn_edu_update_option('lock_chapters', isset($_POST['lock_chapters']));
        snn_edu_update_option('lock_lessons', isset($_POST['lock_lessons']));
        snn_edu_update_option('enlighterjs_post_types', isset($_POST['enlighterjs_post_types']) ? $_POST['enlighterjs_post_types'] : []);
        
        if (isset($_POST['enable_custom_author_urls'])) {
            flush_rewrite_rules();
        }
        
        echo '<div class="notice notice-success"><p>' . __('Settings saved.', 'snn') . '</p></div>';
    }
    
    $post_types = get_post_types(['public' => true], 'objects');
    $allowed = snn_edu_get_option('allowed_post_types', ['page']);
    $enlighterjs_types = snn_edu_get_option('enlighterjs_post_types', []);
    
    ?>
    <div class="wrap">
        <h1><?php _e('SNN Education Settings', 'snn'); ?></h1>
        
        <form method="post">
            <?php wp_nonce_field('snn_settings'); ?>
            
            <h2><?php _e('General Settings', 'snn'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php _e('Allowed Post Types', 'snn'); ?></th>
                    <td>
                        <?php foreach ($post_types as $type): ?>
                        <label>
                            <input type="checkbox" name="allowed_post_types[]" value="<?php echo esc_attr($type->name); ?>" <?php checked(in_array($type->name, $allowed)); ?>>
                            <?php echo esc_html($type->label); ?>
                        </label><br>
                        <?php endforeach; ?>
                        <p class="description"><?php _e('Select which post types can be tracked.', 'snn'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Restrict WP-Admin', 'snn'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="restrict_admin_access" value="1" <?php checked(snn_edu_get_option('restrict_admin_access', false)); ?>>
                            <?php _e('Only administrators can access wp-admin', 'snn'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Hide Admin Bar', 'snn'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="hide_admin_bar" value="1" <?php checked(snn_edu_get_option('hide_admin_bar', false)); ?>>
                            <?php _e('Hide admin bar for non-admin users', 'snn'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Custom Author URLs', 'snn'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enable_custom_author_urls" value="1" <?php checked(snn_edu_get_option('enable_custom_author_urls', false)); ?>>
                            <?php _e('Enable /user/{id}/ and /instructor/{id}/ URLs', 'snn'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Comment Ratings', 'snn'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enable_comment_ratings" value="1" <?php checked(snn_edu_get_option('enable_comment_ratings', false)); ?>>
                            <?php _e('Enable comment ratings column in admin', 'snn'); ?>
                        </label>
                    </td>
                </tr>
            </table>
            
            <h2><?php _e('Tracking Settings', 'snn'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php _e('Video Threshold', 'snn'); ?></th>
                    <td>
                        <input type="number" name="video_threshold" value="<?php echo esc_attr(snn_edu_get_option('video_threshold', 3)); ?>" min="0">
                        <p class="description"><?php _e('Seconds watched before marking complete (if not requiring full video).', 'snn'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Require Full Video', 'snn'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="require_full_video" value="1" <?php checked(snn_edu_get_option('require_full_video', false)); ?>>
                            <?php _e('Only mark complete when video ends', 'snn'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Lock Chapters', 'snn'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="lock_chapters" value="1" <?php checked(snn_edu_get_option('lock_chapters', false)); ?>>
                            <?php _e('Lock chapters until previous chapter is 100% complete', 'snn'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Lock Lessons', 'snn'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="lock_lessons" value="1" <?php checked(snn_edu_get_option('lock_lessons', false)); ?>>
                            <?php _e('Lock lessons until previous lesson is complete', 'snn'); ?>
                        </label>
                    </td>
                </tr>
            </table>
            
            <h2><?php _e('Code Highlighter', 'snn'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php _e('EnlighterJS Post Types', 'snn'); ?></th>
                    <td>
                        <?php foreach ($post_types as $type): ?>
                        <label>
                            <input type="checkbox" name="enlighterjs_post_types[]" value="<?php echo esc_attr($type->name); ?>" <?php checked(in_array($type->name, $enlighterjs_types)); ?>>
                            <?php echo esc_html($type->label); ?>
                        </label><br>
                        <?php endforeach; ?>
                        <p class="description"><?php _e('Select post types where EnlighterJS should load.', 'snn'); ?></p>
                    </td>
                </tr>
            </table>
            
            <p><button type="submit" name="snn_save_settings" class="button button-primary"><?php _e('Save Settings', 'snn'); ?></button></p>
        </form>
    </div>
    <?php
}

// ===========================================================
// SHORTCODES PAGE
// ===========================================================

function snn_edu_shortcodes_page() {
    if (!current_user_can('manage_options')) {
        wp_die(__('Access denied', 'snn'));
    }

    // Each shortcode: name, description, example (minimal), params array
    // param keys: name, default, options, required, desc
    $shortcodes = [
        [
            'tag'     => 'snn_video_player',
            'example' => '[snn_video_player]',
            'desc'    => __('Embeds a custom HTML5 video player with lesson tracking, speed controls, subtitles, and fullscreen. '
                          . 'Reads the video URL from a custom field on the current post. '
                          . 'On load it auto-enrolls the logged-in user in the lesson. '
                          . '<strong>Requires the user to be logged in</strong> — shows a login prompt otherwise.', 'snn'),
            'params'  => [
                ['name' => 'field',       'default' => 'video_url', 'options' => 'Any custom field name',                         'required' => false, 'desc' => 'Custom field key that holds the video file URL. Leave blank to use the default <code>video_url</code> field.'],
                ['name' => 'poster',      'default' => '(featured image)', 'options' => 'Custom field name OR direct URL',        'required' => false, 'desc' => 'Thumbnail shown before the video plays. Can be a custom field key (e.g. <code>thumbnail_url</code>) or a full https:// URL. If omitted, the post\'s featured image is used automatically.'],
                ['name' => 'subtitles',   'default' => '',          'options' => 'Custom field name',                             'required' => false, 'desc' => 'Custom field key containing subtitle data as a serialized array: <code>[\'en\' => \'https://…/en.vtt\', \'tr\' => \'https://…/tr.vtt\']</code>. Only valid https:// URLs are loaded; missing files are silently skipped.'],
                ['name' => 'events',      'default' => 'both',      'options' => 'both | started | completed',                    'required' => false, 'desc' => '<code>both</code> fires started + completed tracking. <code>started</code> marks lesson complete after the threshold seconds watched. <code>completed</code> marks complete only when the video ends. Configure the threshold in Settings.'],
                ['name' => 'autoplay',    'default' => 'false',     'options' => 'true | false',                                  'required' => false, 'desc' => 'Auto-start video on page load. Note: browsers block autoplay with sound — combine with <code>muted="true"</code>.'],
                ['name' => 'muted',       'default' => 'false',     'options' => 'true | false',                                  'required' => false, 'desc' => 'Start the video muted.'],
                ['name' => 'loop',        'default' => 'false',     'options' => 'true | false',                                  'required' => false, 'desc' => 'Loop the video continuously.'],
                ['name' => 'width',       'default' => '100%',      'options' => 'Any CSS width value',                           'required' => false, 'desc' => 'Width of the player wrapper, e.g. <code>640px</code> or <code>80%</code>.'],
                ['name' => 'aspectratio', 'default' => '16/9',      'options' => 'Any CSS aspect-ratio value',                    'required' => false, 'desc' => 'Aspect ratio of the player, e.g. <code>4/3</code> or <code>1/1</code>.'],
            ],
        ],
        [
            'tag'     => 'snn_mark_complete',
            'example' => '[snn_mark_complete]',
            'desc'    => __('Shows a "Complete Lesson" button for text/non-video lessons. '
                          . 'Clicking it marks the current lesson as completed and records the progress. '
                          . 'Automatically replaced by a "Lesson completed!" message if already done. '
                          . '<strong>Requires the user to be logged in.</strong>', 'snn'),
            'params'  => [
                ['name' => 'text', 'default' => 'Complete Lesson', 'options' => 'Any string', 'required' => false, 'desc' => 'Button label text.'],
            ],
        ],
        [
            'tag'     => 'snn_certificate_button',
            'example' => '[snn_certificate_button]',
            'desc'    => __('Displays a "Get Certificate" link that appears <em>only</em> when the user has completed 100% of the course and a certificate record exists. '
                          . 'By default, resolves the course from the current post\'s hierarchy — no attributes needed when placed on a lesson or course page. '
                          . '<strong>Requires the user to be logged in.</strong>', 'snn'),
            'params'  => [
                ['name' => 'course_id', 'default' => '(auto)',          'options' => 'Post ID',         'required' => false, 'desc' => 'ID of the top-level course post. Auto-detected from the current page hierarchy — only set this if the shortcode is on a page outside the course tree.'],
                ['name' => 'page_url',  'default' => '(instructor URL)', 'options' => 'Any page URL',   'required' => false, 'desc' => 'Custom URL for the certificate page. If blank, links to the instructor profile URL with certificate query parameters appended.'],
                ['name' => 'text',      'default' => 'Get Certificate', 'options' => 'Any string',      'required' => false, 'desc' => 'Link label text.'],
            ],
        ],
        [
            'tag'     => 'snn_course_progress',
            'example' => '[snn_course_progress]',
            'desc'    => __('Outputs the logged-in user\'s completion percentage for a course. '
                          . 'Auto-detects the course from the current page. '
                          . '<strong>Requires the user to be logged in.</strong>', 'snn'),
            'params'  => [
                ['name' => 'course_id', 'default' => '(auto)',   'options' => 'Post ID',          'required' => false, 'desc' => 'Top-level course post ID. Auto-detected when placed inside a course hierarchy.'],
                ['name' => 'format',    'default' => 'number',   'options' => 'number | bar',     'required' => false, 'desc' => '<code>number</code> outputs <code>&lt;span&gt;75%&lt;/span&gt;</code>. <code>bar</code> outputs a CSS progress bar div.'],
            ],
        ],
        [
            'tag'     => 'snn_strike_weekly',
            'example' => '[snn_strike_weekly]',
            'desc'    => __('Shows a 7-day row (Mon–Sun) for the current week. Days with at least one completed lesson show 🔥; inactive days show ●. '
                          . '<strong>Requires the user to be logged in.</strong>', 'snn'),
            'params'  => [],
        ],
        [
            'tag'     => 'snn_strike_count',
            'example' => '[snn_strike_count]',
            'desc'    => __('Outputs the user\'s current consecutive-day learning streak as a plain number inside a <code>&lt;span&gt;</code>. '
                          . '<strong>Requires the user to be logged in.</strong>', 'snn'),
            'params'  => [],
        ],
        [
            'tag'     => 'snn_user_strikes',
            'example' => '[snn_user_strikes]',
            'desc'    => __('Convenience shortcode that combines <code>[snn_strike_weekly]</code> + <code>[snn_strike_count]</code> into one block: the weekly fire calendar followed by "Streak: N days". '
                          . '<strong>Requires the user to be logged in.</strong>', 'snn'),
            'params'  => [],
        ],
        [
            'tag'     => 'snn_user_enrolled_courses',
            'example' => '[snn_user_enrolled_courses]',
            'desc'    => __('Lists all courses the logged-in user has started, with links and current progress %. '
                          . '<strong>Requires the user to be logged in.</strong>', 'snn'),
            'params'  => [],
        ],
        [
            'tag'     => 'snn_user_completions',
            'example' => '[snn_user_completions]',
            'desc'    => __('Lists courses the user has fully completed (100%), with the completion date. '
                          . '<strong>Requires the user to be logged in.</strong>', 'snn'),
            'params'  => [],
        ],
        [
            'tag'     => 'snn_user_certificates',
            'example' => '[snn_user_certificates]',
            'desc'    => __('Lists all certificates the user has earned, with course name and a link to view/verify the certificate. '
                          . '<strong>Requires the user to be logged in.</strong>', 'snn'),
            'params'  => [],
        ],
        [
            'tag'     => 'snn_comment_form',
            'example' => '[snn_comment_form]',
            'desc'    => __('Renders a styled comment form + existing approved comments for the current post. '
                          . 'Logged-in users do not see name/email fields. '
                          . 'If the "Enable Comment Ratings" option is on in Settings, a star-rating selector appears too.', 'snn'),
            'params'  => [
                ['name' => 'post_id',      'default' => '(current post)', 'options' => 'Post ID',      'required' => false, 'desc' => 'Load comments for a different post. Leave blank to use the current post.'],
                ['name' => 'title_reply',  'default' => 'Leave a Reply',  'options' => 'Any string',   'required' => false, 'desc' => 'Heading above the comment form.'],
                ['name' => 'label_submit', 'default' => 'Post Comment',   'options' => 'Any string',   'required' => false, 'desc' => 'Submit button label.'],
            ],
        ],
        [
            'tag'     => 'snn_course_sidebar',
            'example' => '[snn_course_sidebar]',
            'desc'    => __('Renders a Udemy-style course outline sidebar: chapters are shown as non-clickable headings; lessons are links. '
                          . 'The currently viewed lesson is highlighted; completed lessons show a ✓ checkmark. '
                          . 'Works without any attributes when placed on a lesson or chapter page — the course is auto-detected from the page hierarchy.', 'snn'),
            'params'  => [
                ['name' => 'course_id', 'default' => '(auto)', 'options' => 'Post ID', 'required' => false, 'desc' => 'Top-level course post ID. Only needed when the shortcode is placed outside the course hierarchy (e.g. a custom sidebar widget page).'],
            ],
        ],
    ];

    ?>
    <div class="wrap">
        <h1><?php _e('SNN Education Shortcodes', 'snn'); ?></h1>
        <p><?php _e('All parameters are optional unless noted. Parameters auto-detect values from the current post context when possible.', 'snn'); ?></p>

        <style>
            .snn-sc-card { background:#fff; border:1px solid #c3c4c7; border-radius:4px; margin-bottom:20px; padding:0; }
            .snn-sc-header { padding:14px 18px; border-bottom:1px solid #c3c4c7; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
            .snn-sc-tag { font-family:monospace; font-size:14px; font-weight:600; color:#1d2327; }
            .snn-sc-example { font-family:monospace; font-size:12px; background:#f0f0f1; padding:3px 8px; border-radius:3px; color:#50575e; }
            .snn-sc-copy { margin-left:auto !important; }
            .snn-sc-body { padding:14px 18px; }
            .snn-sc-desc { margin:0 0 12px; color:#50575e; }
            .snn-sc-no-params { color:#999; font-style:italic; font-size:13px; }
            .snn-sc-params { width:100%; border-collapse:collapse; font-size:13px; }
            .snn-sc-params th { text-align:left; padding:6px 10px; background:#f6f7f7; border:1px solid #e0e0e0; font-weight:600; }
            .snn-sc-params td { padding:6px 10px; border:1px solid #e0e0e0; vertical-align:top; }
            .snn-sc-params tr:nth-child(even) td { background:#fafafa; }
            .snn-sc-params td:first-child { font-family:monospace; font-weight:600; color:#2271b1; white-space:nowrap; }
            .snn-sc-params .default-val { color:#646970; }
            .snn-sc-params .options-val { color:#1d7917; font-family:monospace; font-size:12px; }
        </style>

        <?php foreach ($shortcodes as $sc): ?>
        <div class="snn-sc-card">
            <div class="snn-sc-header">
                <span class="snn-sc-tag">[<?php echo esc_html($sc['tag']); ?>]</span>
                <code class="snn-sc-example"><?php echo esc_html($sc['example']); ?></code>
                <button class="button button-small snn-sc-copy"
                        onclick="navigator.clipboard.writeText('<?php echo esc_js($sc['example']); ?>').then(()=>{ this.textContent='<?php echo esc_js(__('Copied!', 'snn')); ?>'; setTimeout(()=>{ this.textContent='<?php echo esc_js(__('Copy', 'snn')); ?>'; },1500); })">
                    <?php _e('Copy', 'snn'); ?>
                </button>
            </div>
            <div class="snn-sc-body">
                <p class="snn-sc-desc"><?php echo wp_kses_post($sc['desc']); ?></p>

                <?php if (empty($sc['params'])): ?>
                    <p class="snn-sc-no-params"><?php _e('No parameters — use as-is.', 'snn'); ?></p>
                <?php else: ?>
                    <table class="snn-sc-params">
                        <thead>
                            <tr>
                                <th><?php _e('Parameter', 'snn'); ?></th>
                                <th><?php _e('Default', 'snn'); ?></th>
                                <th><?php _e('Accepted values', 'snn'); ?></th>
                                <th><?php _e('Description', 'snn'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sc['params'] as $p): ?>
                            <tr>
                                <td><?php echo esc_html($p['name']); ?></td>
                                <td class="default-val"><?php echo esc_html($p['default']); ?></td>
                                <td class="options-val"><?php echo esc_html($p['options']); ?></td>
                                <td><?php echo wp_kses_post($p['desc']); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
}
