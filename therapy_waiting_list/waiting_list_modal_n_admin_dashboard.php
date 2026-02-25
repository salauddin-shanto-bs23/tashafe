<?php
// -------------------------------------
// 1. Create Waiting List Table
// -------------------------------------
function create_waiting_list_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'waiting_list';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(255) NOT NULL,
        email VARCHAR(255) NOT NULL,
        phone VARCHAR(50) NULL,
        issue VARCHAR(100) NOT NULL,
        gender VARCHAR(20) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
add_action('init', 'create_waiting_list_table');

// -------------------------------------
// 2. AJAX Submission Handler
// -------------------------------------
add_action('wp_ajax_add_to_waiting_list', 'handle_waiting_list_submission');
add_action('wp_ajax_nopriv_add_to_waiting_list', 'handle_waiting_list_submission');

function handle_waiting_list_submission() {
    if (!isset($_POST['form'])) {
        wp_send_json_error('Form data missing.');
    }

    parse_str($_POST['form'], $form_data);

    $name   = sanitize_text_field($form_data['name'] ?? '');
    $email  = sanitize_email($form_data['email'] ?? '');
    $phone  = sanitize_text_field($form_data['phone'] ?? '');
    $issue  = sanitize_text_field($form_data['topic'] ?? '');
    $gender = sanitize_text_field($form_data['gender'] ?? '');

    if (!$name || !$email || !$issue || !$gender) {
        wp_send_json_error('Please fill all required fields.');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'waiting_list';

    $wpdb->insert($table_name, [
        'name'       => $name,
        'email'      => $email,
        'phone'      => $phone,
        'issue'      => $issue,
        'gender'     => $gender,
        'created_at' => current_time('mysql'),
    ]);

    // Send gradient-themed HTML email
	$subject = "You're on the Waiting List – Tashafe";

	$message = '
	<!DOCTYPE html>
	<html lang="en">
	<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<title>Tashafe Email</title>
	</head>
	<body style="margin:0; padding:0; background:#f6f6f6; font-family:Arial, sans-serif;">

	<table width="100%" cellpadding="0" cellspacing="0" style="background:#f6f6f6; padding:40px 0;">
		<tr>
			<td align="center">

				<!-- Email Container -->
				<table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 4px 12px rgba(0,0,0,0.08);">

					<!-- Header -->
					<tr>
						<td style="background:linear-gradient(135deg, #C3DDD2, #6059A6); padding:24px; text-align:center; color:#ffffff; font-size:24px; font-weight:bold;">
							Tashafe Waiting List
						</td>
					</tr>

					<!-- Body -->
					<tr>
						<td style="padding:30px; color:#333; font-size:16px; line-height:26px;">
							<p style="margin:0 0 16px;">Hi,</p>

							<p style="margin:0 0 16px;">
								Thank you for joining the <strong>Tashafe Waiting List</strong>.  
								We’ve received your interest and will notify you as soon as your issue is ready or your turn arrives.
							</p>

							<p style="margin:0 0 24px;">
								You are now officially in our queue. If you need any updates, feel free to contact us anytime.
							</p>

							<!-- Button -->
							<table cellspacing="0" cellpadding="0">
								<tr>
									<td align="center">
										<a href="https://tanafs.com.sa" 
										   style="display:inline-block; padding:14px 28px; background:linear-gradient(135deg, #C3DDD2, #6059A6); color:#fff; 
										   text-decoration:none; font-weight:600; border-radius:6px; font-size:16px;">
											Visit Tashafe.com
										</a>
									</td>
								</tr>
							</table>

						</td>
					</tr>

					<!-- Footer -->
					<tr>
						<td style="background:#f0f0f0; padding:16px; text-align:center; font-size:12px; color:#666;">
							© <?= date("Y"); ?> Tashafe — All Rights Reserved.
						</td>
					</tr>

				</table>

			</td>
		</tr>
	</table>

	</body>
	</html>
	';

	$headers = [
		"Content-Type: text/html; charset=UTF-8",
		"From: Tashafe <no-reply@tanafs.com.sa>"
	];

	wp_mail($email, $subject, $message, $headers);

    wp_send_json_success('Added to waiting list.');
}

// -------------------------------------
// 3. Admin Menu & Dashboard
// -------------------------------------
add_action('admin_menu', 'waiting_list_dashboard_menu');

function waiting_list_dashboard_menu() {
    add_menu_page(
        'Therapy Group Waiting List', 	 // Page title
        'Therapy Group Waiting List', 	 // Menu title
        'manage_options',                // Capability
        'waiting-list-dashboard',        // Menu slug
        'render_waiting_list_dashboard', // Callback function
        'dashicons-list-view',           // Icon
        6                                // Position
    );
}

function render_waiting_list_dashboard() {
    global $wpdb;

    $issues = ['Anxiety', 'Depression', 'Relationship', 'Grief'];
    $table_name = $wpdb->prefix . 'waiting_list';
    $entries = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at ASC");

    $grouped = [];
    foreach ($issues as $issue) {
        $grouped[$issue] = ['male' => [], 'female' => []];
    }

    foreach ($entries as $entry) {
        $entry_issue = ucfirst(strtolower($entry->issue));
        $gender = strtolower($entry->gender);
        if (in_array($entry_issue, $issues) && ($gender === 'male' || $gender === 'female')) {
            $grouped[$entry_issue][$gender][] = $entry;
        }
    }
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Waiting List Dashboard</h1>
        <hr class="wp-header-end">

        <style>
            body { background: #d8e8eb; }
            .wl-tabs { display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; }
            .wl-tab { padding: 10px 20px; background: #cdc6e0; cursor: pointer; border-radius: 6px; font-weight: 600; }
            .wl-tab.active { background: #9ec9cc; color: #fff; }
            .issue-content { display: none; }
            .issue-content.active { display: flex; gap: 20px; flex-wrap: wrap; }
            .wl-gender { flex: 1 1 48%; background: #ffffff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 6px rgba(0,0,0,0.1); }
            .wl-gender h4 { margin-bottom: 10px; font-weight: 600; }
            .waiting-table { width: 100%; border-collapse: collapse; }
            .waiting-table th, .waiting-table td { border: 1px solid #ddd; padding: 8px; font-size: 14px; }
            .waiting-table th { background-color: #cdc6e0; color: #000; font-weight: 600; }
            .waiting-table td { background: #fdfdfd; }
            .delete-btn { background: #ff4d4d; color: #fff; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 13px; }
            .delete-btn:hover { opacity: 0.85; }
            @media(max-width: 768px){
                .issue-content { flex-direction: column; }
                .wl-gender { flex: 1 1 100%; }
            }
        </style>

        <div class="wl-tabs">
            <?php foreach ($issues as $index => $issue): ?>
                <div class="wl-tab <?php echo $index === 0 ? 'active' : ''; ?>" data-issue="<?php echo esc_attr($issue); ?>">
                    <?php echo esc_html($issue); ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php foreach ($issues as $index => $issue): ?>
            <div class="issue-content <?php echo $index === 0 ? 'active' : ''; ?>" id="issue-<?php echo esc_attr($issue); ?>">
                <?php foreach (['male', 'female'] as $gender): ?>
                    <div class="wl-gender">
                        <h4><?php echo $gender === 'male' ? '♂ Male Groups' : '♀ Female Groups'; ?></h4>
                        <table class="waiting-table">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Registered At</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $users = $grouped[$issue][$gender] ?? [];
                                if (!$users) {
                                    echo '<tr><td colspan="6">No entries.</td></tr>';
                                } else {
                                    $i = 1;
                                    foreach ($users as $user):
                                ?>
                                    <tr id="entry-<?php echo esc_attr($user->id); ?>">
                                        <td><?php echo $i++; ?></td>
                                        <td><?php echo esc_html($user->name); ?></td>
                                        <td><?php echo esc_html($user->email); ?></td>
                                        <td><?php echo esc_html($user->phone); ?></td>
                                        <td><?php echo esc_html(date('d/m/Y', strtotime($user->created_at))); ?></td>
                                        <td>
                                            <button class="delete-btn" data-id="<?php echo esc_attr($user->id); ?>">Delete</button>
                                        </td>
                                    </tr>
                                <?php endforeach; } ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
        jQuery(document).ready(function($){
            // Tab switching
            $('.wl-tab').on('click', function(){
                var issue = $(this).data('issue');
                $('.wl-tab').removeClass('active');
                $(this).addClass('active');
                $('.issue-content').removeClass('active');
                $('#issue-' + issue).addClass('active');
            });

            // Delete entry
            $('.delete-btn').on('click', function(){
                if (!confirm('Are you sure you want to delete this entry?')) return;
                var entryId = $(this).data('id');
                $.post(ajaxurl, {
                    action: 'delete_waiting_list_entry',
                    id: entryId
                }, function(response){
                    if(response.success){
                        $('#entry-' + entryId).fadeOut();
                    } else {
                        alert('Error deleting entry.');
                    }
                });
            });
        });
    </script>
    <?php
}

add_action('wp_ajax_delete_waiting_list_entry', 'delete_waiting_list_entry');
function delete_waiting_list_entry() {
    global $wpdb;

    $id = intval($_POST['id'] ?? 0);
    if(!$id) wp_send_json_error('Invalid ID');

    $table_name = $wpdb->prefix . 'waiting_list';
    $deleted = $wpdb->delete($table_name, ['id' => $id]);

    if($deleted !== false){
        wp_send_json_success('Entry deleted.');
    } else {
        wp_send_json_error('Failed to delete.');
    }
}

// -------------------------------------
// 4. Enqueue JS & Localize AJAX
// -------------------------------------
add_action('wp_enqueue_scripts', 'waiting_list_frontend_scripts');
function waiting_list_frontend_scripts() {
    wp_enqueue_script('jquery');
    wp_enqueue_script('waiting-list-js', plugin_dir_url(__FILE__) . 'waiting-list.js', ['jquery'], false, true);

    wp_localize_script('waiting-list-js', 'waiting_list_ajax', [
        'ajaxurl' => admin_url('admin-ajax.php')
    ]);
}
