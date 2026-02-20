<?php

/**
 * Tanafs Academy System
 * Complete management system for professional training programs
 * 
 * Features:
 * - Program management for Phase 1 (3 programs)
 * - Schedule creation with Zoom integration
 * - User registration and tracking
 * - Admin dashboard for schedule and notification management
 * - Email notifications to registered users
 */

add_filter('wp_mail_content_type', function () {
    return 'text/html';
});

// ==========================================
// LANGUAGE SUPPORT (Arabic & English)
// ==========================================

function academy_is_arabic()
{
    // Check if current page URL contains /ar/ or has RTL direction
    $current_url = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($current_url, '/ar/') !== false) {
        return true;
    }
    // Check WordPress locale
    $locale = get_locale();
    if (strpos($locale, 'ar') !== false) {
        return true;
    }
    return false;
}

function academy_get_text($key)
{
    $is_arabic = academy_is_arabic();

    $translations = [
        // Program Names (from client data.md)
        'program_1_name' => [
            'en' => 'Foundation in Group Therapy',
            'ar' => 'الدورة التأسيسية في العلاج الجماعي'
        ],
        'program_1_desc' => [
            'en' => 'This course covers the essential foundations of group therapy, including core principles, professional ethics, and therapeutic relationships.',
            'ar' => 'تغطي هذه المرحلة الأسس الجوهرية للعلاج الجماعي، بما في ذلك المبادئ الأساسية للعلاج الجماعي، والأخلاقيات المهنية، وكل ما يتعلق بالعلاقة العلاجية بين المختص والمشاركين في المجموعة.'
        ],
        'program_1_audience' => [
            'en' => 'Professionals wishing to build a professional foundation in group therapy',
            'ar' => 'المختصون الراغبون في بناء أساس مهني في العلاج الجماعي'
        ],
        'program_2_name' => [
            'en' => 'Ethics and Cultural Sensitivity in Group Therapy',
            'ar' => 'الأخلاقيات المهنية والحساسية الثقافية في العلاج الجماعي'
        ],
        'program_2_desc' => [
            'en' => 'Focuses on professional ethics, cultural sensitivity, and ethical practice in group therapy settings.',
            'ar' => 'تركّز على بناء الوعي الأخلاقي، وترسيخ الحدود المهنية، وفهم ديناميكيات المجموعة، بما يضمن ممارسة علاجية آمنة ومنظمة ضمن إطار علاجي واضح.'
        ],
        'program_2_audience' => [
            'en' => 'All practitioners in the field of group therapy and counseling',
            'ar' => 'جميع الممارسين في مجال العلاج والإرشاد الجماعي'
        ],
        'program_3_name' => [
            'en' => 'Creative Group Therapy – Using Different Arts',
            'ar' => 'العلاج الجماعي بالإبداع'
        ],
        'program_3_desc' => [
            'en' => 'Utilizes various art-based modalities as therapeutic tools, facilitated by trained professionals.',
            'ar' => 'استخدام الفنون المختلفة كأدوات علاجية (بإشراف مختصين)'
        ],
        'program_3_audience' => [
            'en' => 'Anyone interested (professionals, counselors, and trainers)',
            'ar' => 'عامة المهتمين (مختصون، مرشدون، ومدربون)'
        ],

        // UI Labels
        'phase_1' => ['en' => 'Phase 1 – Fundamentals of Group Therapy', 'ar' => 'المرحلة الأولى - أساسيات العلاج الجماعي'],
        'phase_subtitle' => [
            'en' => 'This phase covers the essential foundations of group therapy, including core principles, professional ethics, and therapeutic relationships.',
            'ar' => 'تغطي هذه المرحلة الأسس الجوهرية للعلاج الجماعي، بما في ذلك المبادئ الأساسية للعلاج الجماعي، والأخلاقيات المهنية، وكل ما يتعلق بالعلاقة العلاجية بين المختص والمشاركين في المجموعة.'
        ],
        'duration_label' => ['en' => 'Duration', 'ar' => 'المدة'],
        'schedule_label' => ['en' => 'Schedule', 'ar' => 'الجدول'],
        'hour' => ['en' => 'hour', 'ar' => 'ساعة'],
        'hours' => ['en' => 'hours', 'ar' => 'ساعات'],
        'hours_weekly_duration' => ['en' => 'hours weekly for', 'ar' => 'ساعات أسبوعيًا لمدة'],
        'total_hours' => ['en' => 'Total Hours', 'ar' => 'إجمالي الساعات'],
        'weeks' => ['en' => 'Weeks', 'ar' => 'أسابيع'],
        'weeks_singular' => ['en' => 'week', 'ar' => 'أسبوع'],
        'hours_per_week' => ['en' => 'hours/week', 'ar' => 'ساعات/أسبوع'],
        'max_participants' => ['en' => 'Max', 'ar' => 'الحد الأقصى'],
        'participants_count' => ['en' => 'Participants', 'ar' => 'عدد المشاركين'],
        'participants' => ['en' => 'participants', 'ar' => 'مشاركًا'],
        'delivery_mode' => ['en' => 'Delivery Mode', 'ar' => 'طريقة التقديم'],
        'target_audience' => ['en' => 'Target Audience', 'ar' => 'الفئة المستهدفة'],
        'curriculum' => ['en' => 'Curriculum', 'ar' => 'المنهج'],
        'register_now' => ['en' => 'Register Now', 'ar' => 'سجل الآن'],
        'view_details' => ['en' => 'View Details & Register', 'ar' => 'عرض التفاصيل والتسجيل'],
        'online' => ['en' => 'Online', 'ar' => 'عن بُعد (أونلاين)'],
        'in_person' => ['en' => 'In-person', 'ar' => 'حضوري'],
        'online_in_person' => ['en' => 'Online – In-person', 'ar' => 'عن بُعد (أونلاين) – حضوري'],

        // Modal Labels
        'register_for_program' => ['en' => 'Register for Program', 'ar' => 'التسجيل في البرنامج'],
        'full_name' => ['en' => 'Full Name', 'ar' => 'الاسم الكامل'],
        'email' => ['en' => 'Email', 'ar' => 'البريد الإلكتروني'],
        'phone' => ['en' => 'Phone', 'ar' => 'الهاتف'],
        'job_title' => ['en' => 'Job Title', 'ar' => 'المسمى الوظيفي'],
        'license_number' => ['en' => 'Medical License Number', 'ar' => 'رقم الترخيص الطبي'],
        'country' => ['en' => 'Country', 'ar' => 'البلد'],
        'select_country' => ['en' => 'Select Country', 'ar' => 'اختر البلد'],
        'submit_registration' => ['en' => 'Submit Registration', 'ar' => 'إرسال التسجيل'],
        'required_field' => ['en' => '*', 'ar' => '*'],

        // Details Modal
        'program_details' => ['en' => 'Program Details', 'ar' => 'تفاصيل البرنامج'],
        'total_duration' => ['en' => 'Total Duration', 'ar' => 'المدة الإجمالية'],
        'program_length' => ['en' => 'Program Length', 'ar' => 'مدة البرنامج'],
        'weekly_schedule' => ['en' => 'Weekly Schedule', 'ar' => 'الجدول الأسبوعي'],
        'maximum_participants' => ['en' => 'Maximum Participants', 'ar' => 'الحد الأقصى للمشاركين'],

        // Email Translations
        'email_welcome' => ['en' => 'Welcome to Tanafs Academy!', 'ar' => 'مرحبًا بك في أكاديمية تنفس!'],
        'email_dear' => ['en' => 'Dear', 'ar' => 'عزيزي/عزيزتي'],
        'email_thank_you' => [
            'en' => 'Thank you for registering for',
            'ar' => 'شكرًا لتسجيلك في'
        ],
        'email_excited' => [
            'en' => "We're excited to have you join our professional training program. You will receive session schedules and Zoom links as they become available.",
            'ar' => 'يسعدنا انضمامك إلى برنامجنا التدريبي المهني. ستتلقى جداول الجلسات وروابط Zoom عند توفرها.'
        ],
        'email_questions' => [
            'en' => "If you have any questions, please don't hesitate to contact us.",
            'ar' => 'إذا كان لديك أي أسئلة، يرجى عدم التردد في الاتصال بنا.'
        ],
        'email_best_regards' => ['en' => 'Best regards,', 'ar' => 'مع أطيب التحيات،'],
        'email_team' => ['en' => 'Tanafs Academy Team', 'ar' => 'فريق أكاديمية تنفس'],
        'email_copyright' => ['en' => '© 2025 Tanafs Academy. All rights reserved.', 'ar' => '© 2025 أكاديمية تنفس. جميع الحقوق محفوظة.'],

        // Session Reminder Email
        'email_session_reminder' => ['en' => 'Tanafs Academy - Session Reminder', 'ar' => 'أكاديمية تنفس - تذكير بالجلسة'],
        'email_session_upcoming' => [
            'en' => 'This is a reminder about your upcoming session:',
            'ar' => 'هذا تذكير بجلستك القادمة:'
        ],
        'email_program' => ['en' => 'Program', 'ar' => 'البرنامج'],
        'email_date' => ['en' => 'Date', 'ar' => 'التاريخ'],
        'email_time' => ['en' => 'Time', 'ar' => 'الوقت'],
        'email_meeting_password' => ['en' => 'Meeting Password', 'ar' => 'كلمة مرور الاجتماع'],
        'email_join_zoom' => ['en' => 'Join Zoom Meeting', 'ar' => 'الانضمام إلى اجتماع Zoom'],
        'email_look_forward' => [
            'en' => 'We look forward to seeing you!',
            'ar' => 'نتطلع لرؤيتك!'
        ],

        // Error Messages
        'error_required_fields' => [
            'en' => 'Please fill in all required fields',
            'ar' => 'يرجى ملء جميع الحقول المطلوبة'
        ],
        'error_invalid_email' => [
            'en' => 'Invalid email address',
            'ar' => 'عنوان بريد إلكتروني غير صحيح'
        ],
        'error_already_registered' => [
            'en' => 'You are already registered for this program',
            'ar' => 'أنت مسجل بالفعل في هذا البرنامج'
        ],
        'success_registration' => [
            'en' => 'Registration successful! You will receive a confirmation email shortly.',
            'ar' => 'تم التسجيل بنجاح! ستتلقى رسالة تأكيد عبر البريد الإلكتروني قريبًا.'
        ],
        'error_registration_failed' => [
            'en' => 'Registration failed. Please try again.',
            'ar' => 'فشل التسجيل. يرجى المحاولة مرة أخرى.'
        ],
    ];

    if (isset($translations[$key])) {
        return $is_arabic ? $translations[$key]['ar'] : $translations[$key]['en'];
    }

    return $key; // Return key if translation not found
}

// ==========================================
// DATABASE TABLES
// ==========================================

// Table 1: Academy Programs
add_action('init', 'create_academy_programs_table');
function create_academy_programs_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'academy_programs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        program_name VARCHAR(255) NOT NULL,
        program_slug VARCHAR(100) NOT NULL,
        phase INT NOT NULL DEFAULT 1,
        duration_hours INT NOT NULL,
        duration_weeks INT NOT NULL,
        hours_per_week INT NOT NULL,
        delivery_mode VARCHAR(100) DEFAULT NULL,
        max_participants INT NOT NULL DEFAULT 24,
        target_audience TEXT DEFAULT NULL,
        description TEXT DEFAULT NULL,
        program_name_ar VARCHAR(255) DEFAULT NULL,
        description_ar TEXT DEFAULT NULL,
        target_audience_ar TEXT DEFAULT NULL,
        delivery_mode_ar VARCHAR(100) DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY program_slug (program_slug),
        KEY phase (phase)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// Table 2: Academy Schedules
add_action('init', 'create_academy_schedules_table');
function create_academy_schedules_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'academy_schedules';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        program_id BIGINT UNSIGNED NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        repetition_days VARCHAR(100) DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_by BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY program_id (program_id),
        KEY start_date (start_date)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// Table 3: Academy Scheduled Sessions
add_action('init', 'create_academy_sessions_table');
function create_academy_sessions_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'academy_sessions';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        schedule_id BIGINT UNSIGNED NOT NULL,
        program_id BIGINT UNSIGNED NOT NULL,
        session_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        zoom_meeting_id VARCHAR(100) DEFAULT NULL,
        zoom_join_url TEXT DEFAULT NULL,
        zoom_start_url TEXT DEFAULT NULL,
        zoom_password VARCHAR(50) DEFAULT NULL,
        notification_sent TINYINT(1) DEFAULT 0,
        session_status VARCHAR(20) DEFAULT 'scheduled',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY schedule_id (schedule_id),
        KEY program_id (program_id),
        KEY session_date (session_date),
        KEY notification_sent (notification_sent)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// Table 4: Academy Registrations
add_action('init', 'create_academy_registrations_table');
function create_academy_registrations_table()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'academy_registrations';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        program_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        full_name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        phone VARCHAR(50) DEFAULT NULL,
        job_title VARCHAR(255) DEFAULT NULL,
        license_number VARCHAR(100) DEFAULT NULL,
        country VARCHAR(100) DEFAULT NULL,
        registration_status VARCHAR(20) DEFAULT 'registered',
        registered_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY program_id (program_id),
        KEY user_id (user_id),
        KEY email (email)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// Ensure Zoom URL columns can store long payloads
add_action('init', 'academy_upgrade_zoom_url_columns');
function academy_upgrade_zoom_url_columns()
{
    global $wpdb;
    $table = $wpdb->prefix . 'academy_sessions';

    $columns = ['zoom_join_url', 'zoom_start_url'];
    foreach ($columns as $column) {
        $column_info = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
        if ($column_info && stripos($column_info->Type, 'text') === false) {
            $wpdb->query("ALTER TABLE {$table} MODIFY {$column} TEXT NULL");
        }
    }
}

// Add Arabic language columns to existing tables
add_action('init', 'academy_upgrade_arabic_columns');
function academy_upgrade_arabic_columns()
{
    global $wpdb;
    $table = $wpdb->prefix . 'academy_programs';

    $columns = ['program_name_ar', 'description_ar', 'target_audience_ar', 'delivery_mode_ar'];
    foreach ($columns as $column) {
        $column_info = $wpdb->get_row($wpdb->prepare("SHOW COLUMNS FROM {$table} LIKE %s", $column));
        if (!$column_info) {
            $type = (strpos($column, 'name') !== false || strpos($column, 'mode') !== false) ? 'VARCHAR(255)' : 'TEXT';
            $wpdb->query("ALTER TABLE {$table} ADD COLUMN {$column} {$type} NULL");
        }
    }
}

// ==========================================
// ZOOM API FUNCTIONS (Reusing existing credentials)
// ==========================================

function academy_get_zoom_access_token()
{
    return tashafe_get_zoom_access_token();
}

function academy_create_zoom_meeting($topic, $start_time, $duration = 180, $timezone = 'Asia/Riyadh')
{
    return tashafe_create_zoom_meeting($topic, $start_time, $duration, $timezone);
}

// ==========================================
// INITIALIZE PHASE 1 PROGRAMS
// ==========================================

add_action('init', 'academy_init_phase1_programs');
function academy_init_phase1_programs()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'academy_programs';

    // Check if programs already exist
    $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
    if ($count > 0) {
        return; // Programs already initialized
    }

    $programs = [
        [
            'program_name' => 'Foundation in Group Therapy',
            'program_name_ar' => 'أساسيات العلاج الجماعي',
            'program_slug' => 'foundation-group-therapy',
            'phase' => 1,
            'duration_hours' => 30,
            'duration_weeks' => 10,
            'hours_per_week' => 3,
            'delivery_mode' => 'online - in-person',
            'delivery_mode_ar' => 'عبر الإنترنت - حضورياً',
            'max_participants' => 24,
            'target_audience' => 'Professionals wishing to build a professional foundation in group therapy',
            'target_audience_ar' => 'المختصون الراغبون في بناء أساس مهني في العلاج الجماعي',
            'description' => 'This course covers the essential foundations of group therapy, including core principles, professional ethics, and therapeutic relationships.',
            'description_ar' => 'تغطي هذه المرحلة الأسس الجوهرية للعلاج الجماعي، بما في ذلك المبادئ الأساسية للعلاج الجماعي، والأخلاقيات المهنية، وكل ما يتعلق بالعلاقة العلاجية بين المختص والمشاركين في المجموعة.'
        ],
        [
            'program_name' => 'Ethics and Cultural Sensitivity in Group Therapy',
            'program_name_ar' => 'الأخلاقيات المهنية والحساسية الثقافية في العلاج الجماعي',
            'program_slug' => 'ethics-cultural-sensitivity',
            'phase' => 1,
            'duration_hours' => 10,
            'duration_weeks' => 4,
            'hours_per_week' => 3,
            'delivery_mode' => 'online',
            'delivery_mode_ar' => 'عن بُعد',
            'max_participants' => 24,
            'target_audience' => 'All practitioners in the field of group therapy and counseling',
            'target_audience_ar' => 'جميع الممارسين في مجال العلاج والإرشاد الجماعي',
            'description' => 'Focuses on professional ethics, cultural sensitivity, and ethical practice in group therapy settings.',
            'description_ar' => 'تركّز على بناء الوعي الأخلاقي، وترسيخ الحدود المهنية، وفهم ديناميكيات المجموعة، بما يضمن ممارسة علاجية آمنة ومنظمة ضمن إطار علاجي واضح.'
        ],
        [
            'program_name' => 'Creative Group Therapy – Using Different Arts',
            'program_name_ar' => 'العلاج الجماعي بالإبداع',
            'program_slug' => 'creative-group-therapy',
            'phase' => 1,
            'duration_hours' => 40,
            'duration_weeks' => 14,
            'hours_per_week' => 3,
            'delivery_mode' => 'online - in-person',
            'delivery_mode_ar' => 'عبر الإنترنت - حضورياً',
            'max_participants' => 24,
            'target_audience' => 'Anyone interested (professionals, counselors, and trainers)',
            'target_audience_ar' => 'عامة المهتمين (مختصون، مرشدون، ومدربون)',
            'description' => 'Utilizes various art-based modalities as therapeutic tools, facilitated by trained professionals.',
            'description_ar' => 'استخدام الفنون المختلفة كأدوات علاجية (بإشراف مختصين)'
        ]
    ];

    foreach ($programs as $program) {
        $wpdb->insert($table_name, $program);
    }
}

// Update existing programs with Arabic translations
add_action('init', 'academy_update_existing_programs_arabic');
function academy_update_existing_programs_arabic()
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'academy_programs';

    // Check if any program is missing Arabic data
    $needs_update = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE (program_name_ar IS NULL OR program_name_ar = '') AND phase = 1");

    if ($needs_update == 0) {
        return; // All programs already have Arabic data
    }

    // Update Program 1: Foundation in Group Therapy
    $wpdb->update(
        $table_name,
        [
            'program_name_ar' => 'الدورة التأسيسية في العلاج الجماعي',
            'description_ar' => 'تغطي هذه المرحلة الأسس الجوهرية للعلاج الجماعي، بما في ذلك المبادئ الأساسية للعلاج الجماعي، والأخلاقيات المهنية، وكل ما يتعلق بالعلاقة العلاجية بين المختص والمشاركين في المجموعة.',
            'target_audience_ar' => 'المختصون الراغبون في بناء أساس مهني في العلاج الجماعي',
            'delivery_mode_ar' => 'عن بُعد – حضوري'
        ],
        ['program_slug' => 'foundation-group-therapy'],
        ['%s', '%s', '%s', '%s'],
        ['%s']
    );

    // Update Program 2: Ethics and Cultural Sensitivity
    $wpdb->update(
        $table_name,
        [
            'program_name_ar' => 'الأخلاقيات المهنية والحساسية الثقافية في العلاج الجماعي',
            'description_ar' => 'تركّز على بناء الوعي الأخلاقي، وترسيخ الحدود المهنية، وفهم ديناميكيات المجموعة، بما يضمن ممارسة علاجية آمنة ومنظمة ضمن إطار علاجي واضح.',
            'target_audience_ar' => 'جميع الممارسين في مجال العلاج والإرشاد الجماعي',
            'delivery_mode_ar' => 'عن بُعد'
        ],
        ['program_slug' => 'ethics-cultural-sensitivity'],
        ['%s', '%s', '%s', '%s'],
        ['%s']
    );

    // Update Program 3: Creative Group Therapy
    $wpdb->update(
        $table_name,
        [
            'program_name_ar' => 'العلاج الجماعي بالإبداع',
            'description_ar' => 'استخدام الفنون المختلفة كأدوات علاجية (بإشراف مختصين)',
            'target_audience_ar' => 'عامة المهتمين (مختصون، مرشدون، ومدربون)',
            'delivery_mode_ar' => 'عن بُعد – حضوري'
        ],
        ['program_slug' => 'creative-group-therapy'],
        ['%s', '%s', '%s', '%s'],
        ['%s']
    );
}

// ==========================================
// ADMIN MENU
// ==========================================

add_action('admin_menu', 'academy_admin_menu');
function academy_admin_menu()
{
    add_menu_page(
        'Tanafs Academy Management',
        'Tanafs Academy Management',
        'manage_options',
        'academy-management',
        'render_academy_dashboard',
        'dashicons-welcome-learn-more',
        8
    );
}

// ==========================================
// HELPER FUNCTIONS
// ==========================================

// Get programs without existing schedules (for schedule creation)
function academy_get_unscheduled_programs()
{
    global $wpdb;
    $programs_table = $wpdb->prefix . 'academy_programs';
    $schedules_table = $wpdb->prefix . 'academy_schedules';
    $sessions_table = $wpdb->prefix . 'academy_sessions';

    $programs = $wpdb->get_results("SELECT * FROM {$programs_table} WHERE phase = 1 AND is_active = 1 ORDER BY id ASC");
    $unscheduled = [];

    foreach ($programs as $program) {
        // Check if program has active schedules with upcoming sessions
        $has_active_schedule = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$schedules_table} sc 
             INNER JOIN {$sessions_table} se ON sc.id = se.schedule_id
             WHERE sc.program_id = %d AND sc.is_active = 1 AND se.session_date >= CURDATE()",
            $program->id
        ));

        if (!$has_active_schedule) {
            $unscheduled[] = $program;
        }
    }

    return $unscheduled;
}

// Get programs with existing schedules (for revert functionality)
function academy_get_scheduled_programs()
{
    global $wpdb;
    $programs_table = $wpdb->prefix . 'academy_programs';
    $schedules_table = $wpdb->prefix . 'academy_schedules';
    $sessions_table = $wpdb->prefix . 'academy_sessions';

    $scheduled = $wpdb->get_results("
        SELECT p.*, sc.id as schedule_id, sc.start_date, sc.end_date, sc.start_time, sc.end_time,
               (SELECT COUNT(*) FROM {$sessions_table} WHERE schedule_id = sc.id) as sessions_count
        FROM {$programs_table} p
        INNER JOIN {$schedules_table} sc ON p.id = sc.program_id
        WHERE p.phase = 1 AND p.is_active = 1 AND sc.is_active = 1
        ORDER BY sc.created_at DESC
    ");

    return $scheduled;
}

// ==========================================
// ADMIN DASHBOARD
// ==========================================

function render_academy_dashboard()
{
    if (!current_user_can('manage_options')) {
        return;
    }

    global $wpdb;
    $programs_table = $wpdb->prefix . 'academy_programs';
    $all_programs = $wpdb->get_results("SELECT * FROM {$programs_table} WHERE phase = 1 AND is_active = 1 ORDER BY id ASC");
    $unscheduled_programs = academy_get_unscheduled_programs();
    $scheduled_programs = academy_get_scheduled_programs();

?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Tanafs Tanafs Academy Management</h1>
        <hr class="wp-header-end">

        <!-- Load Bootstrap & Icons -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

        <style>
            /* Reset WordPress admin conflicts */
            #wpbody-content {
                overflow-x: hidden !important;
            }

            #wpbody-content .wrap {
                max-width: 100% !important;
                width: 100% !important;
                overflow-x: hidden !important;
            }

            #wpbody-content .wrap .card,
            #wpbody-content .wrap .table-responsive,
            #wpbody-content .wrap .tab-content,
            #wpbody-content .wrap .tab-pane {
                width: 100% !important;
                max-width: 100% !important;
            }

            .academy-wrapper {
                width: 100%;
                max-width: 100%;
                overflow-x: hidden;
            }

            .academy-wrapper .card {
                border: none;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
                margin-bottom: 20px;
            }

            .academy-wrapper .card-header {
                background: #635ba3;
                color: white;
                font-weight: 600;
            }

            .academy-wrapper .nav-tabs .nav-link.active {
                background-color: #635ba3;
                color: white;
                border-color: #635ba3;
            }

            .academy-wrapper .nav-tabs .nav-link {
                color: #635ba3;
                font-weight: 600;
            }

            .academy-wrapper .btn-primary-academy {
                background: #635ba3;
                border: none;
                color: white;
                padding: 12px 30px;
                font-weight: 600;
            }

            .academy-wrapper .btn-primary-academy:hover {
                background: #504a8a;
                color: white;
            }

            .academy-wrapper .program-card {
                background: #f8f9fa;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 15px;
                margin-bottom: 10px;
                cursor: pointer;
                transition: all 0.2s;
            }

            .academy-wrapper .program-card:hover {
                border-color: #6059A6;
                background: #f0eef7;
            }

            .academy-wrapper .program-card.selected {
                border: 2px solid #6059A6;
                background: #f0eef7;
            }

            .academy-wrapper .day-checkbox {
                display: inline-block;
                margin-right: 10px;
                margin-bottom: 10px;
            }

            .academy-wrapper .day-checkbox input {
                display: none;
            }

            .academy-wrapper .day-checkbox label {
                cursor: pointer;
                padding: 8px 16px;
                border: 2px solid #e0e0e0;
                border-radius: 20px;
                transition: all 0.2s;
            }

            .academy-wrapper .day-checkbox input:checked+label {
                background: #6059A6;
                color: white;
                border-color: #6059A6;
            }

            .academy-wrapper .sessions-table th {
                background: #635ba3;
                color: white;
            }

            .academy-wrapper .zoom-link {
                max-width: 200px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
                display: inline-block;
            }

            .academy-wrapper .revert-card {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 15px;
                margin-bottom: 10px;
                cursor: pointer;
                transition: all 0.2s;
            }

            .academy-wrapper .revert-card:hover {
                border-color: #dc3545;
                background: #fff5f5;
            }

            .academy-wrapper .revert-card.selected {
                border: 2px solid #dc3545;
                background: #fff5f5;
            }

            .academy-wrapper .btn-revert {
                background: #dc3545;
                border: none;
                color: white;
                padding: 12px 30px;
                font-weight: 600;
            }

            .academy-wrapper .btn-revert:hover {
                background: #c82333;
                color: white;
            }

            .academy-wrapper .registrations-table th {
                background: #635ba3;
                color: white;
            }
        </style>

        <div class="academy-wrapper">
            <!-- Main Tabs -->
            <ul class="nav nav-tabs mb-4" id="academyTabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link active" id="schedule-tab" data-bs-toggle="tab" href="#scheduleTab" role="tab">
                        <i class="bi bi-calendar-plus me-1"></i> Create Schedule
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="sessions-tab" data-bs-toggle="tab" href="#sessionsTab" role="tab">
                        <i class="bi bi-calendar-check me-1"></i> View Sessions
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="registrations-tab" data-bs-toggle="tab" href="#registrationsTab" role="tab">
                        <i class="bi bi-people me-1"></i> Registrations
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" id="revert-tab" data-bs-toggle="tab" href="#revertTab" role="tab">
                        <i class="bi bi-arrow-counterclockwise me-1"></i> Revert Schedule
                    </a>
                </li>
            </ul>

            <div class="tab-content">
                <!-- Tab 1: Create Schedule -->
                <div class="tab-pane fade show active" id="scheduleTab" role="tabpanel">
                    <div class="card">
                        <div class="card-header"><i class="bi bi-1-circle me-2"></i>Step 1: Select Program (Without Existing Schedule)</div>
                        <div class="card-body">
                            <div id="programsContainer">
                                <?php if (empty($unscheduled_programs)): ?>
                                    <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>All programs have active schedules. Use the "Revert Schedule" tab to remove a schedule if needed.</div>
                                <?php else: ?>
                                    <?php foreach ($unscheduled_programs as $program): ?>
                                        <div class="program-card" data-program-id="<?php echo esc_attr($program->id); ?>">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong><?php echo esc_html($program->program_name); ?></strong>
                                                    <span class="text-muted ms-2">(<?php echo esc_html($program->duration_hours); ?> hours, <?php echo esc_html($program->duration_weeks); ?> weeks)</span>
                                                </div>
                                                <span class="badge bg-secondary"><?php echo esc_html($program->delivery_mode); ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" id="selectedProgramId">
                        </div>
                    </div>

                    <div class="card" id="scheduleDetailsCard" style="display:none;">
                        <div class="card-header"><i class="bi bi-2-circle me-2"></i>Step 2: Set Schedule Details</div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Start Date</label>
                                    <input type="date" class="form-control" id="startDate">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">End Date</label>
                                    <input type="date" class="form-control" id="endDate">
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Start Time</label>
                                    <input type="time" class="form-control" id="startTime" value="18:00">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-bold">End Time</label>
                                    <input type="time" class="form-control" id="endTime" value="21:00">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Repetition Days</label>
                                <div id="repetitionDays">
                                    <span class="day-checkbox"><input type="checkbox" id="daySunday" value="sunday"><label for="daySunday">Sunday</label></span>
                                    <span class="day-checkbox"><input type="checkbox" id="dayMonday" value="monday"><label for="dayMonday">Monday</label></span>
                                    <span class="day-checkbox"><input type="checkbox" id="dayTuesday" value="tuesday"><label for="dayTuesday">Tuesday</label></span>
                                    <span class="day-checkbox"><input type="checkbox" id="dayWednesday" value="wednesday"><label for="dayWednesday">Wednesday</label></span>
                                    <span class="day-checkbox"><input type="checkbox" id="dayThursday" value="thursday"><label for="dayThursday">Thursday</label></span>
                                    <span class="day-checkbox"><input type="checkbox" id="dayFriday" value="friday"><label for="dayFriday">Friday</label></span>
                                    <span class="day-checkbox"><input type="checkbox" id="daySaturday" value="saturday"><label for="daySaturday">Saturday</label></span>
                                </div>
                            </div>
                            <div class="text-center mt-4">
                                <button type="button" class="btn btn-primary-academy btn-lg" id="saveScheduleBtn">
                                    <i class="bi bi-calendar-check me-2"></i>Create Schedule & Zoom Meetings
                                </button>
                            </div>
                            <div id="scheduleResult" class="mt-3"></div>
                        </div>
                    </div>
                </div>

                <!-- Tab 2: View Sessions -->
                <div class="tab-pane fade" id="sessionsTab" role="tabpanel">
                    <div class="card">
                        <div class="card-header"><i class="bi bi-calendar-week me-2"></i>Upcoming Sessions</div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <select class="form-select" id="filterProgram">
                                        <option value="">-- All Programs --</option>
                                        <?php foreach ($all_programs as $program): ?>
                                            <option value="<?php echo esc_attr($program->id); ?>"><?php echo esc_html($program->program_name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-primary-academy" id="loadSessionsBtn"><i class="bi bi-search"></i> Filter</button>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover sessions-table">
                                    <thead>
                                        <tr>
                                            <th>Program</th>
                                            <th>Date</th>
                                            <th>Time</th>
                                            <th>Zoom Link</th>
                                            <th>Status</th>
                                            <th>Notified</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="sessionsTableBody">
                                        <tr>
                                            <td colspan="7" class="text-center text-muted">Click "Filter" to load sessions</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab 3: Registrations -->
                <div class="tab-pane fade" id="registrationsTab" role="tabpanel">
                    <div class="card">
                        <div class="card-header"><i class="bi bi-people me-2"></i>User Registrations</div>
                        <div class="card-body">
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <select class="form-select" id="filterRegProgram">
                                        <option value="">-- All Programs --</option>
                                        <?php foreach ($all_programs as $program): ?>
                                            <option value="<?php echo esc_attr($program->id); ?>"><?php echo esc_html($program->program_name); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-primary-academy" id="loadRegistrationsBtn"><i class="bi bi-search"></i> Filter</button>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-hover registrations-table">
                                    <thead>
                                        <tr>
                                            <th>Program</th>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Phone</th>
                                            <th>Job Title</th>
                                            <th>License #</th>
                                            <th>Country</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody id="registrationsTableBody">
                                        <tr>
                                            <td colspan="8" class="text-center text-muted">Click "Filter" to load registrations</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab 4: Revert Schedule -->
                <div class="tab-pane fade" id="revertTab" role="tabpanel">
                    <div class="card">
                        <div class="card-header"><i class="bi bi-arrow-counterclockwise me-2"></i>Revert Schedule</div>
                        <div class="card-body">
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>Warning:</strong> Reverting a schedule will delete all scheduled sessions and Zoom meetings for the selected program. The program will then appear again in the "Create Schedule" tab for re-scheduling.
                            </div>

                            <div id="revertProgramsContainer">
                                <?php if (empty($scheduled_programs)): ?>
                                    <div class="alert alert-info"><i class="bi bi-info-circle me-2"></i>No programs with active schedules found.</div>
                                <?php else: ?>
                                    <h5 class="mb-3"><i class="bi bi-collection me-2"></i>Select a Program to Revert</h5>
                                    <?php foreach ($scheduled_programs as $program): ?>
                                        <div class="revert-card" data-program-id="<?php echo esc_attr($program->id); ?>" data-schedule-id="<?php echo esc_attr($program->schedule_id); ?>">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong><?php echo esc_html($program->program_name); ?></strong>
                                                </div>
                                                <span class="badge bg-info"><?php echo esc_html($program->sessions_count); ?> session(s)</span>
                                            </div>
                                            <div class="mt-2 small text-muted">
                                                <i class="bi bi-calendar me-1"></i> <?php echo esc_html($program->start_date); ?> to <?php echo esc_html($program->end_date); ?>
                                                <span class="ms-3"><i class="bi bi-clock me-1"></i> <?php echo esc_html(substr($program->start_time, 0, 5)); ?> - <?php echo esc_html(substr($program->end_time, 0, 5)); ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" id="selectedRevertProgramId">
                            <input type="hidden" id="selectedRevertScheduleId">

                            <div id="revertActionContainer" style="display:none;" class="mt-4">
                                <div class="text-center">
                                    <button type="button" class="btn btn-revert btn-lg" id="revertScheduleBtn">
                                        <i class="bi bi-arrow-counterclockwise me-2"></i>Revert Schedule
                                    </button>
                                </div>
                            </div>
                            <div id="revertResult" class="mt-3"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            jQuery(document).ready(function($) {
                const ajaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';

                // Program card selection
                $('.program-card').on('click', function() {
                    $('.program-card').removeClass('selected');
                    $(this).addClass('selected');
                    $('#selectedProgramId').val($(this).data('program-id'));
                    $('#scheduleDetailsCard').show();
                });

                // Save schedule
                $('#saveScheduleBtn').on('click', function() {
                    const programId = $('#selectedProgramId').val();
                    const startDate = $('#startDate').val();
                    const endDate = $('#endDate').val();
                    const startTime = $('#startTime').val();
                    const endTime = $('#endTime').val();
                    const repetitionDays = [];
                    $('#repetitionDays input:checked').each(function() {
                        repetitionDays.push($(this).val());
                    });

                    if (!programId || !startDate || !endDate || !startTime || !endTime || repetitionDays.length === 0) {
                        alert('Please fill all fields and select at least one day.');
                        return;
                    }

                    const $btn = $(this);
                    $btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat"></i> Creating...');

                    $.post(ajaxUrl, {
                        action: 'academy_save_schedule',
                        program_id: programId,
                        start_date: startDate,
                        end_date: endDate,
                        start_time: startTime,
                        end_time: endTime,
                        'repetition_days[]': repetitionDays
                    }, function(response) {
                        $btn.prop('disabled', false).html('<i class="bi bi-calendar-check me-2"></i>Create Schedule & Zoom Meetings');

                        if (response.success) {
                            let html = '<div class="alert alert-success">' + response.data.message + '</div>';
                            if (response.data.zoom_errors && response.data.zoom_errors.length > 0) {
                                html += '<div class="alert alert-warning"><strong>Some Zoom errors:</strong><ul>';
                                response.data.zoom_errors.forEach(err => html += '<li>' + err + '</li>');
                                html += '</ul></div>';
                            }
                            $('#scheduleResult').html(html);
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $('#scheduleResult').html('<div class="alert alert-danger">' + response.data + '</div>');
                        }
                    });
                });

                // Load sessions
                $('#loadSessionsBtn').on('click', function() {
                    const programId = $('#filterProgram').val();
                    $('#sessionsTableBody').html('<tr><td colspan="7" class="text-center"><i class="bi bi-arrow-repeat"></i> Loading...</td></tr>');

                    $.post(ajaxUrl, {
                        action: 'academy_get_sessions',
                        program_id: programId
                    }, function(response) {
                        if (response.success && response.data.length > 0) {
                            let html = '';
                            response.data.forEach(function(s) {
                                const date = new Date(s.session_date).toLocaleDateString('en-US', {
                                    weekday: 'short',
                                    year: 'numeric',
                                    month: 'short',
                                    day: 'numeric'
                                });
                                const notified = s.notification_sent == 1 ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>';
                                const zoomLink = s.zoom_join_url ? '<a href="' + s.zoom_join_url + '" target="_blank" class="zoom-link">' + s.zoom_join_url + '</a>' : '<span class="text-muted">N/A</span>';
                                html += '<tr>';
                                html += '<td>' + s.program_name + '</td>';
                                html += '<td>' + date + '</td>';
                                html += '<td>' + s.start_time + ' - ' + s.end_time + '</td>';
                                html += '<td>' + zoomLink + '</td>';
                                html += '<td><span class="badge bg-info">' + s.session_status + '</span></td>';
                                html += '<td>' + notified + '</td>';
                                html += '<td><button class="btn btn-sm btn-outline-primary notify-btn" data-id="' + s.id + '" ' + (s.notification_sent == 1 ? 'disabled' : '') + '><i class="bi bi-envelope"></i> Notify</button></td>';
                                html += '</tr>';
                            });
                            $('#sessionsTableBody').html(html);

                            $('.notify-btn').on('click', function() {
                                const $btn = $(this);
                                const sessionId = $btn.data('id');
                                if (!confirm('Send notification emails to all registered users?')) return;
                                $btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat"></i>');
                                $.post(ajaxUrl, {
                                    action: 'academy_notify_session',
                                    session_id: sessionId
                                }, function(response) {
                                    if (response.success) {
                                        $btn.html('<i class="bi bi-check"></i> Sent');
                                        setTimeout(function() {
                                            $('#loadSessionsBtn').click();
                                        }, 1000);
                                    } else {
                                        $btn.prop('disabled', false).html('<i class="bi bi-envelope"></i> Notify');
                                        alert('Failed: ' + response.data);
                                    }
                                });
                            });
                        } else {
                            $('#sessionsTableBody').html('<tr><td colspan="7" class="text-center text-muted">No sessions found.</td></tr>');
                        }
                    });
                });

                // Load sessions when tab is shown
                $('#sessions-tab').on('shown.bs.tab', function() {
                    $('#loadSessionsBtn').click();
                });

                // Load registrations
                $('#loadRegistrationsBtn').on('click', function() {
                    const programId = $('#filterRegProgram').val();
                    $('#registrationsTableBody').html('<tr><td colspan="8" class="text-center"><i class="bi bi-arrow-repeat"></i> Loading...</td></tr>');

                    $.post(ajaxUrl, {
                        action: 'academy_get_registrations',
                        program_id: programId
                    }, function(response) {
                        if (response.success && response.data.length > 0) {
                            let html = '';
                            response.data.forEach(function(r) {
                                html += '<tr>';
                                html += '<td>' + r.program_name + '</td>';
                                html += '<td>' + r.full_name + '</td>';
                                html += '<td>' + r.email + '</td>';
                                html += '<td>' + (r.phone || '-') + '</td>';
                                html += '<td>' + (r.job_title || '-') + '</td>';
                                html += '<td>' + (r.license_number || '-') + '</td>';
                                html += '<td>' + (r.country || '-') + '</td>';
                                html += '<td>' + r.registered_at + '</td>';
                                html += '</tr>';
                            });
                            $('#registrationsTableBody').html(html);
                        } else {
                            $('#registrationsTableBody').html('<tr><td colspan="8" class="text-center text-muted">No registrations found.</td></tr>');
                        }
                    });
                });

                // Revert card selection
                $('.revert-card').on('click', function() {
                    $('.revert-card').removeClass('selected');
                    $(this).addClass('selected');
                    $('#selectedRevertProgramId').val($(this).data('program-id'));
                    $('#selectedRevertScheduleId').val($(this).data('schedule-id'));
                    $('#revertActionContainer').show();
                });

                // Revert schedule
                $('#revertScheduleBtn').on('click', function() {
                    const programId = $('#selectedRevertProgramId').val();
                    const scheduleId = $('#selectedRevertScheduleId').val();

                    if (!programId || !scheduleId) {
                        alert('Please select a program to revert.');
                        return;
                    }

                    if (!confirm('Are you sure you want to revert this schedule? All scheduled sessions will be deleted.')) {
                        return;
                    }

                    const $btn = $(this);
                    $btn.prop('disabled', true).html('<i class="bi bi-arrow-repeat"></i> Reverting...');

                    $.post(ajaxUrl, {
                        action: 'academy_revert_schedule',
                        program_id: programId,
                        schedule_id: scheduleId
                    }, function(response) {
                        $btn.prop('disabled', false).html('<i class="bi bi-arrow-counterclockwise me-2"></i>Revert Schedule');

                        if (response.success) {
                            $('#revertResult').html('<div class="alert alert-success">' + response.data.message + '</div>');
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $('#revertResult').html('<div class="alert alert-danger">' + response.data + '</div>');
                        }
                    });
                });
            });
        </script>
    </div>
<?php
}

// ==========================================
// AJAX HANDLERS
// ==========================================

// Revert Schedule
add_action('wp_ajax_academy_revert_schedule', 'ajax_academy_revert_schedule');
function ajax_academy_revert_schedule()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    global $wpdb;

    $program_id = intval($_POST['program_id'] ?? 0);
    $schedule_id = intval($_POST['schedule_id'] ?? 0);

    if (!$program_id || !$schedule_id) {
        wp_send_json_error('Invalid program or schedule ID');
    }

    $schedules_table = $wpdb->prefix . 'academy_schedules';
    $sessions_table = $wpdb->prefix . 'academy_sessions';

    // Delete all sessions associated with this schedule
    $sessions_deleted = $wpdb->delete(
        $sessions_table,
        ['schedule_id' => $schedule_id],
        ['%d']
    );

    // Delete the schedule itself (or mark as inactive)
    $schedule_deleted = $wpdb->update(
        $schedules_table,
        ['is_active' => 0],
        ['id' => $schedule_id],
        ['%d'],
        ['%d']
    );

    if ($schedule_deleted !== false) {
        wp_send_json_success([
            'message' => "Schedule reverted successfully! {$sessions_deleted} session(s) deleted. The program is now available for re-scheduling.",
            'sessions_deleted' => $sessions_deleted
        ]);
    } else {
        wp_send_json_error('Failed to revert schedule');
    }
}

// Save Schedule
add_action('wp_ajax_academy_save_schedule', 'ajax_academy_save_schedule');
function ajax_academy_save_schedule()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    global $wpdb;

    $program_id = intval($_POST['program_id'] ?? 0);
    $start_date = sanitize_text_field($_POST['start_date'] ?? '');
    $end_date = sanitize_text_field($_POST['end_date'] ?? '');
    $start_time = sanitize_text_field($_POST['start_time'] ?? '');
    $end_time = sanitize_text_field($_POST['end_time'] ?? '');
    $repetition_days = isset($_POST['repetition_days']) ? $_POST['repetition_days'] : [];

    if (!$program_id || empty($start_date) || empty($end_date) || empty($start_time) || empty($end_time) || empty($repetition_days)) {
        wp_send_json_error('All fields are required');
    }

    if (is_array($repetition_days)) {
        $repetition_days = implode(',', array_map('sanitize_text_field', $repetition_days));
    }

    $schedules_table = $wpdb->prefix . 'academy_schedules';
    $sessions_table = $wpdb->prefix . 'academy_sessions';
    $programs_table = $wpdb->prefix . 'academy_programs';

    $program = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$programs_table} WHERE id = %d", $program_id));

    if (!$program) {
        wp_send_json_error('Invalid program');
    }

    $start_dt = new DateTime($start_time);
    $end_dt = new DateTime($end_time);
    $duration = ($end_dt->getTimestamp() - $start_dt->getTimestamp()) / 60;

    // Insert schedule
    $inserted = $wpdb->insert($schedules_table, [
        'program_id' => $program_id,
        'start_date' => $start_date,
        'end_date' => $end_date,
        'start_time' => $start_time,
        'end_time' => $end_time,
        'repetition_days' => $repetition_days,
        'created_by' => get_current_user_id()
    ], ['%d', '%s', '%s', '%s', '%s', '%s', '%d']);

    if (!$inserted) {
        wp_send_json_error('Failed to save schedule');
    }

    $schedule_id = $wpdb->insert_id;

    // Generate sessions
    $rep_days = explode(',', $repetition_days);
    $current_date = new DateTime($start_date);
    $end_date_obj = new DateTime($end_date);
    $sessions_created = 0;
    $zoom_errors = [];

    while ($current_date <= $end_date_obj) {
        $current_day_name = strtolower($current_date->format('l'));

        if (in_array($current_day_name, $rep_days)) {
            $session_datetime = $current_date->format('Y-m-d') . 'T' . $start_time;
            $meeting_topic = $program->program_name . ' - Session';

            $zoom_result = academy_create_zoom_meeting($meeting_topic, $session_datetime, $duration);

            $zoom_meeting_id = null;
            $zoom_join_url = null;
            $zoom_start_url = null;
            $zoom_password = null;

            if (isset($zoom_result['meeting_id'])) {
                $zoom_meeting_id = $zoom_result['meeting_id'];
                $zoom_join_url = $zoom_result['join_url'];
                $zoom_start_url = $zoom_result['start_url'];
                $zoom_password = $zoom_result['password'];
            } else {
                $zoom_errors[] = $current_date->format('Y-m-d') . ': ' . ($zoom_result['error'] ?? 'Unknown error');
            }

            $session_inserted = $wpdb->insert($sessions_table, [
                'schedule_id' => $schedule_id,
                'program_id' => $program_id,
                'session_date' => $current_date->format('Y-m-d'),
                'start_time' => $start_time,
                'end_time' => $end_time,
                'zoom_meeting_id' => $zoom_meeting_id,
                'zoom_join_url' => $zoom_join_url,
                'zoom_start_url' => $zoom_start_url,
                'zoom_password' => $zoom_password,
                'notification_sent' => 0,
                'session_status' => 'scheduled'
            ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s']);

            if ($session_inserted !== false) {
                $sessions_created++;
            }
        }

        $current_date->modify('+1 day');
    }

    if ($sessions_created === 0) {
        $wpdb->delete($schedules_table, ['id' => $schedule_id], ['%d']);
        wp_send_json_error('Unable to generate any sessions');
    }

    $response = [
        'message' => "Schedule created successfully! {$sessions_created} session(s) generated with Zoom meetings.",
        'schedule_id' => $schedule_id,
        'sessions_created' => $sessions_created
    ];

    if (!empty($zoom_errors)) {
        $response['zoom_errors'] = $zoom_errors;
    }

    wp_send_json_success($response);
}

// Get Sessions
add_action('wp_ajax_academy_get_sessions', 'ajax_academy_get_sessions');
function ajax_academy_get_sessions()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    global $wpdb;

    $program_id = intval($_POST['program_id'] ?? 0);
    $sessions_table = $wpdb->prefix . 'academy_sessions';
    $programs_table = $wpdb->prefix . 'academy_programs';

    $where = "WHERE s.session_date >= CURDATE()";
    if ($program_id) {
        $where .= $wpdb->prepare(" AND s.program_id = %d", $program_id);
    }

    $sessions = $wpdb->get_results("
        SELECT s.*, p.program_name
        FROM {$sessions_table} s
        INNER JOIN {$programs_table} p ON s.program_id = p.id
        {$where}
        ORDER BY s.session_date ASC, s.start_time ASC
    ", ARRAY_A);

    if (empty($sessions)) {
        wp_send_json_success([]);
        return;
    }

    foreach ($sessions as &$session) {
        $session['start_time'] = substr($session['start_time'], 0, 5);
        $session['end_time'] = substr($session['end_time'], 0, 5);
    }

    wp_send_json_success($sessions);
}

// Notify Session Users
add_action('wp_ajax_academy_notify_session', 'ajax_academy_notify_session');
function ajax_academy_notify_session()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $session_id = intval($_POST['session_id'] ?? 0);

    if (!$session_id) {
        wp_send_json_error('Invalid session ID');
    }

    $result = academy_send_session_notification($session_id);

    if ($result) {
        wp_send_json_success(['message' => 'Notifications sent successfully']);
    } else {
        wp_send_json_error('Failed to send notifications');
    }
}

// Get Registrations
add_action('wp_ajax_academy_get_registrations', 'ajax_academy_get_registrations');
function ajax_academy_get_registrations()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    global $wpdb;

    $program_id = intval($_POST['program_id'] ?? 0);
    $registrations_table = $wpdb->prefix . 'academy_registrations';
    $programs_table = $wpdb->prefix . 'academy_programs';

    $where = "WHERE 1=1";
    if ($program_id) {
        $where .= $wpdb->prepare(" AND r.program_id = %d", $program_id);
    }

    $registrations = $wpdb->get_results("
        SELECT r.*, p.program_name
        FROM {$registrations_table} r
        INNER JOIN {$programs_table} p ON r.program_id = p.id
        {$where}
        ORDER BY r.registered_at DESC
    ", ARRAY_A);

    wp_send_json_success($registrations ?: []);
}

// ==========================================
// NOTIFICATION FUNCTIONS
// ==========================================

function academy_send_session_notification($session_id)
{
    global $wpdb;

    $sessions_table = $wpdb->prefix . 'academy_sessions';
    $programs_table = $wpdb->prefix . 'academy_programs';
    $registrations_table = $wpdb->prefix . 'academy_registrations';

    $session = $wpdb->get_row($wpdb->prepare("
        SELECT s.*, p.program_name
        FROM {$sessions_table} s
        INNER JOIN {$programs_table} p ON s.program_id = p.id
        WHERE s.id = %d
    ", $session_id), ARRAY_A);

    if (!$session) {
        return false;
    }

    // Get registered users for this program
    $registrations = $wpdb->get_results($wpdb->prepare("
        SELECT * FROM {$registrations_table}
        WHERE program_id = %d AND registration_status = 'registered'
    ", $session['program_id']), ARRAY_A);

    if (empty($registrations)) {
        return false;
    }

    $sent_count = 0;
    foreach ($registrations as $reg) {
        $email_sent = academy_send_session_email($reg, $session);
        if ($email_sent) {
            $sent_count++;
        }
    }

    // Mark notification as sent
    $wpdb->update(
        $sessions_table,
        ['notification_sent' => 1],
        ['id' => $session_id],
        ['%d'],
        ['%d']
    );

    return $sent_count > 0;
}

function academy_send_session_email($registration, $session)
{
    $to = $registration['email'];
    $subject = 'Tanafs Academy - Upcoming Session: ' . $session['program_name'];

    $session_date = date('l, F j, Y', strtotime($session['session_date']));
    $session_time = substr($session['start_time'], 0, 5) . ' - ' . substr($session['end_time'], 0, 5);

    $zoom_link = $session['zoom_join_url'] ? $session['zoom_join_url'] : 'Will be provided soon';
    $zoom_password = $session['zoom_password'] ? $session['zoom_password'] : 'N/A';

    $message = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:0; background:#f6f6f6; font-family:Arial, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f6; padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0"
                       style="background:#ffffff; border-radius:10px; overflow:hidden;
                              box-shadow:0 4px 10px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background:linear-gradient(135deg, #C3DDD2, #6059A6);
                                   padding:30px 20px; text-align:center;
                                   color:#ffffff; font-size:24px; font-weight:bold;">
                            Tanafs Academy - Session Reminder
                        </td>
                    </tr>

                    <!-- Body -->
                    <tr>
                        <td style="padding:30px; color:#333; font-size:16px; line-height:26px;">
                            <p>Dear {$registration['full_name']},</p>

                            <p>This is a reminder about your upcoming session:</p>

                            <div style="background:#f9f9f9; padding:20px; border-radius:8px; margin:20px 0;">
                                <p style="margin:5px 0;"><strong>Program:</strong> {$session['program_name']}</p>
                                <p style="margin:5px 0;"><strong>Date:</strong> {$session_date}</p>
                                <p style="margin:5px 0;"><strong>Time:</strong> {$session_time}</p>
                                <p style="margin:5px 0;"><strong>Meeting Password:</strong> {$zoom_password}</p>
                            </div>

                            <p style="text-align:center; margin:30px 0;">
                                <a href="{$zoom_link}"
                                   style="display:inline-block; padding:14px 30px;
                                          background:linear-gradient(135deg, #C3DDD2, #6059A6);
                                          color:#fff; text-decoration:none;
                                          border-radius:6px; font-weight:bold; font-size:16px;">
                                    Join Zoom Meeting
                                </a>
                            </p>

                            <p>We look forward to seeing you!</p>

                            <p style="margin-top:30px;">
                                Best regards,<br>
                                <strong>Tanafs Academy Team</strong>
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background:#f0f0f0; padding:16px; text-align:center;
                                   font-size:12px; color:#666;">
                            © 2025 Tanafs Academy. All rights reserved.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

    return wp_mail($to, $subject, $message);
}

// ==========================================
// SHORTCODE: PHASE 1 PROGRAMS DISPLAY (Figma Design)
// ==========================================

add_shortcode('tanafs_academy_phase1', 'academy_phase1_shortcode');
function academy_phase1_shortcode()
{
    global $wpdb;
    $programs_table = $wpdb->prefix . 'academy_programs';
    $programs = $wpdb->get_results("SELECT * FROM {$programs_table} WHERE phase = 1 AND is_active = 1 ORDER BY id ASC");

    if (empty($programs)) {
        return '<p>No programs available at the moment.</p>';
    }

    $is_arabic = academy_is_arabic();
    $dir = $is_arabic ? 'rtl' : 'ltr';
    $text_align = $is_arabic ? 'right' : 'left';
    $text_align_opposite = $is_arabic ? 'left' : 'right';

    // Enqueue Select2 for country dropdown
    wp_enqueue_style('select2-css', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css');
    wp_enqueue_script('select2-js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], null, true);

    // Card color variations (matching Figma - RTL order: right to left)
    $card_colors = [
        1 => [ // Foundation in Group Therapy
            'gradient' => 'linear-gradient(135deg, #7b74cc 0%, #6059A6 100%)',
            'chip_border' => '#6059A6',
            'title_color' => '#ffffff'
        ],
        2 => [ // Ethics & Cultural Sensitivity
            'gradient' => 'linear-gradient(135deg, #C8E6EB 0%, #8FBFC8 100%)',
            'chip_border' => '#6059A6',
            'title_color' => '#ffffff'
        ],
        3 => [ // Creative Group Therapy
            'gradient' => 'linear-gradient(135deg, #8e86d6 0%, #6059A6 100%)',
            'chip_border' => '#6059A6',
            'title_color' => '#ffffff'
        ]
    ];

    ob_start();
?>
    <style>
        body {
            overflow-x: hidden !important;
        }

        .academy-phase1-section {
            background-color: #f4f4f7 !important;
            width: 100vw !important;
            position: relative !important;
            left: 50% !important;
            right: 50% !important;
            margin-left: -50vw !important;
            margin-right: -50vw !important;
            padding: 60px 0 !important;
            margin-top: 0 !important;
            margin-bottom: 0 !important;
            direction: <?php echo $dir; ?>;
        }

        .academy-phase1-section * {
            font-family: <?php echo $is_arabic ? "'Almarai', 'Noto Sans Arabic', sans-serif" : "Arial, sans-serif"; ?>;
            box-sizing: border-box;
        }

        .academy-phase1-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .academy-phase1-header {
            text-align: center;
            margin-bottom: 64px;
        }

        .academy-phase1-header h2 {
            color: #2b2540;
            font-size: 48px;
            font-weight: 800;
            margin-bottom: 24px;
            line-height: 1.3;
        }

        .academy-phase1-header p {
            color: #4b4b5f;
            font-size: 20px;
            max-width: 860px;
            margin: 0 auto;
            line-height: 1.8;
        }

        .academy-phase1-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 24px;
        }

        .academy-phase1-card {
            background: #fff;
            border-radius: 20px;
            padding: 24px;
            box-shadow: 0 8px 24px rgba(22, 27, 45, 0.08);
            display: flex;
            flex-direction: column;
            gap: 16px;
            transition: all 0.25s ease;
            height: 100%;
        }

        .academy-phase1-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 16px 32px rgba(22, 27, 45, 0.12);
        }

        .academy-card-header {
            border-radius: 18px;
            padding: 18px;
            min-height: 120px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }

        .academy-card-title-ar {
            color: #ffffff;
            font-size: 20px;
            font-weight: 800;
            margin: 0;
            line-height: 1.6;
        }

        .academy-card-chip {
            align-self: center;
            padding: 6px 20px;
            border: 1.5px solid #9b8dc8;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.02em;
            background: transparent;
            color: #9b8dc8;
            margin-top: 8px;
        }

        .academy-card-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }

        .academy-info-list {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .academy-info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 4px 0;
            min-height: 44px;
            border-bottom: 1px solid #eceff7;
        }

        .academy-info-row:last-of-type {
            border-bottom: none;
        }

        .academy-info-label {
            color: #7a7a90;
            font-size: 13px;
            font-weight: 500;
            min-width: 120px;
            text-align: <?php echo $text_align; ?>;
            line-height: 1.4;
        }

        .academy-info-value {
            color: #1f1f33;
            font-size: 14px;
            font-weight: 600;
            text-align: <?php echo $text_align_opposite; ?>;
            flex: 1;
            line-height: 1.5;
        }

        .academy-card-btn {
            width: 100%;
            padding: 14px 20px;
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: auto;
            box-shadow: 0 8px 20px rgba(99, 91, 163, 0.25);
            background: linear-gradient(to bottom, #C3DDD2, #635ba3);
        }

        .academy-card-btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }

        .academy-card-btn .plus-icon {
            font-size: 16px;
            font-weight: 700;
        }

        /* Responsive Grid */
        @media (max-width: 992px) {
            .academy-phase1-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 768px) {
            .academy-phase1-grid {
                grid-template-columns: 1fr;
            }

            .academy-phase1-header h2 {
                font-size: 32px;
            }

            .academy-phase1-header p {
                font-size: 16px;
            }
        }
    </style>
    <?php if ($is_arabic): ?>
        <link href="https://fonts.googleapis.com/css2?family=Almarai:wght@400;700;800&family=Noto+Sans+Arabic:wght@400;500;600;700&display=swap" rel="stylesheet">
    <?php endif; ?>

    <section class="academy-phase1-section">
        <div class="academy-phase1-container">
            <div class="academy-phase1-header">
                <h2><?php echo academy_get_text('phase_1'); ?></h2>
                <p><?php echo academy_get_text('phase_subtitle'); ?></p>
            </div>

            <div class="academy-phase1-grid">
                <?php
                // Reverse order for RTL display (so Foundation appears on right, Creative on left)
                $display_programs = $is_arabic ? array_reverse($programs) : $programs;
                $card_index = 0;

                foreach ($display_programs as $program):
                    $card_index++;
                    $colors = $card_colors[$program->id] ?? $card_colors[1];

                    $program_name = $is_arabic && !empty($program->program_name_ar) ? $program->program_name_ar : $program->program_name;
                    $program_name_en = $program->program_name;
                    $program_audience = $is_arabic && !empty($program->target_audience_ar) ? $program->target_audience_ar : $program->target_audience;
                    $program_mode = $is_arabic && !empty($program->delivery_mode_ar) ? $program->delivery_mode_ar : $program->delivery_mode;
                    $program_curriculum = $is_arabic && !empty($program->description_ar) ? $program->description_ar : $program->description;

                    // Format schedule text
                    $schedule_text = $program->hours_per_week . ' ' . academy_get_text('hours_weekly_duration') . ' ' . $program->duration_weeks . ' ' . academy_get_text('weeks');
                ?>
                    <div class="academy-phase1-card">
                        <div class="academy-card-header" style="background: <?php echo $colors['gradient']; ?>;">
                            <h3 class="academy-card-title-ar" style="color: <?php echo $colors['title_color'] ?? '#ffffff'; ?>;">
                                <?php echo esc_html($program_name); ?>
                            </h3>
                        </div>

                        <span class="academy-card-chip" style="border-color: <?php echo $colors['chip_border']; ?>; color: <?php echo $colors['chip_border']; ?>;" dir="ltr">
                            <?php echo esc_html($program_name_en); ?>
                        </span>

                        <div class="academy-card-body">
                            <div class="academy-info-list">
                                <div class="academy-info-row">
                                    <span class="academy-info-label"><?php echo academy_get_text('duration_label'); ?></span>
                                    <span class="academy-info-value"><?php echo esc_html($program->duration_hours); ?> <?php echo academy_get_text('hour'); ?></span>
                                </div>

                                <div class="academy-info-row">
                                    <span class="academy-info-label"><?php echo academy_get_text('schedule_label'); ?></span>
                                    <span class="academy-info-value"><?php echo esc_html($schedule_text); ?></span>
                                </div>

                                <div class="academy-info-row">
                                    <span class="academy-info-label"><?php echo academy_get_text('delivery_mode'); ?></span>
                                    <span class="academy-info-value"><?php echo esc_html($program_mode); ?></span>
                                </div>

                                <div class="academy-info-row">
                                    <span class="academy-info-label"><?php echo academy_get_text('participants_count'); ?></span>
                                    <span class="academy-info-value"><?php echo esc_html($program->max_participants); ?> <?php echo academy_get_text('participants'); ?></span>
                                </div>

                                <div class="academy-info-row">
                                    <span class="academy-info-label"><?php echo academy_get_text('target_audience'); ?></span>
                                    <span class="academy-info-value"><?php echo esc_html($program_audience); ?></span>
                                </div>
                            </div>

                            <button class="academy-view-details-btn academy-card-btn"
                                data-program-id="<?php echo esc_attr($program->id); ?>"
                                data-program-name="<?php echo esc_attr($program_name); ?>"
                                data-program-description="<?php echo esc_attr($program_curriculum); ?>"
                                data-program-hours="<?php echo esc_attr($program->duration_hours); ?>"
                                data-program-weeks="<?php echo esc_attr($program->duration_weeks); ?>"
                                data-program-weekly="<?php echo esc_attr($program->hours_per_week); ?>"
                                data-program-mode="<?php echo esc_attr($program_mode); ?>"
                                data-program-max="<?php echo esc_attr($program->max_participants); ?>"
                                data-program-audience="<?php echo esc_attr($program_audience); ?>">
                                <span class="plus-icon">+</span>
                                <?php echo academy_get_text('view_details'); ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- Program Details Modal -->
    <div id="academy-details-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:9999; overflow-y:auto; direction: <?php echo $dir; ?>;">
        <div style="position:relative; max-width:800px; margin:40px auto; background:#fff; border-radius:16px; overflow:hidden; box-shadow:0 10px 40px rgba(0,0,0,0.3);">
            <span id="academy-close-details" style="position:absolute; top:20px; <?php echo $is_arabic ? 'left' : 'right'; ?>:25px; cursor:pointer; font-size:32px; font-weight:bold; color:#3d3d4e; z-index:10; line-height:1; transition: color 0.2s;">&times;</span>

            <!-- Modal Header -->
            <div style="background:linear-gradient(135deg, #9B8DC8 0%, #8ECFC3 100%); padding:40px 30px; text-align: <?php echo $text_align; ?>;">
                <h2 id="details-program-name" style="color:#3d3d4e; font-size:28px; font-weight:700; margin:0;"></h2>
            </div>

            <!-- Modal Body -->
            <div style="padding:30px;">
                <p id="details-program-description" style="color:#555; font-size:16px; line-height:1.8; margin-bottom:30px; text-align: <?php echo $text_align; ?>;"></p>

                <!-- Stats Grid -->
                <div style="display:grid; grid-template-columns:repeat(4, 1fr); gap:15px; margin-bottom:30px;">
                    <div style="background:#f4f4f7; padding:20px; border-radius:12px; text-align:center;">
                        <span id="details-hours" style="display:block; color:#3d3d4e; font-size:28px; font-weight:700;"></span>
                        <span style="color:#888; font-size:13px;"><?php echo academy_get_text('total_hours'); ?></span>
                    </div>
                    <div style="background:#f4f4f7; padding:20px; border-radius:12px; text-align:center;">
                        <span id="details-weeks" style="display:block; color:#3d3d4e; font-size:28px; font-weight:700;"></span>
                        <span style="color:#888; font-size:13px;"><?php echo academy_get_text('weeks'); ?></span>
                    </div>
                    <div style="background:#f4f4f7; padding:20px; border-radius:12px; text-align:center;">
                        <span id="details-weekly" style="display:block; color:#3d3d4e; font-size:28px; font-weight:700;"></span>
                        <span style="color:#888; font-size:13px;"><?php echo academy_get_text('hours_per_week'); ?></span>
                    </div>
                    <div style="background:#f4f4f7; padding:20px; border-radius:12px; text-align:center;">
                        <span id="details-max" style="display:block; color:#3d3d4e; font-size:28px; font-weight:700;"></span>
                        <span style="color:#888; font-size:13px;"><?php echo academy_get_text('max_participants'); ?></span>
                    </div>
                </div>

                <!-- Program Details -->
                <div style="background:#f4f4f7; padding:25px; border-radius:12px; margin-bottom:30px; direction: <?php echo $dir; ?>;">
                    <h4 style="color:#3d3d4e; margin:0 0 20px 0; font-size:18px; text-align: <?php echo $text_align; ?>;"><?php echo academy_get_text('program_details'); ?></h4>
                    <div style="display:grid; gap:15px;">
                        <div style="display:flex; align-items:flex-start; direction: <?php echo $dir; ?>;">
                            <span style="width:30px; color:#9B8DC8; font-size:18px; margin-<?php echo $is_arabic ? 'left' : 'right'; ?>: 10px;">💻</span>
                            <div style="text-align: <?php echo $text_align; ?>;">
                                <strong style="color:#333; display:block; font-size:14px;"><?php echo academy_get_text('delivery_mode'); ?></strong>
                                <span id="details-mode" style="color:#666; font-size:14px;"></span>
                            </div>
                        </div>
                        <div style="display:flex; align-items:flex-start; direction: <?php echo $dir; ?>;">
                            <span style="width:30px; color:#9B8DC8; font-size:18px; margin-<?php echo $is_arabic ? 'left' : 'right'; ?>: 10px;">🎯</span>
                            <div style="text-align: <?php echo $text_align; ?>;">
                                <strong style="color:#333; display:block; font-size:14px;"><?php echo academy_get_text('target_audience'); ?></strong>
                                <span id="details-audience" style="color:#666; font-size:14px;"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Register Button -->
                <div style="text-align:center;">
                    <button id="details-register-btn" style="padding:16px 50px; background:linear-gradient(90deg, #9B8DC8 0%, #8ECFC3 100%); color:#fff; border:none; border-radius:8px; font-size:18px; font-weight:700; cursor:pointer; transition:all 0.3s;">
                        <?php echo academy_get_text('register_now'); ?>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Registration Modal -->
    <div id="academy-register-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:10000; overflow-y:auto; direction: <?php echo $dir; ?>;">
        <div class="academy-register-content" style="position:relative; max-width:650px; margin:40px auto; background:#fff; border-radius:16px; overflow:hidden; box-shadow:0 10px 40px rgba(0,0,0,0.3);">
            <span id="academy-close-modal" style="position:absolute; top:20px; <?php echo $is_arabic ? 'left' : 'right'; ?>:25px; cursor:pointer; font-size:32px; font-weight:bold; color:#3d3d4e; z-index:10; line-height:1; transition:color 0.2s;">&times;</span>

            <!-- Modal Header -->
            <div style="background:linear-gradient(135deg, #9B8DC8 0%, #8ECFC3 100%); padding:30px; text-align: <?php echo $text_align; ?>;">
                <h2 style="color:#3d3d4e; font-size:24px; font-weight:700; margin:0;"><?php echo academy_get_text('register_for_program'); ?></h2>
                <p id="modal-program-name" style="color:#3d3d4e; font-size:16px; margin:10px 0 0 0; opacity:0.8;"></p>
            </div>

            <!-- Modal Body -->
            <div style="padding:30px;">
                <form id="academy-register-form">
                    <input type="hidden" id="register-program-id" name="program_id">

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
                        <div>
                            <label style="display:block; margin-bottom:8px; font-weight:600; color:#333; font-size:14px; text-align: <?php echo $text_align; ?>;"><?php echo academy_get_text('full_name'); ?> *</label>
                            <input type="text" name="full_name" required style="width:100%; padding:14px 16px; border:2px solid #e0e0e0; border-radius:10px; font-size:15px; transition:border 0.3s; box-sizing:border-box; direction: <?php echo $dir; ?>;">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:8px; font-weight:600; color:#333; font-size:14px; text-align: <?php echo $text_align; ?>;"><?php echo academy_get_text('email'); ?> *</label>
                            <input type="email" name="email" required style="width:100%; padding:14px 16px; border:2px solid #e0e0e0; border-radius:10px; font-size:15px; transition:border 0.3s; box-sizing:border-box; direction: ltr;">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
                        <div>
                            <label style="display:block; margin-bottom:8px; font-weight:600; color:#333; font-size:14px; text-align: <?php echo $text_align; ?>;"><?php echo academy_get_text('phone'); ?></label>
                            <input type="tel" name="phone" style="width:100%; padding:14px 16px; border:2px solid #e0e0e0; border-radius:10px; font-size:15px; transition:border 0.3s; box-sizing:border-box; direction: ltr;">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:8px; font-weight:600; color:#333; font-size:14px; text-align: <?php echo $text_align; ?>;"><?php echo academy_get_text('job_title'); ?></label>
                            <input type="text" name="job_title" style="width:100%; padding:14px 16px; border:2px solid #e0e0e0; border-radius:10px; font-size:15px; transition:border 0.3s; box-sizing:border-box; direction: <?php echo $dir; ?>;">
                        </div>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
                        <div>
                            <label style="display:block; margin-bottom:8px; font-weight:600; color:#333; font-size:14px; text-align: <?php echo $text_align; ?>;"> <?php echo academy_get_text('license_number'); ?></label>
                            <input type="text" name="license_number" style="width:100%; padding:14px 16px; border:2px solid #e0e0e0; border-radius:10px; font-size:15px; transition:border 0.3s; box-sizing:border-box; direction: ltr;">
                        </div>
                        <div>
                            <label style="display:block; margin-bottom:8px; font-weight:600; color:#333; font-size:14px; text-align: <?php echo $text_align; ?>;"> <?php echo academy_get_text('country'); ?> *</label>
                            <select name="country" id="academy-country-select" required style="width:100%; padding:14px 16px; border:2px solid #e0e0e0; border-radius:10px; font-size:15px; box-sizing:border-box; direction: <?php echo $dir; ?>;">
                                <option value=""><?php echo academy_get_text('select_country'); ?></option>
                                <?php
                                $countries = ['Afghanistan', 'Albania', 'Algeria', 'Andorra', 'Angola', 'Argentina', 'Armenia', 'Australia', 'Austria', 'Azerbaijan', 'Bahamas', 'Bahrain', 'Bangladesh', 'Barbados', 'Belarus', 'Belgium', 'Belize', 'Benin', 'Bhutan', 'Bolivia', 'Bosnia and Herzegovina', 'Botswana', 'Brazil', 'Brunei', 'Bulgaria', 'Burkina Faso', 'Burundi', 'Cambodia', 'Cameroon', 'Canada', 'Cape Verde', 'Central African Republic', 'Chad', 'Chile', 'China', 'Colombia', 'Comoros', 'Congo', 'Costa Rica', 'Croatia', 'Cuba', 'Cyprus', 'Czech Republic', 'Denmark', 'Djibouti', 'Dominica', 'Dominican Republic', 'East Timor', 'Ecuador', 'Egypt', 'El Salvador', 'Equatorial Guinea', 'Eritrea', 'Estonia', 'Ethiopia', 'Fiji', 'Finland', 'France', 'Gabon', 'Gambia', 'Georgia', 'Germany', 'Ghana', 'Greece', 'Grenada', 'Guatemala', 'Guinea', 'Guinea-Bissau', 'Guyana', 'Haiti', 'Honduras', 'Hungary', 'Iceland', 'India', 'Indonesia', 'Iran', 'Iraq', 'Ireland', 'Israel', 'Italy', 'Ivory Coast', 'Jamaica', 'Japan', 'Jordan', 'Kazakhstan', 'Kenya', 'Kiribati', 'North Korea', 'South Korea', 'Kuwait', 'Kyrgyzstan', 'Laos', 'Latvia', 'Lebanon', 'Lesotho', 'Liberia', 'Libya', 'Liechtenstein', 'Lithuania', 'Luxembourg', 'Macedonia', 'Madagascar', 'Malawi', 'Malaysia', 'Maldives', 'Mali', 'Malta', 'Marshall Islands', 'Mauritania', 'Mauritius', 'Mexico', 'Micronesia', 'Moldova', 'Monaco', 'Mongolia', 'Montenegro', 'Morocco', 'Mozambique', 'Myanmar', 'Namibia', 'Nauru', 'Nepal', 'Netherlands', 'New Zealand', 'Nicaragua', 'Niger', 'Nigeria', 'Norway', 'Oman', 'Pakistan', 'Palau', 'Palestine', 'Panama', 'Papua New Guinea', 'Paraguay', 'Peru', 'Philippines', 'Poland', 'Portugal', 'Qatar', 'Romania', 'Russia', 'Rwanda', 'Saint Kitts and Nevis', 'Saint Lucia', 'Samoa', 'San Marino', 'Saudi Arabia', 'Senegal', 'Serbia', 'Seychelles', 'Sierra Leone', 'Singapore', 'Slovakia', 'Slovenia', 'Solomon Islands', 'Somalia', 'South Africa', 'South Sudan', 'Spain', 'Sri Lanka', 'Sudan', 'Suriname', 'Swaziland', 'Sweden', 'Switzerland', 'Syria', 'Taiwan', 'Tajikistan', 'Tanzania', 'Thailand', 'Togo', 'Tonga', 'Trinidad and Tobago', 'Tunisia', 'Turkey', 'Turkmenistan', 'Tuvalu', 'Uganda', 'Ukraine', 'United Arab Emirates', 'United Kingdom', 'United States', 'Uruguay', 'Uzbekistan', 'Vanuatu', 'Vatican City', 'Venezuela', 'Vietnam', 'Yemen', 'Zambia', 'Zimbabwe'];
                                foreach ($countries as $country): ?>
                                    <option value="<?php echo esc_attr($country); ?>"><?php echo esc_html($country); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <button type="submit" style="width:100%; padding:16px; background:linear-gradient(90deg, #9B8DC8 0%, #8ECFC3 100%); color:#fff; border:none; border-radius:8px; font-size:17px; font-weight:700; cursor:pointer; transition:all 0.3s;">
                        <?php echo academy_get_text('submit_registration'); ?>
                    </button>
                </form>

                <div id="academy-register-result" style="display:none; margin-top:20px; padding:15px; border-radius:10px;"></div>
            </div>
        </div>
    </div>

    <style>
        /* Close button hover */
        #academy-close-details:hover,
        #academy-close-modal:hover {
            color: #666 !important;
        }

        /* Select2 Styling for Academy Modal */
        .academy-register-content .select2-container {
            width: 100% !important;
        }

        .academy-register-content .select2-container .select2-selection--single {
            height: 50px !important;
            border: 2px solid #e0e0e0 !important;
            border-radius: 10px !important;
            padding: 0 40px 0 12px !important;
            display: flex !important;
            align-items: center !important;
        }

        .academy-register-content .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 50px !important;
            padding-left: 0 !important;
            color: #333 !important;
        }

        .academy-register-content .select2-container--default .select2-selection--single .select2-selection__arrow {
            height: 50px !important;
            right: 12px !important;
        }

        .academy-register-content .select2-container--default.select2-container--focus .select2-selection--single,
        .academy-register-content .select2-container--default.select2-container--open .select2-selection--single {
            border-color: #9B8DC8 !important;
        }

        .select2-dropdown {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            z-index: 100001;
        }

        .select2-search--dropdown .select2-search__field {
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 10px;
        }

        .select2-results__option--highlighted[aria-selected] {
            background-color: #9B8DC8 !important;
        }

        /* Input focus */
        #academy-register-form input:focus,
        #academy-register-form select:focus {
            outline: none;
            border-color: #9B8DC8;
        }

        /* Button hover */
        #details-register-btn:hover {
            opacity: 0.9;
            transform: translateY(-2px);
        }

        /* Modal responsive */
        @media (max-width: 768px) {
            .academy-register-content {
                margin: 20px !important;
            }

            #academy-register-form>div {
                grid-template-columns: 1fr !important;
            }
        }
    </style>

    <script>
        jQuery(document).ready(function($) {
            let currentProgramId = null;

            // Initialize Select2 when modal opens
            function initSelect2() {
                if ($.fn.select2) {
                    $('#academy-country-select').select2({
                        placeholder: 'Select Country',
                        allowClear: true,
                        dropdownParent: $('#academy-register-modal'),
                        width: '100%'
                    });
                }
            }

            // View Details button click
            $('.academy-view-details-btn').on('click', function() {
                const $btn = $(this);
                currentProgramId = $btn.data('program-id');

                // Populate details modal
                $('#details-program-name').text($btn.data('program-name'));
                $('#details-program-description').text($btn.data('program-description'));
                $('#details-hours').text($btn.data('program-hours'));
                $('#details-weeks').text($btn.data('program-weeks'));
                $('#details-weekly').text($btn.data('program-weekly'));
                $('#details-max').text($btn.data('program-max'));
                $('#details-mode').text($btn.data('program-mode'));
                $('#details-audience').text($btn.data('program-audience'));

                $('#academy-details-modal').fadeIn(300);
            });

            // Close details modal
            $('#academy-close-details').on('click', function() {
                $('#academy-details-modal').fadeOut(300);
            });

            // Click outside details modal
            $('#academy-details-modal').on('click', function(e) {
                if (e.target === this) {
                    $(this).fadeOut(300);
                }
            });

            // Register button in details modal
            $('#details-register-btn').on('click', function() {
                $('#academy-details-modal').fadeOut(300);

                // Set values for registration modal
                $('#register-program-id').val(currentProgramId);
                $('#modal-program-name').text($('#details-program-name').text());

                // Show registration modal
                setTimeout(function() {
                    $('#academy-register-modal').fadeIn(300);
                    initSelect2();
                }, 300);
            });

            // Close registration modal
            $('#academy-close-modal').on('click', function() {
                $('#academy-register-modal').fadeOut(300);
            });

            // Click outside registration modal
            $('#academy-register-modal').on('click', function(e) {
                if (e.target === this) {
                    $(this).fadeOut(300);
                }
            });

            // Form submission
            $('#academy-register-form').on('submit', function(e) {
                e.preventDefault();

                var formData = $(this).serialize();
                formData += '&action=academy_register_user';

                const $btn = $(this).find('button[type="submit"]');
                $btn.prop('disabled', true).text('Submitting...');

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: formData,
                    success: function(response) {
                        $btn.prop('disabled', false).text('Submit Registration');

                        if (response.success) {
                            $('#academy-register-result')
                                .css({
                                    'background': '#d4edda',
                                    'color': '#155724',
                                    'border': '1px solid #c3e6cb'
                                })
                                .html('<strong>✓ Success!</strong> ' + response.data.message)
                                .show();
                            $('#academy-register-form')[0].reset();
                            if ($.fn.select2) {
                                $('#academy-country-select').val('').trigger('change');
                            }

                            setTimeout(function() {
                                $('#academy-register-modal').fadeOut(300);
                                $('#academy-register-result').hide();
                            }, 3000);
                        } else {
                            $('#academy-register-result')
                                .css({
                                    'background': '#f8d7da',
                                    'color': '#721c24',
                                    'border': '1px solid #f5c6cb'
                                })
                                .html('<strong>✗ Error:</strong> ' + response.data)
                                .show();
                        }
                    },
                    error: function() {
                        $btn.prop('disabled', false).text('Submit Registration');
                        $('#academy-register-result')
                            .css({
                                'background': '#f8d7da',
                                'color': '#721c24',
                                'border': '1px solid #f5c6cb'
                            })
                            .html('<strong>✗ Error:</strong> An error occurred. Please try again.')
                            .show();
                    }
                });
            });
        });
    </script>
<?php
    return ob_get_clean();
}

// ==========================================
// AJAX: User Registration
// ==========================================

add_action('wp_ajax_academy_register_user', 'ajax_academy_register_user');
add_action('wp_ajax_nopriv_academy_register_user', 'ajax_academy_register_user');
function ajax_academy_register_user()
{
    global $wpdb;

    $program_id = intval($_POST['program_id'] ?? 0);
    $full_name = sanitize_text_field($_POST['full_name'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $phone = sanitize_text_field($_POST['phone'] ?? '');
    $job_title = sanitize_text_field($_POST['job_title'] ?? '');
    $license_number = sanitize_text_field($_POST['license_number'] ?? '');
    $country = sanitize_text_field($_POST['country'] ?? '');

    if (!$program_id || empty($full_name) || empty($email)) {
        wp_send_json_error('Please fill in all required fields');
    }

    if (!is_email($email)) {
        wp_send_json_error('Invalid email address');
    }

    $registrations_table = $wpdb->prefix . 'academy_registrations';

    // Check if already registered
    $existing = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$registrations_table} WHERE program_id = %d AND email = %s",
        $program_id,
        $email
    ));

    if ($existing > 0) {
        wp_send_json_error('You are already registered for this program');
    }

    $user_id = is_user_logged_in() ? get_current_user_id() : 0;

    $inserted = $wpdb->insert($registrations_table, [
        'program_id' => $program_id,
        'user_id' => $user_id,
        'full_name' => $full_name,
        'email' => $email,
        'phone' => $phone,
        'job_title' => $job_title,
        'license_number' => $license_number,
        'country' => $country,
        'registration_status' => 'registered'
    ], ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

    if ($inserted) {
        // Send confirmation email
        academy_send_registration_confirmation($email, $full_name, $program_id);

        wp_send_json_success([
            'message' => 'Registration successful! You will receive a confirmation email shortly.'
        ]);
    } else {
        wp_send_json_error('Registration failed. Please try again.');
    }
}

function academy_send_registration_confirmation($email, $name, $program_id)
{
    global $wpdb;
    $programs_table = $wpdb->prefix . 'academy_programs';

    $program = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$programs_table} WHERE id = %d", $program_id));

    if (!$program) {
        return false;
    }

    $subject = 'Tanafs Academy - Registration Confirmation';

    $message = <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0; padding:0; background:#f6f6f6; font-family:Arial, sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f6; padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0"
                       style="background:#ffffff; border-radius:10px; overflow:hidden;
                              box-shadow:0 4px 10px rgba(0,0,0,0.1);">
                    <tr>
                        <td style="background:linear-gradient(135deg, #C3DDD2, #6059A6);
                                   padding:30px 20px; text-align:center;
                                   color:#ffffff; font-size:24px; font-weight:bold;">
                            Welcome to Tanafs Academy!
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px; color:#333; font-size:16px; line-height:26px;">
                            <p>Dear {$name},</p>
                            <p>Thank you for registering for <strong>{$program->program_name}</strong>!</p>
                            <p>We're excited to have you join our professional training program. You will receive session schedules and Zoom links as they become available.</p>
                            <p>If you have any questions, please don't hesitate to contact us.</p>
                            <p style="margin-top:30px;">
                                Best regards,<br>
                                <strong>Tanafs Academy Team</strong>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f0f0f0; padding:16px; text-align:center;
                                   font-size:12px; color:#666;">
                            © 2025 Tanafs Academy. All rights reserved.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;

    return wp_mail($email, $subject, $message);
}

// ==========================================
// SHORTCODE: PROGRAM DETAILS (Optional)
// ==========================================

add_shortcode('tanafs_academy_details', 'academy_details_shortcode');
function academy_details_shortcode($atts)
{
    $atts = shortcode_atts([
        'program_id' => 0
    ], $atts);

    if (!$atts['program_id']) {
        return '<p>Invalid program ID</p>';
    }

    global $wpdb;
    $programs_table = $wpdb->prefix . 'academy_programs';
    $program = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$programs_table} WHERE id = %d AND is_active = 1", $atts['program_id']));

    if (!$program) {
        return '<p>Program not found</p>';
    }

    ob_start();
?>
    <div class="academy-detail-page" style="max-width:900px; margin:0 auto; padding:20px;">
        <h1 style="color:#6059A6; font-size:32px; margin-bottom:20px;"><?php echo esc_html($program->program_name); ?></h1>

        <div style="background:linear-gradient(135deg, rgba(195, 221, 210, 0.1), rgba(96, 89, 166, 0.1)); padding:25px; border-radius:10px; margin-bottom:25px;">
            <p style="font-size:18px; line-height:1.8; color:#333;"><?php echo esc_html($program->description); ?></p>
        </div>

        <div style="background:#fff; padding:20px; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.05);">
            <h3 style="color:#6059A6; margin-top:0;">Program Details</h3>
            <ul style="list-style:none; padding:0;">
                <li style="padding:10px 0; border-bottom:1px solid #e0e0e0;"><strong>Total Duration:</strong> <?php echo esc_html($program->duration_hours); ?> hours</li>
                <li style="padding:10px 0; border-bottom:1px solid #e0e0e0;"><strong>Program Length:</strong> <?php echo esc_html($program->duration_weeks); ?> weeks</li>
                <li style="padding:10px 0; border-bottom:1px solid #e0e0e0;"><strong>Weekly Schedule:</strong> <?php echo esc_html($program->hours_per_week); ?> hours per week</li>
                <li style="padding:10px 0; border-bottom:1px solid #e0e0e0;"><strong>Delivery Mode:</strong> <?php echo esc_html($program->delivery_mode); ?></li>
                <li style="padding:10px 0; border-bottom:1px solid #e0e0e0;"><strong>Maximum Participants:</strong> <?php echo esc_html($program->max_participants); ?></li>
                <li style="padding:10px 0;"><strong>Target Audience:</strong> <?php echo esc_html($program->target_audience); ?></li>
            </ul>
        </div>
    </div>
<?php
    return ob_get_clean();
}
