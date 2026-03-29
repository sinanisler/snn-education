# SNN Education

Complete education platform for WordPress with video tracking, certificates, strikes, and course management.

**Author:** sinanisler  
**Website:** [sinanisler.com](https://sinanisler.com)  
**GitHub:** [github.com/sinanisler/snn-education](https://github.com/sinanisler/snn-education)  
**Text Domain:** snn  
**Requires PHP:** 8.0+

---

## Features

### Core Functionality
- ✅ **Post Type Hierarchy Support** - Grandparent (Course) → Children (Chapters) → Grandchildren (Lessons)
- ✅ **Auto-Enrollment System** - Automatic enrollment on first lesson page load
- ✅ **Ancestor Chain Resolution** - Recursive grandparent resolver for complex hierarchies
- ✅ **Progress Tracking** - Real-time course completion percentage calculation
- ✅ **Certificate Generation** - Automatic certificate issuance upon 100% completion
- ✅ **Strike System** - Daily activity tracking with weekly calendar and consecutive streak counts
- ✅ **Comment Ratings** - 1-5 star rating system for comments
- ✅ **Code Syntax Highlighting** - EnlighterJS integration for code blocks
- ✅ **Custom Author URLs** - Role-based permalinks (/user/{id}/ and /instructor/{id}/)
- ✅ **Admin Restrictions** - Configurable wp-admin access and admin bar visibility

### Video Player
- Custom HTML5 video player (self-hosted MP4 only)
- Play/Pause, volume control, progress bar with tooltips
- Subtitle (CC) support with multi-language tracks
- CC settings: font size, text color, background color/opacity
- Playback speed selector (1x, 1.5x, 2x, 4x, 8x)
- Fullscreen mode
- Chapter markers (visual dots on progress bar)
- Completion tracking based on watch time or full video

### REST API
- `POST /snn-edu/v2/track` - Track lesson started/completed
- `POST /snn-edu/v1/enroll` - Enroll in a post (and ancestors)
- `POST /snn-edu/v1/unenroll` - Disabled (returns error)
- `GET /snn-edu/v1/enrollments` - Get all enrollments for current user
- `POST /snn-edu/v1/complete` - Mark top-level course complete
- `GET /snn-edu/v1/completions` - Get all completions with dates
- `GET /snn-edu/v1/user-name/{uid}` - Get user full name (public)

### Frontend JavaScript Functions
All functions are globally available on all pages:
- `snnEduEnrollUser(postId)`
- `snnEduUnenrollUser(postId)` - Returns error, disabled
- `snnEduGetEnrollments()`
- `snnEduIsEnrolled(postId)`
- `snnEduCompletePost(postId)`
- `snnEduGetCompletions()`
- `snnEduIsCompleted(postId)`

### Custom Events
**Dispatched:**
- `snn_edu_enrolled` - When user enrolls
- `snn_edu_completed` - When user completes a course
- `snn_video_started` - When video tracking starts
- `snn_video_completed` - When video ends

**Listened:**
- `snn_video_started` - External video start events
- `snn_video_completed` - External video completion events

---

## Database Structure

### Table 1: wp_snn_edu_data
Tracks user progress through lessons.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) | Primary key |
| user_id | bigint(20) | WordPress user ID |
| course_id | bigint(20) | Top-level course post ID |
| lesson_id | bigint(20) | Lesson post ID |
| status | varchar(20) | `started` or `completed` |
| updated_at | datetime | Last update timestamp |

**Unique Key:** `user_id + course_id + lesson_id` (duplicate-safe with ON DUPLICATE KEY UPDATE)

### Table 2: wp_snn_edu_certificates
Stores issued certificates.

| Column | Type | Description |
|--------|------|-------------|
| id | bigint(20) | Primary key |
| user_id | bigint(20) | WordPress user ID |
| course_id | bigint(20) | Course post ID |
| certificate_id | varchar(255) | Base64-encoded hash for verification |
| completion_date | datetime | Certificate issue date |

**Unique Key:** `user_id + course_id`

---

## Shortcodes

### [snn_video_player]
Custom video player with completion tracking.

**Parameters:**
- `field` - Custom field key for video URL (default: `video_url`)
- `poster` - Custom field key or direct URL for poster image
- `autoplay` - `true` or `false` (default: `false`)
- `muted` - `true` or `false` (default: `false`)
- `loop` - `true` or `false` (default: `false`)
- `events` - `both|started|completed` (default: `both`)
- `subtitles` - Custom field key containing subtitle array
- `width` - Player width (default: `100%`)
- `aspectratio` - Aspect ratio (default: `16/9`)

**Subtitle Format:**
Store in custom field as array:
```php
[
    "en" => "https://example.com/subtitles-en.vtt",
    "de" => "https://example.com/subtitles-de.vtt"
]
```

**Example:**
```
[snn_video_player field="my_video" poster="my_poster" events="completed"]
```

---

### [snn_mark_complete]
Manual completion button for lessons without video.

**Parameters:**
- `text` - Button text (default: "Complete Lesson")

**Example:**
```
[snn_mark_complete text="Mark as Done"]
```

---

### [snn_certificate_button]
Links to certificate page when course is 100% complete.

**Parameters:**
- `course_id` - Course post ID (auto-detected if omitted)
- `page_url` - Custom certificate page URL
- `text` - Button text (default: "Get Certificate")

**Example:**
```
[snn_certificate_button page_url="/certificate/" text="Download Certificate"]
```

**Certificate URL Format:**
```
/instructor/{instructor_id}/?cid={course_id}&uid={user_id}&completion_date={date}&certificate_id={hash}
```

---

### [snn_course_progress]
Displays course completion percentage.

**Parameters:**
- `course_id` - Course post ID (auto-detected if omitted)
- `format` - `number` or `bar` (default: `number`)

**Example:**
```
[snn_course_progress format="bar"]
```

---

### [snn_strike_weekly]
Weekly strike calendar (Monday-Sunday).

Shows 🔥 for days with activity, ● for days without.

**Example:**
```
[snn_strike_weekly]
```

---

### [snn_strike_count]
Total consecutive day streak count.

**Example:**
```
[snn_strike_count]
```

---

### [snn_user_enrolled_courses]
Lists all enrolled courses for logged-in user with progress.

**Example:**
```
[snn_user_enrolled_courses]
```

---

### [snn_user_completions]
Lists all completed courses with completion dates.

**Example:**
```
[snn_user_completions]
```

---

### [snn_user_strikes]
Combined weekly calendar + streak count.

**Example:**
```
[snn_user_strikes]
```

---

### [snn_user_certificates]
Lists all earned certificates with download links.

**Example:**
```
[snn_user_certificates]
```

---

## Admin Pages

### Dashboard (SNN Education → Dashboard)
- Total active students count
- Total course completions count
- Most active courses widget
- Full data table with filters (course, user, status)
- Manual enrollment tool
- CSV export

### Settings (SNN Education → Settings)

**General Settings:**
- Allowed post types for tracking
- Restrict wp-admin to administrators only
- Hide admin bar for non-admin users
- Enable custom author URLs
- Enable comment ratings column

**Tracking Settings:**
- Video completion threshold (seconds)
- Require full video completion
- Lock chapters until previous complete
- Lock lessons until previous complete

**Code Highlighter:**
- Post types to load EnlighterJS on

### Shortcodes (SNN Education → Shortcodes)
Reference page with all shortcodes, parameters, and copy buttons.

---

## Post Type Hierarchy

```
Grandparent (Course)
  └── Children (Chapters)
       └── Grandchildren (Lessons)
```

**Important Rules:**
1. **Chapters redirect to first lesson** - When a user visits a chapter URL, they're automatically redirected to the first lesson (ordered by `menu_order`)
2. **Chapter auto-completion** - When a chapter is visited, it's marked as completed/enrolled
3. **Lesson ordering** - Uses `menu_order` field (set via page ordering plugin)
4. **Progress calculation** - Only lessons (grandchildren) count toward course completion percentage
5. **Ancestor enrollment** - Enrolling in a lesson automatically enrolls all ancestors

---

## Custom Author URLs

When enabled in settings, author archive URLs change based on user role:

- **Instructor role:** `/instructor/{user_id}/`
- **All other roles:** `/user/{user_id}/`

Old `/author/` URLs automatically redirect to the correct format.

**404 enforcement:** If an instructor visits `/user/{id}/` or a non-instructor visits `/instructor/{id}/`, a 404 is shown.

---

## Comment Ratings

When enabled:
1. Comment submission form shows 1-5 rating selector
2. Rating saved to comment meta: `snn_education_rating_comment`
3. Admin comments list shows "Rating" column with star display
4. Admin comment edit page has rating meta box

---

## EnlighterJS Integration

- Enqueues EnlighterJS CSS and JS on selected post types
- Auto-initializes on `pre#wp-block-snn-pre-code` and `code` elements
- Theme: Monokai
- Language: Generic (auto-detect)
- Hides website and collapse buttons via CSS

**Already included in `/assets/`:**
- `enlighterjs.min_.css`
- `enlighterjs.min_.js`

---

## File Structure

```
snn-education/
├── snn-education.php          # Main plugin file (ALL backend logic)
├── README.md                   # This file
└── assets/
    ├── css/
    │   ├── snn-education.css   # Frontend styles
    │   └── enlighterjs.min_.css # EnlighterJS styles
    └── js/
        ├── snn-education.js    # Frontend JavaScript
        └── enlighterjs.min_.js # EnlighterJS library
```

---

## Installation

1. Upload the `snn-education` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to **SNN Education → Settings** to configure
4. Add shortcodes to your pages/posts

---

## Requirements

- WordPress 5.0+
- PHP 8.0+
- MySQL 5.7+ or MariaDB 10.2+

---

## Security Features

- ✅ All REST endpoints require authentication (except `/user-name/`)
- ✅ Nonce verification on all AJAX/form submissions
- ✅ Capability checks (`manage_options` for admin pages)
- ✅ Input sanitization and SQL injection protection
- ✅ XSS prevention via `esc_*` functions
- ✅ CSRF protection via WordPress nonces

---

## Troubleshooting

### Video player not tracking
- Ensure user is logged in
- Check that video URL custom field is correctly set
- Verify video is MP4 format (self-hosted)
- Check browser console for JavaScript errors

### Chapter redirects not working
- Flush rewrite rules (Settings → Permalinks → Save)
- Ensure chapter has at least one published child lesson
- Check `menu_order` values are set correctly

### Custom author URLs not working
- Enable feature in Settings → General
- Flush rewrite rules (Settings → Permalinks → Save)
- Verify user has "instructor" role for `/instructor/` URLs

### EnlighterJS not loading
- Check post type is enabled in Settings → Code Highlighter
- Verify code blocks use correct selector: `pre#wp-block-snn-pre-code`
- Check for JavaScript conflicts in browser console

---

## Support

For issues, questions, or contributions:
- **GitHub:** [github.com/sinanisler/snn-education](https://github.com/sinanisler/snn-education)
- **Website:** [sinanisler.com](https://sinanisler.com)

---

## License

This plugin is provided as-is without warranty. Use at your own risk.

---

## Changelog

### 1.0.0 (2026-03-30)
- Initial release
- Complete education platform implementation
- Video player with full tracking
- Strike system
- Certificate generation
- Comment ratings
- EnlighterJS integration
- Custom author URLs
- Admin restrictions
- REST API
- All shortcodes implemented
