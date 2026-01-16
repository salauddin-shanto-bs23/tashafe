<?php
// --------------------------------------------------
// REGISTER Retreat CPT
// --------------------------------------------------
add_action('init', 'register_retreat_group_cpt');
function register_retreat_group_cpt()
{
    register_post_type('retreat_group', [
        'labels' => [
            'name'          => 'Retreat Groups',
            'singular_name' => 'Retreat Group',
            'add_new_item'  => 'Add New Retreat Group',
        ],
        'public'       => true,
        'show_ui'      => true,
        'show_in_menu' => true,
        'menu_icon'    => 'dashicons-palmtree',
        'supports'     => ['title'],
    ]);
}

// --------------------------------------------------
// CREATE GENDER GROUP SETTINGS TABLE
// --------------------------------------------------
function create_retreat_gender_settings_table()
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'retreat_gender_settings';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        gender_type VARCHAR(20) NOT NULL,
        cover_image_url VARCHAR(500) DEFAULT '',
        group_title VARCHAR(255) DEFAULT '',
        group_subtitle TEXT DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY gender_type (gender_type)
    ) {$charset_collate};";

    $wpdb->query($sql);

    // Insert default rows if not exist
    $types = ['male', 'female', 'teen'];
    foreach ($types as $type) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table_name} WHERE gender_type = %s",
            $type
        ));

        if (!$exists) {
            $wpdb->insert($table_name, [
                'gender_type' => $type,
                'group_title' => ucfirst($type) . ' Wellness Retreat',
                'group_subtitle' => 'A transformative retreat experience designed for ' . $type . ' participants.'
            ], ['%s', '%s', '%s']);
        }
    }
}

add_action('admin_init', 'create_retreat_gender_settings_table');

// --------------------------------------------------
// GET GENDER GROUP SETTINGS
// --------------------------------------------------
function get_retreat_gender_settings($gender_type = null)
{
    global $wpdb;
    $table_name = $wpdb->prefix . 'retreat_gender_settings';

    if ($gender_type) {
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_name} WHERE gender_type = %s", $gender_type), ARRAY_A);
    }

    return $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY FIELD(gender_type, 'male', 'female', 'teen')", ARRAY_A);
}

// --------------------------------------------------
// DATE NORMALIZATION HELPERS
// --------------------------------------------------
function parse_retreat_date_value($date)
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

function normalize_retreat_date_value($date)
{
    $dt = parse_retreat_date_value($date);
    return $dt ? $dt->format('Y-m-d') : $date;
}

// --------------------------------------------------
// AJAX: SAVE GENDER GROUP SETTINGS
// --------------------------------------------------
add_action('wp_ajax_save_retreat_gender_settings', 'handle_save_retreat_gender_settings');
function handle_save_retreat_gender_settings()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'retreat_gender_settings';

    // Ensure table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
    if ($table_exists != $table_name) {
        create_retreat_gender_settings_table();
    }

    $gender_type = sanitize_text_field($_POST['gender_type'] ?? '');

    if (!in_array($gender_type, ['male', 'female', 'teen'])) {
        wp_send_json_error('Invalid gender type');
    }

    // Get existing settings to preserve group_title and group_subtitle
    $existing = get_retreat_gender_settings($gender_type);
    $group_title = $existing['group_title'] ?? '';
    $group_subtitle = $existing['group_subtitle'] ?? '';

    // Handle image upload
    $cover_image_url = '';
    if (!empty($_FILES['cover_image']['name'])) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $upload = wp_handle_upload($_FILES['cover_image'], ['test_form' => false]);
        if (!isset($upload['error'])) {
            $cover_image_url = esc_url_raw($upload['url']);
        }
    } else {
        // Keep existing image if no new upload
        $cover_image_url = $existing['cover_image_url'] ?? '';
    }

    $query = $wpdb->prepare(
        "INSERT INTO {$table_name} (gender_type, cover_image_url, group_title, group_subtitle)
         VALUES (%s, %s, %s, %s)
         ON DUPLICATE KEY UPDATE
            cover_image_url = VALUES(cover_image_url),
            group_title = VALUES(group_title),
            group_subtitle = VALUES(group_subtitle)",
        $gender_type,
        $cover_image_url,
        $group_title,
        $group_subtitle
    );

    $result = $wpdb->query($query);

    if ($result === false) {
        wp_send_json_error('Failed to save settings: ' . $wpdb->last_error);
    }

    wp_send_json_success('Cover image saved successfully');
}

// --------------------------------------------------
// AJAX: DELETE GENDER COVER IMAGE
// --------------------------------------------------
add_action('wp_ajax_delete_retreat_gender_cover', 'handle_delete_retreat_gender_cover');
function handle_delete_retreat_gender_cover()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'retreat_gender_settings';

    $gender_type = sanitize_text_field($_POST['gender_type'] ?? '');

    if (!in_array($gender_type, ['male', 'female', 'teen'])) {
        wp_send_json_error('Invalid gender type');
    }

    $wpdb->update(
        $table_name,
        ['cover_image_url' => ''],
        ['gender_type' => $gender_type],
        ['%s'],
        ['%s']
    );

    wp_send_json_success('Cover image removed');
}

// --------------------------------------------------
// ADD RETREAT DASHBOARD MENU
// --------------------------------------------------
add_action('admin_menu', 'retreat_group_dashboard_admin_menu');
function retreat_group_dashboard_admin_menu()
{
    add_menu_page(
        'Retreat Dashboard',
        'Retreat Dashboard',
        'manage_options',
        'retreat-dashboard',
        'render_retreat_dashboard',
        'dashicons-palmtree',
        7
    );

    // Add Gender Settings submenu
    add_submenu_page(
        'retreat-dashboard',
        'Gender Group Settings',
        'Gender Settings',
        'manage_options',
        'retreat-gender-settings',
        'render_retreat_gender_settings'
    );
}

// --------------------------------------------------
// RENDER GENDER SETTINGS PAGE
// --------------------------------------------------
function render_retreat_gender_settings()
{
    $settings = get_retreat_gender_settings();

    // Ensure all three types are always available
    $all_types = ['male', 'female', 'teen'];
    $settings_by_type = [];
    foreach ($settings as $s) {
        $settings_by_type[$s['gender_type']] = $s;
    }

    // Create default entries for missing types
    $final_settings = [];
    foreach ($all_types as $type) {
        if (isset($settings_by_type[$type])) {
            $final_settings[] = $settings_by_type[$type];
        } else {
            $final_settings[] = [
                'gender_type' => $type,
                'cover_image_url' => '',
            ];
        }
    }
?>
    <div class="wrap">
        <h1>Retreat Gender Group Settings</h1>
        <p class="description">Configure the cover photo for each retreat group (Male, Women, Teen). These will be displayed on the retreat details page when users view a specific retreat.</p>

        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

        <style>
            .gender-settings-wrap {
                margin-top: 20px;
            }

            .gender-card {
                background: #fff;
                border-radius: 12px;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
                margin-bottom: 25px;
                overflow: hidden;
            }

            .gender-card-header {
                background: linear-gradient(135deg, #6059A6, #8B7DC9);
                color: #fff;
                padding: 15px 20px;
                font-weight: 600;
                font-size: 18px;
            }

            .gender-card-body {
                padding: 25px;
            }

            .cover-preview {
                width: 100%;
                max-width: 400px;
                height: 200px;
                background: #f0f0f0;
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                overflow: hidden;
                margin-bottom: 15px;
                border: 2px dashed #ccc;
            }

            .cover-preview img {
                width: 100%;
                height: 100%;
                object-fit: cover;
            }

            .cover-preview.has-image {
                border: none;
            }

            .form-label {
                font-weight: 600;
                color: #333;
            }

            .btn-save {
                background: linear-gradient(135deg, #C3DDD2, #6059A6);
                border: none;
                color: #fff;
                font-weight: 600;
            }

            .btn-save:hover {
                opacity: 0.9;
                color: #fff;
            }

            .btn-remove {
                background: #dc3545;
                border: none;
                color: #fff;
                font-size: 12px;
                padding: 4px 10px;
            }
        </style>

        <div class="gender-settings-wrap">
            <?php foreach ($final_settings as $setting): ?>
                <div class="gender-card">
                    <div class="gender-card-header">
                        <?php
                        $labels = ['male' => 'Male', 'female' => 'Female', 'teen' => 'Teen'];
                        $display_name = $labels[$setting['gender_type']] ?? ucfirst($setting['gender_type']);
                        echo esc_html($display_name . ' Retreat Cover Image');
                        ?>
                    </div>
                    <div class="gender-card-body">
                        <form class="gender-settings-form" enctype="multipart/form-data">
                            <input type="hidden" name="gender_type" value="<?php echo esc_attr($setting['gender_type']); ?>">

                            <div class="row">
                                <div class="col-md-6">
                                    <label class="form-label">Cover Photo</label>
                                    <div class="cover-preview <?php echo $setting['cover_image_url'] ? 'has-image' : ''; ?>" id="preview-<?php echo $setting['gender_type']; ?>">
                                        <?php if ($setting['cover_image_url']): ?>
                                            <img src="<?php echo esc_url($setting['cover_image_url']); ?>" alt="Cover">
                                        <?php else: ?>
                                            <span style="color:#999;">No image uploaded</span>
                                        <?php endif; ?>
                                    </div>
                                    <input type="file" name="cover_image" accept="image/*" class="form-control mb-2" onchange="previewImage(this, '<?php echo $setting['gender_type']; ?>')">
                                    <div class="d-flex gap-2 mt-2">
                                        <?php if ($setting['cover_image_url']): ?>
                                            <button type="button" class="btn btn-remove btn-sm" onclick="removeCover('<?php echo $setting['gender_type']; ?>')">Remove Cover</button>
                                        <?php endif; ?>
                                        <button type="submit" class="btn btn-save btn-sm">Save Settings</button>
                                    </div>
                                    <span class="save-status ms-2" style="display:none;"></span>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Group Title</label>
                                    <input type="text" name="group_title" class="form-control mb-3" value="<?php echo esc_attr($setting['group_title'] ?? ''); ?>" placeholder="e.g., Male Retreat 2024">
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        function previewImage(input, type) {
            const preview = document.getElementById('preview-' + type);
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.innerHTML = '<img src="' + e.target.result + '" alt="Cover">';
                    preview.classList.add('has-image');
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        function removeCover(type) {
            if (!confirm('Remove this cover image?')) return;

            fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: 'action=delete_retreat_gender_cover&gender_type=' + type
                })
                .then(res => res.json())
                .then(r => {
                    if (r.success) location.reload();
                });
        }

        document.querySelectorAll('.gender-settings-form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('action', 'save_retreat_gender_settings');

                const statusEl = this.querySelector('.save-status');
                const btn = this.querySelector('button[type="submit"]');
                btn.disabled = true;
                btn.textContent = 'Saving...';

                fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.json())
                    .then(r => {
                        btn.disabled = false;
                        btn.textContent = 'Save Cover Image';
                        if (r.success) {
                            statusEl.style.display = 'inline';
                            statusEl.style.color = 'green';
                            statusEl.textContent = '✓ Saved!';
                            setTimeout(() => {
                                statusEl.style.display = 'none';
                            }, 2000);
                        } else {
                            alert('Error: ' + r.data);
                        }
                    });
            });
        });
    </script>
<?php
}

// --------------------------------------------------
// AJAX: CREATE Retreat
// --------------------------------------------------
add_action('wp_ajax_create_retreat_group', 'handle_create_retreat_group');
function handle_create_retreat_group()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $retreat_type     = sanitize_text_field($_POST['retreat_type'] ?? '');
    $start_date_raw   = sanitize_text_field($_POST['start_date'] ?? '');
    $end_date_raw     = sanitize_text_field($_POST['end_date'] ?? '');
    $max_participants = intval($_POST['max_participants'] ?? 0);
    $trip_destination = sanitize_text_field($_POST['trip_destination'] ?? '');

    // New retreat-specific fields
    $retreat_description = sanitize_textarea_field($_POST['retreat_description'] ?? '');
    $retreat_price_sar   = sanitize_text_field($_POST['retreat_price_sar'] ?? '');
    $retreat_price_usd   = sanitize_text_field($_POST['retreat_price_usd'] ?? '');
    $package_includes    = sanitize_textarea_field($_POST['package_includes'] ?? '');

    $start_date = normalize_retreat_date_value($start_date_raw);
    $end_date   = normalize_retreat_date_value($end_date_raw);

    if (!$retreat_type || !$start_date || !$end_date || !$max_participants) {
        wp_send_json_error('All fields are required.');
    }

    $existing = get_posts([
        'post_type'      => 'retreat_group',
        'posts_per_page' => -1,
        'meta_query'     => [
            ['key' => 'retreat_type', 'value' => $retreat_type],
        ],
    ]);

    $post_id = wp_insert_post([
        'post_title'  => ucfirst($retreat_type) . ' Retreat',
        'post_type'   => 'retreat_group',
        'post_status' => 'publish',
    ]);

    if (is_wp_error($post_id)) {
        wp_send_json_error('Failed to create retreat.');
    }

    update_field('retreat_type', $retreat_type, $post_id);
    update_field('start_date', $start_date, $post_id);
    update_field('end_date', $end_date, $post_id);
    update_field('max_participants', $max_participants, $post_id);
    update_field('retreat_status', 'active', $post_id);
    update_field('retreat_number', count($existing) + 1, $post_id);
    update_field('trip_destination', $trip_destination, $post_id);

    // Save new retreat-specific fields
    update_field('retreat_description', $retreat_description, $post_id);
    update_field('retreat_price_sar', $retreat_price_sar, $post_id);
    update_field('retreat_price_usd', $retreat_price_usd, $post_id);
    update_field('package_includes', $package_includes, $post_id);

    // Notify waiting list users
    notify_retreat_waiting_list_users($retreat_type);

    wp_send_json_success('Retreat created successfully.');
}

// Notify waiting list users when new retreat group is created
function notify_retreat_waiting_list_users($retreat_type)
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'retreat_waiting_list';

    $users = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT full_name, email FROM $table_name WHERE retreat_type = %s",
            $retreat_type
        )
    );

    if (empty($users)) {
        return;
    }

    $subject = "New " . ucfirst($retreat_type) . " Retreat Available – Tashafe";

    foreach ($users as $user) {
        $message = '
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Tashafe Retreat</title>
        </head>
        <body style="margin:0; padding:0; background:#f6f6f6; font-family:Arial, sans-serif;">

        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f6; padding:40px 0;">
            <tr>
                <td align="center">

                    <table width="600" cellpadding="0" cellspacing="0"
                        style="background:#ffffff; border-radius:10px; overflow:hidden;
                               box-shadow:0 4px 12px rgba(0,0,0,0.08);">

                        <!-- Header -->
                        <tr>
                            <td style="background:linear-gradient(135deg, #C3DDD2, #6059A6);
                                       padding:24px; text-align:center;
                                       color:#ffffff; font-size:24px; font-weight:bold;">
                                Tashafe Retreats
                            </td>
                        </tr>

                        <!-- Body -->
                        <tr>
                            <td style="padding:30px; color:#333; font-size:16px; line-height:26px;">

                                <p>Hi ' . esc_html($user->full_name) . ',</p>

                                <p>
                                    Great news! A new <strong>' . ucfirst($retreat_type) . ' Retreat</strong> has just been created.
                                </p>

                                <p>
                                    Since you were on our waiting list, you can now register for this retreat.
                                    Spots are limited, so we encourage you to register as soon as possible.
                                </p>

                                <!-- Button -->
                                <table cellspacing="0" cellpadding="0" style="margin-top:20px;">
                                    <tr>
                                        <td align="center">
                                            <a href="https://tanafs.com.sa/retreat"
                                               style="display:inline-block; padding:14px 28px;
                                                      background:linear-gradient(135deg, #C3DDD2, #6059A6);
                                                      color:#fff; text-decoration:none;
                                                      font-weight:600; border-radius:6px;
                                                      font-size:16px;">
                                                Register Now
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
                                © ' . date("Y") . ' Tashafe — All Rights Reserved.
                            </td>
                        </tr>

                    </table>

                </td>
            </tr>
        </table>

        </body>
        </html>
        ';

        wp_mail($user->email, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
    }
}

// --------------------------------------------------
// AJAX: TOGGLE Retreat STATUS
// --------------------------------------------------
add_action('wp_ajax_toggle_retreat_status', 'handle_toggle_retreat_status');
function handle_toggle_retreat_status()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $group_id     = intval($_POST['group_id'] ?? 0);
    $retreat_type = sanitize_text_field($_POST['retreat_type'] ?? '');
    $status       = sanitize_text_field($_POST['status'] ?? '');

    if (!$group_id || !$retreat_type || !$status) {
        wp_send_json_error('Invalid data.');
    }

    if ($status === 'active') {
        $others = get_posts([
            'post_type'      => 'retreat_group',
            'posts_per_page' => -1,
            'post__not_in'   => [$group_id],
            'meta_query'     => [
                ['key' => 'retreat_type', 'value' => $retreat_type],
            ],
        ]);
        foreach ($others as $other) {
            update_field('retreat_status', 'inactive', $other->ID);
        }
    }

    update_field('retreat_status', $status, $group_id);
    wp_send_json_success('Status updated.');
}

// --------------------------------------------------
// AJAX: EDIT Retreat Group
// --------------------------------------------------
add_action('wp_ajax_edit_retreat_group', 'handle_edit_retreat_group');
function handle_edit_retreat_group()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $group_id = intval($_POST['group_id'] ?? 0);
    $start_date_raw = sanitize_text_field($_POST['start_date'] ?? '');
    $end_date_raw = sanitize_text_field($_POST['end_date'] ?? '');
    $max_participants = intval($_POST['max_participants'] ?? 0);
    $trip_destination = sanitize_text_field($_POST['trip_destination'] ?? '');

    // New retreat-specific fields
    $retreat_description = sanitize_textarea_field($_POST['retreat_description'] ?? '');
    $retreat_price_sar   = sanitize_text_field($_POST['retreat_price_sar'] ?? '');
    $retreat_price_usd   = sanitize_text_field($_POST['retreat_price_usd'] ?? '');
    $package_includes    = sanitize_textarea_field($_POST['package_includes'] ?? '');

    if (!$group_id) {
        wp_send_json_error('Invalid group ID.');
    }

    $start_date = normalize_retreat_date_value($start_date_raw);
    $end_date   = normalize_retreat_date_value($end_date_raw);

    if (empty($start_date) || empty($end_date) || !$max_participants) {
        wp_send_json_error('Please fill all required fields.');
    }

    // Verify the group exists
    $post = get_post($group_id);
    if (!$post || $post->post_type !== 'retreat_group') {
        wp_send_json_error('Retreat group not found.');
    }

    // Update ACF fields
    update_field('start_date', $start_date, $group_id);
    update_field('end_date', $end_date, $group_id);
    update_field('max_participants', $max_participants, $group_id);
    update_field('trip_destination', $trip_destination, $group_id);

    // Update new retreat-specific fields
    update_field('retreat_description', $retreat_description, $group_id);
    update_field('retreat_price_sar', $retreat_price_sar, $group_id);
    update_field('retreat_price_usd', $retreat_price_usd, $group_id);
    update_field('package_includes', $package_includes, $group_id);

    wp_send_json_success('Retreat updated successfully!');
}

// --------------------------------------------------
// AJAX: DELETE Retreat Group
// --------------------------------------------------
add_action('wp_ajax_delete_retreat_group', 'handle_delete_retreat_group');
function handle_delete_retreat_group()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    $group_id = intval($_POST['group_id'] ?? 0);

    if (!$group_id) {
        wp_send_json_error('Invalid group ID.');
    }

    // Check if group exists
    $post = get_post($group_id);
    if (!$post || $post->post_type !== 'retreat_group') {
        wp_send_json_error('Retreat group not found.');
    }

    // Delete the retreat group post
    $deleted = wp_delete_post($group_id, true);

    if ($deleted) {
        wp_send_json_success('Retreat group deleted successfully.');
    } else {
        wp_send_json_error('Failed to delete retreat group.');
    }
}

// --------------------------------------------------
// RENDER Retreat Group Accordions
// --------------------------------------------------
function render_retreat_groups($retreat_type)
{
    $groups = get_posts([
        'post_type'      => 'retreat_group',
        'posts_per_page' => -1,
        'meta_query'     => [['key' => 'retreat_type', 'value' => $retreat_type]],
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);

    if (!$groups) {
        echo '<p>No retreats found.</p>';
        return;
    }

    $accordion_id = esc_attr($retreat_type . '_accordion');
    echo '<div class="accordion" id="' . $accordion_id . '">';

    foreach ($groups as $group) {
        $post_id       = $group->ID;
        $collapse_id   = 'retreat_collapse_' . $post_id;

        // Get field values for edit modal
        $start_date = get_field('start_date', $post_id);
        $end_date = get_field('end_date', $post_id);
        $max_participants = get_field('max_participants', $post_id);
        $trip_destination = get_field('trip_destination', $post_id);

        // New retreat-specific fields
        $retreat_description = get_field('retreat_description', $post_id);
        $retreat_price_sar = get_field('retreat_price_sar', $post_id);
        $retreat_price_usd = get_field('retreat_price_usd', $post_id);
        $package_includes = get_field('package_includes', $post_id);
        $retreat_title = get_field('retreat_title', $post_id) ?: get_the_title($post_id);

        // Convert dates to YYYY-MM-DD format for HTML date inputs
        if ($start_date) {
            $start_dt = parse_retreat_date_value($start_date);
            if ($start_dt) {
                $start_date = $start_dt->format('Y-m-d');
            }
        }
        if ($end_date) {
            $end_dt = parse_retreat_date_value($end_date);
            if ($end_dt) {
                $end_date = $end_dt->format('Y-m-d');
            }
        }

        echo '<div class="accordion-item">';
        echo '<h2 class="accordion-header d-flex align-items-center justify-content-between" id="heading_' . $post_id . '">';
        echo '<button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#' . $collapse_id . '" style="flex:0 1 auto;max-width:300px;">';
        echo esc_html($retreat_title) . ' <small style="color:#999;margin-left:8px;">(#' . esc_html(get_field('retreat_number', $post_id)) . ')</small>';
        echo '</button>';
        echo '<div class="d-flex align-items-center" style="gap:15px;">';
        echo '<button class="btn btn-sm edit-retreat" data-id="' . esc_attr($post_id) . '" data-type="' . esc_attr($retreat_type) . '" data-start-date="' . esc_attr($start_date) . '" data-end-date="' . esc_attr($end_date) . '" data-max-participants="' . esc_attr($max_participants) . '" data-trip-destination="' . esc_attr($trip_destination) . '" data-retreat-description="' . esc_attr($retreat_description) . '" data-retreat-price-sar="' . esc_attr($retreat_price_sar) . '" data-retreat-price-usd="' . esc_attr($retreat_price_usd) . '" data-package-includes="' . esc_attr($package_includes) . '" title="Edit Retreat" data-bs-toggle="modal" data-bs-target="#editRetreatModal" style="padding:4px 12px;font-size:12px;background:#635ba3;border:none;color:white;border-radius:4px;"><i class="bi bi-pencil"></i> Edit</button>';
        echo '<button class="btn btn-sm btn-danger delete-retreat" data-id="' . esc_attr($post_id) . '" data-type="' . esc_attr($retreat_type) . '" title="Delete Retreat" style="padding:4px 12px;font-size:12px;margin-right:10px;"><i class="bi bi-trash"></i> Delete</button>';
        echo '</div>';
        echo '</h2>';

        echo '<div id="' . $collapse_id . '" class="accordion-collapse collapse" data-bs-parent="#' . $accordion_id . '">';
        echo '<div class="accordion-body">';
        echo '<p><strong>Retreat Start:</strong> ' . esc_html($start_date) . '</p>';
        echo '<p><strong>Retreat End:</strong> ' . esc_html($end_date) . '</p>';
        echo '<p><strong>Max Participants:</strong> ' . esc_html($max_participants) . '</p>';

        // Display new retreat-specific fields
        if ($retreat_description || $retreat_price_sar || $retreat_price_usd || $package_includes) {
            echo '<hr style="margin:15px 0;border-color:#e0e0e0;">';
            echo '<h6 style="color:#6059A6;margin-bottom:10px;">Retreat Package Details</h6>';
            if ($retreat_description) echo '<p><strong>Description:</strong> ' . nl2br(esc_html($retreat_description)) . '</p>';
            if ($retreat_price_sar) echo '<p><strong>Price (SAR):</strong> ' . esc_html($retreat_price_sar) . ' SAR</p>';
            if ($retreat_price_usd) echo '<p><strong>Price (USD):</strong> $' . esc_html($retreat_price_usd) . '</p>';
            if ($package_includes) {
                echo '<p><strong>Package Includes:</strong></p><ul>';
                $items = explode("\n", $package_includes);
                foreach ($items as $item) {
                    $item = trim($item);
                    if ($item) echo '<li>' . esc_html($item) . '</li>';
                }
                echo '</ul>';
            }
        }

        $trip_destination_display = get_field('trip_destination', $post_id);
        if ($trip_destination_display) {
            echo '<hr style="margin:15px 0;border-color:#e0e0e0;">';
            echo '<h6 style="color:#6059A6;margin-bottom:10px;">Location</h6>';
            echo '<p><strong>Destination:</strong> ' . esc_html($trip_destination_display) . '</p>';
        }

        // Get users assigned to this retreat group
        $members = get_users([
            'meta_key' => 'assigned_retreat_group',
            'meta_value' => $post_id,
            'number' => -1,
        ]);

        if ($members) {
            echo '<div class="table-responsive mt-3">';
            echo '<h5>Registered Participants (' . count($members) . ')</h5>';
            echo '<table class="table table-sm table-bordered">';
            echo '<thead class="table-light">';
            echo '<tr><th>#</th><th>Name</th><th>Email</th><th>Birth Date</th><th>Phone</th><th>Country</th><th>Passport</th><th>Registered</th><th>Questionnaire</th></tr>';
            echo '</thead><tbody>';

            $i = 1;
            foreach ($members as $member) {
                $first_name = get_user_meta($member->ID, 'first_name', true);
                $last_name = get_user_meta($member->ID, 'last_name', true);
                $full_name = trim($first_name . ' ' . $last_name) ?: $member->display_name;
                $birth_date = get_user_meta($member->ID, 'birth_date', true);
                $phone = get_user_meta($member->ID, 'phone', true);
                $country = get_user_meta($member->ID, 'country', true);
                $passport = get_user_meta($member->ID, 'passport_file', true);
                $reg_date = date('Y-m-d', strtotime($member->user_registered));

                echo '<tr>';
                echo '<td>' . $i++ . '</td>';
                echo '<td>' . esc_html($full_name) . '</td>';
                echo '<td>' . esc_html($member->user_email) . '</td>';
                echo '<td>' . esc_html($birth_date) . '</td>';
                echo '<td>' . esc_html($phone) . '</td>';
                echo '<td>' . esc_html($country) . '</td>';
                echo '<td>' . ($passport ? '<a href="' . esc_url($passport) . '" target="_blank">View</a>' : 'N/A') . '</td>';
                echo '<td>' . esc_html($reg_date) . '</td>';
                echo '<td><button class="btn btn-sm btn-info view-questionnaire-answers" data-user-id="' . $member->ID . '" data-user-name="' . esc_attr($full_name) . '">View Answers</button></td>';
                echo '</tr>';
            }

            echo '</tbody></table></div>';
        } else {
            echo '<p class="mt-3"><em>No participants registered yet.</em></p>';
        }

        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    echo '</div>';
}

// --------------------------------------------------
// RETREAT DASHBOARD UI
// --------------------------------------------------
function render_retreat_dashboard()
{
?>
    <div class="wrap">
        <h1>Retreat Dashboard</h1>

        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

        <style>
            .accordion-button:not(.collapsed) {
                background: #6059A6;
                color: white;
            }

            button.btn-create {
                background: #635ba3;
                color: white;
                border: none;
            }

            button.btn-create:hover {
                background: #b4aad0;
            }
        </style>

        <ul class="nav nav-tabs mb-3" id="retreatTabs">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#male">Male</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#female">Female</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#teen">Teen</a></li>
        </ul>

        <div class="tab-content">
            <?php foreach (['male', 'female', 'teen'] as $type) { ?>
                <div class="tab-pane fade <?php echo ($type === 'male') ? 'show active' : ''; ?>" id="<?php echo esc_attr($type); ?>">
                    <button class="btn btn-sm btn-create mb-2 open-retreat-modal" data-type="<?php echo esc_attr($type); ?>" data-bs-toggle="modal" data-bs-target="#createRetreatModal">Create Retreat</button>
                    <?php render_retreat_groups($type); ?>
                </div>
            <?php } ?>
        </div>
    </div>

    <!-- CREATE RETREAT MODAL -->
    <div class="modal fade" id="createRetreatModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form id="createRetreatForm">
                <div class="modal-content">
                    <div class="modal-header" style="background:#6059A6;color:white;">
                        <h5 class="modal-title">Create Retreat</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="retreatAlert"></div>
                        <input type="hidden" name="retreat_type" id="retreat_type">

                        <div class="row">
                            <div class="col-md-6">
                                <h6 style="color:#6059A6;margin-bottom:15px;border-bottom:1px solid #e0e0e0;padding-bottom:10px;">Retreat Schedule</h6>
                                <div class="mb-3">
                                    <label>Retreat Start Date <span class="text-danger">*</span></label>
                                    <input type="date" name="start_date" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label>Retreat End Date <span class="text-danger">*</span></label>
                                    <input type="date" name="end_date" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label>Max Participants <span class="text-danger">*</span></label>
                                    <input type="number" name="max_participants" class="form-control" value="20" required>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <h6 style="color:#6059A6;margin-bottom:15px;border-bottom:1px solid #e0e0e0;padding-bottom:10px;">Package Details</h6>
                                <div class="mb-3">
                                    <label>Price (SAR)</label>
                                    <input type="text" name="retreat_price_sar" class="form-control" placeholder="e.g., 4800">
                                </div>
                                <div class="mb-3">
                                    <label>Price (USD)</label>
                                    <input type="text" name="retreat_price_usd" class="form-control" placeholder="e.g., 410">
                                </div>
                                <div class="mb-3">
                                    <label>Location</label>
                                    <input type="text" name="trip_destination" class="form-control" placeholder="e.g., Wahiba Sands, Oman">
                                </div>
                            </div>
                        </div>

                        <h6 style="color:#6059A6;margin-top:20px;margin-bottom:15px;border-bottom:1px solid #e0e0e0;padding-bottom:10px;">Retreat Description</h6>
                        <div class="mb-3">
                            <label>Description</label>
                            <textarea name="retreat_description" class="form-control" rows="4" placeholder="Describe the retreat experience..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label>Package Includes (one item per line)</label>
                            <textarea name="package_includes" class="form-control" rows="5" placeholder="Luxury Accommodation&#10;All Meals&#10;Daily Therapy Sessions&#10;Breathwork Workshops&#10;Wellness Activities">Luxury Accommodation
All Meals
Daily Therapy Sessions
Breathwork Workshops
Wellness Activities</textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-create">Create</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.querySelectorAll('.open-retreat-modal').forEach(btn => {
            btn.addEventListener('click', () => {
                document.getElementById('retreat_type').value = btn.dataset.type;
            });
        });

        document.getElementById('createRetreatForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const data = new FormData(this);
            data.append('action', 'create_retreat_group');

            fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                    method: 'POST',
                    body: data
                })
                .then(res => res.json())
                .then(r => {
                    if (r.success) {
                        location.reload();
                    } else {
                        alert(r.data);
                    }
                });
        });

        // Delete retreat group
        document.querySelectorAll('.delete-retreat').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (!confirm('Are you sure you want to delete this retreat group? This action cannot be undone.')) {
                    return;
                }

                const data = new FormData();
                data.append('action', 'delete_retreat_group');
                data.append('group_id', this.dataset.id);

                fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                        method: 'POST',
                        body: data
                    })
                    .then(res => res.json())
                    .then(r => {
                        if (r.success) {
                            location.reload();
                        } else {
                            alert('Error: ' + r.data);
                        }
                    });
            });
        });

        // View questionnaire answers
        document.querySelectorAll('.view-questionnaire-answers').forEach(btn => {
            btn.addEventListener('click', function() {
                const userId = this.dataset.userId;
                const userName = this.dataset.userName;

                fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'action=get_user_questionnaire_answers&user_id=' + userId
                    })
                    .then(res => res.json())
                    .then(r => {
                        if (r.success && r.data.answers && r.data.answers.length > 0) {
                            let html = '<div class="qa-list">';
                            r.data.answers.forEach(qa => {
                                html += '<div class="qa-item">';
                                html += '<div class="qa-question">Q' + qa.question_number + ': ' + qa.question_text + '</div>';
                                html += '<div class="qa-answer">' + qa.answer.replace(/\n/g, '<br>') + '</div>';
                                html += '</div>';
                            });
                            html += '</div>';

                            document.getElementById('questionnaireModalLabel').textContent = userName + ' - Questionnaire Answers';
                            document.getElementById('questionnaireAnswersContent').innerHTML = html;
                            new bootstrap.Modal(document.getElementById('questionnaireModal')).show();
                        } else {
                            alert(r.data || 'No questionnaire answers found for this user.');
                        }
                    });
            });
        });
    </script>

    <!-- Edit Retreat Modal -->
    <div class="modal fade" id="editRetreatModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <form id="editRetreatForm">
                <div class="modal-content">
                    <div class="modal-header" style="background:#6059A6;color:white;">
                        <h5 class="modal-title">Edit Retreat</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div id="editRetreatAlert"></div>
                        <input type="hidden" name="group_id" id="edit_retreat_id">
                        <input type="hidden" name="retreat_type" id="edit_retreat_type">

                        <div class="row">
                            <div class="col-md-6">
                                <h6 style="color:#6059A6;margin-bottom:15px;border-bottom:1px solid #e0e0e0;padding-bottom:10px;">Retreat Schedule</h6>
                                <div class="mb-3">
                                    <label>Retreat Start Date <span class="text-danger">*</span></label>
                                    <input type="date" name="start_date" id="edit_start_date" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label>Retreat End Date <span class="text-danger">*</span></label>
                                    <input type="date" name="end_date" id="edit_end_date" class="form-control" required>
                                </div>
                                <div class="mb-3">
                                    <label>Max Participants <span class="text-danger">*</span></label>
                                    <input type="number" name="max_participants" id="edit_max_participants" class="form-control" required>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <h6 style="color:#6059A6;margin-bottom:15px;border-bottom:1px solid #e0e0e0;padding-bottom:10px;">Package Details</h6>
                                <div class="mb-3">
                                    <label>Price (SAR)</label>
                                    <input type="text" name="retreat_price_sar" id="edit_retreat_price_sar" class="form-control" placeholder="e.g., 4800">
                                </div>
                                <div class="mb-3">
                                    <label>Price (USD)</label>
                                    <input type="text" name="retreat_price_usd" id="edit_retreat_price_usd" class="form-control" placeholder="e.g., 410">
                                </div>
                                <div class="mb-3">
                                    <label>Location</label>
                                    <input type="text" name="trip_destination" id="edit_trip_destination" class="form-control" placeholder="e.g., Wahiba Sands, Oman">
                                </div>
                            </div>
                        </div>

                        <h6 style="color:#6059A6;margin-top:20px;margin-bottom:15px;border-bottom:1px solid #e0e0e0;padding-bottom:10px;">Retreat Description</h6>
                        <div class="mb-3">
                            <label>Description</label>
                            <textarea name="retreat_description" id="edit_retreat_description" class="form-control" rows="4" placeholder="Describe the retreat experience..."></textarea>
                        </div>
                        <div class="mb-3">
                            <label>Package Includes (one item per line)</label>
                            <textarea name="package_includes" id="edit_package_includes" class="form-control" rows="5" placeholder="Luxury Accommodation&#10;All Meals&#10;Daily Therapy Sessions"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-create">Save Changes</button>
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Edit retreat - populate modal
        document.querySelectorAll('.edit-retreat').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.stopPropagation();
                document.getElementById('edit_retreat_id').value = this.dataset.id;
                document.getElementById('edit_retreat_type').value = this.dataset.type;
                document.getElementById('edit_start_date').value = this.dataset.startDate;
                document.getElementById('edit_end_date').value = this.dataset.endDate;
                document.getElementById('edit_max_participants').value = this.dataset.maxParticipants;
                document.getElementById('edit_trip_destination').value = this.dataset.tripDestination || '';
                document.getElementById('edit_retreat_description').value = this.dataset.retreatDescription || '';
                document.getElementById('edit_retreat_price_sar').value = this.dataset.retreatPriceSar || '';
                document.getElementById('edit_retreat_price_usd').value = this.dataset.retreatPriceUsd || '';
                document.getElementById('edit_package_includes').value = this.dataset.packageIncludes || '';
            });
        });

        // Edit retreat form submission
        document.getElementById('editRetreatForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const data = new FormData(this);
            data.append('action', 'edit_retreat_group');

            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Saving...';

            fetch('<?php echo admin_url("admin-ajax.php"); ?>', {
                    method: 'POST',
                    body: data
                })
                .then(res => res.json())
                .then(r => {
                    const alertBox = document.getElementById('editRetreatAlert');
                    if (r.success) {
                        alertBox.innerHTML = '<div class="alert alert-success">' + r.data + '</div>';
                        setTimeout(() => {
                            location.reload();
                        }, 1000);
                    } else {
                        alertBox.innerHTML = '<div class="alert alert-danger">' + r.data + '</div>';
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = 'Save Changes';
                    }
                })
                .catch(error => {
                    document.getElementById('editRetreatAlert').innerHTML = '<div class="alert alert-danger">Error: ' + error + '</div>';
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = 'Save Changes';
                });
        });
    </script>

    <!-- Questionnaire Answers Modal -->
    <div class="modal fade" id="questionnaireModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background:#6059A6;color:white;">
                    <h5 class="modal-title" id="questionnaireModalLabel">Questionnaire Answers</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="questionnaireAnswersContent">
                    <!-- Content loaded via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <style>
        .qa-list {
            padding: 10px 0;
        }

        .qa-item {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 15px;
            border-left: 3px solid #C3DDD2;
        }

        .qa-question {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .qa-answer {
            color: #555;
            font-size: 14px;
            line-height: 1.6;
        }
    </style>
<?php
}

// --------------------------------------------------
// RETREAT WAITING LIST DASHBOARD
// --------------------------------------------------
add_action('admin_menu', 'retreat_waiting_list_dashboard_menu');

function retreat_waiting_list_dashboard_menu()
{
    add_menu_page(
        'Retreat Waiting List',
        'Retreat Waiting List',
        'manage_options',
        'retreat-waiting-list-dashboard',
        'render_retreat_waiting_list_dashboard',
        'dashicons-list-view',
        8
    );
}

function render_retreat_waiting_list_dashboard()
{
    global $wpdb;

    $retreat_types = ['male', 'female', 'teen'];
    $table_name = $wpdb->prefix . 'retreat_waiting_list';
    $entries = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at ASC");

    $grouped = [];
    foreach ($retreat_types as $type) {
        $grouped[$type] = [];
    }

    foreach ($entries as $entry) {
        $type = strtolower($entry->retreat_type);
        if (in_array($type, $retreat_types)) {
            $grouped[$type][] = $entry;
        }
    }
?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Retreat Waiting List Dashboard</h1>
        <hr class="wp-header-end">

        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

        <style>
            body {
                background: #f8f9fa;
            }

            .retreat-tabs {
                display: flex;
                gap: 10px;
                margin-bottom: 20px;
                flex-wrap: wrap;
            }

            .retreat-tab {
                padding: 10px 20px;
                background: #cdc6e0;
                cursor: pointer;
                border-radius: 6px;
                font-weight: 600;
                color: #333;
                transition: all 0.3s;
            }

            .retreat-tab.active {
                background: #6059A6;
                color: #fff;
            }

            .retreat-tab:hover {
                background: #9a92c8;
                color: #fff;
            }

            .retreat-content {
                display: none;
            }

            .retreat-content.active {
                display: block;
            }

            .retreat-card {
                background: #ffffff;
                padding: 20px;
                border-radius: 8px;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.1);
            }

            .retreat-card h4 {
                margin-bottom: 15px;
                font-weight: 600;
                color: #6059A6;
            }

            .waiting-table {
                width: 100%;
                border-collapse: collapse;
            }

            .waiting-table th,
            .waiting-table td {
                border: 1px solid #ddd;
                padding: 10px;
                font-size: 14px;
                text-align: left;
            }

            .waiting-table th {
                background-color: #6059A6;
                color: #fff;
                font-weight: 600;
            }

            .waiting-table td {
                background: #fdfdfd;
            }

            .delete-btn {
                background: #dc3545;
                color: #fff;
                border: none;
                padding: 6px 12px;
                border-radius: 4px;
                cursor: pointer;
                font-size: 13px;
                transition: all 0.3s;
            }

            .delete-btn:hover {
                background: #c82333;
            }

            .empty-state {
                text-align: center;
                padding: 30px;
                color: #999;
                font-style: italic;
            }
        </style>

        <div class="retreat-tabs">
            <?php foreach ($retreat_types as $index => $type): ?>
                <div class="retreat-tab <?php echo $index === 0 ? 'active' : ''; ?>" data-type="<?php echo esc_attr($type); ?>">
                    <?php echo ucfirst($type); ?> Retreat
                </div>
            <?php endforeach; ?>
        </div>

        <?php foreach ($retreat_types as $index => $type): ?>
            <div class="retreat-content <?php echo $index === 0 ? 'active' : ''; ?>" id="retreat-<?php echo esc_attr($type); ?>">
                <div class="retreat-card">
                    <h4><?php echo ucfirst($type); ?> Retreat Waiting List</h4>
                    <table class="waiting-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Registered At</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $users = $grouped[$type] ?? [];
                            if (!$users) {
                                echo '<tr><td colspan="6" class="empty-state">No entries in waiting list.</td></tr>';
                            } else {
                                $i = 1;
                                foreach ($users as $user):
                            ?>
                                    <tr id="entry-<?php echo esc_attr($user->id); ?>">
                                        <td><?php echo $i++; ?></td>
                                        <td><?php echo esc_html($user->full_name); ?></td>
                                        <td><?php echo esc_html($user->email); ?></td>
                                        <td><?php echo esc_html($user->phone); ?></td>
                                        <td><?php echo esc_html(date('d/m/Y H:i', strtotime($user->created_at))); ?></td>
                                        <td>
                                            <button class="delete-btn" data-id="<?php echo esc_attr($user->id); ?>">Delete</button>
                                        </td>
                                    </tr>
                            <?php endforeach;
                            } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
        jQuery(document).ready(function($) {
            // Tab switching
            $('.retreat-tab').on('click', function() {
                var type = $(this).data('type');
                $('.retreat-tab').removeClass('active');
                $(this).addClass('active');
                $('.retreat-content').removeClass('active');
                $('#retreat-' + type).addClass('active');
            });

            // Delete entry
            $('.delete-btn').on('click', function() {
                if (!confirm('Are you sure you want to delete this entry?')) return;
                var entryId = $(this).data('id');
                $.post(ajaxurl, {
                    action: 'delete_retreat_waiting_list_entry',
                    id: entryId
                }, function(response) {
                    if (response.success) {
                        $('#entry-' + entryId).fadeOut(function() {
                            $(this).remove();
                        });
                    } else {
                        alert('Error deleting entry.');
                    }
                });
            });
        });
    </script>
<?php
}

add_action('wp_ajax_delete_retreat_waiting_list_entry', 'delete_retreat_waiting_list_entry');
function delete_retreat_waiting_list_entry()
{
    global $wpdb;

    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error('Invalid ID');

    $table_name = $wpdb->prefix . 'retreat_waiting_list';
    $deleted = $wpdb->delete($table_name, ['id' => $id]);

    if ($deleted) {
        wp_send_json_success('Entry deleted successfully.');
    } else {
        wp_send_json_error('Failed to delete entry.');
    }
}

// --------------------------------------------------
// AJAX: Get User Questionnaire Answers
// --------------------------------------------------
add_action('wp_ajax_get_user_questionnaire_answers', 'get_user_questionnaire_answers');
function get_user_questionnaire_answers()
{
    global $wpdb;

    $user_id = intval($_POST['user_id'] ?? 0);
    if (!$user_id) {
        wp_send_json_error('Invalid user ID');
    }

    $table_name = $wpdb->prefix . 'retreat_questionnaire_answers';
    $answers = $wpdb->get_results($wpdb->prepare(
        "SELECT question_number, question_text, answer 
         FROM {$table_name} 
         WHERE user_id = %d 
         ORDER BY question_number ASC",
        $user_id
    ));

    if (empty($answers)) {
        wp_send_json_error('No answers found');
    }

    wp_send_json_success(['answers' => $answers]);
}
