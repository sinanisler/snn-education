<?php
/**
 * Plugin Name: SNN Education
 * Description: Complete education platform with video tracking, certificates, strikes, and course management
 * Version: 1.1
 * Author: sinanisler
 * Author URI: https://sinanisler.com
 * Text Domain: snn
 * Requires PHP: 8.0
 * GitHub: https://github.com/sinanisler/snn-education
 */

if (!defined('ABSPATH')) exit;

// Constants
define('SNN_EDU_VERSION', '1.0');
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
    register_rest_route('snn-edu/v2', '/track', [
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
    
    $atts = shortcode_atts([
        'field' => 'video_url',
        'poster' => '',
        'autoplay' => 'false',
        'muted' => 'false',
        'loop' => 'false',
        'events' => 'both',
        'subtitles' => '',
        'width' => '100%',
        'aspectratio' => '16/9'
    ], $atts);
    
    $post_id = get_the_ID();
    $video_url = get_post_meta($post_id, $atts['field'], true);
    
    if (!$video_url) {
        return '<p>' . __('No video available.', 'snn') . '</p>';
    }
    
    // Auto-enroll on load
    if (snn_edu_is_lesson($post_id)) {
        $user_id = get_current_user_id();
        snn_edu_auto_enroll($user_id, $post_id);
    }
    
    // Get poster
    $poster_url = '';
    if ($atts['poster']) {
        $poster_url = filter_var($atts['poster'], FILTER_VALIDATE_URL) 
            ? $atts['poster'] 
            : get_post_meta($post_id, $atts['poster'], true);
    }
    
    // Get subtitles
    $subtitles = [];
    if ($atts['subtitles']) {
        $subtitle_data = get_post_meta($post_id, $atts['subtitles'], true);
        if (is_array($subtitle_data)) {
            $subtitles = $subtitle_data;
        }
    }
    
    $player_id = 'snn-video-' . $post_id;
    $threshold = snn_edu_get_option('video_threshold', 3);
    $require_full = snn_edu_get_option('require_full_video', false);
    
    ob_start();
    ?>
    <div class="snn-video-player-wrapper" style="width: <?php echo esc_attr($atts['width']); ?>;">
        <div class="snn-video-container" id="<?php echo esc_attr($player_id); ?>" 
             data-lesson-id="<?php echo esc_attr($post_id); ?>"
             data-events="<?php echo esc_attr($atts['events']); ?>"
             data-threshold="<?php echo esc_attr($threshold); ?>"
             data-require-full="<?php echo esc_attr($require_full ? 'true' : 'false'); ?>">
            <video class="snn-video" <?php echo $poster_url ? 'poster="' . esc_url($poster_url) . '"' : ''; ?>>
                <source src="<?php echo esc_url($video_url); ?>" type="video/mp4">
                <?php foreach ($subtitles as $lang => $vtt_url): ?>
                <track kind="subtitles" src="<?php echo esc_url($vtt_url); ?>" srclang="<?php echo esc_attr($lang); ?>" label="<?php echo esc_attr(strtoupper($lang)); ?>">
                <?php endforeach; ?>
            </video>
            <div class="snn-video-controls">
                <button class="snn-play-btn" aria-label="Play">▶</button>
                <input type="range" class="snn-progress-bar" min="0" max="100" value="0" step="0.1">
                <span class="snn-time-display">0:00 / 0:00</span>
                <button class="snn-volume-btn" aria-label="Volume">🔊</button>
                <input type="range" class="snn-volume-slider" min="0" max="100" value="100">
                <button class="snn-cc-btn" aria-label="Subtitles">CC</button>
                <div class="snn-cc-menu" style="display: none;">
                    <div class="snn-cc-option" data-lang="off"><?php _e('Off', 'snn'); ?></div>
                    <?php foreach ($subtitles as $lang => $vtt_url): ?>
                    <div class="snn-cc-option" data-lang="<?php echo esc_attr($lang); ?>"><?php echo esc_html(strtoupper($lang)); ?></div>
                    <?php endforeach; ?>
                </div>
                <button class="snn-settings-btn" aria-label="Settings">⚙</button>
                <div class="snn-settings-menu" style="display: none;">
                    <label><?php _e('Font Size', 'snn'); ?>: <input type="range" class="snn-font-size" min="12" max="32" value="20"></label>
                    <label><?php _e('Text Color', 'snn'); ?>: <input type="color" class="snn-text-color" value="#ffffff"></label>
                    <label><?php _e('BG Color', 'snn'); ?>: <input type="color" class="snn-bg-color" value="#000000"></label>
                    <label><?php _e('BG Opacity', 'snn'); ?>: <input type="range" class="snn-bg-opacity" min="0" max="100" value="80"></label>
                </div>
                <button class="snn-speed-btn" aria-label="Speed">1x</button>
                <div class="snn-speed-menu" style="display: none;">
                    <div class="snn-speed-option" data-speed="1">1x</div>
                    <div class="snn-speed-option" data-speed="1.5">1.5x</div>
                    <div class="snn-speed-option" data-speed="2">2x</div>
                    <div class="snn-speed-option" data-speed="4">4x</div>
                    <div class="snn-speed-option" data-speed="8">8x</div>
                </div>
                <button class="snn-fullscreen-btn" aria-label="Fullscreen">⛶</button>
            </div>
            <div class="snn-progress-tooltip" style="display: none;">0:00</div>
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
        10
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
    
    $shortcodes = [
        [
            'code' => '[snn_video_player field="video_url" poster="poster_url" events="both"]',
            'desc' => __('Custom video player with tracking', 'snn'),
            'params' => 'field, poster, autoplay, muted, loop, events, subtitles, width, aspectratio'
        ],
        [
            'code' => '[snn_mark_complete text="Complete Lesson"]',
            'desc' => __('Manual completion button for non-video lessons', 'snn'),
            'params' => 'text'
        ],
        [
            'code' => '[snn_certificate_button course_id="" page_url="" text="Get Certificate"]',
            'desc' => __('Certificate download button (shown when course is 100% complete)', 'snn'),
            'params' => 'course_id, page_url, text'
        ],
        [
            'code' => '[snn_course_progress course_id="" format="number"]',
            'desc' => __('Display course completion percentage', 'snn'),
            'params' => 'course_id, format (number|bar)'
        ],
        [
            'code' => '[snn_strike_weekly]',
            'desc' => __('Weekly strike calendar (M-S with 🔥 for active days)', 'snn'),
            'params' => 'none'
        ],
        [
            'code' => '[snn_strike_count]',
            'desc' => __('Total consecutive day streak count', 'snn'),
            'params' => 'none'
        ],
        [
            'code' => '[snn_user_enrolled_courses]',
            'desc' => __('List of user\'s enrolled courses', 'snn'),
            'params' => 'none'
        ],
        [
            'code' => '[snn_user_completions]',
            'desc' => __('List of user\'s completed courses with dates', 'snn'),
            'params' => 'none'
        ],
        [
            'code' => '[snn_user_strikes]',
            'desc' => __('Combined weekly view + streak count', 'snn'),
            'params' => 'none'
        ],
        [
            'code' => '[snn_user_certificates]',
            'desc' => __('List of earned certificates with links', 'snn'),
            'params' => 'none'
        ]
    ];
    
    ?>
    <div class="wrap">
        <h1><?php _e('SNN Education Shortcodes', 'snn'); ?></h1>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 40%;"><?php _e('Shortcode', 'snn'); ?></th>
                    <th style="width: 40%;"><?php _e('Description', 'snn'); ?></th>
                    <th style="width: 20%;"><?php _e('Parameters', 'snn'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($shortcodes as $sc): ?>
                <tr>
                    <td>
                        <code style="font-size: 12px;"><?php echo esc_html($sc['code']); ?></code>
                        <button class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_js($sc['code']); ?>')"><?php _e('Copy', 'snn'); ?></button>
                    </td>
                    <td><?php echo esc_html($sc['desc']); ?></td>
                    <td><small><?php echo esc_html($sc['params']); ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}
