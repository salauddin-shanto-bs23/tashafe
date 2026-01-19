<?php
// Register Retreat Group Custom Post Type
add_action('init', function () {

    register_post_type('retreat_group', [
        'labels' => [
            'name'          => 'Retreat Groups',
            'singular_name' => 'Retreat Group',
            'add_new_item'  => 'Add New Retreat Group',
        ],
        'public'       => false,
        'show_ui'      => true,
        'show_in_menu' => true,
        'menu_icon'    => 'dashicons-palmtree',
        'supports'     => ['title'],
    ]);
});

// Create retreat waiting list table
add_action('init', function () {

    global $wpdb;

    $table_name = $wpdb->prefix . 'retreat_waiting_list';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        retreat_type VARCHAR(20) NOT NULL,
        full_name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        phone VARCHAR(50) DEFAULT '',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY retreat_type (retreat_type),
        KEY email (email)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
});

// --------------------------------------------------
// CREATE RETREAT QUESTIONNAIRE ANSWERS TABLE
// --------------------------------------------------
add_action('init', function () {
    global $wpdb;

    $table_name = $wpdb->prefix . 'retreat_questionnaire_answers';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        retreat_type VARCHAR(20) NOT NULL,
        retreat_group_id BIGINT UNSIGNED DEFAULT 0,
        question_number INT NOT NULL,
        question_text TEXT NOT NULL,
        answer TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY retreat_type (retreat_type),
        KEY retreat_group_id (retreat_group_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
});

// Get active retreat group by type
function get_active_retreat_group($retreat_type)
{

    $groups = get_posts([
        'post_type'      => 'retreat_group',
        'posts_per_page' => 1,
        'post_status'    => 'publish',
        'meta_query'     => [
            [
                'key'   => 'retreat_type',
                'value' => $retreat_type,
            ],
            [
                'key'   => 'retreat_status',
                'value' => 'active',
            ],
        ],
        'orderby' => 'date',
        'order'   => 'DESC',
    ]);

    if (!empty($groups)) {
        return $groups[0];
    }

    return false;
}

// Count users assigned to a retreat group
function count_retreat_group_members($group_id)
{

    if (!$group_id) {
        return 0;
    }

    $users = get_users([
        'meta_key'   => 'assigned_retreat_group',
        'meta_value' => $group_id,
        'number'     => -1,
        'fields'     => 'ID',
    ]);

    return count($users);
}

function retreat_parse_date_value($date)
{
    if (!$date) {
        return null;
    }
    $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y', 'Y/m/d'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $date);
        if ($dt instanceof DateTime) {
            return $dt;
        }
    }
    if (strtotime($date)) {
        return (new DateTime())->setTimestamp(strtotime($date));
    }
    return null;
}

function retreat_format_date_range($start_date, $end_date)
{
    $start = retreat_parse_date_value($start_date);
    $end   = retreat_parse_date_value($end_date);

    if ($start && $end) {
        if ($start->format('Y') === $end->format('Y')) {
            if ($start->format('m') === $end->format('m')) {
                return $start->format('F j') . ' – ' . $end->format('j, Y');
            }
            return $start->format('F j') . ' – ' . $end->format('F j, Y');
        }
        return $start->format('F j, Y') . ' – ' . $end->format('F j, Y');
    }

    if ($start) {
        return $start->format('F j, Y');
    }
    if ($end) {
        return $end->format('F j, Y');
    }

    return '';
}

// Check if retreat has available seats
function check_retreat_availability($retreat_type)
{

    $group = get_active_retreat_group($retreat_type);

    if (!$group) {
        return [
            'available' => false,
            'reason'    => 'no_active_group',
        ];
    }

    $capacity = intval(get_field('max_participants', $group->ID));
    if ($capacity <= 0) {
        $capacity = 12; // Default capacity
    }
    $current  = count_retreat_group_members($group->ID);

    if ($current >= $capacity) {
        return [
            'available' => false,
            'reason'    => 'full',
        ];
    }

    return [
        'available' => true,
        'group_id'  => $group->ID,
        'capacity'  => $capacity,
        'current'   => $current,
    ];
}

// Assign user to active retreat group
function assign_user_to_active_retreat_group($user_id, $retreat_type)
{

    $group = get_active_retreat_group($retreat_type);

    if (!$group) {
        return false;
    }

    update_user_meta($user_id, 'assigned_retreat_group', $group->ID);

    return true;
}

// Remove retreat waiting list entry by email
function remove_user_from_retreat_waiting_list($email)
{

    global $wpdb;

    if (empty($email)) {
        return;
    }

    $table = $wpdb->prefix . 'retreat_waiting_list';

    $wpdb->delete(
        $table,
        ['email' => $email],
        ['%s']
    );
}

// ============================================================================
// BUDDYPRESS RETREAT GROUP ENROLLMENT
// ============================================================================

/**
 * Get BuddyPress group slug based on retreat type
 * Maps retreat_type to existing BP group slugs
 * 
 * @param string $retreat_type The retreat type (male, female, teen)
 * @return string|false BP group slug or false if not found
 */
function get_retreat_bp_group_slug($retreat_type)
{
    $group_slugs = [
        'male'   => 'male-retreat',
        'female' => 'female-retreat',
        'teen'   => 'teen-retreat',
    ];

    return isset($group_slugs[$retreat_type]) ? $group_slugs[$retreat_type] : false;
}

/**
 * Format BuddyPress nickname for retreat users
 * Format: "First Name - Month Year" (e.g., "Ahmed - January 2026")
 * 
 * @param string $first_name User's first name
 * @param string $start_date Retreat start date
 * @return string Formatted nickname
 */
function format_retreat_bp_nickname($first_name, $start_date)
{
    $nickname = $first_name;

    if ($start_date) {
        $date_obj = retreat_parse_date_value($start_date);
        if ($date_obj) {
            $month_year = $date_obj->format('F Y');
            $nickname .= ' - ' . $month_year;
        }
    }

    // Limit to 50 characters (BuddyPress nickname limit)
    return substr($nickname, 0, 50);
}

/**
 * Enroll retreat user into BuddyPress chat group
 * Also sets nickname format: "First Name - Retreat Month Year"
 * 
 * @param int $user_id WordPress user ID
 * @param string $retreat_type Retreat type (male, female, teen)
 * @param string $first_name User's first name (for nickname)
 * @param string $start_date Retreat start date (for nickname)
 * @return bool True on success, false on failure
 */
function enroll_retreat_user_to_bp_chat_group($user_id, $retreat_type, $first_name = '', $start_date = '')
{

    // Defer if BuddyPress not fully loaded
    if (function_exists('did_action') && !did_action('bp_init')) {
        add_action('bp_init', function () use ($user_id, $retreat_type, $first_name, $start_date) {
            enroll_retreat_user_to_bp_chat_group($user_id, $retreat_type, $first_name, $start_date);
        }, 20);
        error_log("[Retreat BP] BuddyPress not ready. Deferring enrollment for user {$user_id}.");
        return false;
    }

    // Check if BuddyPress Groups are active
    if (!function_exists('bp_is_active') || !bp_is_active('groups')) {
        error_log('[Retreat BP] BuddyPress Groups component not active.');
        return false;
    }

    if (!$retreat_type) {
        error_log("[Retreat BP] No retreat type provided for user {$user_id}.");
        return false;
    }

    $user_id = intval($user_id);
    error_log("[Retreat BP] ==== Starting BP enrollment for user {$user_id} (retreat_type: {$retreat_type}) ====");

    // Get BP group slug from retreat type
    $bp_group_slug = get_retreat_bp_group_slug($retreat_type);
    if (!$bp_group_slug) {
        error_log("[Retreat BP] Invalid retreat type: {$retreat_type}");
        return false;
    }

    // Get BuddyPress group by slug
    if (!function_exists('groups_get_id')) {
        error_log('[Retreat BP] groups_get_id function not available.');
        return false;
    }

    $bp_group_id = groups_get_id($bp_group_slug);

    if (!$bp_group_id) {
        error_log("[Retreat BP] BP group not found for slug: {$bp_group_slug}");
        return false;
    }

    $bp_group_id = intval($bp_group_id);
    error_log("[Retreat BP] Found BP group ID: {$bp_group_id} for slug: {$bp_group_slug}");

    // Verify group exists
    if (function_exists('groups_get_group')) {
        $bp_group = groups_get_group($bp_group_id);
        if (!$bp_group || empty($bp_group->id)) {
            error_log("[Retreat BP] BP group {$bp_group_id} does not exist.");
            return false;
        }
        error_log("[Retreat BP] Verified BP group: '{$bp_group->name}' (status: {$bp_group->status})");
    }

    // Check if already a member
    if (function_exists('groups_is_user_member') && groups_is_user_member($user_id, $bp_group_id)) {
        error_log("[Retreat BP] User {$user_id} already member of BP group {$bp_group_id}");

        // Still set/update nickname if needed
        if ($first_name && function_exists('bp_update_user_meta')) {
            $nickname = format_retreat_bp_nickname($first_name, $start_date);
            bp_update_user_meta($user_id, 'bp_nick_name', $nickname);
            error_log("[Retreat BP] Updated nickname for existing member: {$nickname}");
        }

        return true;
    }

    // Enroll user into group
    $enrolled = false;
    if (function_exists('groups_add_member')) {
        error_log("[Retreat BP] Attempting groups_add_member for user {$user_id} into BP group {$bp_group_id}...");
        $enrolled = (bool) groups_add_member($user_id, $bp_group_id);
    } elseif (function_exists('groups_join_group')) {
        error_log("[Retreat BP] Falling back to groups_join_group for user {$user_id}...");
        $enrolled = (bool) groups_join_group($bp_group_id, $user_id);
    } else {
        error_log('[Retreat BP] No BuddyPress group membership functions available.');
        return false;
    }

    if ($enrolled) {
        error_log("[Retreat BP] ✓✓✓ SUCCESS! User {$user_id} enrolled into BP group {$bp_group_id}");

        // Save enrollment metadata
        update_user_meta($user_id, '_retreat_bp_group_id', $bp_group_id);
        update_user_meta($user_id, '_retreat_bp_enrollment_date', current_time('mysql'));

        // Set nickname: "First Name - Month Year"
        if ($first_name) {
            $nickname = format_retreat_bp_nickname($first_name, $start_date);

            // Update BuddyPress nickname
            if (function_exists('bp_update_user_meta')) {
                bp_update_user_meta($user_id, 'bp_nick_name', $nickname);
            }

            // Also update WordPress nickname and display name for consistency
            wp_update_user([
                'ID' => $user_id,
                'nickname' => $nickname,
                'display_name' => $nickname,
            ]);

            update_user_meta($user_id, 'retreat_bp_nickname', $nickname);
            error_log("[Retreat BP] Set nickname: {$nickname}");
        }

        error_log("[Retreat BP] ==== Enrollment complete ====");
        return true;
    }

    error_log("[Retreat BP] ✗✗✗ FAILED to enroll user {$user_id} into BP group {$bp_group_id}");
    return false;
}

if (!function_exists('retreat_get_language_slug_cached')) {
    function retreat_get_language_slug_cached()
    {
        static $lang = null;
        if ($lang !== null) {
            return $lang;
        }

        if (function_exists('pll_current_language')) {
            $detected = pll_current_language('slug');
            if (!empty($detected)) {
                $lang = $detected;
                return $lang;
            }
        }

        $lang = is_rtl() ? 'ar' : 'en';
        return $lang;
    }
}

if (!function_exists('retreat_is_arabic_locale')) {
    function retreat_is_arabic_locale()
    {
        return retreat_get_language_slug_cached() === 'ar';
    }
}

if (!function_exists('retreat_translate')) {
    function retreat_translate($english, $arabic = '')
    {
        if (retreat_is_arabic_locale() && $arabic !== '') {
            return $arabic;
        }

        return $english;
    }
}

function retreat_get_js_strings()
{
    $is_ar = retreat_is_arabic_locale();

    return [
        'loadingSchedules'        => $is_ar ? 'جارٍ تحميل الرحلات المتاحة...' : 'Loading available retreats...',
        'loadingSchedulesShort'   => $is_ar ? 'جارٍ تحميل الجداول...' : 'Loading schedules...',
        'noSchedules'             => $is_ar ? 'لا توجد رحلات متاحة حالياً لهذه الفئة.' : 'No retreats are currently available for this category.',
        'joinWaitingList'         => $is_ar ? 'انضم إلى قائمة الانتظار' : 'Join Waiting List',
        'selectScheduleFirst'     => $is_ar ? 'يرجى اختيار جدول أولاً' : 'Please select a schedule first',
        'failedLoadDetails'       => $is_ar ? 'تعذر تحميل تفاصيل الرحلة' : 'Failed to load retreat details',
        'agreeTerms'              => $is_ar ? 'يرجى الموافقة على الشروط والأحكام' : 'Please agree to the Terms & Conditions',
        'passwordMin'             => $is_ar ? 'يجب أن تتكون كلمة المرور من 8 أحرف على الأقل' : 'Password must be at least 8 characters',
        'questionnaireIncomplete' => $is_ar ? 'يرجى الإجابة على جميع الأسئلة قبل الإرسال.' : 'Please answer all questions before submitting.',
        'submitting'              => $is_ar ? 'جارٍ الإرسال...' : 'Submitting...',
        'submitInformation'       => $is_ar ? 'إرسال المعلومات' : 'Submit Information',
        'ajaxError'               => $is_ar ? 'حدث خطأ، يرجى المحاولة مرة أخرى.' : 'An error occurred. Please try again.',
        'exitConfirm'             => $is_ar ? 'هل أنت متأكد من إغلاق النافذة؟ ستفقد تقدمك.' : 'Are you sure you want to exit? Your progress will be lost.',
        'discordEnterUsername'    => $is_ar ? 'يرجى إدخال اسم مستخدم ديسكورد.' : 'Please enter your Discord username.',
        'discordInvalidUsername'  => $is_ar ? 'يرجى إدخال اسم مستخدم صالح (من 2 إلى 32 حرفاً).' : 'Please enter a valid Discord username (2-32 characters).',
        'discordVerifying'        => $is_ar ? 'جارٍ التحقق...' : 'Verifying...',
        'discordSearching'        => $is_ar ? '⏳ جارٍ البحث عن حسابك في ديسكورد...' : '⏳ Looking for your Discord account...',
        'discordVerified'         => $is_ar ? '✓ تم التحقق من حساب ديسكورد!' : '✓ Discord account verified!',
        'discordUserNotFound'     => $is_ar ? 'لم يتم العثور على المستخدم. تأكد من أنك انضممت إلى خادم ديسكورد.' : 'User not found. Make sure you\'ve joined the Discord server first!',
        'discordVerifyButton'     => $is_ar ? 'تأكيد وإكمال الإعداد' : 'Verify & Complete Setup',
        'connectionError'         => $is_ar ? 'خطأ في الاتصال. حاول مرة أخرى.' : 'Connection error. Please try again.',
        'waitingListSuccessTitle' => $is_ar ? 'تمت إضافتك إلى قائمة الانتظار!' : 'Added to Waiting List!',
        'waitingListSuccessDesc'  => $is_ar ? 'سنقوم بإخطارك فور توفر مقعد.' : 'We\'ll notify you when a spot opens up.',
        'waitingListError'        => $is_ar ? 'تعذر الانضمام إلى قائمة الانتظار' : 'Failed to join waiting list',
        'notifyMessage'           => $is_ar ? 'سنقوم بإخطارك فور توفر مقعد.' : 'We\'ll notify you when a spot opens up.',
        'waitingListButton'       => $is_ar ? 'انضم إلى قائمة الانتظار' : 'Join Waiting List',
        'contactForPrice'         => $is_ar ? 'تواصل لمعرفة السعر' : 'Contact for price',
        'selectSchedule'          => $is_ar ? 'اختر جدولاً' : 'Select schedule',
        'selectSchedulePrompt'    => $is_ar ? 'اختر موعداً من القائمة أعلاه' : 'Select a schedule above',
        'chooseDatePlaceholder'   => $is_ar ? 'اختر تاريخاً' : 'Choose a date',
        'noSchedulesLabel'        => $is_ar ? 'لا توجد جداول متاحة' : 'No schedules available',
        'dateTBA'                 => $is_ar ? 'سيحدد لاحقاً' : 'Date TBA',
        'locationTBA'             => $is_ar ? 'سيحدد الموقع لاحقاً' : 'Location TBA',
        'priceCurrency'           => $is_ar ? 'ر.س' : 'SAR',
        'passportSelected'        => $is_ar ? 'تم اختيار: ' : 'Selected: ',
    ];
}

add_action('wp_enqueue_scripts', function () {
    wp_localize_script('jquery', 'RETREAT_AJAX', [
        'url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('retreat_nonce'),
        'lang' => retreat_get_language_slug_cached(),
        'strings' => retreat_get_js_strings(),
    ]);
});

add_action('wp_ajax_check_retreat_availability', 'ajax_check_retreat_availability');
add_action('wp_ajax_nopriv_check_retreat_availability', 'ajax_check_retreat_availability');

function ajax_check_retreat_availability()
{

    check_ajax_referer('retreat_nonce', 'nonce');

    $type = sanitize_text_field($_POST['retreat_type'] ?? '');

    if (!$type) {
        wp_send_json_error(['message' => 'Invalid retreat type']);
    }

    $result = check_retreat_availability($type);

    wp_send_json_success($result);
}

// --------------------------------------------------
// AJAX: GET AVAILABLE RETREAT SCHEDULES
// --------------------------------------------------
add_action('wp_ajax_get_retreat_schedules', 'ajax_get_retreat_schedules');
add_action('wp_ajax_nopriv_get_retreat_schedules', 'ajax_get_retreat_schedules');

function ajax_get_retreat_schedules()
{
    check_ajax_referer('retreat_nonce', 'nonce');

    $retreat_type = sanitize_text_field($_POST['retreat_type'] ?? '');

    if (!$retreat_type) {
        wp_send_json_error(['message' => 'Invalid retreat type']);
    }

    // Get all retreat groups for this type with available slots
    $groups = get_posts([
        'post_type' => 'retreat_group',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'meta_query' => [
            [
                'key' => 'retreat_type',
                'value' => $retreat_type,
            ],
        ],
        'orderby' => 'meta_value',
        'meta_key' => 'start_date',
        'order' => 'ASC',
    ]);

    $schedules = [];

    foreach ($groups as $group) {
        $capacity = intval(get_field('max_participants', $group->ID)) ?: 12;
        $current_members = count_retreat_group_members($group->ID);
        $available_spots = $capacity - $current_members;

        if ($available_spots > 0) {
            $start_date = get_field('start_date', $group->ID);
            $end_date = get_field('end_date', $group->ID);
            $location = get_field('trip_destination', $group->ID);

            $start_obj = retreat_parse_date_value($start_date);
            $end_obj   = retreat_parse_date_value($end_date);

            $start_formatted = $start_obj ? $start_obj->format('F j, Y') : ($start_date ?: 'Date TBA');
            $end_formatted   = $end_obj ? $end_obj->format('F j, Y') : ($end_date ?: 'Date TBA');
            $date_label      = retreat_format_date_range($start_date, $end_date);
            if (!$date_label) {
                $date_label = $start_formatted;
            }

            $schedules[] = [
                'group_id' => $group->ID,
                'title' => get_the_title($group->ID),
                'start_date' => $start_date,
                'end_date' => $end_date,
                'start_formatted' => $start_formatted,
                'end_formatted' => $end_formatted,
                'date_range_label' => $date_label,
                'location' => $location ?: 'Location TBA',
                'available_spots' => $available_spots,
                'capacity' => $capacity
            ];
        }
    }

    wp_send_json_success([
        'schedules' => $schedules,
        'has_schedules' => !empty($schedules)
    ]);
}

// --------------------------------------------------
// AJAX: GET RETREAT DETAILS
// --------------------------------------------------
add_action('wp_ajax_get_retreat_details', 'ajax_get_retreat_details');
add_action('wp_ajax_nopriv_get_retreat_details', 'ajax_get_retreat_details');

function ajax_get_retreat_details()
{
    check_ajax_referer('retreat_nonce', 'nonce');

    $group_id = intval($_POST['group_id'] ?? 0);
    $retreat_type = sanitize_text_field($_POST['retreat_type'] ?? '');

    if (!$group_id) {
        wp_send_json_error(['message' => 'Invalid group ID']);
    }

    $group = get_post($group_id);
    if (!$group || $group->post_type !== 'retreat_group') {
        wp_send_json_error(['message' => 'Retreat not found']);
    }

    if (!$retreat_type) {
        $retreat_type = get_field('retreat_type', $group_id);
    }

    // Get gender settings for cover image and title
    $gender_settings = [];
    if (function_exists('get_retreat_gender_settings')) {
        $gender_settings = get_retreat_gender_settings($retreat_type);
    }

    // Get retreat fields
    $start_date = get_field('start_date', $group_id);
    $end_date = get_field('end_date', $group_id);
    $trip_destination = get_field('trip_destination', $group_id);
    $retreat_description = get_field('retreat_description', $group_id);
    $retreat_price_sar = get_field('retreat_price_sar', $group_id);
    $retreat_price_usd = get_field('retreat_price_usd', $group_id);
    $package_includes = get_field('package_includes', $group_id);
    $max_participants = intval(get_field('max_participants', $group_id)) ?: 20;
    $current_members = count_retreat_group_members($group_id);

    // Format dates
    $date_range = retreat_format_date_range($start_date, $end_date);

    // Parse package includes
    $package_items = [];
    if ($package_includes) {
        $items = explode("\n", $package_includes);
        foreach ($items as $item) {
            $item = trim($item);
            if ($item) $package_items[] = $item;
        }
    }

    wp_send_json_success([
        'group_id' => $group_id,
        'retreat_type' => $retreat_type,
        'cover_image' => $gender_settings['cover_image_url'] ?? '',
        'group_title' => $gender_settings['group_title'] ?? ucfirst($retreat_type) . ' Retreat',
        'title' => get_the_title($group_id),
        'description' => $retreat_description,
        'price_sar' => $retreat_price_sar,
        'price_usd' => $retreat_price_usd,
        'date_range' => $date_range ?: 'Dates to be announced',
        'location' => $trip_destination ?: 'Location to be announced',
        'package_items' => $package_items,
        'max_participants' => $max_participants,
        'current_members' => $current_members,
        'available_spots' => $max_participants - $current_members
    ]);
}

// --------------------------------------------------
// AJAX: GET GENDER SETTINGS (FOR FRONTEND)
// --------------------------------------------------
add_action('wp_ajax_get_retreat_gender_settings', 'ajax_get_retreat_gender_settings');
add_action('wp_ajax_nopriv_get_retreat_gender_settings', 'ajax_get_retreat_gender_settings');

function ajax_get_retreat_gender_settings()
{
    check_ajax_referer('retreat_nonce', 'nonce');

    $retreat_type = sanitize_text_field($_POST['retreat_type'] ?? '');

    if (!$retreat_type || !in_array($retreat_type, ['male', 'female', 'teen'])) {
        wp_send_json_error(['message' => 'Invalid retreat type']);
    }

    if (function_exists('get_retreat_gender_settings')) {
        $settings = get_retreat_gender_settings($retreat_type);
        wp_send_json_success($settings);
    }

    wp_send_json_error(['message' => 'Settings not available']);
}

add_action('wp_ajax_register_retreat_user', 'ajax_register_retreat_user');
add_action('wp_ajax_nopriv_register_retreat_user', 'ajax_register_retreat_user');

function ajax_register_retreat_user()
{
    check_ajax_referer('retreat_nonce', 'nonce');

    $email = sanitize_email($_POST['email'] ?? '');

    if (!$email) {
        wp_send_json_error(['message' => 'Email required']);
    }

    // Create or get user
    if (email_exists($email)) {
        $user = get_user_by('email', $email);
        $user_id = $user->ID;
    } else {
        add_filter('wp_send_new_user_notifications', function () {
            return false;
        });
        // Use provided password from form
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validate passwords match
        if ($password !== $confirm_password) {
            wp_send_json_error(['message' => 'Passwords do not match']);
        }

        if (strlen($password) < 8) {
            wp_send_json_error(['message' => 'Password must be at least 8 characters']);
        }

        $user_id = wp_create_user($email, $password, $email);

        if (is_wp_error($user_id)) {
            wp_send_json_error(['message' => 'User creation failed']);
        }
    }

    // Save user meta
    $full_name = sanitize_text_field($_POST['full_name'] ?? '');
    $first_name = sanitize_text_field($_POST['first_name'] ?? '');
    $last_name = sanitize_text_field($_POST['last_name'] ?? '');

    // Handle full_name if first_name/last_name not provided
    if ($full_name && !$first_name) {
        $name_parts = explode(' ', $full_name, 2);
        $first_name = $name_parts[0];
        $last_name = isset($name_parts[1]) ? $name_parts[1] : '';
    }

    update_user_meta($user_id, 'first_name', $first_name);
    update_user_meta($user_id, 'last_name', $last_name);
    update_user_meta($user_id, 'birth_date', sanitize_text_field($_POST['birth_date'] ?? ''));
    update_user_meta($user_id, 'phone', sanitize_text_field($_POST['phone'] ?? ''));
    update_user_meta($user_id, 'country', sanitize_text_field($_POST['country'] ?? ''));

    // Save gender
    $gender = sanitize_text_field($_POST['gender'] ?? '');
    if ($gender) {
        update_user_meta($user_id, 'gender', $gender);
    }

    // Assign retreat group - use specific group_id if provided
    $retreat_type = sanitize_text_field($_POST['retreat_type'] ?? '');
    $group_id = intval($_POST['group_id'] ?? 0);

    if ($group_id) {
        // Verify the group exists and is active
        $group = get_post($group_id);
        if (!$group || $group->post_type !== 'retreat_group') {
            wp_send_json_error(['message' => 'Invalid retreat group']);
        }
        update_user_meta($user_id, 'assigned_retreat_group', $group_id);
    } else {
        // Fallback to active group
        $group = get_active_retreat_group($retreat_type);
        if (!$group) {
            wp_send_json_error(['message' => 'No active retreat available']);
        }
        $assigned = assign_user_to_active_retreat_group($user_id, $retreat_type);
        if (!$assigned) {
            wp_send_json_error(['message' => 'No active retreat available']);
        }
        $group_id = $group->ID;
    }

    $group = get_post($group_id);

    // Save questionnaire answers
    global $wpdb;
    $questionnaire_table = $wpdb->prefix . 'retreat_questionnaire_answers';
    $questions = [
        'Do you have any chronic illnesses?',
        'Have you had any previous surgeries or injuries?',
        'Have you ever been diagnosed with any psychological disorder?',
        'Are you currently taking any psychiatric or other medications?',
        'How are you feeling during this period of your life?',
        'How do your emotions affect your daily life and relationships?',
        'Do you have any fears or challenges you would like to discuss in the group?',
        'How do you think group therapy sessions can support you in achieving what you are aiming for?',
        'What steps have you taken so far to overcome the challenges you are facing?',
        'What is your level of comfort with sharing and expressing your emotions within a group?',
        'Have you practiced yoga before?',
        'Do you have any food allergies or follow any specific dietary restrictions?'
    ];

    $questionnaire_answers = isset($_POST['questionnaire_answers']) ? json_decode(stripslashes($_POST['questionnaire_answers']), true) : [];

    if (!empty($questionnaire_answers)) {
        // Delete old answers for this user if any
        $wpdb->delete($questionnaire_table, ['user_id' => $user_id], ['%d']);

        foreach ($questionnaire_answers as $index => $answer) {
            if (isset($questions[$index]) && !empty(trim($answer))) {
                $wpdb->insert($questionnaire_table, [
                    'user_id' => $user_id,
                    'retreat_type' => $retreat_type,
                    'retreat_group_id' => $group_id,
                    'question_number' => $index + 1,
                    'question_text' => $questions[$index],
                    'answer' => sanitize_textarea_field($answer)
                ], ['%d', '%s', '%d', '%d', '%s', '%s']);
            }
        }
    }

    // Get chat links (server link + private channel link)
    $chat_table = $wpdb->prefix . 'retreat_chat_links';
    $chat_data = $wpdb->get_row($wpdb->prepare("SELECT discord_link, private_channel_link FROM {$chat_table} WHERE retreat_type = %s", $retreat_type), ARRAY_A);
    $chat_link = $chat_data ? $chat_data['discord_link'] : '';
    $private_channel_link = $chat_data ? ($chat_data['private_channel_link'] ?? '') : '';

    // Get retreat dates
    $start_date = get_field('start_date', $group_id);
    $end_date = get_field('end_date', $group_id);
    $retreat_dates = '';
    if ($start_date && $end_date) {
        $start_obj = retreat_parse_date_value($start_date);
        $end_obj = retreat_parse_date_value($end_date);
        if ($start_obj && $end_obj) {
            $retreat_dates = $start_obj->format('M j, Y') . ' - ' . $end_obj->format('M j, Y');
        }
    }

    // Get trip details
    $trip_destination = get_field('trip_destination', $group_id);

    if ($chat_link) {
        update_user_meta($user_id, 'retreat_chat_link', $chat_link);
        update_user_meta($user_id, 'retreat_chat_access', 'yes');
    }

    // Store enrollment month/year for chat name formatting
    $month_year = '';
    if ($start_date) {
        $date_obj = retreat_parse_date_value($start_date);
        if ($date_obj) {
            $month_year = $date_obj->format('F Y');
            update_user_meta($user_id, 'retreat_enrollment_month_year', $month_year);
        }
    }

    update_user_meta($user_id, 'retreat_type', $retreat_type);

    // Passport upload
    if (!empty($_FILES['passport']['name'])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $upload = wp_handle_upload($_FILES['passport'], ['test_form' => false]);
        if (!isset($upload['error'])) {
            update_user_meta($user_id, 'passport_file', esc_url_raw($upload['url']));
        }
    }

    remove_user_from_retreat_waiting_list($email);

    // ✅ ENROLL USER INTO BUDDYPRESS RETREAT CHAT GROUP
    $bp_enrollment_result = enroll_retreat_user_to_bp_chat_group($user_id, $retreat_type, $first_name, $start_date);
    if ($bp_enrollment_result) {
        error_log("[Retreat Reg] ✓ BP enrollment successful for user {$user_id} into {$retreat_type}-retreat group");
    } else {
        error_log("[Retreat Reg] ✗ BP enrollment failed for user {$user_id} - Check enroll_retreat_user_to_bp_chat_group logs");
    }

    // Prepare suggested Discord nickname: First Name - Trip Month Year
    $suggested_nickname = $first_name;
    if ($start_date) {
        $date_obj = retreat_parse_date_value($start_date);
        if ($date_obj) {
            $trip_month_year = $date_obj->format('F Y');
            $suggested_nickname .= ' - ' . $trip_month_year;
        }
    }

    // Generate chat join token for email link
    $chat_join_token = '';
    $chat_join_url = '';
    if (function_exists('generate_retreat_chat_join_token') && !empty($chat_link)) {
        $chat_join_token = generate_retreat_chat_join_token($user_id, $retreat_type, $suggested_nickname);
        $chat_join_url = get_retreat_chat_join_url($chat_join_token);
    }

    // Send registration email with retreat and trip details (includes fallback join link)
    wp_mail(
        $email,
        'Retreat Registration Confirmed – Tashafe',
        get_retreat_registration_email_with_details($first_name, $retreat_type, $chat_link, $private_channel_link, $suggested_nickname, $retreat_dates, $trip_destination, '', $chat_join_url),
        ['Content-Type: text/html; charset=UTF-8']
    );

    wp_send_json_success([
        'message' => 'Registered successfully',
        'chat_link' => $chat_link,
        'private_channel_link' => $private_channel_link,
        'has_chat' => !empty($chat_link),
        'has_private_channel' => !empty($private_channel_link),
        'retreat_type' => $retreat_type,
        'suggested_nickname' => $suggested_nickname,
        'retreat_dates' => $retreat_dates,
        'trip_destination' => $trip_destination,
        'trip_dates' => '',
        'chat_join_token' => $chat_join_token,
        'user_id' => $user_id
    ]);
}

add_action('wp_ajax_join_retreat_waiting_list', 'ajax_join_retreat_waiting_list');
add_action('wp_ajax_nopriv_join_retreat_waiting_list', 'ajax_join_retreat_waiting_list');

function ajax_join_retreat_waiting_list()
{

    check_ajax_referer('retreat_nonce', 'nonce');

    global $wpdb;

    $table = $wpdb->prefix . 'retreat_waiting_list';
    $retreat_type = sanitize_text_field($_POST['retreat_type']);
    $full_name = sanitize_text_field($_POST['full_name']);
    $email = sanitize_email($_POST['email']);
    $phone = sanitize_text_field($_POST['phone']);

    $wpdb->insert($table, [
        'retreat_type' => $retreat_type,
        'full_name'    => $full_name,
        'email'        => $email,
        'phone'        => $phone,
    ]);

    // Send waiting list email with Therapy Group style
    wp_mail(
        $email,
        'You\'re on the Waiting List – Tashafe',
        get_retreat_waiting_email($full_name),
        ['Content-Type: text/html; charset=UTF-8']
    );

    wp_send_json_success(['message' => 'Added to waiting list']);
}

// --------------------------------------------------
// AJAX: SAVE RETREAT DISCORD USERNAME (WITH BOT INTEGRATION)
// --------------------------------------------------
add_action('wp_ajax_save_retreat_discord_username', 'ajax_save_retreat_discord_username');
add_action('wp_ajax_nopriv_save_retreat_discord_username', 'ajax_save_retreat_discord_username');

function ajax_save_retreat_discord_username()
{
    check_ajax_referer('retreat_nonce', 'nonce');

    $discord_username = sanitize_text_field($_POST['discord_username'] ?? '');
    $retreat_type = sanitize_text_field($_POST['retreat_type'] ?? '');
    $suggested_nickname = sanitize_text_field($_POST['suggested_nickname'] ?? '');

    if (empty($discord_username)) {
        wp_send_json_error('Discord username is required.');
    }

    // Validate username length (Discord usernames are 2-32 characters)
    if (strlen($discord_username) < 2 || strlen($discord_username) > 32) {
        wp_send_json_error('Invalid Discord username length.');
    }

    // Check if bot functions are available
    if (!function_exists('retreat_discord_find_user')) {
        // Fallback: just save username without bot integration
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            update_user_meta($user_id, 'retreat_discord_username', $discord_username);
            update_user_meta($user_id, 'retreat_discord_linked_at', current_time('mysql'));
        }
        wp_send_json_success([
            'message' => 'Discord username saved successfully.',
            'bot_integration' => false
        ]);
        return;
    }

    // Check if bot is configured
    $bot_token = get_option('retreat_discord_bot_token');
    $server_id = get_option('retreat_discord_server_id');

    if (empty($bot_token) || empty($server_id)) {
        // Bot not configured - fallback to basic save
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            update_user_meta($user_id, 'retreat_discord_username', $discord_username);
            update_user_meta($user_id, 'retreat_discord_linked_at', current_time('mysql'));
        }
        wp_send_json_success([
            'message' => 'Discord username saved successfully.',
            'bot_integration' => false
        ]);
        return;
    }

    // Step 1: Find user in Discord server
    $find_result = retreat_discord_find_user($discord_username);

    if (!$find_result['success']) {
        wp_send_json_error($find_result['message']);
        return;
    }

    $discord_user_id = $find_result['user_id'];
    $found_username = $find_result['username'];

    // Step 2: Set nickname if provided
    $nickname_set = false;
    if (!empty($suggested_nickname)) {
        $nick_result = retreat_discord_set_nickname($discord_user_id, $suggested_nickname);
        if ($nick_result['success']) {
            $nickname_set = true;
            error_log("Retreat: Set nickname '{$suggested_nickname}' for {$discord_username}");
        } else {
            error_log("Retreat: Failed to set nickname - " . $nick_result['message']);
        }
    }

    // Step 3: Get role ID for this retreat type and assign
    $role_assigned = false;
    if (!empty($retreat_type)) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'retreat_chat_links';
        $role_id = $wpdb->get_var($wpdb->prepare(
            "SELECT discord_role_id FROM `{$table_name}` WHERE retreat_type = %s",
            $retreat_type
        ));

        if (!empty($role_id)) {
            $role_result = retreat_discord_assign_role($discord_user_id, $role_id);
            if ($role_result['success']) {
                $role_assigned = true;
                error_log("Retreat: Assigned role {$role_id} to {$discord_username}");
            } else {
                error_log("Retreat: Failed to assign role - " . $role_result['message']);
            }
        }
    }

    // Save to user meta if logged in
    if (is_user_logged_in()) {
        $user_id = get_current_user_id();
        update_user_meta($user_id, 'retreat_discord_username', $found_username);
        update_user_meta($user_id, 'retreat_discord_user_id', $discord_user_id);
        update_user_meta($user_id, 'retreat_discord_linked_at', current_time('mysql'));
    }

    // Build success message
    $messages = ['✓ Discord account verified!'];
    if ($nickname_set) {
        $messages[] = "Nickname set to: {$suggested_nickname}";
    }
    if ($role_assigned) {
        $messages[] = 'Private channel access granted!';
    }

    wp_send_json_success([
        'message' => implode(' ', $messages),
        'discord_user_id' => $discord_user_id,
        'username' => $found_username,
        'nickname_set' => $nickname_set,
        'role_assigned' => $role_assigned,
        'bot_integration' => true
    ]);
}

function get_retreat_waiting_email($full_name)
{

    ob_start();
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Tashafe Retreat Waiting List</title>
    </head>

    <body style="margin:0; padding:0; background:#f6f6f6; font-family:Arial, sans-serif;">

        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f6; padding:40px 0;">

            <!-- Body -->
            <tr>
                <td style="padding:30px; color:#333; font-size:16px; line-height:26px;">

                    <p>Hi <?php echo esc_html($full_name); ?>,</p>

                    <p>
                        Thank you for joining the <strong>Tashafe Retreat Waiting List</strong>.
                        We've received your interest and will notify you as soon as a spot becomes available.
                    </p>

                    <p>
                        You are now officially in our queue. If you need any updates, feel free to contact us anytime.
                    </p>

                    <!-- Button -->
                    <table cellspacing="0" cellpadding="0" style="margin-top:20px;">
                        <tr>
                            <td align="center">
                                <a href="https://tanafs.com.sa"
                                    style="display:inline-block; padding:14px 28px;
                                                  background:linear-gradient(135deg, #C3DDD2, #6059A6);
                                                  color:#fff; text-decoration:none;
                                                  font-weight:600; border-radius:6px;
                                                  font-size:16px;">
                                    Visit Tanafs.com.sa
                                </a>
                            </td>
                        </tr>
                    </table>

                </td>
            </tr>

            <!-- Footer -->
            <tr>
                <td style="background:#f0f0f0; padding:16px; text-align:center;
                                   font-size:12px; color:#666;">
                    © <?php echo date("Y"); ?> Tashafe — All Rights Reserved.
                </td>
            </tr>

        </table>

        </td>
        </tr>
        </table>

    </body>

    </html>
<?php
    return ob_get_clean();
}

// Email template with retreat and trip details
function get_retreat_registration_email_with_details($first_name, $retreat_type, $discord_link = '', $private_channel_link = '', $suggested_nickname = '', $retreat_dates = '', $trip_destination = '', $trip_dates = '', $chat_join_url = '')
{
    ob_start();
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Tashafe Retreat Registration</title>
    </head>

    <body style="margin:0; padding:0; background:#f6f6f6; font-family:Arial, sans-serif;">
        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f6; padding:40px 0;">
            <tr>
                <td align="center">
                    <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.08);">
                        <!-- Header -->
                        <tr>
                            <td style="background:linear-gradient(135deg, #C3DDD2, #6059A6); padding:24px; text-align:center; color:#ffffff; font-size:24px; font-weight:bold;">
                                🎉 Retreat Registration Confirmed!
                            </td>
                        </tr>
                        <!-- Body -->
                        <tr>
                            <td style="padding:30px; color:#333; font-size:16px; line-height:26px;">
                                <p>Hi <?php echo esc_html($first_name); ?>,</p>
                                <p>Congratulations! Your registration for the <strong><?php echo ucfirst($retreat_type); ?> Retreat</strong> has been successfully confirmed.</p>

                                <!-- Retreat Details Box -->
                                <div style="background:linear-gradient(135deg, #f8f9fa, #e9ecef); padding:20px; border-radius:10px; margin:20px 0; border-left:4px solid #6059A6;">
                                    <p style="margin:0 0 15px 0; font-weight:600; color:#6059A6; font-size:18px;">📋 Your Retreat Details</p>
                                    <?php if ($retreat_dates): ?>
                                        <p style="margin:8px 0; font-size:15px;"><strong>Retreat Dates:</strong> <?php echo esc_html($retreat_dates); ?></p>
                                    <?php endif; ?>
                                    <?php if ($trip_destination): ?>
                                        <p style="margin:8px 0; font-size:15px;"><strong>Trip Destination:</strong> <?php echo esc_html($trip_destination); ?></p>
                                    <?php endif; ?>
                                    <?php if ($trip_dates): ?>
                                        <p style="margin:8px 0; font-size:15px;"><strong>Trip Dates:</strong> <?php echo esc_html($trip_dates); ?></p>
                                    <?php endif; ?>
                                </div>

                                <!-- Pre-Trip Contact Note -->
                                <div style="background:#fff3cd; padding:15px; border-radius:8px; margin:20px 0; border-left:4px solid #ffc107;">
                                    <p style="margin:0; font-size:14px; color:#856404;">
                                        📞 <strong>Pre-Trip Contact Note:</strong> You will be contacted one week before the retreat begins with final details and preparation information.
                                    </p>
                                </div>

                                <p style="margin-top:25px;">If you have any questions, feel free to contact us anytime.</p>
                            </td>
                        </tr>
                        <!-- Footer -->
                        <tr>
                            <td style="background:#f0f0f0; padding:16px; text-align:center; font-size:12px; color:#666;">
                                © <?php echo date("Y"); ?> Tashafe — All Rights Reserved.
                            </td>
                        </tr>
                    </table>
                </td>
            </tr>
        </table>
    </body>

    </html>
<?php
    return ob_get_clean();
}

// --------------------------------------------------
// STEP 3A: ENQUEUE RETREAT MODAL SCRIPT
// --------------------------------------------------
add_action('wp_enqueue_scripts', function () {

    // Enqueue jQuery first (if not already loaded)
    wp_enqueue_script('jquery');

    // Enqueue Select2 for searchable country dropdown
    wp_enqueue_style('select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
    wp_enqueue_script('select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], null, true);

    wp_enqueue_script(
        'retreat-booking-js',
        '',
        ['jquery'],
        null,
        true
    );

    wp_add_inline_script('retreat-booking-js', "
        var retreatAjax = {
            ajaxurl: '" . admin_url('admin-ajax.php') . "'
        };
    ");
});

// RETREAT MODAL + FORMS
add_action('wp_footer', function () {
    $is_ar = retreat_is_arabic_locale();

    // Define questionnaire questions - All questions from ques.txt
    $questions = $is_ar ? [
        ['text' => 'هل تعاني من أي أمراض مزمنة؟', 'type' => 'radio', 'options' => ['لا', 'نعم']],
        ['text' => 'هل سبق وأن أجريت عمليات جراحية أو تعرضت لإصابات؟', 'type' => 'radio', 'options' => ['لا', 'نعم']],
        ['text' => 'هل تم تشخيصك بأي اضطراب نفسي من قبل؟', 'type' => 'radio', 'options' => ['لا', 'نعم']],
        ['text' => 'هل تتناول حالياً أي أدوية نفسية أو طبية أخرى؟', 'type' => 'textarea'],
        ['text' => 'كيف تشعر خلال هذه الفترة من حياتك؟', 'type' => 'textarea'],
        ['text' => 'كيف تؤثر عواطفك على حياتك اليومية وعلاقاتك؟', 'type' => 'textarea'],
        ['text' => 'هل لديك مخاوف أو تحديات تود مناقشتها مع المجموعة؟', 'type' => 'radio', 'options' => ['لا', 'نعم']],
        ['text' => 'كيف تعتقد أن الجلسات الجماعية يمكن أن تساعدك في تحقيق ما تطمح إليه؟', 'type' => 'textarea'],
        ['text' => 'ما الخطوات التي اتخذتها حتى الآن لتجاوز التحديات التي تواجهها؟', 'type' => 'textarea'],
        ['text' => 'ما مدى ارتياحك لمشاركة مشاعرك والتعبير عنها داخل المجموعة؟', 'type' => 'textarea'],
        ['text' => 'هل سبق لك ممارسة اليوغا؟', 'type' => 'radio', 'options' => ['لا', 'نعم']],
        ['text' => 'هل لديك أي حساسية غذائية أو نظام غذائي خاص تتبعه؟', 'type' => 'textarea'],
    ] : [
        ['text' => 'Do you have any chronic illnesses?', 'type' => 'radio', 'options' => ['No', 'Yes']],
        ['text' => 'Have you had any previous surgeries or injuries?', 'type' => 'radio', 'options' => ['No', 'Yes']],
        ['text' => 'Have you ever been diagnosed with any psychological disorder?', 'type' => 'radio', 'options' => ['No', 'Yes']],
        ['text' => 'Are you currently taking any psychiatric or other medications?', 'type' => 'textarea'],
        ['text' => 'How are you feeling during this period of your life?', 'type' => 'textarea'],
        ['text' => 'How do your emotions affect your daily life and relationships?', 'type' => 'textarea'],
        ['text' => 'Do you have any fears or challenges you would like to discuss in the group?', 'type' => 'radio', 'options' => ['No', 'Yes']],
        ['text' => 'How do you think group therapy sessions can support you in achieving what you are aiming for?', 'type' => 'textarea'],
        ['text' => 'What steps have you taken so far to overcome the challenges you are facing?', 'type' => 'textarea'],
        ['text' => 'What is your level of comfort with sharing and expressing your emotions within a group?', 'type' => 'textarea'],
        ['text' => 'Have you practiced yoga before?', 'type' => 'radio', 'options' => ['No', 'Yes']],
        ['text' => 'Do you have any food allergies or follow any specific dietary restrictions?', 'type' => 'textarea'],
    ];

    // Country list for dropdown
    $countries = ['Afghanistan', 'Albania', 'Algeria', 'Andorra', 'Angola', 'Argentina', 'Armenia', 'Australia', 'Austria', 'Azerbaijan', 'Bahamas', 'Bahrain', 'Bangladesh', 'Barbados', 'Belarus', 'Belgium', 'Belize', 'Benin', 'Bhutan', 'Bolivia', 'Bosnia and Herzegovina', 'Botswana', 'Brazil', 'Brunei', 'Bulgaria', 'Burkina Faso', 'Burundi', 'Cambodia', 'Cameroon', 'Canada', 'Cape Verde', 'Central African Republic', 'Chad', 'Chile', 'China', 'Colombia', 'Comoros', 'Congo', 'Costa Rica', 'Croatia', 'Cuba', 'Cyprus', 'Czech Republic', 'Denmark', 'Djibouti', 'Dominica', 'Dominican Republic', 'Ecuador', 'Egypt', 'El Salvador', 'Equatorial Guinea', 'Eritrea', 'Estonia', 'Ethiopia', 'Fiji', 'Finland', 'France', 'Gabon', 'Gambia', 'Georgia', 'Germany', 'Ghana', 'Greece', 'Grenada', 'Guatemala', 'Guinea', 'Guyana', 'Haiti', 'Honduras', 'Hungary', 'Iceland', 'India', 'Indonesia', 'Iran', 'Iraq', 'Ireland', 'Israel', 'Italy', 'Jamaica', 'Japan', 'Jordan', 'Kazakhstan', 'Kenya', 'Kiribati', 'Kuwait', 'Kyrgyzstan', 'Laos', 'Latvia', 'Lebanon', 'Lesotho', 'Liberia', 'Libya', 'Liechtenstein', 'Lithuania', 'Luxembourg', 'Madagascar', 'Malawi', 'Malaysia', 'Maldives', 'Mali', 'Malta', 'Mauritania', 'Mauritius', 'Mexico', 'Moldova', 'Monaco', 'Mongolia', 'Montenegro', 'Morocco', 'Mozambique', 'Myanmar', 'Namibia', 'Nepal', 'Netherlands', 'New Zealand', 'Nicaragua', 'Niger', 'Nigeria', 'North Macedonia', 'Norway', 'Oman', 'Pakistan', 'Palestine', 'Panama', 'Papua New Guinea', 'Paraguay', 'Peru', 'Philippines', 'Poland', 'Portugal', 'Qatar', 'Romania', 'Russia', 'Rwanda', 'Saint Kitts and Nevis', 'Saint Lucia', 'Samoa', 'San Marino', 'Saudi Arabia', 'Senegal', 'Serbia', 'Seychelles', 'Sierra Leone', 'Singapore', 'Slovakia', 'Slovenia', 'Solomon Islands', 'Somalia', 'South Africa', 'South Korea', 'South Sudan', 'Spain', 'Sri Lanka', 'Sudan', 'Suriname', 'Sweden', 'Switzerland', 'Syria', 'Taiwan', 'Tajikistan', 'Tanzania', 'Thailand', 'Togo', 'Tonga', 'Trinidad and Tobago', 'Tunisia', 'Turkey', 'Turkmenistan', 'Tuvalu', 'Uganda', 'Ukraine', 'United Arab Emirates', 'United Kingdom', 'United States', 'Uruguay', 'Uzbekistan', 'Vanuatu', 'Vatican City', 'Venezuela', 'Vietnam', 'Yemen', 'Zambia', 'Zimbabwe'];
?>
    <!-- STEP 1: Main Retreat Type Selection Modal -->
    <div id="retreat-modal" style="display:none;">
        <div class="retreat-modal-content <?php echo $is_ar ? 'retreat-lang-ar' : ''; ?>" style="max-width:550px;">
            <span class="retreat-modal-close">&times;</span>
            <h3><?php echo esc_html(retreat_translate('Select Retreat Type', 'اختر نوع الرحلة العلاجية')); ?></h3>
            <p style="color:#666;text-align:center;margin-bottom:25px;">
                <?php echo esc_html(retreat_translate('Choose the retreat group that best fits you', 'اختر المجموعة الأنسب لك')); ?>
            </p>
            <button class="retreat-option" data-type="male">
                <?php echo esc_html(retreat_translate('Male Retreat', 'رحلة الرجال')); ?>
            </button>
            <button class="retreat-option" data-type="female">
                <?php echo esc_html(retreat_translate('Female Retreat', 'رحلة السيدات')); ?>
            </button>
            <button class="retreat-option" data-type="teen">
                <?php echo esc_html(retreat_translate('Teen Retreat', 'رحلة اليافعين')); ?>
            </button>
            <div id="retreat-result" style="margin-top:20px;"></div>
        </div>
    </div>

    <!-- STEP 2: Schedule Selection Modal -->
    <div id="retreat-schedule-modal" style="display:none;">
        <div class="retreat-modal-content <?php echo $is_ar ? 'retreat-lang-ar' : ''; ?>" style="max-width:600px;">
            <span class="retreat-schedule-close">&times;</span>
            <div id="schedule-header" style="text-align:center;margin-bottom:25px;">
                <span id="schedule-gender-badge" style="display:inline-block;padding:6px 16px;background:#f0e8ff;color:#6059A6;border-radius:20px;font-size:13px;font-weight:600;margin-bottom:15px;"></span>
                <h3 style="margin:0;color:#6059A6;">
                    <?php echo esc_html(retreat_translate('Select Your Schedule', 'اختر المواعيد المناسبة')); ?>
                </h3>
                <p style="color:#666;font-size:14px;margin-top:8px;">
                    <?php echo esc_html(retreat_translate('Choose an available retreat date', 'اختر موعد الرحلة المتاح')); ?>
                </p>
            </div>
            <div id="schedule-list" style="max-height:400px;overflow-y:auto;"></div>
            <div id="schedule-loading" style="text-align:center;padding:40px;display:none;">
                <div style="color:#6059A6;">
                    <?php echo esc_html(retreat_translate('Loading schedules...', 'جارٍ تحميل الجداول...')); ?>
                </div>
            </div>
        </div>
    </div>

    <!-- STEP 3: Retreat Details Modal -->
    <div id="retreat-details-modal" style="display:none;">
        <div class="retreat-modal-content retreat-details-content <?php echo $is_ar ? 'retreat-lang-ar' : ''; ?>" style="max-width:750px;max-height:90vh;padding:0;overflow-y:auto;">
            <span class="retreat-details-close">&times;</span>

            <!-- Cover Image Section -->
            <div id="retreat-cover-section" style="position:relative;height:280px;background:#f0f0f0;overflow:hidden;">
                <img id="retreat-cover-image" src="" alt="Retreat Cover" style="width:100%;height:100%;object-fit:cover;display:none;">
                <div id="retreat-cover-placeholder" style="width:100%;height:100%;background:linear-gradient(135deg, #C3DDD2, #6059A6);display:flex;align-items:center;justify-content:center;">
                    <span style="font-size:60px;opacity:0.5;">🏝️</span>
                </div>
                <span id="retreat-type-badge" style="position:absolute;top:20px;left:20px;background:#fff;padding:8px 16px;border-radius:20px;font-size:13px;font-weight:600;color:#6059A6;box-shadow:0 2px 10px rgba(0,0,0,0.1);"></span>
            </div>

            <!-- Details Content -->
            <div style="padding:30px;">
                <h2 id="retreat-title" style="color:#333;margin:0 0 15px;font-size:26px;font-weight:700;"></h2>
                <p id="retreat-description" style="color:#555;line-height:1.7;font-size:15px;margin-bottom:25px;"></p>

                <!-- Package Price Box -->
                <div style="background:#f8f5ff;padding:15px 20px;border-radius:10px;margin-bottom:25px;">
                    <span style="color:#666;font-size:13px;display:block;">
                        <?php echo esc_html(retreat_translate('Package Price', 'سعر الباقة')); ?>
                    </span>
                    <span id="retreat-price" style="color:#6059A6;font-size:22px;font-weight:700;"></span>
                </div>

                <!-- Info Grid -->
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:25px;">
                    <div style="display:flex;align-items:flex-start;gap:12px;">
                        <div style="width:42px;height:42px;background:#6059A6;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <span style="color:#fff;font-size:18px;">📅</span>
                        </div>
                        <div>
                            <span style="color:#666;font-size:12px;display:block;">
                                <?php echo esc_html(retreat_translate('Date:', 'التاريخ:')); ?>
                            </span>
                            <span id="retreat-dates" style="color:#333;font-weight:600;font-size:14px;"></span>
                        </div>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:12px;">
                        <div style="width:42px;height:42px;background:#6059A6;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <span style="color:#fff;font-size:18px;">📍</span>
                        </div>
                        <div>
                            <span style="color:#666;font-size:12px;display:block;">
                                <?php echo esc_html(retreat_translate('Location:', 'الموقع:')); ?>
                            </span>
                            <span id="retreat-location" style="color:#333;font-weight:600;font-size:14px;"></span>
                        </div>
                    </div>
                    <div style="display:flex;align-items:flex-start;gap:12px;">
                        <div style="width:42px;height:42px;background:#6059A6;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                            <span style="color:#fff;font-size:18px;">💰</span>
                        </div>
                        <div>
                            <span style="color:#666;font-size:12px;display:block;">
                                <?php echo esc_html(retreat_translate('Price:', 'السعر:')); ?>
                            </span>
                            <span id="retreat-price-usd" style="color:#333;font-weight:600;font-size:14px;"></span>
                        </div>
                    </div>
                    <!-- Availability removed -->
                </div>

                <!-- Package Includes -->
                <div id="package-includes-section" style="display:none;">
                    <h4 style="color:#333;margin-bottom:12px;font-size:18px;">
                        <?php echo esc_html(retreat_translate('Package Includes:', 'تشمل الباقة:')); ?>
                    </h4>
                    <ul id="retreat-package-list" style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:1px;"></ul>
                </div>

                <!-- Book Button -->
                <button id="book-spot-btn" style="width:100%;padding:18px;background:linear-gradient(135deg, #C3DDD2, #6059A6);color:#fff;border:none;border-radius:12px;font-size:17px;font-weight:600;cursor:pointer;margin-top:25px;display:flex;align-items:center;justify-content:center;gap:10px;transition:all 0.3s;">
                    <?php echo esc_html(retreat_translate('Book Your Spot', 'احجز مقعدك')); ?> <span style="font-size:18px;">📅</span>
                </button>
            </div>
        </div>
    </div>

    <!-- STEP 4: Registration Form Modal - Redesigned -->
    <div id="retreat-register-modal" style="display:none;">
        <div class="retreat-modal-content <?php echo $is_ar ? 'retreat-lang-ar' : ''; ?>" style="max-width:650px;">
            <span class="retreat-register-close">&times;</span>
            <h3 style="color:#333;margin-bottom:15px;font-size:22px;">
                <?php echo esc_html(retreat_translate('Personal Information', 'المعلومات الشخصية')); ?>
            </h3>

            <form id="retreat-register-form" enctype="multipart/form-data">
                <input type="hidden" name="retreat_type" id="reg_retreat_type">
                <input type="hidden" name="group_id" id="reg_group_id">

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div class="form-group">
                        <label style="display:block;color:#333;font-weight:500;margin-bottom:5px;font-size:13px;">
                            <?php echo esc_html(retreat_translate('Full Name', 'الاسم الكامل')); ?>
                        </label>
                        <div style="position:relative;">
                            <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#999;font-size:16px;">👤</span>
                            <input type="text" name="full_name" placeholder="<?php echo esc_attr(retreat_translate('Enter full name', 'اكتب اسمك الكامل')); ?>" required style="width:100%;padding:10px 10px 10px 38px;border:1px solid #e0e0e0;border-radius:8px;font-size:14px;box-sizing:border-box;">
                        </div>
                    </div>
                    <div class="form-group">
                        <label style="display:block;color:#333;font-weight:500;margin-bottom:5px;font-size:13px;">
                            <?php echo esc_html(retreat_translate('Email', 'البريد الإلكتروني')); ?>
                        </label>
                        <div style="position:relative;">
                            <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#999;font-size:16px;">✉️</span>
                            <input type="email" name="email" placeholder="<?php echo esc_attr(retreat_translate('Email address', 'عنوان البريد الإلكتروني')); ?>" required style="width:100%;padding:10px 10px 10px 38px;border:1px solid #e0e0e0;border-radius:8px;font-size:14px;box-sizing:border-box;">
                        </div>
                    </div>
                    <div class="form-group">
                        <label style="display:block;color:#333;font-weight:500;margin-bottom:5px;font-size:13px;">
                            <?php echo esc_html(retreat_translate('Phone', 'رقم الهاتف')); ?>
                        </label>
                        <div style="position:relative;">
                            <span style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#999;font-size:16px;">📞</span>
                            <input type="text" name="phone" placeholder="<?php echo esc_attr(retreat_translate('Enter phone number', 'اكتب رقم الهاتف')); ?>" required style="width:100%;padding:10px 10px 10px 38px;border:1px solid #e0e0e0;border-radius:8px;font-size:14px;box-sizing:border-box;">
                        </div>
                    </div>
                    <div class="form-group">
                        <label style="display:block;color:#333;font-weight:500;margin-bottom:5px;font-size:13px;">
                            <?php echo esc_html(retreat_translate('Country', 'الدولة')); ?>
                        </label>
                        <select name="country" id="reg-country-select" required style="width:100%;padding:14px;border:1px solid #e0e0e0;border-radius:8px;font-size:14px;box-sizing:border-box;background:#fff;">
                            <option value=""><?php echo esc_html(retreat_translate('Select country', 'اختر الدولة')); ?></option>
                            <?php foreach ($countries as $country): ?>
                                <option value="<?php echo esc_attr($country); ?>"><?php echo esc_html($country); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="display:block;color:#333;font-weight:500;margin-bottom:8px;font-size:14px;">
                            <?php echo esc_html(retreat_translate('Gender', 'الجنس')); ?>
                        </label>
                        <select name="gender" required style="width:100%;padding:14px;border:1px solid #e0e0e0;border-radius:8px;font-size:14px;box-sizing:border-box;background:#fff;">
                            <option value=""><?php echo esc_html(retreat_translate('Select gender', 'اختر الجنس')); ?></option>
                            <option value="male"><?php echo esc_html(retreat_translate('Male', 'ذكر')); ?></option>
                            <option value="female"><?php echo esc_html(retreat_translate('Female', 'أنثى')); ?></option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label style="display:block;color:#333;font-weight:500;margin-bottom:8px;font-size:14px;">
                            <?php echo esc_html(retreat_translate('Date of Birth', 'تاريخ الميلاد')); ?>
                        </label>
                        <div style="position:relative;">
                            <input type="date" name="birth_date" required style="width:100%;padding:14px;border:1px solid #e0e0e0;border-radius:8px;font-size:14px;box-sizing:border-box;">
                        </div>
                    </div>
                    <div class="form-group">
                        <label style="display:block;color:#333;font-weight:500;margin-bottom:8px;font-size:14px;">
                            <?php echo esc_html(retreat_translate('Password', 'كلمة المرور')); ?>
                        </label>
                        <div style="position:relative;">
                            <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#999;">🔒</span>
                            <input type="password" name="password" id="retreat-password" placeholder="<?php echo esc_attr(retreat_translate('Create a password', 'أنشئ كلمة مرور')); ?>" required minlength="8" style="width:100%;padding:14px 14px 14px 42px;border:1px solid #e0e0e0;border-radius:8px;font-size:14px;box-sizing:border-box;">
                        </div>
                        <small style="color:#666;font-size:12px;display:block;margin-top:4px;">
                            <?php echo esc_html(retreat_translate('Must be at least 8 characters', 'يجب أن تتكون من 8 أحرف على الأقل')); ?>
                        </small>
                    </div>
                    <div class="form-group">
                        <label style="display:block;color:#333;font-weight:500;margin-bottom:8px;font-size:14px;">
                            <?php echo esc_html(retreat_translate('Confirm Password', 'تأكيد كلمة المرور')); ?>
                        </label>
                        <div style="position:relative;">
                            <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#999;">🔒</span>
                            <input type="password" name="confirm_password" id="retreat-confirm-password" placeholder="<?php echo esc_attr(retreat_translate('Confirm your password', 'أعد إدخال كلمة المرور')); ?>" required minlength="8" style="width:100%;padding:14px 14px 14px 42px;border:1px solid #e0e0e0;border-radius:8px;font-size:14px;box-sizing:border-box;">
                        </div>
                        <small id="password-match-error" style="color:#dc3545;font-size:12px;display:none;margin-top:4px;">
                            <?php echo esc_html(retreat_translate('Passwords do not match', 'كلمتا المرور غير متطابقتين')); ?>
                        </small>
                    </div>
                </div>

                <!-- Passport Upload -->
                <div class="form-group" style="margin-top:10px;">
                    <label style="display:block;color:#333;font-weight:500;margin-bottom:8px;font-size:14px;">
                        <?php echo esc_html(retreat_translate('Passport (JPG/PDF)', 'جواز السفر (JPG/PDF)')); ?>
                    </label>
                    <input type="file" name="passport" accept="image/*,.pdf" style="position:absolute;opacity:0;width:0;height:0;pointer-events:none;" id="passport-input">
                    <label for="passport-input" id="passport-dropzone" style="display:block;border:2px dashed #d0d0d0;border-radius:10px;padding:30px;text-align:center;background:#faf8ff;cursor:pointer;transition:all 0.3s;">
                        <div style="color:#6059A6;font-size:30px;margin-bottom:10px;">☁️</div>
                        <p style="color:#6059A6;margin:0 0 5px;font-weight:500;">
                            <?php echo esc_html(retreat_translate('Drop your files here', 'أسقط ملفاتك هنا')); ?>
                        </p>
                        <p style="color:#999;margin:0 0 10px;font-size:13px;">
                            <?php echo esc_html(retreat_translate('Or', 'أو')); ?>
                        </p>
                        <span style="display:inline-block;padding:8px 20px;background:#6059A6;color:#fff;border-radius:6px;font-size:13px;font-weight:500;">
                            <?php echo esc_html(retreat_translate('Select file', 'اختر ملفاً')); ?>
                        </span>
                    </label>
                    <p id="passport-filename" style="margin-top:8px;font-size:13px;color:#666;display:none;"></p>
                </div>

                <!-- Terms & Conditions Checkbox -->
                <div style="margin-top:12px;display:flex;align-items:flex-start;gap:10px;">
                    <input type="checkbox" id="agree-terms-reg" style="width:18px;height:18px;margin-top:2px;cursor:pointer;">
                    <label for="agree-terms-reg" style="font-size:14px;color:#333;cursor:pointer;">
                        <?php echo esc_html(retreat_translate('By clicking this, you confirm that you have read and agree to the', 'بتحديد هذا الخيار فإنك تؤكد قراءتك وموافقتك على')); ?>
                        <a href="#" id="open-terms-link" style="color:#6059A6;text-decoration:underline;">
                            <?php echo esc_html(retreat_translate('Retreat Terms & Conditions', 'شروط وأحكام الرحلة')); ?>
                        </a>.
                    </label>
                </div>

                <button type="submit" id="reg-submit-btn" disabled style="width:100%;padding:14px;background:linear-gradient(135deg, #C3DDD2, #6059A6);color:#fff;border:none;border-radius:12px;font-size:16px;font-weight:600;cursor:pointer;margin-top:15px;display:flex;align-items:center;justify-content:center;gap:10px;opacity:0.5;transition:all 0.3s;">
                    <?php echo esc_html(retreat_translate('Book Your Spot', 'احجز مقعدك')); ?> <span style="font-size:18px;">📅</span>
                </button>
            </form>
        </div>
    </div>

    <!-- STEP 5: Terms & Conditions Modal (Popup from registration page) -->
    <div id="retreat-terms-modal" style="display:none;">
        <div class="retreat-modal-content <?php echo $is_ar ? 'retreat-lang-ar' : ''; ?>" style="max-width:700px;">
            <span class="retreat-terms-close">&times;</span>
            <?php if ($is_ar): ?>
                <h3 style="color:#6059A6;margin-bottom:20px;">شروط وأحكام الرحلة</h3>
                <p style="color:#666;font-size:14px;margin-bottom:20px;text-align:center;">يرجى قراءة الشروط والأحكام التالية بعناية قبل المشاركة في رحلة تنفّس.</p>
                <div id="terms-content" style="max-height:400px;overflow-y:auto;padding:20px;background:#f8f9fa;border-radius:10px;margin-bottom:25px;font-size:14px;line-height:1.8;color:#333;">
                    <h4 style="color:#6059A6;margin-bottom:15px;">شروط وأحكام المشاركين في رحلة تنفّس</h4>

                    <h5 style="color:#6059A6;margin-top:20px;">2. الإلغاء والتعديلات</h5>
                    <p><strong>2.1</strong> تحتفظ الجهة المنظمة بحق إلغاء الرحلة أو تأجيل أي نشاط في أي وقت بسبب الظروف القاهرة أو الأسباب الخارجة عن الإرادة.</p>
                    <p><strong>2.2</strong> في حال إلغاء الرحلة من قبل الجهة المنظمة، يتم رد جميع الرسوم المدفوعة بالكامل للمشاركين.</p>
                    <p><strong>2.3</strong> في حال رغب المشارك في إلغاء مشاركته:</p>
                    <ul style="margin-left:20px;">
                        <li>الإلغاء قبل موعد الرحلة بأسبوعين على الأقل: استرداد كامل.</li>
                        <li>الإلغاء قبل أسبوع واحد: استرداد 50٪ من القيمة.</li>
                        <li>الإلغاء خلال أقل من أسبوع: لا يتم استرداد الرسوم.</li>
                    </ul>

                    <h5 style="color:#6059A6;margin-top:20px;">3. المسؤولية</h5>
                    <p><strong>3.1</strong> يتحمل المشاركون مسؤولية التأكد من أن حالتهم الصحية والبدنية تسمح لهم بالمشاركة بأمان في جميع الأنشطة.</p>
                    <p><strong>3.2</strong> لا تتحمل الجهة المنظمة مسؤولية أي إصابات أو حوادث قد تقع أثناء الرحلة.</p>
                    <p><strong>3.3</strong> يتحمل المشاركون مسؤولية ممتلكاتهم الشخصية طوال فترة الرحلة.</p>

                    <h5 style="color:#6059A6;margin-top:20px;">4. السلوك</h5>
                    <p><strong>4.1</strong> يجب على المشاركين الالتزام بالسلوك اللائق واتباع جميع التعليمات المقدمة من الفريق المنظم.</p>
                    <p><strong>4.2</strong> يُطلب ارتداء ملابس محتشمة ومناسبة خلال جميع فعاليات الرحلة.</p>
                    <p><strong>4.3</strong> يمنع التدخين نهائياً في جميع مناطق الرحلة.</p>
                    <p><strong>4.4</strong> يمنع تصوير أو تسجيل المشاركين أو الفريق دون إذن.</p>
                    <p><strong>4.5</strong> يمنع تماماً تصوير أو تسجيل جلسات العلاج الفردية أو الجماعية حفاظاً على الخصوصية.</p>
                    <p style="color:#dc3545;font-weight:600;margin-top:10px;">يحق للجهة المنظمة استبعاد أي مشارك يخالف هذه التعليمات دون رد الرسوم.</p>

                    <h5 style="color:#6059A6;margin-top:20px;">5. الخصوصية</h5>
                    <p><strong>5.1</strong> تُستخدم المعلومات الشخصية فقط لأغراض التسجيل والتنظيم، ولا تُشارك مع أي طرف ثالث دون إذن صريح.</p>
                    <p><strong>5.2</strong> قد تُستخدم المعلومات من قبل الفريق النفسي لمتابعة حالة المشاركين بهدف إنجاح البرنامج العلاجي فقط.</p>

                    <h5 style="color:#6059A6;margin-top:20px;">6. تعديل الشروط</h5>
                    <p><strong>6.1</strong> سيتم إبلاغ المشاركين بأي تغييرات على الشروط عبر البريد الإلكتروني أو وسائل التواصل المناسبة.</p>

                    <h5 style="color:#6059A6;margin-top:20px;">7. الإقرار</h5>
                    <p>بمجرد مشاركتك في رحلة تنفّس:</p>
                    <p><strong>7.1</strong> فإنك تقر بأنك قرأت وفهمت ووافقت على جميع الشروط المذكورة أعلاه.</p>
                    <p><strong>7.2</strong> تلتزم بالضوابط الواردة وتقبل تبعات أي مخالفة، بما في ذلك الاستبعاد من الرحلة دون تعويض.</p>
                </div>
            <?php else: ?>
                <h3 style="color:#6059A6;margin-bottom:20px;">Retreat Terms & Conditions</h3>
                <p style="color:#666;font-size:14px;margin-bottom:20px;text-align:center;">Please read the following terms and conditions carefully before participating in the Tanafs Retreat.</p>

                <div id="terms-content" style="max-height:400px;overflow-y:auto;padding:20px;background:#f8f9fa;border-radius:10px;margin-bottom:25px;font-size:14px;line-height:1.8;color:#333;">
                    <h4 style="color:#6059A6;margin-bottom:15px;">Terms and Conditions for the Tanafs Retreat Participants</h4>

                    <h5 style="color:#6059A6;margin-top:20px;">2. Cancellation and Modifications</h5>
                    <p><strong>2.1</strong> The organizing entity reserves the right to cancel or postpone the retreat or any scheduled activity at any time due to force majeure or unforeseen circumstances.</p>
                    <p><strong>2.2</strong> If the retreat is cancelled by the organizing entity, all paid fees will be fully refunded to participants.</p>
                    <p><strong>2.3</strong> If a participant wishes to cancel their participation:</p>
                    <ul style="margin-left:20px;">
                        <li>Cancellation made at least two weeks before the retreat date: Full refund.</li>
                        <li>Cancellation made one week before the retreat date: 50% refund.</li>
                        <li>Cancellation made less than one week before the retreat date: No refund.</li>
                    </ul>

                    <h5 style="color:#6059A6;margin-top:20px;">3. Responsibility</h5>
                    <p><strong>3.1</strong> Participants are responsible for ensuring that their health condition and physical fitness allow them to safely participate in all scheduled activities.</p>
                    <p><strong>3.2</strong> The organizing entity is not responsible for any injuries or incidents that may occur during the retreat.</p>
                    <p><strong>3.3</strong> Participants are responsible for their personal belongings throughout the retreat.</p>

                    <h5 style="color:#6059A6;margin-top:20px;">4. Conduct</h5>
                    <p><strong>4.1</strong> Participants must adhere to respectful and ethical behavior, follow all rules, and comply with the instructions provided by the organizing entity.</p>
                    <p><strong>4.2</strong> Participants are required to wear modest and appropriate clothing during all retreat activities.</p>
                    <p><strong>4.3</strong> Smoking is strictly prohibited in all retreat areas.</p>
                    <p><strong>4.4</strong> Photography or recording of other participants or the organizing team is not permitted.</p>
                    <p><strong>4.5</strong> Photographing or recording individual or group therapy sessions is strictly prohibited to protect participant privacy.</p>
                    <p style="color:#dc3545;font-weight:600;margin-top:10px;">The organizing entity reserves the right to remove any participant who violates these rules without issuing a refund.</p>

                    <h5 style="color:#6059A6;margin-top:20px;">5. Privacy</h5>
                    <p><strong>5.1</strong> Personal information provided by participants will be used solely for registration and organization purposes and will not be shared with any third party without explicit consent.</p>
                    <p><strong>5.2</strong> Personal information may be used by the psychological team to monitor and assess participant well-being during the retreat, exclusively for the purpose of achieving the therapeutic goals of the program.</p>

                    <h5 style="color:#6059A6;margin-top:20px;">6. Changes to Terms and Conditions</h5>
                    <p><strong>6.1</strong> Participants will be notified of any changes to the terms and conditions via email or other appropriate communication channels.</p>

                    <h5 style="color:#6059A6;margin-top:20px;">7. Agreement</h5>
                    <p>By participating in the Tanafs Retreat:</p>
                    <p><strong>7.1</strong> You acknowledge that you have read, understood, and agreed to all the terms and conditions stated above.</p>
                    <p><strong>7.2</strong> You commit to complying with all the outlined rules and agree to the consequences in case of any violations, including removal from the retreat without refund.</p>
                </div>
            <?php endif; ?>

            <div style="text-align:center;">
                <button id="terms-close-btn" style="padding:16px 50px;background:linear-gradient(135deg, #C3DDD2, #6059A6);color:#fff;border:none;border-radius:10px;font-size:17px;font-weight:600;cursor:pointer;transition:all 0.3s;">
                    <?php echo esc_html(retreat_translate('Close', 'إغلاق')); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- STEP 6: Single Questionnaire Modal (All questions at once) -->
    <div id="retreat-questionnaire-modal" style="display:none;">
        <div class="retreat-modal-content" style="max-width:700px;">
            <span class="retreat-questionnaire-close">&times;</span>
            <h3 style="color:#333;margin-bottom:5px;">Personal Wellbeing & Background Information</h3>
            <p style="color:#666;font-size:14px;text-align:center;margin-bottom:25px;">Please answer all questions below. All fields are required.</p>

            <form id="questionnaire-form" style="max-height:500px;overflow-y:auto;padding-right:10px;">
                <?php foreach ($questions as $index => $q): ?>
                    <div class="questionnaire-item" style="margin-bottom:25px;">
                        <p style="font-size:15px;color:#333;margin-bottom:12px;font-weight:500;"><?php echo esc_html($q['text']); ?> <span style="color:#dc3545;">*</span></p>
                        <?php if ($q['type'] === 'textarea'): ?>
                            <textarea class="q-answer" data-question="<?php echo $index; ?>" placeholder="Write here..." required style="width:100%;height:100px;padding:14px;border:1px solid #e0e0e0;border-radius:8px;font-size:14px;resize:none;box-sizing:border-box;transition:border 0.3s;"></textarea>
                        <?php elseif ($q['type'] === 'radio'): ?>
                            <div style="display:flex;gap:20px;">
                                <?php foreach ($q['options'] as $opt): ?>
                                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                        <input type="radio" name="q_<?php echo $index; ?>" class="q-answer" data-question="<?php echo $index; ?>" value="<?php echo esc_attr($opt); ?>" required style="width:18px;height:18px;accent-color:#6059A6;">
                                        <span style="font-size:14px;color:#333;"><?php echo esc_html($opt); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </form>

            <button id="submit-questionnaire-btn" style="width:100%;padding:18px;background:linear-gradient(135deg, #C3DDD2, #6059A6);color:#fff;border:none;border-radius:12px;font-size:17px;font-weight:600;cursor:pointer;margin-top:20px;transition:all 0.3s;">
                Submit Information
            </button>
        </div>
    </div>

    <!-- Success Modal - Thank You for Sharing -->
    <div id="retreat-chat-modal" style="display:none;">
        <div class="retreat-modal-content <?php echo $is_ar ? 'retreat-lang-ar' : ''; ?>" style="max-width:500px;text-align:center;" <?php echo $is_ar ? 'dir="rtl"' : ''; ?>>
            <span class="retreat-chat-close">&times;</span>

            <!-- Success Icon with confetti effect -->
            <div style="position:relative;margin:20px auto 25px;width:90px;height:90px;">
                <!-- Confetti particles -->
                <div style="position:absolute;top:-10px;left:-15px;font-size:12px;color:#5BCEFA;">💧</div>
                <div style="position:absolute;top:5px;right:-10px;font-size:10px;color:#F5A9B8;">✦</div>
                <div style="position:absolute;top:-5px;right:10px;font-size:8px;color:#FFD700;">◆</div>
                <div style="position:absolute;bottom:10px;left:-10px;font-size:10px;color:#90EE90;">●</div>
                <div style="position:absolute;bottom:-5px;right:-5px;font-size:8px;color:#DDA0DD;">▲</div>
                <div style="position:absolute;top:20px;left:-20px;font-size:6px;color:#87CEEB;">★</div>
                <div style="position:absolute;top:0;right:-15px;font-size:7px;color:#FFB6C1;">◇</div>
                <div style="position:absolute;bottom:20px;right:-15px;font-size:9px;color:#98FB98;">○</div>
                <!-- Main checkmark circle -->
                <div style="width:90px;height:90px;background:#6059A6;border-radius:50%;display:flex;align-items:center;justify-content:center;">
                    <svg width="45" height="45" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                </div>
            </div>

            <h3 style="color:#333;margin-bottom:15px;font-size:24px;font-weight:700;">
                <?php echo esc_html(retreat_translate('Thank You for Sharing', 'شكراً لمشاركتك')); ?>
            </h3>

            <p style="color:#666;font-size:15px;margin-bottom:30px;line-height:1.7;padding:0 20px;">
                <?php echo esc_html(retreat_translate('Thank you for completing the form. Your information has been received and will be reviewed by our team prior to the group session.', 'شكراً لإكمال النموذج. تم استلام معلوماتك وسيراجعها فريقنا قبل جلسة المجموعة.')); ?>
            </p>

            <button id="finish-setup-btn" style="width:100%;max-width:350px;padding:16px 40px;background:linear-gradient(135deg, #C3DDD2, #6059A6);color:#fff;border:none;border-radius:12px;font-size:16px;font-weight:600;cursor:pointer;transition:all 0.3s;margin-bottom:10px;">
                <?php echo esc_html(retreat_translate('Go to Main Page', 'الذهاب إلى الصفحة الرئيسية')); ?>
            </button>
        </div>
    </div>

    <!-- Waiting List Modal -->
    <div id="retreat-waiting-modal" style="display:none;">
        <div class="retreat-modal-content" style="max-width:500px;">
            <span class="retreat-waiting-close">&times;</span>
            <h3 style="color:#6059A6;margin-bottom:15px;">Join Waiting List</h3>
            <p style="color:#666;font-size:14px;text-align:center;margin-bottom:25px;">No retreats are currently available. Join our waiting list and we'll notify you when a spot opens.</p>

            <form id="retreat-waiting-form">
                <input type="hidden" name="retreat_type" id="waiting_retreat_type">
                <div class="form-group" style="margin-bottom:15px;">
                    <label style="display:block;color:#333;font-weight:500;margin-bottom:8px;font-size:14px;">Full Name</label>
                    <input type="text" name="full_name" placeholder="Enter your full name" required style="width:100%;padding:14px;border:1px solid #e0e0e0;border-radius:8px;font-size:14px;box-sizing:border-box;">
                </div>
                <div class="form-group" style="margin-bottom:15px;">
                    <label style="display:block;color:#333;font-weight:500;margin-bottom:8px;font-size:14px;">Email</label>
                    <input type="email" name="email" placeholder="Enter your email" required style="width:100%;padding:14px;border:1px solid #e0e0e0;border-radius:8px;font-size:14px;box-sizing:border-box;">
                </div>
                <div class="form-group" style="margin-bottom:20px;">
                    <label style="display:block;color:#333;font-weight:500;margin-bottom:8px;font-size:14px;">Phone (Optional)</label>
                    <input type="text" name="phone" placeholder="Enter your phone number" style="width:100%;padding:14px;border:1px solid #e0e0e0;border-radius:8px;font-size:14px;box-sizing:border-box;">
                </div>
                <button type="submit" style="width:100%;padding:16px;background:linear-gradient(135deg, #C3DDD2, #6059A6);color:#fff;border:none;border-radius:10px;font-size:16px;font-weight:600;cursor:pointer;transition:all 0.3s;">
                    Join Waiting List
                </button>
            </form>
        </div>
    </div>

    <style>
        /* Base Modal Styles - All Modals */
        #retreat-modal,
        #retreat-schedule-modal,
        #retreat-details-modal,
        #retreat-register-modal,
        #retreat-terms-modal,
        #retreat-questionnaire-modal,
        #retreat-chat-modal,
        #retreat-waiting-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            overflow-y: auto;
            overflow-x: hidden;
        }

        .retreat-modal-content {
            background: #fff;
            padding: 25px 35px;
            max-width: 650px;
            width: 90%;
            margin: 30px auto;
            border-radius: 16px;
            position: relative;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            max-height: 90vh;
            overflow-y: auto;
            overflow-x: hidden;
            box-sizing: border-box;
        }

        .retreat-details-content {
            padding: 0 !important;
        }

        .retreat-modal-content h3 {
            margin-top: 0;
            color: #6059A6;
            font-size: 24px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 700;
        }

        /* Close buttons for all modals */
        .retreat-modal-close,
        .retreat-schedule-close,
        .retreat-details-close,
        .retreat-register-close,
        .retreat-terms-close,
        .retreat-questionnaire-close,
        .retreat-chat-close,
        .retreat-waiting-close {
            position: absolute;
            top: 15px;
            right: 20px;
            cursor: pointer;
            font-size: 28px;
            font-weight: bold;
            color: #aaa;
            transition: color 0.2s;
            line-height: 1;
            z-index: 10;
        }

        .retreat-details-close {
            background: rgba(255, 255, 255, 0.9);
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            top: 15px;
            right: 15px;
        }

        .retreat-modal-close:hover,
        .retreat-schedule-close:hover,
        .retreat-details-close:hover,
        .retreat-register-close:hover,
        .retreat-terms-close:hover,
        .retreat-questionnaire-close:hover,
        .retreat-chat-close:hover,
        .retreat-waiting-close:hover {
            color: #333;
        }

        /* Type selection buttons (Step 1) */
        .retreat-option {
            display: block;
            width: 100%;
            margin: 12px 0;
            padding: 18px 24px;
            background: #6059A6;
            color: #fff;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 17px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(96, 89, 166, 0.3);
            text-align: center;
        }

        .retreat-option:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(96, 89, 166, 0.4);
        }

        /* Schedule cards (Step 2) */
        .schedule-card {
            background: #fff;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .schedule-card:hover {
            border-color: #6059A6;
            box-shadow: 0 4px 12px rgba(96, 89, 166, 0.2);
        }

        .schedule-card.selected {
            border-color: #6059A6;
            background: linear-gradient(135deg, rgba(195, 221, 210, 0.1), rgba(96, 89, 166, 0.1));
        }

        #retreat-result {
            margin-top: 30px;
        }

        .retreat-register,
        .retreat-waiting {
            display: block;
            width: 60%;
            margin: 20px auto 0;
            padding: 18px 24px;
            background: linear-gradient(135deg, #C3DDD2, #6059A6);
            color: #fff;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 18px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(96, 89, 166, 0.3);
        }

        .retreat-register:hover,
        .retreat-waiting:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(96, 89, 166, 0.4);
        }

        /* Form input styles */
        #retreat-register-form input:not([type="file"]),
        #retreat-waiting-form input,
        .retreat-reg-input {
            display: block;
            width: 100%;
            padding: 10px 12px;
            margin: 6px 0;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
            transition: border 0.3s;
        }

        #retreat-register-form input:not([type="file"]):focus,
        #retreat-waiting-form input:focus,
        .retreat-reg-input:focus {
            outline: none;
            border-color: #6059A6;
        }

        #retreat-register-form button,
        #retreat-waiting-form button {
            display: block;
            width: 100%;
            padding: 16px;
            margin-top: 25px;
            background: linear-gradient(135deg, #C3DDD2, #6059A6);
            color: #fff;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            font-size: 17px;
            font-weight: 700;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(96, 89, 166, 0.3);
        }

        #retreat-register-form button:hover,
        #retreat-waiting-form button:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(96, 89, 166, 0.4);
        }

        .retreat-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            color: #333;
            font-size: 15px;
        }

        /* Select2 Styles for Modal */
        .select2-container {
            width: 100% !important;
            margin-bottom: 6px;
            z-index: 99999;
        }

        .select2-container .select2-selection--single {
            height: 38px !important;
            border: 1px solid #e0e0e0 !important;
            border-radius: 8px !important;
            padding: 0 40px 0 12px !important;
            display: flex !important;
            align-items: center !important;
            background: #fff !important;
            background-image: none !important;
            position: relative !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 38px !important;
            padding-left: 0 !important;
            padding-right: 30px !important;
            display: block !important;
            text-align: left !important;
            color: #333 !important;
        }

        /* Show arrow on the right side */
        .select2-container--default .select2-selection--single .select2-selection__arrow {
            display: block !important;
            height: 20px !important;
            position: absolute !important;
            right: 12px !important;
            top: 50% !important;
            width: 20px !important;
            transform: translateY(-50%) !important;
            background: transparent !important;
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow b {
            border-color: #888 transparent transparent transparent !important;
            border-style: solid !important;
            border-width: 6px 5px 0 5px !important;
            height: 0 !important;
            width: 0 !important;
            left: 50% !important;
            top: 50% !important;
            margin: 0 !important;
            transform: translate(-50%, -50%) !important;
            position: absolute !important;
        }

        /* Remove any extra theme arrows */
        .select2-container--default .select2-selection--single .select2-selection__arrow:before,
        .select2-container--default .select2-selection--single .select2-selection__arrow:after {
            display: none !important;
            content: none !important;
        }

        /* Remove stray arrows/icons inside the text area */
        .select2-container--default .select2-selection--single .select2-selection__rendered:before,
        .select2-container--default .select2-selection--single .select2-selection__rendered:after {
            display: none !important;
            content: none !important;
        }

        /* Hide the clear button (X) */
        .select2-container--default .select2-selection--single .select2-selection__clear {
            display: none !important;
        }

        /* Hide native select arrow */
        select#country-select {
            -webkit-appearance: none !important;
            -moz-appearance: none !important;
            appearance: none !important;
            background-image: none !important;
            background-color: #fff !important;
            text-align: left !important;
            text-align-last: left !important;
        }

        /* Remove focus gradient/outline */
        .select2-container--default.select2-container--focus .select2-selection--single {
            border: 1px solid #6059A6 !important;
            background: #fff !important;
            background-image: none !important;
            outline: none !important;
            box-shadow: none !important;
        }

        .select2-container--default .select2-selection--single:focus {
            background: #fff !important;
            background-image: none !important;
            outline: none !important;
            box-shadow: none !important;
        }

        /* Remove gradient on open/active state */
        .select2-container--default.select2-container--open .select2-selection--single {
            background: #fff !important;
            background-image: none !important;
            border: 1px solid #6059A6 !important;
            outline: none !important;
            box-shadow: none !important;
        }

        /* Arrow rotation when open */
        .select2-container--default.select2-container--open .select2-selection--single .select2-selection__arrow b {
            border-color: transparent transparent #888 transparent !important;
            border-width: 0 5px 6px 5px !important;
        }

        .select2-dropdown {
            border: 1px solid #ccc;
            border-radius: 8px;
            z-index: 99999;
            margin-top: 4px;
        }

        .select2-search--dropdown .select2-search__field {
            border: 1px solid #ccc;
            border-radius: 4px;
            padding: 8px;
        }

        .select2-results__option {
            padding: 10px;
        }

        .select2-container--open .select2-dropdown {
            position: absolute;
            top: 100%;
        }

        #retreat-chat-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.85);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }

        .retreat-chat-close,
        .retreat-terms-close,
        .retreat-questionnaire-close {
            position: absolute;
            top: 20px;
            right: 25px;
            cursor: pointer;
            font-size: 32px;
            font-weight: bold;
            color: #aaa;
            transition: color 0.2s;
            line-height: 1;
        }

        .retreat-chat-close:hover,
        .retreat-terms-close:hover,
        .retreat-questionnaire-close:hover {
            color: #333;
        }

        #join-chat-btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(195, 221, 210, 0.5);
            background: #b3cdc2;
        }

        /* Terms Modal Styles */
        #retreat-terms-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.85);
            z-index: 10001;
            align-items: center;
            justify-content: center;
        }

        #terms-continue-btn:not(:disabled) {
            opacity: 1;
            cursor: pointer;
        }

        #terms-continue-btn:not(:disabled):hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(96, 89, 166, 0.4);
        }

        /* Questionnaire Modal Styles */
        #retreat-questionnaire-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.85);
            z-index: 10002;
            align-items: center;
            justify-content: center;
        }

        .question-slide {
            animation: slideIn 0.4s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(30px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes slideOut {
            from {
                opacity: 1;
                transform: translateX(0);
            }

            to {
                opacity: 0;
                transform: translateX(-30px);
            }
        }

        .question-answer:focus {
            outline: none;
            border-color: #6059A6 !important;
        }

        #q-prev-btn:hover {
            background: #d0d0d0;
        }

        #q-next-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(96, 89, 166, 0.4);
        }

        /* Smooth modal transitions */
        .modal-fade-in {
            animation: modalFadeIn 0.3s ease;
        }

        .modal-fade-out {
            animation: modalFadeOut 0.3s ease;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        @keyframes modalFadeOut {
            from {
                opacity: 1;
            }

            to {
                opacity: 0;
            }
        }

        .retreat-modal-content {
            animation: contentSlideIn 0.3s ease;
        }

        @keyframes contentSlideIn {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Mobile Responsive Styles */
        @media screen and (max-width: 768px) {
            .retreat-modal-content {
                width: 95%;
                padding: 20px 15px;
                margin: 20px auto;
                max-height: 95vh;
            }

            .retreat-modal-content h3 {
                font-size: 20px;
                margin-bottom: 15px;
            }

            /* Stack form grid on mobile */
            #retreat-register-form>div[style*="grid"] {
                display: flex !important;
                flex-direction: column !important;
                gap: 8px !important;
            }

            /* Ensure all inputs fit properly */
            #retreat-register-form input,
            #retreat-register-form select,
            #retreat-waiting-form input {
                width: 100% !important;
                box-sizing: border-box !important;
                font-size: 16px !important;
                /* Prevents zoom on iOS */
            }

            .select2-container {
                width: 100% !important;
                box-sizing: border-box !important;
            }

            /* Adjust close button */
            .retreat-modal-close,
            .retreat-schedule-close,
            .retreat-details-close,
            .retreat-register-close,
            .retreat-terms-close,
            .retreat-questionnaire-close,
            .retreat-chat-close,
            .retreat-waiting-close {
                top: 10px;
                right: 10px;
                font-size: 24px;
            }

            /* Reduce padding for form groups */
            .form-group {
                margin-bottom: 8px !important;
            }

            /* Compact buttons on mobile */
            #retreat-register-form button,
            #retreat-waiting-form button {
                padding: 14px !important;
                font-size: 16px !important;
            }
        }

        @media screen and (max-width: 480px) {
            .retreat-modal-content {
                width: 98%;
                padding: 15px 10px;
                border-radius: 12px;
            }

            .retreat-modal-content h3 {
                font-size: 18px;
            }

            /* Smaller labels on very small screens */
            label {
                font-size: 13px !important;
            }
        }
    </style>

    <script>
        jQuery(document).ready(function($) {
            // ===== GLOBAL STATE =====
            // Using window scope to allow access from card shortcode
            window.selectedRetreatType = window.selectedRetreatType || '';
            window.selectedGroupId = window.selectedGroupId || '';
            window.selectedRetreatData = window.selectedRetreatData || {};
            let registrationData = {};
            let questionAnswers = {};
            const totalQuestions = <?php echo count($questions); ?>;

            // Local references for cleaner code
            let selectedRetreatType = window.selectedRetreatType;
            let selectedGroupId = window.selectedGroupId;
            let selectedRetreatData = window.selectedRetreatData;

            // Sync function to update local from window
            function syncFromWindow() {
                selectedRetreatType = window.selectedRetreatType;
                selectedGroupId = window.selectedGroupId;
                selectedRetreatData = window.selectedRetreatData;
            }

            // Sync function to update window from local
            function syncToWindow() {
                window.selectedRetreatType = selectedRetreatType;
                window.selectedGroupId = selectedGroupId;
                window.selectedRetreatData = selectedRetreatData;
            }

            // ===== REAL-TIME PASSWORD VALIDATION =====
            $('#retreat-confirm-password').on('input', function() {
                const password = $('#retreat-password').val();
                const confirmPassword = $(this).val();
                const errorMsg = $('#password-match-error');

                if (confirmPassword.length > 0) {
                    if (password !== confirmPassword) {
                        errorMsg.show();
                        $(this).css('border-color', '#dc3545');
                    } else {
                        errorMsg.hide();
                        $(this).css('border-color', '#28a745');
                    }
                } else {
                    errorMsg.hide();
                    $(this).css('border-color', '#e0e0e0');
                }
            });

            $('#retreat-password').on('input', function() {
                const password = $(this).val();
                const confirmPassword = $('#retreat-confirm-password').val();
                const errorMsg = $('#password-match-error');

                if (confirmPassword.length > 0) {
                    if (password !== confirmPassword) {
                        errorMsg.show();
                        $('#retreat-confirm-password').css('border-color', '#dc3545');
                    } else {
                        errorMsg.hide();
                        $('#retreat-confirm-password').css('border-color', '#28a745');
                    }
                }
            });

            // ===== STEP 1: TYPE SELECTION MODAL =====
            // Open type selection modal
            $('.book-retreat-btn').on('click', function(e) {
                e.preventDefault();
                $('#retreat-modal').css('display', 'flex').hide().fadeIn(300);
            });

            // Close type selection modal
            $(document).on('click', '.retreat-modal-close', function() {
                $('#retreat-modal').fadeOut(300);
            });

            $(document).on('click', '#retreat-modal', function(e) {
                if (e.target.id === 'retreat-modal') {
                    $(this).fadeOut(300);
                }
            });

            // Select retreat type - go to schedule selection
            $(document).on('click', '.retreat-option', function() {
                selectedRetreatType = $(this).data('type');
                syncToWindow();

                // Close type modal and open schedule modal
                $('#retreat-modal').fadeOut(300, function() {
                    loadSchedules(selectedRetreatType);
                    $('#retreat-schedule-modal').css('display', 'flex').hide().fadeIn(300);
                });
            });

            // ===== STEP 2: SCHEDULE SELECTION MODAL =====
            function loadSchedules(type) {
                $('#schedule-list').html('<div style="text-align:center;padding:30px;color:#666;">Loading available retreats...</div>');

                $.post(RETREAT_AJAX.url, {
                    action: 'get_retreat_schedules',
                    retreat_type: type,
                    nonce: RETREAT_AJAX.nonce
                }, function(response) {
                    if (response.success && response.data.schedules && response.data.schedules.length > 0) {
                        let html = '';
                        response.data.schedules.forEach(function(retreat, index) {
                            html += `
                                <div class="schedule-card" data-group-id="${retreat.group_id}" style="display:flex;align-items:center;gap:20px;">
                                    <div style="width:50px;height:50px;background:linear-gradient(135deg, #C3DDD2, #6059A6);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                                        <span style="color:#fff;font-size:20px;font-weight:700;">${index + 1}</span>
                                    </div>
                                    <div style="flex:1;">
                                        <h4 style="margin:0 0 8px 0;color:#6059A6;font-size:18px;font-weight:600;">${retreat.date_range_label || 'Dates TBA'}</h4>
                                        <p style="margin:0;color:#666;font-size:14px;">📍 ${retreat.location || 'Location TBA'}</p>
                                    </div>
                                </div>
                            `;
                        });
                        $('#schedule-list').html(html);
                    } else {
                        // No retreats available - show waiting list option
                        $('#schedule-list').html(`
                            <div style="text-align:center;padding:30px;">
                                <p style="color:#666;font-size:16px;margin-bottom:20px;">No retreats are currently available for this category.</p>
                                <button class="join-waiting-list-btn" data-type="${type}" style="padding:14px 30px;background:linear-gradient(135deg, #C3DDD2, #6059A6);color:#fff;border:none;border-radius:10px;font-size:16px;font-weight:600;cursor:pointer;">
                                    Join Waiting List
                                </button>
                            </div>
                        `);
                    }
                });
            }

            // Schedule card click - go to details
            $(document).on('click', '.schedule-card', function() {
                selectedGroupId = $(this).data('group-id');
                syncToWindow();
                loadRetreatDetails(selectedGroupId);

                $('#retreat-schedule-modal').fadeOut(300, function() {
                    $('#retreat-details-modal').css('display', 'flex').hide().fadeIn(300);
                });
            });

            // Back button from schedule to type
            $(document).on('click', '#schedule-back-btn', function() {
                $('#retreat-schedule-modal').fadeOut(300, function() {
                    $('#retreat-modal').css('display', 'flex').hide().fadeIn(300);
                });
            });

            // Close schedule modal
            $(document).on('click', '.retreat-schedule-close', function() {
                $('#retreat-schedule-modal').fadeOut(300);
            });

            $(document).on('click', '#retreat-schedule-modal', function(e) {
                if (e.target.id === 'retreat-schedule-modal') {
                    $(this).fadeOut(300);
                }
            });

            // ===== STEP 3: RETREAT DETAILS MODAL =====
            function loadRetreatDetails(groupId) {
                $.post(RETREAT_AJAX.url, {
                    action: 'get_retreat_details',
                    group_id: groupId,
                    retreat_type: selectedRetreatType,
                    nonce: RETREAT_AJAX.nonce
                }, function(response) {
                    if (response.success) {
                        selectedRetreatData = response.data;
                        syncToWindow();
                        let d = response.data;

                        // Populate cover image
                        if (d.cover_image) {
                            $('#retreat-cover-image').attr('src', d.cover_image).show();
                            $('#retreat-cover-placeholder').hide();
                        } else {
                            $('#retreat-cover-image').hide();
                            $('#retreat-cover-placeholder').show();
                        }

                        // Populate text fields
                        $('#retreat-type-badge').text(selectedRetreatType.charAt(0).toUpperCase() + selectedRetreatType.slice(1) + ' Retreat');
                        $('#retreat-title').text(d.group_title || d.title || '');
                        $('#retreat-description').text(d.description || '');
                        $('#retreat-dates').text(d.date_range || 'TBA');
                        $('#retreat-location').text(d.location || 'TBA');

                        // Price
                        let priceText = d.price_sar ? d.price_sar + ' SAR' : 'Contact for price';
                        $('#retreat-price').text(priceText);
                        $('#retreat-price-usd').text(d.price_usd ? '$' + d.price_usd + ' / person' : '');

                        // Package includes
                        if (d.package_items && d.package_items.length > 0) {
                            let listHtml = '';
                            d.package_items.forEach(function(item) {
                                listHtml += `<li style="padding:2px 0;display:flex;align-items:center;gap:8px;">
                                    <span style="color:#C3DDD2;font-size:16px;">✓</span>
                                    <span style="color:#333;font-size:14px;">${item}</span>
                                </li>`;
                            });
                            $('#retreat-package-list').html(listHtml);
                            $('#package-includes-section').show();
                        } else {
                            $('#package-includes-section').hide();
                        }
                    } else {
                        alert('Failed to load retreat details');
                    }
                });
            }

            // Proceed to registration (from Book Your Spot button)
            $(document).on('click', '#book-spot-btn', function() {
                // Sync from window in case values were set by card shortcode
                syncFromWindow();

                $('#retreat-details-modal').fadeOut(300, function() {
                    // Pre-fill hidden fields in registration form
                    $('#reg_retreat_type').val(selectedRetreatType || window.selectedRetreatType);
                    $('#reg_group_id').val(selectedGroupId || window.selectedGroupId);
                    $('#retreat-register-modal').css('display', 'flex').hide().fadeIn(300);

                    // Initialize Select2 for country dropdown
                    initCountrySelect2();
                });
            });

            // Back from details to schedule
            $(document).on('click', '#details-back-btn', function() {
                $('#retreat-details-modal').fadeOut(300, function() {
                    $('#retreat-schedule-modal').css('display', 'flex').hide().fadeIn(300);
                });
            });

            // Close details modal
            $(document).on('click', '.retreat-details-close', function() {
                $('#retreat-details-modal').fadeOut(300);
            });

            $(document).on('click', '#retreat-details-modal', function(e) {
                if (e.target.id === 'retreat-details-modal') {
                    $(this).fadeOut(300);
                }
            });

            // ===== STEP 4: REGISTRATION MODAL =====
            function initCountrySelect2() {
                setTimeout(function() {
                    if (typeof $.fn.select2 !== 'undefined') {
                        $('#reg-country-select').select2({
                            placeholder: 'Select Country',
                            allowClear: false,
                            width: '100%',
                            dropdownParent: $('#retreat-register-modal'),
                            dropdownAutoWidth: false
                        });
                        $('#reg-country-select').on('select2:open', function() {
                            $('.select2-dropdown').css({
                                'z-index': 10020,
                                'max-width': '550px',
                                'width': '100%'
                            });
                        });
                    }
                }, 300);
            }

            // Terms checkbox handler
            $('#agree-terms-reg').on('change', function() {
                if ($(this).is(':checked')) {
                    $('#reg-submit-btn').prop('disabled', false).css('opacity', '1');
                } else {
                    $('#reg-submit-btn').prop('disabled', true).css('opacity', '0.5');
                }
            });

            // Open Terms modal from registration
            $(document).on('click', '#open-terms-link', function(e) {
                e.preventDefault();
                $('#retreat-terms-modal').css('display', 'flex').hide().fadeIn(300);
            });

            // Close Terms modal
            $(document).on('click', '.retreat-terms-close, #terms-close-btn', function() {
                $('#retreat-terms-modal').fadeOut(300);
            });

            $(document).on('click', '#retreat-terms-modal', function(e) {
                if (e.target.id === 'retreat-terms-modal') {
                    $(this).fadeOut(300);
                }
            });

            // Passport file upload handler - file input change
            $(document).on('change', '#passport-input', function() {
                const file = this.files[0];
                if (file) {
                    $('#passport-filename').text('Selected: ' + file.name).show();
                    $('#passport-dropzone').css('border-color', '#6059A6');
                }
            });

            // Prevent passport dropzone click from closing modal
            $(document).on('click', '#passport-dropzone', function(e) {
                e.stopPropagation();
            });

            // Handle drag and drop
            $('#passport-dropzone').on('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).css('border-color', '#6059A6');
            });

            $('#passport-dropzone').on('dragleave', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).css('border-color', '#d0d0d0');
            });

            $('#passport-dropzone').on('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                $(this).css('border-color', '#6059A6');
                const files = e.originalEvent.dataTransfer.files;
                if (files.length > 0) {
                    $('#passport-input')[0].files = files;
                    $('#passport-filename').text('Selected: ' + files[0].name).show();
                }
            });

            // Registration form submission - go to questionnaire
            $(document).on('submit', '#retreat-register-form', function(e) {
                e.preventDefault();

                if (!$('#agree-terms-reg').is(':checked')) {
                    alert('Please agree to the Terms & Conditions');
                    return;
                }

                // Validate passwords match
                const password = $('#retreat-password').val();
                const confirmPassword = $('#retreat-confirm-password').val();
                const errorMsg = $('#password-match-error');

                if (password !== confirmPassword) {
                    errorMsg.show();
                    $('#retreat-confirm-password').css('border-color', '#dc3545');
                    return;
                } else {
                    errorMsg.hide();
                    $('#retreat-confirm-password').css('border-color', '#e0e0e0');
                }

                if (password.length < 8) {
                    alert('Password must be at least 8 characters');
                    return;
                }

                // Store form data
                registrationData = new FormData(this);
                registrationData.append('action', 'register_retreat_user');
                registrationData.append('nonce', RETREAT_AJAX.nonce);

                // Close registration modal and open questionnaire
                $('#retreat-register-modal').fadeOut(300, function() {
                    questionAnswers = {};
                    $('#retreat-questionnaire-modal').css('display', 'flex').hide().fadeIn(300);
                });
            });

            // Back from registration to details
            $(document).on('click', '#register-back-btn', function() {
                $('#retreat-register-modal').fadeOut(300, function() {
                    $('#retreat-details-modal').css('display', 'flex').hide().fadeIn(300);
                });
            });

            // Close registration modal
            $(document).on('click', '.retreat-register-close', function() {
                $('#retreat-register-modal').fadeOut(300);
            });

            $(document).on('click', '#retreat-register-modal', function(e) {
                if (e.target.id === 'retreat-register-modal') {
                    $(this).fadeOut(300);
                }
            });

            // ===== STEP 5: QUESTIONNAIRE MODAL (Single Page) =====
            // Submit questionnaire - complete registration
            $(document).on('click', '#submit-questionnaire-btn', function() {
                // Collect all answers
                let allAnswered = true;
                $('.q-answer').each(function() {
                    const qIndex = $(this).data('question');
                    if ($(this).is('textarea')) {
                        questionAnswers[qIndex] = $(this).val();
                        if (!$(this).val().trim()) allAnswered = false;
                    } else if ($(this).is('input[type="radio"]:checked')) {
                        questionAnswers[qIndex] = $(this).val();
                    }
                });

                // Check radio groups
                for (let i = 0; i < totalQuestions; i++) {
                    if (questionAnswers[i] === undefined || questionAnswers[i] === '') {
                        allAnswered = false;
                        break;
                    }
                }

                if (!allAnswered) {
                    alert('Please answer all questions before submitting.');
                    return;
                }

                const btn = $(this);
                btn.prop('disabled', true).text('Submitting...');

                registrationData.append('questionnaire_answers', JSON.stringify(questionAnswers));

                $.ajax({
                    url: RETREAT_AJAX.url,
                    type: 'POST',
                    data: registrationData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            $('#retreat-questionnaire-modal').fadeOut(300, function() {
                                showChatPopup(
                                    response.data.chat_link,
                                    response.data.private_channel_link,
                                    response.data.suggested_nickname,
                                    response.data.retreat_dates,
                                    response.data.trip_destination,
                                    response.data.trip_dates,
                                    response.data.retreat_type,
                                    response.data.chat_join_token,
                                    response.data.user_id
                                );
                            });
                        } else {
                            alert(response.data.message || 'Registration failed');
                            btn.prop('disabled', false).text('Submit Information');
                        }
                    },
                    error: function() {
                        alert('An error occurred. Please try again.');
                        btn.prop('disabled', false).text('Submit Information');
                    }
                });
            });

            // Close questionnaire modal
            $(document).on('click', '.retreat-questionnaire-close', function() {
                if (confirm('Are you sure you want to exit? Your progress will be lost.')) {
                    $('#retreat-questionnaire-modal').fadeOut(300, function() {
                        location.reload();
                    });
                }
            });

            $(document).on('click', '#retreat-questionnaire-modal', function(e) {
                if (e.target.id === 'retreat-questionnaire-modal') {
                    if (confirm('Are you sure you want to exit? Your progress will be lost.')) {
                        $(this).fadeOut(300, function() {
                            location.reload();
                        });
                    }
                }
            });

            // ===== STEP 6: SUCCESS MODAL =====
            function showChatPopup(chatLink, privateChannelLink, suggestedNickname, retreatDates, tripDestination, tripDates, retreatType, chatJoinToken, userId) {
                // Simply show the success modal
                $('#retreat-chat-modal').css('display', 'flex').hide().fadeIn(300);
            }

            $('#finish-setup-btn').on('click', function() {
                $('#retreat-chat-modal').fadeOut(300, function() {
                    location.reload();
                });
            });

            $(document).on('click', '.retreat-chat-close', function() {
                $('#retreat-chat-modal').fadeOut(300, function() {
                    location.reload();
                });
            });

            $(document).on('click', '#retreat-chat-modal', function(e) {
                if (e.target.id === 'retreat-chat-modal') {
                    $(this).fadeOut(300, function() {
                        location.reload();
                    });
                }
            });

            // ===== WAITING LIST FLOW =====
            $(document).on('click', '.join-waiting-list-btn', function() {
                selectedRetreatType = $(this).data('type');
                $('#waiting_retreat_type').val(selectedRetreatType);

                $('#retreat-schedule-modal').fadeOut(300, function() {
                    $('#retreat-waiting-modal').css('display', 'flex').hide().fadeIn(300);
                });
            });

            $(document).on('submit', '#retreat-waiting-form', function(e) {
                e.preventDefault();
                let formData = $(this).serialize();
                formData += '&action=join_retreat_waiting_list&nonce=' + RETREAT_AJAX.nonce;

                $(this).find('button').prop('disabled', true).text('Submitting...');

                $.post(RETREAT_AJAX.url, formData, function(response) {
                    if (response.success) {
                        $('#retreat-waiting-modal .retreat-modal-content').html(`
                            <div style="text-align:center;padding:30px;">
                                <div style="width:70px;height:70px;background:#d4edda;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
                                    <span style="font-size:32px;">✓</span>
                                </div>
                                <h3 style="color:#28a745;margin-bottom:15px;">Added to Waiting List!</h3>
                                <p style="color:#666;font-size:15px;">We'll notify you when a spot opens up.</p>
                            </div>
                        `);
                        setTimeout(function() {
                            $('#retreat-waiting-modal').fadeOut(300, function() {
                                location.reload();
                            });
                        }, 3000);
                    } else {
                        alert(response.data.message || 'Failed to join waiting list');
                        $('#retreat-waiting-form button').prop('disabled', false).text('Join Waiting List');
                    }
                });
            });

            $(document).on('click', '.retreat-waiting-close', function() {
                $('#retreat-waiting-modal').fadeOut(300);
            });

            $(document).on('click', '#retreat-waiting-modal', function(e) {
                if (e.target.id === 'retreat-waiting-modal') {
                    $(this).fadeOut(300);
                }
            });
        });
    </script>

<?php
});

// --------------------------------------------------
// SHORTCODE: RETREAT SLOT BOOKING PAGE (Inactive)
// --------------------------------------------------
add_shortcode('retreat_slot_booking', function () {
    ob_start();
    echo '<div style="text-align:center;padding:50px;"><h2 style="color:#6059A6;">Feature Currently Unavailable</h2><p>The slot booking feature is temporarily unavailable. Please contact support for assistance with your retreat booking.</p></div>';
    return ob_get_clean();
});

// --------------------------------------------------
// SHORTCODE: RETREAT EVENT CARDS (3 Cards Display)
// --------------------------------------------------
add_shortcode('retreat_event_cards', 'render_retreat_event_cards');

function render_retreat_event_cards()
{
    $is_ar = retreat_is_arabic_locale();

    // Get gender settings for all three types
    $gender_types = ['male', 'female', 'teen'];
    $all_settings = [];

    $fallback_titles = [
        'male' => retreat_translate('Male Wellness Retreat', 'الملاذ العلاجي للرجال'),
        'female' => retreat_translate('Female Wellness Retreat', 'الملاذ العلاجي للسيدات'),
        'teen' => retreat_translate('Teen Wellness Retreat', 'الملاذ العلاجي لليافعين'),
    ];

    $card_strings = [
        'select_schedule_label' => retreat_translate('Select Schedule:', 'اختر المواعيد:'),
        'choose_date' => retreat_translate('Choose a date', 'اختر تاريخاً'),
        'schedule_above' => retreat_translate('Select a schedule above', 'اختر موعداً من القائمة أعلاه'),
        'no_schedules' => retreat_translate('No schedules available', 'لا توجد جداول متاحة حالياً'),
        'location_label' => retreat_translate('Location:', 'الموقع:'),
        'price_label' => retreat_translate('Price:', 'السعر:'),
        'tba' => retreat_translate('TBA', 'لاحقاً'),
        'select_schedule' => retreat_translate('Select schedule', 'اختر موعداً'),
        'contact_price' => retreat_translate('Contact for price', 'تواصل لمعرفة السعر'),
        'book_now' => retreat_translate('Book Now', 'احجز الآن'),
        'coming_soon' => retreat_translate('Groups coming soon', 'المجموعات قادمة قريباً'),
        'alert_select_schedule' => retreat_translate('Please select a schedule first', 'يرجى اختيار موعد أولاً'),
        'load_error' => retreat_translate('Failed to load retreat details', 'تعذر تحميل تفاصيل الرحلة'),
        'sar_suffix' => retreat_translate(' SAR', ' ر.س'),
        'usd_suffix' => retreat_translate(' / person', ' / للشخص'),
    ];

    foreach ($gender_types as $type) {
        $settings = function_exists('get_retreat_gender_settings') ? get_retreat_gender_settings($type) : null;
        $all_settings[$type] = $settings ?: [
            'gender_type' => $type,
            'cover_image_url' => '',
            'group_title' => $fallback_titles[$type] ?? retreat_translate('Retreat', 'الرحلة')
        ];
    }

    // Get schedules for each type
    $schedules_by_type = [];
    foreach ($gender_types as $type) {
        $groups = get_posts([
            'post_type' => 'retreat_group',
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => [
                ['key' => 'retreat_type', 'value' => $type],
            ],
            'orderby' => 'meta_value',
            'meta_key' => 'start_date',
            'order' => 'ASC',
        ]);

        $schedules = [];
        foreach ($groups as $group) {
            $capacity = intval(get_field('max_participants', $group->ID)) ?: 12;
            $current_members = count_retreat_group_members($group->ID);
            $available_spots = $capacity - $current_members;

            if ($available_spots > 0) {
                $start_date = get_field('start_date', $group->ID);
                $end_date = get_field('end_date', $group->ID);
                $location = get_field('trip_destination', $group->ID);
                $price_sar = get_field('retreat_price_sar', $group->ID);

                $date_label = retreat_format_date_range($start_date, $end_date);
                if (!$date_label) {
                    $start_obj = retreat_parse_date_value($start_date);
                    $date_label = $start_obj ? $start_obj->format('F j, Y') : retreat_translate('Date TBA', 'سيتم تحديد التاريخ لاحقاً');
                }

                $schedules[] = [
                    'group_id' => $group->ID,
                    'date_label' => $date_label,
                    'location' => $location ?: retreat_translate('Location TBA', 'سيتم تحديد الموقع لاحقاً'),
                    'price_sar' => $price_sar ?: '',
                    'available_spots' => $available_spots
                ];
            }
        }
        $schedules_by_type[$type] = $schedules;
    }

    // Badge labels and icons
    $badge_config = [
        'male' => [
            'label' => retreat_translate('Male Group', 'مجموعة الرجال'),
            'icon' => '👨',
            'color' => '#6059A6',
            'type_label' => retreat_translate('Male Retreat', 'رحلة الرجال')
        ],
        'female' => [
            'label' => retreat_translate('Female Group', 'مجموعة السيدات'),
            'icon' => '👩',
            'color' => '#C3DDD2',
            'type_label' => retreat_translate('Female Retreat', 'رحلة السيدات')
        ],
        'teen' => [
            'label' => retreat_translate('Teen Group', 'مجموعة اليافعين'),
            'icon' => '🧑‍🎓',
            'color' => '#8B7DC9',
            'type_label' => retreat_translate('Teen Retreat', 'رحلة اليافعين')
        ]
    ];

    $type_label_map = [];
    foreach ($badge_config as $type => $config) {
        $type_label_map[$type] = $config['type_label'];
    }

    ob_start();
?>
    <style>
        .retreat-sections-container {
            display: flex;
            flex-direction: column;
            gap: 30px;
            padding: 20px 0 10px;
        }

        .retreat-section {
            background: #fff;
            padding: 5%;
            border-radius: 22px;
            box-shadow: 0 10px 30px rgba(96, 89, 166, 0.1);
            overflow: hidden;
        }

        .retreat-section-inner {
            display: flex;
            gap: 30px;
            padding: 30px;
            align-items: stretch;
        }

        .retreat-section.is-reversed .retreat-section-inner {
            flex-direction: row-reverse;
        }

        .retreat-section-content,
        .retreat-section-image {
            flex: 1;
            min-width: 0;
        }

        .retreat-section-image {
            display: flex;
            align-items: stretch;
        }

        .retreat-section-image img,
        .retreat-image-placeholder {
            width: 100%;
            border-radius: 18px;
            object-fit: cover;
            min-height: 280px;
            max-height: 360px;
            background: linear-gradient(135deg, #C3DDD2, #6059A6);
        }

        .retreat-image-placeholder {
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 64px;
            opacity: 0.65;
        }

        .retreat-section-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 12px;
        }

        .retreat-section-badge.male {
            background: rgba(96, 89, 166, 0.12);
            color: #6059A6;
        }

        .retreat-section-badge.female {
            background: rgba(195, 221, 210, 0.3);
            color: #4a8b6f;
        }

        .retreat-section-badge.teen {
            background: rgba(139, 125, 201, 0.15);
            color: #8B7DC9;
        }

        .retreat-section-title {
            font-size: 26px;
            font-weight: 700;
            color: #2f2f2f;
            margin: 0 0 12px;
            line-height: 1.3;
        }

        .retreat-section-desc {
            color: #555;
            font-size: 15px;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .retreat-schedule-card {
            background: #f8f7fb;
            border-radius: 16px;
            padding: 18px;
            border: 1px solid rgba(96, 89, 166, 0.08);
        }

        .retreat-schedule-card-title {
            font-weight: 700;
            color: #3b3b3b;
            margin-bottom: 12px;
            font-size: 16px;
        }

        .retreat-date-buttons {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .retreat-date-button {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            text-align: left;
            padding: 12px 16px;
            border-radius: 18px;
            border: none;
            background: linear-gradient(135deg, #6059A6 0%, #C3DDD2 100%);
            font-size: 14px;
            font-weight: 600;
            color: #ffffff;
            cursor: pointer;
            transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
        }

        .retreat-date-button:hover,
        .retreat-date-button.is-active {
            box-shadow: 0 8px 20px rgba(96, 89, 166, 0.2);
            transform: translateY(-3px);
            filter: saturate(1.05) brightness(1.02);
        }

        .retreat-date-icon {
            font-size: 16px;
            opacity: 0.9;
            flex-shrink: 0;
        }

        .retreat-coming-soon {
            text-align: center;
            color: #6059A6;
            font-size: 14px;
            font-weight: 600;
            padding: 10px;
            background: rgba(96, 89, 166, 0.08);
            border-radius: 10px;
        }

        .retreat-lang-ar .retreat-date-button {
            text-align: right;
            flex-direction: row-reverse;
        }

        @media (max-width: 992px) {
            .retreat-section-inner {
                flex-direction: column;
                padding: 24px;
            }

            .retreat-section.is-reversed .retreat-section-inner {
                flex-direction: column;
            }

            .retreat-section-image img,
            .retreat-image-placeholder {
                max-height: 320px;
            }
        }

        @media (max-width: 600px) {
            .retreat-section-title {
                font-size: 22px;
            }
        }
    </style>

    <div class="retreat-sections-container<?php echo $is_ar ? ' retreat-lang-ar' : ''; ?>" <?php echo $is_ar ? 'dir="rtl"' : ''; ?>>
        <?php foreach ($gender_types as $type):
            $settings = $all_settings[$type];
            $schedules = $schedules_by_type[$type];
            $has_schedules = !empty($schedules);
            $badge = $badge_config[$type];
            $subtitle = $settings['group_subtitle'] ?? '';
        ?>
            <section class="retreat-section <?php echo $type === 'female' ? 'is-reversed' : ''; ?>" data-type="<?php echo esc_attr($type); ?>">
                <div class="retreat-section-inner">
                    <div class="retreat-section-content">
                        <span class="retreat-section-badge <?php echo esc_attr($type); ?>">
                            <span><?php echo $badge['icon']; ?></span>
                            <?php echo esc_html($badge['label']); ?>
                        </span>

                        <h2 class="retreat-section-title"><?php echo esc_html($settings['group_title'] ?: ($fallback_titles[$type] ?? '')); ?></h2>
                        <p class="retreat-section-desc"><?php echo esc_html($subtitle ?: retreat_translate('A restorative retreat experience tailored to your group.', 'تجربة ملاذ مميزة ومصممة خصيصًا لفئتكم.')); ?></p>

                        <div class="retreat-schedule-card">
                            <div class="retreat-schedule-card-title"><?php echo esc_html(retreat_translate('Book Your Schedule', 'احجز موعدك')); ?></div>
                            <?php if ($has_schedules): ?>
                                <div class="retreat-date-buttons">
                                    <?php foreach ($schedules as $schedule): ?>
                                        <button class="retreat-date-button" type="button"
                                            data-type="<?php echo esc_attr($type); ?>"
                                            data-group-id="<?php echo esc_attr($schedule['group_id']); ?>"
                                            data-date="<?php echo esc_attr($schedule['date_label']); ?>">
                                            <?php echo esc_html($schedule['date_label']); ?>
                                            <span class="retreat-date-icon" aria-hidden="true">📅</span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="retreat-coming-soon"><?php echo esc_html($card_strings['no_schedules']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="retreat-section-image">
                        <?php if (!empty($settings['cover_image_url'])): ?>
                            <img src="<?php echo esc_url($settings['cover_image_url']); ?>" alt="<?php echo esc_attr($settings['group_title']); ?>">
                        <?php else: ?>
                            <div class="retreat-image-placeholder">🏝️</div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        <?php endforeach; ?>
    </div>

    <script>
        jQuery(document).ready(function($) {
            const cardStrings = <?php echo wp_json_encode([
                                    'tba' => $card_strings['tba'],
                                    'contactPrice' => $card_strings['contact_price'],
                                    'loadError' => $card_strings['load_error'],
                                ]); ?>;
            const sarSuffix = '<?php echo esc_js($card_strings['sar_suffix']); ?>';
            const usdSuffix = '<?php echo esc_js($card_strings['usd_suffix']); ?>';
            const typeLabels = <?php echo wp_json_encode($type_label_map); ?>;

            function openRetreatModal(type, groupId, buttonEl) {
                if (!groupId || typeof RETREAT_AJAX === 'undefined') {
                    return;
                }

                $('.retreat-date-button').removeClass('is-active');
                if (buttonEl) {
                    $(buttonEl).addClass('is-active');
                }

                $.post(RETREAT_AJAX.url, {
                    action: 'get_retreat_details',
                    group_id: groupId,
                    retreat_type: type,
                    nonce: RETREAT_AJAX.nonce
                }, function(response) {
                    if (response.success) {
                        const d = response.data;

                        if (d.cover_image) {
                            $('#retreat-cover-image').attr('src', d.cover_image).show();
                            $('#retreat-cover-placeholder').hide();
                        } else {
                            $('#retreat-cover-image').hide();
                            $('#retreat-cover-placeholder').show();
                        }

                        $('#retreat-type-badge').text(typeLabels[type] || '');
                        $('#retreat-title').text(d.group_title || d.title || '');
                        $('#retreat-description').text(d.description || '');
                        $('#retreat-dates').text(d.date_range || cardStrings.tba);
                        $('#retreat-location').text(d.location || cardStrings.tba);

                        const priceText = d.price_sar ? d.price_sar + sarSuffix : cardStrings.contactPrice;
                        $('#retreat-price').text(priceText);
                        $('#retreat-price-usd').text(d.price_usd ? '$' + d.price_usd + usdSuffix : '');

                        if (d.package_items && d.package_items.length > 0) {
                            let listHtml = '';
                            d.package_items.forEach(function(item) {
                                listHtml += `<li style="padding:4px 0;display:flex;align-items:center;gap:8px;">
                                    <span style="color:#C3DDD2;font-size:16px;">✓</span>
                                    <span style="color:#333;font-size:14px;">${item}</span>
                                </li>`;
                            });
                            $('#retreat-package-list').html(listHtml);
                            $('#package-includes-section').show();
                        } else {
                            $('#package-includes-section').hide();
                        }

                        window.selectedRetreatType = type;
                        window.selectedGroupId = groupId;
                        window.selectedRetreatData = d;

                        $('#retreat-details-modal').css('display', 'flex').hide().fadeIn(300);
                    } else {
                        alert(cardStrings.loadError);
                    }
                });
            }

            $(document).on('click', '.retreat-date-button', function(e) {
                e.preventDefault();
                const type = $(this).data('type');
                const groupId = $(this).data('group-id');
                openRetreatModal(type, groupId, this);
            });

            $(document).on('click', '#book-spot-btn', function() {
                if (window.selectedRetreatType && window.selectedGroupId) {
                    $('#reg_retreat_type').val(window.selectedRetreatType);
                    $('#reg_group_id').val(window.selectedGroupId);
                }
            });
        });
    </script>
<?php
    return ob_get_clean();
}
