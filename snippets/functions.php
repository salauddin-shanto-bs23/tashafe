<?php

// 1. Create the admin menu
add_action('admin_menu', function () {
    add_menu_page(
        'Slot Management',      // Page title
        'Slot Management',      // Menu title
        'manage_options',       // Capability
        'slot-management',      // Menu slug
        'slot_management_page', // Callback function
        'dashicons-calendar'   // Icon
    );
});

// 2. Callback function to render the menu page
function slot_management_page()
{
    echo do_shortcode('[slot_management]');
}

// --------------------------------------------------
// CREATE SLOT MANAGEMENT TABLE
// --------------------------------------------------
add_action('init', 'create_slot_management_table');
function create_slot_management_table()
{
    global $wpdb;

    $table_name = $wpdb->prefix . 'therapy_slots';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        slot_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        user_id BIGINT UNSIGNED DEFAULT NULL,
        user_name VARCHAR(255) DEFAULT NULL,
        user_email VARCHAR(255) DEFAULT NULL,
        group_id BIGINT UNSIGNED DEFAULT NULL,
        group_name VARCHAR(255) DEFAULT NULL,
        gender VARCHAR(20) DEFAULT NULL,
        host_link VARCHAR(500) DEFAULT 'host.zoom.com/123',
        participant_link VARCHAR(500) DEFAULT 'join.zoom.com/456',
        meeting_status VARCHAR(50) DEFAULT 'pending',
        created_by BIGINT UNSIGNED DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY slot_date (slot_date),
        KEY user_id (user_id)
    ) {$charset_collate};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// --------------------------------------------------
// AJAX: SAVE SLOTS
// --------------------------------------------------
add_action('wp_ajax_save_therapy_slots', 'handle_save_therapy_slots');
function handle_save_therapy_slots()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'therapy_slots';

    $slots = isset($_POST['slots']) ? $_POST['slots'] : [];
    $current_user_id = get_current_user_id();

    if (empty($slots)) {
        wp_send_json_error('No slots provided');
    }

    $inserted = 0;
    foreach ($slots as $slot) {
        $slot_date = sanitize_text_field($slot['date']);
        $start_time = sanitize_text_field($slot['start_time']);
        $end_time = sanitize_text_field($slot['end_time']);

        // Check if slot already exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} WHERE slot_date = %s AND start_time = %s AND end_time = %s",
            $slot_date,
            $start_time,
            $end_time
        ));

        if (!$exists) {
            $wpdb->insert(
                $table_name,
                [
                    'slot_date' => $slot_date,
                    'start_time' => $start_time,
                    'end_time' => $end_time,
                    'created_by' => $current_user_id
                ],
                ['%s', '%s', '%s', '%d']
            );
            $inserted++;
        }
    }

    wp_send_json_success(['message' => "$inserted slot(s) saved successfully", 'inserted' => $inserted]);
}

// --------------------------------------------------
// AJAX: GET ALL SLOTS
// --------------------------------------------------
add_action('wp_ajax_get_therapy_slots', 'handle_get_therapy_slots');
function handle_get_therapy_slots()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'therapy_slots';

    $slots = $wpdb->get_results(
        "SELECT * FROM {$table_name} ORDER BY slot_date ASC, start_time ASC",
        ARRAY_A
    );

    wp_send_json_success($slots);
}

// --------------------------------------------------
// AJAX: DELETE SLOT
// --------------------------------------------------
add_action('wp_ajax_delete_therapy_slot', 'handle_delete_therapy_slot');
function handle_delete_therapy_slot()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'therapy_slots';

    $slot_id = intval($_POST['slot_id'] ?? 0);

    if (!$slot_id) {
        wp_send_json_error('Invalid slot ID');
    }

    $deleted = $wpdb->delete($table_name, ['id' => $slot_id], ['%d']);

    if ($deleted) {
        wp_send_json_success('Slot deleted');
    } else {
        wp_send_json_error('Failed to delete slot');
    }
}

// --------------------------------------------------
// AJAX: ASSIGN USER TO SLOT
// --------------------------------------------------
add_action('wp_ajax_assign_user_to_slot', 'handle_assign_user_to_slot');
function handle_assign_user_to_slot()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'therapy_slots';

    $slot_id = intval($_POST['slot_id'] ?? 0);
    $user_id = intval($_POST['user_id'] ?? 0);

    if (!$slot_id) {
        wp_send_json_error('Invalid slot ID');
    }

    if ($user_id) {
        $user = get_user_by('ID', $user_id);
        if (!$user) {
            wp_send_json_error('User not found');
        }

        $first_name = get_user_meta($user_id, 'first_name', true);
        $last_name = get_user_meta($user_id, 'last_name', true);
        $user_name = trim($first_name . ' ' . $last_name) ?: $user->display_name;
        $group_id = get_user_meta($user_id, 'assigned_group', true);
        $group_name = $group_id ? get_the_title($group_id) : '';
        $gender = get_user_meta($user_id, 'gender', true);

        $updated = $wpdb->update(
            $table_name,
            [
                'user_id' => $user_id,
                'user_name' => $user_name,
                'user_email' => $user->user_email,
                'group_id' => $group_id,
                'group_name' => $group_name,
                'gender' => $gender
            ],
            ['id' => $slot_id],
            ['%d', '%s', '%s', '%d', '%s', '%s'],
            ['%d']
        );
    } else {
        // Unassign user
        $updated = $wpdb->update(
            $table_name,
            [
                'user_id' => null,
                'user_name' => null,
                'user_email' => null,
                'group_id' => null,
                'group_name' => null,
                'gender' => null
            ],
            ['id' => $slot_id],
            ['%d', '%s', '%s', '%d', '%s', '%s'],
            ['%d']
        );
    }

    wp_send_json_success('User assignment updated');
}

// --------------------------------------------------
// AJAX: UPDATE MEETING STATUS
// --------------------------------------------------
add_action('wp_ajax_update_slot_meeting_status', 'handle_update_slot_meeting_status');
function handle_update_slot_meeting_status()
{
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'therapy_slots';

    $slot_id = intval($_POST['slot_id'] ?? 0);
    $status = sanitize_text_field($_POST['status'] ?? 'pending');

    if (!$slot_id) {
        wp_send_json_error('Invalid slot ID');
    }

    $wpdb->update(
        $table_name,
        ['meeting_status' => $status],
        ['id' => $slot_id],
        ['%s'],
        ['%d']
    );

    wp_send_json_success('Status updated');
}



function get_available_users_for_therapist($limit = null)
{
    // Get active therapy group IDs
    $active_group_numbers = get_active_therapy_group_numbers();

    if (empty($active_group_numbers)) {
        error_log("No active therapy groups found");
        return [];
    }

    // Get users who belong to those groups and are not assigned to a coordinator
    $available_users = get_users([
        'meta_query' => [
            'relation' => 'AND',
            [
                'key' => 'assigned_group',
                'value' => $active_group_numbers,
                'compare' => 'IN'
            ],
            [
                'relation' => 'OR',
                [
                    'key' => 'assigned_coordinator',
                    'compare' => 'NOT EXISTS'
                ],
                [
                    'key' => 'assigned_coordinator',
                    'value' => '',
                    'compare' => '='
                ]
            ]
        ]
    ]);

    $filtered_users = [];
    $count = 0;

    foreach ($available_users as $user) {
        $meeting_status = get_field('meeting_completed', 'user_' . $user->ID);
        $group_id       = get_user_meta($user->ID, 'assigned_group', true);
        $group_name     = $group_id ? get_the_title($group_id) : '';
        $gender         = get_user_meta($user->ID, 'gender', true); // assuming "gender" meta key

        // Only include users with pending meetings
        if (
            $meeting_status === 'pending' ||
            $meeting_status === 'Pending' ||
            strtolower(trim($meeting_status)) === 'pending' ||
            empty($meeting_status)
        ) {

            $filtered_users[] = [
                'ID'               => $user->ID,
                'name'             => $user->display_name,
                'email'            => $user->user_email,
                'phone'            => get_field('phone_num', 'user_' . $user->ID),
                'gender'           => $gender ?: 'N/A',
                'group_id'         => $group_id,
                'group_name'       => $group_name,
                'meeting_completed' => $meeting_status ?: 'pending'
            ];

            $count++;
            if ($limit !== null && $count >= $limit) {
                break;
            }
        }
    }

    // Debugging
    //     var_dump($filtered_users);

    return $filtered_users;
}

function get_active_therapy_group_numbers()
{
    $active_group_numbers = [];

    // All therapy groups are active by default - no status filter needed
    $therapy_groups = get_posts([
        'post_type'      => 'therapy_group',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ]);

    foreach ($therapy_groups as $group) {
        if (!empty($group->ID)) {
            $active_group_numbers[] = $group->ID;
        }
    }

    return $active_group_numbers;
}




function get_all_customer_dashboard()
{
    // Get all customers
    $all_customers = get_users(['role' => 'customer']);

    // Categorize customers
    $assigned_customers = [];
    $unassigned_customers = [];
    $completed_customers = [];

    foreach ($all_customers as $user) {
        $user_id = $user->ID;
        $assigned_slot = get_field('assigned_slot', 'user_' . $user_id);
        $meeting_completed = get_field('meeting_completed', 'user_' . $user_id);

        $user_data = [
            'ID' => $user_id,
            'name' => $user->display_name,
            'email' => $user->user_email,
            'assigned_slot' => $assigned_slot,
            'assigned_day' => get_field('assigned_day', 'user_' . $user_id),
            'therapist_id' => get_field('assigned_coordinator', 'user_' . $user_id),
            'meeting_completed' => $meeting_completed,
            'phone' => get_field('phone_num', 'user_' . $user_id),
            'assigned_group' => get_field('assigned_group', 'user_' . $user_id)
        ];

        if ($meeting_completed === 'completed') {
            $completed_customers[] = $user_data;
        } elseif (!empty($assigned_slot)) {
            $assigned_customers[] = $user_data;
        } else {
            $unassigned_customers[] = $user_data;
        }
    }

    $total_customers = count($all_customers);
    $assigned_count = count($assigned_customers);
    $unassigned_count = count($unassigned_customers);
    $completed_count = count($completed_customers);

    // 	var_dump($assigned_customers);
    // 	var_dump($unassigned_customers);
}


// 3. Shortcode to output your HTML/JS
add_shortcode('slot_management', function () {
    ob_start();

    get_all_customer_dashboard();
    echo "<br>";


    get_available_users_for_therapist();

    $available_users = get_available_users_for_therapist();

    $js_users = [];
    foreach ($available_users as $u) {
        $js_users[] = [
            'id'                => $u['ID'],
            'name'              => $u['name'],
            'email'             => $u['email'],
            'phone'             => $u['phone'],
            'gender'            => $u['gender'] ?? 'N/A',
            'group_id'          => $u['group_id'] ?? '',
            'group_name'        => $u['group_name'] ?? '',
            'meeting_completed' => !empty($u['meeting_completed']) ? $u['meeting_completed'] : 'Pending'
        ];
    }

?>
    <div id="slot-management">
        <h2>Slot Management System</h2>

        <div class="tabs">
            <div class="tab active" data-tab="addSlot">Add Slots</div>
            <div class="tab" data-tab="assignedSlots">Assigned Slots</div>
            <div class="tab" data-tab="statsTab">Stats</div>
        </div>

        <div id="addSlot" class="tab-content active">
            <h3>Add Time Slots</h3>
            <form id="slotForm" class="slot-form">
                <div class="form-row">
                    <label>Date:</label>
                    <input type="date" id="slotDate" required>
                </div>
                <div class="form-row">
                    <label>Start:</label>
                    <input type="time" id="startTime" required>
                    <label>End:</label>
                    <input type="time" id="endTime" required>
                </div>
                <button type="submit" class="btn-generate">Generate Slots</button>
            </form>
            <div id="generatedSlotsPreview" style="margin-top:20px;"></div>
        </div>

        <div id="assignedSlots" class="tab-content">
            <h3>Assigned Slots</h3>
            <div class="filter-row" style="margin-bottom:15px;">
                <label>Filter by Date:</label>
                <input type="date" id="filterDate" style="padding:5px;margin-left:10px;">
                <button id="clearFilter" class="btn-generate" style="margin-left:10px;padding:5px 15px;">Clear</button>
            </div>
            <table id="slotsTable">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Time</th>
                        <th>Assigned User</th>
                        <th>Group</th>
                        <th>Gender</th>
                        <th>Zoom Host</th>
                        <th>Zoom Participant</th>
                        <th>Meeting Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>

        <div id="statsTab" class="tab-content">
            <h3>Assignment Stats</h3>
            <div id="stats"></div>
        </div>
    </div>

    <style>
        body {
            font-family: Arial, sans-serif;
        }

        h2 {
            color: #2F4F4F;
            margin-bottom: 20px;
        }

        .tabs {
            display: flex;
            cursor: pointer;
            margin-bottom: 15px;
        }

        .tab {
            padding: 10px 25px;
            border: 1px solid #ccc;
            border-bottom: none;
            margin-right: 3px;
            background: #e2f0ff;
            border-radius: 5px 5px 0 0;
            transition: 0.3s;
        }

        .tab.active {
            background: #0073aa;
            color: #fff;
            font-weight: bold;
        }

        .tab-content {
            border: 1px solid #ccc;
            padding: 20px;
            display: none;
            border-radius: 0 5px 5px 5px;
            background: #f9f9f9;
        }

        .tab-content.active {
            display: block;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 10px;
            font-size: 14px;
        }

        th,
        td {
            border: 1px solid #ccc;
            padding: 8px;
            text-align: left;
        }

        th {
            background-color: #0073aa;
            color: #fff;
        }

        td {
            background-color: #f2f7ff;
        }

        .slot-form {
            display: flex;
            flex-direction: column;
            max-width: 500px;
        }

        .form-row {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            align-items: center;
        }

        input {
            padding: 5px 10px;
            flex: 1;
            border-radius: 4px;
            border: 1px solid #ccc;
        }

        .btn-generate {
            padding: 8px 15px;
            border: none;
            border-radius: 5px;
            background-color: #0073aa;
            color: #fff;
            cursor: pointer;
            transition: 0.3s;
        }

        .btn-generate:hover {
            background-color: #005177;
        }

        .btn-delete {
            padding: 4px 10px;
            border: none;
            border-radius: 4px;
            background-color: #dc3545;
            color: #fff;
            cursor: pointer;
            font-size: 12px;
        }

        .btn-delete:hover {
            background-color: #c82333;
        }

        .btn-assign {
            padding: 4px 10px;
            border: none;
            border-radius: 4px;
            background-color: #28a745;
            color: #fff;
            cursor: pointer;
            font-size: 12px;
            margin-right: 5px;
        }

        .btn-assign:hover {
            background-color: #218838;
        }

        .status-select {
            padding: 4px 8px;
            border-radius: 4px;
            border: 1px solid #ccc;
            font-size: 12px;
        }

        .stats-card {
            background: #fff;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 10px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .stats-card h4 {
            margin: 0 0 10px 0;
            color: #0073aa;
        }

        .preview-table {
            margin-top: 15px;
        }

        .preview-table th {
            background-color: #28a745;
        }
    </style>



    <script>
        // Default users for demo (replace with actual PHP users)
        let users = <?php echo json_encode($js_users, JSON_PRETTY_PRINT); ?>;
        let allSlots = [];
        const ajaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';

        console.log("Slot Management Initialized");
        console.log("Available users:", users);

        // Tabs functionality
        document.querySelectorAll('.tab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
                tab.classList.add('active');
                document.getElementById(tab.dataset.tab).classList.add('active');

                // Load slots when switching to assigned tab
                if (tab.dataset.tab === 'assignedSlots') {
                    loadSlots();
                }
                if (tab.dataset.tab === 'statsTab') {
                    loadStats();
                }
            });
        });

        function generateTimeSlots(date, startTime, endTime) {
            let slots = [];
            let start = new Date(`${date}T${startTime}`);
            let end = new Date(`${date}T${endTime}`);
            const duration = 30; // 30 min slots

            while (start < end) {
                let slotEnd = new Date(start.getTime() + duration * 60000);
                if (slotEnd > end) break;
                let startStr = start.toTimeString().slice(0, 5);
                let endStr = slotEnd.toTimeString().slice(0, 5);
                slots.push({
                    date: date,
                    start_time: startStr + ':00',
                    end_time: endStr + ':00'
                });
                start = slotEnd;
            }
            return slots;
        }

        function saveSlots(slots) {
            return fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'save_therapy_slots',
                        slots: JSON.stringify(slots)
                    })
                })
                .then(res => res.json());
        }

        function loadSlots(filterDate = null) {
            fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=get_therapy_slots'
                })
                .then(res => res.json())
                .then(response => {
                    if (response.success) {
                        allSlots = response.data;
                        renderSlots(filterDate);
                    }
                });
        }

        function deleteSlot(slotId) {
            if (!confirm('Are you sure you want to delete this slot?')) return;

            fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=delete_therapy_slot&slot_id=${slotId}`
                })
                .then(res => res.json())
                .then(response => {
                    if (response.success) {
                        loadSlots();
                    } else {
                        alert('Failed to delete slot');
                    }
                });
        }

        function assignUserToSlot(slotId, userId) {
            fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=assign_user_to_slot&slot_id=${slotId}&user_id=${userId}`
                })
                .then(res => res.json())
                .then(response => {
                    if (response.success) {
                        loadSlots();
                    } else {
                        alert('Failed to assign user');
                    }
                });
        }

        function updateMeetingStatus(slotId, status) {
            fetch(ajaxUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=update_slot_meeting_status&slot_id=${slotId}&status=${status}`
                })
                .then(res => res.json())
                .then(response => {
                    if (!response.success) {
                        alert('Failed to update status');
                    }
                });
        }

        function renderSlots(filterDate = null) {
            const tbody = document.querySelector("#slotsTable tbody");
            tbody.innerHTML = "";

            let filteredSlots = allSlots;
            if (filterDate) {
                filteredSlots = allSlots.filter(s => s.slot_date === filterDate);
            }

            if (filteredSlots.length === 0) {
                tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#666;">No slots found</td></tr>';
                return;
            }

            filteredSlots.forEach(slot => {
                let tr = document.createElement("tr");

                // Format time display
                const startTime = slot.start_time.substring(0, 5);
                const endTime = slot.end_time.substring(0, 5);

                // Build user select dropdown
                let userOptions = '<option value="">-- Select User --</option>';
                users.forEach(u => {
                    const selected = slot.user_id == u.id ? 'selected' : '';
                    userOptions += `<option value="${u.id}" ${selected}>${u.name} (${u.email})</option>`;
                });

                tr.innerHTML = `
                    <td>${slot.slot_date}</td>
                    <td>${startTime} - ${endTime}</td>
                    <td>
                        <select class="status-select user-select" data-slot-id="${slot.id}" style="width:100%;max-width:200px;">
                            ${userOptions}
                        </select>
                    </td>
                    <td>${slot.group_name || '-'}</td>
                    <td>${slot.gender || '-'}</td>
                    <td><a href="${slot.host_link}" target="_blank">${slot.host_link}</a></td>
                    <td><a href="${slot.participant_link}" target="_blank">${slot.participant_link}</a></td>
                    <td>
                        <select class="status-select meeting-status" data-slot-id="${slot.id}">
                            <option value="pending" ${slot.meeting_status === 'pending' ? 'selected' : ''}>Pending</option>
                            <option value="completed" ${slot.meeting_status === 'completed' ? 'selected' : ''}>Completed</option>
                            <option value="cancelled" ${slot.meeting_status === 'cancelled' ? 'selected' : ''}>Cancelled</option>
                        </select>
                    </td>
                    <td>
                        <button class="btn-delete" onclick="deleteSlot(${slot.id})">Delete</button>
                    </td>
                `;
                tbody.appendChild(tr);
            });

            // Add event listeners for dropdowns
            document.querySelectorAll('.user-select').forEach(select => {
                select.addEventListener('change', function() {
                    assignUserToSlot(this.dataset.slotId, this.value);
                });
            });

            document.querySelectorAll('.meeting-status').forEach(select => {
                select.addEventListener('change', function() {
                    updateMeetingStatus(this.dataset.slotId, this.value);
                });
            });
        }

        function loadStats() {
            const statsDiv = document.getElementById("stats");

            const totalSlots = allSlots.length;
            const assignedSlots = allSlots.filter(s => s.user_id).length;
            const emptySlots = totalSlots - assignedSlots;
            const completedMeetings = allSlots.filter(s => s.meeting_status === 'completed').length;
            const pendingMeetings = allSlots.filter(s => s.meeting_status === 'pending' && s.user_id).length;

            // Users without slots
            const assignedUserIds = allSlots.filter(s => s.user_id).map(s => parseInt(s.user_id));
            const unassignedUsers = users.filter(u => !assignedUserIds.includes(u.id));

            statsDiv.innerHTML = `
                <div class="stats-card">
                    <h4>📊 Slot Statistics</h4>
                    <p><strong>Total Slots:</strong> ${totalSlots}</p>
                    <p><strong>Assigned Slots:</strong> ${assignedSlots}</p>
                    <p><strong>Empty Slots:</strong> ${emptySlots}</p>
                </div>
                <div class="stats-card">
                    <h4>📅 Meeting Status</h4>
                    <p><strong>Completed Meetings:</strong> ${completedMeetings}</p>
                    <p><strong>Pending Meetings:</strong> ${pendingMeetings}</p>
                </div>
                <div class="stats-card">
                    <h4>👥 Users Without Slots (${unassignedUsers.length})</h4>
                    ${unassignedUsers.length > 0 
                        ? '<ul>' + unassignedUsers.map(u => `<li>${u.name} (${u.email})</li>`).join('') + '</ul>'
                        : '<p style="color:green;">All users have been assigned slots!</p>'}
                </div>
            `;
        }

        // Filter functionality
        document.getElementById('filterDate').addEventListener('change', function() {
            renderSlots(this.value || null);
        });

        document.getElementById('clearFilter').addEventListener('click', function() {
            document.getElementById('filterDate').value = '';
            renderSlots(null);
        });

        document.getElementById("slotForm").addEventListener("submit", function(e) {
            e.preventDefault();
            const date = document.getElementById("slotDate").value;
            const start = document.getElementById("startTime").value;
            const end = document.getElementById("endTime").value;

            if (!date || !start || !end) {
                alert('Please fill all fields');
                return;
            }

            const newSlots = generateTimeSlots(date, start, end);

            if (newSlots.length === 0) {
                alert('No slots could be generated. Check your time range.');
                return;
            }

            // Show preview
            let previewHtml = `<h4>Generated ${newSlots.length} slots:</h4>`;
            previewHtml += '<table class="preview-table"><thead><tr><th>Date</th><th>Start Time</th><th>End Time</th></tr></thead><tbody>';
            newSlots.forEach(s => {
                previewHtml += `<tr><td>${s.date}</td><td>${s.start_time}</td><td>${s.end_time}</td></tr>`;
            });
            previewHtml += '</tbody></table>';
            previewHtml += '<button id="confirmSaveSlots" class="btn-generate" style="margin-top:15px;background:#28a745;">Save All Slots</button>';
            previewHtml += '<button id="cancelSlots" class="btn-generate" style="margin-top:15px;margin-left:10px;background:#dc3545;">Cancel</button>';

            document.getElementById('generatedSlotsPreview').innerHTML = previewHtml;

            // Save confirmation
            document.getElementById('confirmSaveSlots').addEventListener('click', function() {
                this.disabled = true;
                this.textContent = 'Saving...';

                // Send slots as form data array
                const formData = new FormData();
                formData.append('action', 'save_therapy_slots');
                newSlots.forEach((slot, index) => {
                    formData.append(`slots[${index}][date]`, slot.date);
                    formData.append(`slots[${index}][start_time]`, slot.start_time);
                    formData.append(`slots[${index}][end_time]`, slot.end_time);
                });

                fetch(ajaxUrl, {
                        method: 'POST',
                        body: formData
                    })
                    .then(res => res.json())
                    .then(response => {
                        if (response.success) {
                            alert(response.data.message);
                            document.getElementById('generatedSlotsPreview').innerHTML = '<p style="color:green;">✓ Slots saved successfully!</p>';
                            document.getElementById("slotForm").reset();
                            loadSlots();
                        } else {
                            alert('Error: ' + (response.data || 'Failed to save slots'));
                        }
                    })
                    .catch(err => {
                        alert('Error saving slots: ' + err.message);
                    });
            });

            document.getElementById('cancelSlots').addEventListener('click', function() {
                document.getElementById('generatedSlotsPreview').innerHTML = '';
            });
        });

        // Load slots on page load
        loadSlots();
    </script>
<?php
    return ob_get_clean();
});




// Modified shortcode to render assessment button
function show_generic_assessment_button_new($atts)
{
    $atts = shortcode_atts([
        'issue'  => 'anxiety',
        'gender' => 'male'
    ], $atts, 'assessment_button');

    $issue  = strtolower(trim($atts['issue']));
    $gender = strtolower(trim($atts['gender']));

    // Get all active groups for this issue and gender
    $args = [
        'post_type' => 'therapy_group',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'meta_query' => [
            [
                'key' => 'issue_type',
                'value' => $issue,
                'compare' => '='
            ],
            [
                'key' => 'gender',
                'value' => $gender,
                'compare' => '='
            ]
        ],
        'orderby' => 'meta_value',
        'meta_key' => 'session_start_date',
        'order' => 'ASC',
    ];

    $groups = get_posts($args);

    $available_groups = [];
    foreach ($groups as $group) {
        $count = get_user_count_by_group_id($group->ID);
        $max_members = get_post_meta($group->ID, 'max_members', true);

        // Only include groups that aren't full
        if ($count < $max_members) {
            $session_start = get_field('session_start_date', $group->ID);
            $session_expiry = get_field('session_expiry_date', $group->ID);

            if ($session_start && $session_expiry) {
                $available_groups[] = [
                    'id' => $group->ID,
                    'session_start' => $session_start,
                    'session_expiry' => $session_expiry,
                    'display' => date('M j, Y', strtotime($session_start)) . ' - ' . date('M j, Y', strtotime($session_expiry))
                ];
            }
        }
    }

    $button_disabled = empty($available_groups);
    $message = $button_disabled ? 'No active group found for ' . esc_html($gender) . '.' : '';

    ob_start(); ?>
    <div style="text-align:center; margin-top:20px;">
        <button
            <?php if ($button_disabled): ?>
            disabled
            style="background:#ccc; cursor:not-allowed; padding:15px 25px; border-radius:5px; border:none; font-size:16px;"
            <?php else: ?>
            class="tg-session-modal-btn"
            data-issue="<?php echo esc_attr($issue); ?>"
            data-gender="<?php echo esc_attr($gender); ?>"
            data-groups='<?php echo esc_attr(json_encode($available_groups)); ?>'
            style="padding:15px 25px; border-radius:5px; border:none; cursor:pointer; font-size:16px; background:#6059A6; color:white;"
            <?php endif; ?>>
            Book Appointment
        </button>
    </div>
    <?php if ($button_disabled): ?>
        <div style="text-align:center; margin-top:10px; color:red;"><?php echo esc_html($message); ?></div>
    <?php endif;

    return ob_get_clean();
}
add_shortcode('assessment_button', 'show_generic_assessment_button_new');

// AJAX handler
add_action('wp_ajax_render_assessment_button_new', 'ajax_render_assessment_button_new');
add_action('wp_ajax_nopriv_render_assessment_button_new', 'ajax_render_assessment_button_new');

function ajax_render_assessment_button_new()
{
    $issue = sanitize_text_field($_POST['issue']);
    $gender = sanitize_text_field($_POST['gender']);

    echo do_shortcode('[assessment_button issue="' . $issue . '" gender="' . $gender . '"]');
    wp_die();
}

function enqueue_assessment_js()
{
    wp_enqueue_script('assessment-js', get_template_directory_uri() . '/js/assessment.js', ['jquery'], null, true);

    wp_localize_script('assessment-js', 'assessment_ajax', [
        'ajaxurl' => admin_url('admin-ajax.php'),
        'site_url' => site_url()
    ]);
}
add_action('wp_enqueue_scripts', 'enqueue_assessment_js');

// Fix for session period modal - override the default modal behavior
add_action('wp_footer', 'fix_session_period_modal_js', 100);
function fix_session_period_modal_js()
{
    $current_lang = function_exists('pll_current_language') ? pll_current_language('slug') : (is_rtl() ? 'ar' : 'en');
    $session_modal_copy = [
        'en' => [
            'title' => 'Select Session Period',
            'subtitle' => 'Please choose your preferred therapy session period:',
            'issue' => 'Issue',
            'gender' => 'Gender',
            'session' => 'Session Period'
        ],
        'ar' => [
            'title' => 'اختر فترة الجلسة',
            'subtitle' => 'يرجى اختيار فترة جلسة العلاج المفضلة لديك:',
            'issue' => 'المشكلة',
            'gender' => 'الجنس',
            'session' => 'فترة الجلسة'
        ]
    ];

    $modal_copy = $session_modal_copy[$current_lang] ?? $session_modal_copy['en'];
    ?>
    <script>
        jQuery(document).ready(function($) {
            var currentLang = '<?php echo esc_js($current_lang); ?>';
            var modalStrings = <?php echo wp_json_encode($modal_copy); ?>;
            // Remove any existing click handlers and add corrected one
            $(document).off('click', '.tg-session-modal-btn');
            $('.tg-session-modal-btn').off('click').prop('onclick', null);

            $(document).on('click', '.tg-session-modal-btn', function(e) {
                e.preventDefault();
                e.stopImmediatePropagation(); // Prevent other handlers from firing

                var $btn = $(this);
                var issue = $btn.data('issue');
                var gender = $btn.data('gender');
                var groups = $btn.data('groups');

                // Remove ANY existing modals (both old and new)
                $('#session-period-modal-overlay').remove();
                $('.session-period-modal').remove(); // Old modal
                $('.modal-backdrop').remove(); // Bootstrap backdrop
                $('body').removeClass('modal-open'); // Remove modal-open class

                // Build session options HTML
                var optionsHtml = '';
                var optionTextAlign = currentLang === 'ar' ? 'right' : 'left';
                if (groups && groups.length > 0) {
                    groups.forEach(function(group) {
                        optionsHtml += '<div class="session-option" data-group-id="' + group.id + '" style="padding:15px; border:1px solid #ddd; border-radius:8px; margin-bottom:10px; cursor:pointer; transition:all 0.2s; text-align:' + optionTextAlign + ';">';
                        optionsHtml += '<div style="font-weight:600; color:#6059A6;">' + modalStrings.session + '</div>';
                        optionsHtml += '<div style="color:#666;">' + group.display + '</div>';
                        optionsHtml += '</div>';
                    });
                }

                // Create modal HTML (without issue/gender dropdowns - just display as text)
                var containerExtra = currentLang === 'ar' ? 'direction:rtl; text-align:right;' : '';
                var modalHtml = '<div id="session-period-modal-overlay" style="position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:99999; display:flex; align-items:center; justify-content:center;">';
                modalHtml += '<div style="background:#fff; padding:30px; border-radius:12px; max-width:500px; width:90%; max-height:80vh; overflow-y:auto; position:relative;' + containerExtra + '">';
                modalHtml += '<span class="close-session-modal" style="position:absolute; top:10px; right:15px; font-size:24px; cursor:pointer; color:#666;">&times;</span>';
                modalHtml += '<h3 style="margin:0 0 10px 0; font-weight:700;">' + modalStrings.title + '</h3>';
                modalHtml += '<p style="color:#666; margin-bottom:20px;">' + modalStrings.subtitle + '</p>';

                // Show issue and gender as static text, not dropdowns
                modalHtml += '<div style="display:flex; gap:15px; margin-bottom:20px;">';
                modalHtml += '<div style="flex:1; padding:10px; background:#f5f5f5; border-radius:8px; text-align:center;">';
                modalHtml += '<small style="color:#888;">' + modalStrings.issue + '</small><br>';
                modalHtml += '<strong style="text-transform:capitalize;">' + issue + '</strong>';
                modalHtml += '</div>';
                modalHtml += '<div style="flex:1; padding:10px; background:#f5f5f5; border-radius:8px; text-align:center;">';
                modalHtml += '<small style="color:#888;">' + modalStrings.gender + '</small><br>';
                modalHtml += '<strong style="text-transform:capitalize;">' + gender + '</strong>';
                modalHtml += '</div>';
                modalHtml += '</div>';

                modalHtml += '<div class="session-options-container">' + optionsHtml + '</div>';
                modalHtml += '</div></div>';

                $('body').append(modalHtml);

                // Hover effect for session options
                $('.session-option').hover(
                    function() {
                        $(this).css({
                            'background': '#f0f0f0',
                            'border-color': '#6059A6'
                        });
                    },
                    function() {
                        if (!$(this).hasClass('selected')) {
                            $(this).css({
                                'background': '#fff',
                                'border-color': '#ddd'
                            });
                        }
                    }
                );

                // Click on session option - redirect to assessment with group_id
                $('.session-option').on('click', function() {
                    var groupId = $(this).data('group-id');
                    var basePath = currentLang === 'ar' ? '/ar/' : '/';
                    var slugSuffix = currentLang === 'ar' ? '-assessment-arabic' : '-assessment';
                    var redirectUrl = assessment_ajax.site_url + basePath + issue + slugSuffix + '?issue=' + issue + '&gender=' + gender + '&group_id=' + groupId;
                    window.location.href = redirectUrl;
                });

                // Close modal
                $('.close-session-modal, #session-period-modal-overlay').on('click', function(e) {
                    if (e.target === this) {
                        $('#session-period-modal-overlay').remove();
                    }
                });
            });
        });
    </script>
<?php
}

// response for all type of issue and gender
add_action('wp_ajax_render_assessment_groups', 'render_assessment_groups');
add_action('wp_ajax_nopriv_render_assessment_groups', 'render_assessment_groups');

function render_assessment_groups()
{
    $issues = ['anxiety', 'depression', 'relationship', 'grief'];
    $genders = ['male', 'female'];
    $result = [];

    foreach ($issues as $issue) {
        $result[$issue] = [];
        foreach ($genders as $gender) {
            $args = [
                'post_type' => 'therapy_group',
                'posts_per_page' => -1,
                'post_status' => 'publish',
                'meta_query' => [
                    [
                        'key' => 'issue_type',
                        'value' => $issue,
                        'compare' => '='
                    ],
                    [
                        'key' => 'gender',
                        'value' => $gender,
                        'compare' => '='
                    ]
                ],
                'orderby' => 'meta_value',
                'meta_key' => 'session_start_date',
                'order' => 'ASC',
            ];

            $groups = get_posts($args);
            $available_groups = [];

            foreach ($groups as $group) {
                $count = get_user_count_by_group_id($group->ID);
                $max_members = get_post_meta($group->ID, 'max_members', true);

                if ($count < $max_members) {
                    $session_start = get_field('session_start_date', $group->ID);
                    $session_expiry = get_field('session_expiry_date', $group->ID);

                    if ($session_start && $session_expiry) {
                        $available_groups[] = [
                            'id' => $group->ID,
                            'session_start' => $session_start,
                            'session_expiry' => $session_expiry,
                            'display' => date('M j, Y', strtotime($session_start)) . ' - ' . date('M j, Y', strtotime($session_expiry))
                        ];
                    }
                }
            }

            $button_disabled = empty($available_groups);
            $redirect_url = $button_disabled ? '' : site_url("/{$issue}-assessment?gender={$gender}&issue={$issue}");

            $result[$issue][$gender] = [
                'button_disabled' => $button_disabled,
                'url' => $redirect_url,
                'message' => $button_disabled ? "Not found" : "Available",
                'available_groups' => $available_groups
            ];
        }
    }

    wp_send_json($result);
}

// Remove preloader hook
add_action('after_setup_theme', function () {
    // First, find what function is hooked to 'manasu_hook_top'
    // Then remove it
    remove_all_actions('manasu_hook_top');
}, 11);

// Disable the slide underline effect in header menu
function wdt_disable_slide_underline()
{
    echo '<style>
        /* Completely hide the sliding underline element */
        .wdt-header-menu .menu-container .wdt-primary-nav ~ .slide-underline {
            display: none !important;
            visibility: hidden !important;
            opacity: 0 !important;
        }
    </style>';
}
add_action("wp_head", "wdt_disable_slide_underline", 999);

add_action('wp_head', function () {
?>
    <style>
        /* Hide the entire main title + breadcrumb section */
        section.main-title-section-wrapper.default {
            display: none !important;
        }
    </style>
<?php
});


/**
 * AJAX Blog Search - English & Arabic Posts
 */

// Enqueue jQuery for AJAX search
function blog_ajax_search_enqueue()
{
    wp_enqueue_script('jquery'); // Ensure jQuery is loaded

    // Inline JS for AJAX search
    $inline_js = "
    jQuery(document).ready(function($){
        $('body').on('input', '.blog-search-field', function(){
            var keyword = $(this).val();

            if(keyword.length === 0){
                $('#blog-search-results').html('');
                return;
            }

            $.ajax({
                url: '" . admin_url('admin-ajax.php') . "',
                type: 'POST',
                data: {
                    action: 'blog_live_search',
                    keyword: keyword
                },
                success: function(data){
                    $('#blog-search-results').html(data);
                }
            });
        });
    });
    ";
    wp_add_inline_script('jquery', $inline_js);
}
add_action('wp_enqueue_scripts', 'blog_ajax_search_enqueue');


// AJAX handler for live search
function blog_live_search_callback()
{
    $keyword = isset($_POST['keyword']) ? sanitize_text_field($_POST['keyword']) : '';

    if (empty($keyword)) {
        echo '';
        wp_die();
    }

    $args = array(
        's' => $keyword,
        'post_type' => 'post',
        'posts_per_page' => 5,
        'post_status' => 'publish'
    );

    $query = new WP_Query($args);

    if ($query->have_posts()) {
        echo '<style>#blog-search-results ul, #blog-search-results li, #blog-search-results a { color: #000000 !important; } #blog-search-results a:hover { color: #000 !important; text-decoration: none !important; }</style>';
        echo '<ul>';
        while ($query->have_posts()) {
            $query->the_post();
            echo '<li><a href="' . get_permalink() . '">' . get_the_title() . '</a></li>';
        }
        echo '</ul>';
    } else {
        echo '<p>No articles found</p>';
    }

    wp_die();
}
add_action('wp_ajax_blog_live_search', 'blog_live_search_callback');
add_action('wp_ajax_nopriv_blog_live_search', 'blog_live_search_callback');


/**
 * USP — Approval/Rejection Emails with HTML Template (Brand Colors)
 */

add_action('transition_post_status', function ($new_status, $old_status, $post) {

    if ($post->post_type !== 'post') return;
    if ($old_status === $new_status) return;

    $emails = get_post_meta($post->ID, 'user_submit_email');
    $submitter_email = is_array($emails) ? $emails[0] : $emails;

    if (!$submitter_email) {
        error_log("USP Email ERROR: No user_submit_email meta found for Post ID {$post->ID}");
        return;
    }

    // Branding colors
    $primary   = "#C3DDD2"; // soft mint green
    $secondary = "#6059A6"; // deep purple

    // HTML email headers
    $headers = [
        'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        'Reply-To: ' . get_option('admin_email'),
        'Content-Type: text/html; charset=UTF-8'
    ];

    // ---- APPROVAL EMAIL ----
    if ($new_status === 'publish') {

        $subject = "Your article has been approved!";
        $post_link = get_permalink($post->ID);

        $message = "
        <div style='font-family: Arial, sans-serif; background:#ffffff; padding:30px; border-radius:10px; border:1px solid #eee; max-width:600px; margin:20px auto;'>

            <div style='background:$secondary; color:#fff; padding:18px; border-radius:8px 8px 0 0;'>
                <h2 style='margin:0; font-weight:600;'>Your Article is Approved 🎉</h2>
            </div>

            <div style='padding:25px; color:#333;'>
                <p>Hello,</p>
                <p>Your article titled <strong>{$post->post_title}</strong> has been <strong style='color:$secondary;'>approved</strong> and is now live on our website.</p>

                <a href='$post_link' style='display:inline-block; margin-top:20px; padding:12px 22px; background:$secondary; color:white; border-radius:6px; text-decoration:none; transition:0.3s;'>
                    View Published Article
                </a>

                <p style='margin-top:30px; font-size:13px; color:#666;'>Thank you for contributing to <strong>" . get_bloginfo('name') . "</strong>.</p>
            </div>

            <div style='background:$primary; padding:12px; text-align:center; border-radius:0 0 8px 8px; font-size:12px; color:#333;'>
                This is an automated email from " . get_bloginfo('name') . ".
            </div>
        </div>";

        wp_mail($submitter_email, $subject, $message, $headers);
        error_log("USP APPROVAL EMAIL sent to $submitter_email for Post {$post->ID}");
    }

    // ---- REJECTION EMAIL ----
    if ($new_status === 'trash' && $old_status === 'pending') {

        $subject = "Your article submission has been rejected";

        $message = "
        <div style='font-family: Arial, sans-serif; background:#ffffff; padding:30px; border-radius:10px; border:1px solid #eee; max-width:600px; margin:20px auto;'>

            <div style='background:$secondary; color:#fff; padding:18px; border-radius:8px 8px 0 0;'>
                <h2 style='margin:0; font-weight:600;'>Submission Update</h2>
            </div>

            <div style='padding:25px; color:#333;'>
                <p>Hello,</p>
                <p>Your article titled <strong>{$post->post_title}</strong> has been <strong style='color:red;'>rejected</strong> and was not published.</p>
                <p>You may review your content and submit again.</p>

                <p style='margin-top:30px; font-size:13px; color:#666;'>We appreciate your effort and encourage you to try again.</p>
            </div>

            <div style='background:$primary; padding:12px; text-align:center; border-radius:0 0 8px 8px; font-size:12px; color:#333;'>
                This is an automated email from " . get_bloginfo('name') . ".
            </div>
        </div>";

        wp_mail($submitter_email, $subject, $message, $headers);
        error_log("USP REJECTION EMAIL sent to $submitter_email for Post {$post->ID}");
    }
}, 10, 3);

/**
 * USP Form Data Preservation - Save form data to prevent loss on errors
 */
add_action('wp_footer', function () {
    // Only load on pages with USP form
    if (!function_exists('usp_display_form') && !has_shortcode(get_post()->post_content ?? '', 'user-submitted-posts')) {
        return;
    }
?>
    <script>
        (function() {
            // Save form data to localStorage before submit
            function saveUSPFormData() {
                const form = document.querySelector('#usp_form');
                if (!form) return;

                const formData = {};

                // Save text inputs
                form.querySelectorAll('input[type="text"], input[type="email"], input[type="url"]').forEach(input => {
                    if (input.name) formData[input.name] = input.value;
                });

                // Save textareas (including hidden ones)
                form.querySelectorAll('textarea').forEach(textarea => {
                    if (textarea.name && textarea.id) {
                        formData[textarea.id] = textarea.value;
                    }
                });

                // Save WordPress editor content (check both TinyMCE and textarea)
                const contentField = form.querySelector('textarea[name*="content"], #user-submitted-content, textarea#usp-content');
                if (contentField) {
                    // Try TinyMCE first
                    if (typeof tinymce !== 'undefined') {
                        const editorId = contentField.id;
                        const editor = tinymce.get(editorId);
                        if (editor) {
                            formData['post_content'] = editor.getContent();
                        } else {
                            formData['post_content'] = contentField.value;
                        }
                    } else {
                        formData['post_content'] = contentField.value;
                    }
                }

                // Save select2 tags (multiple selectors)
                const tagsSelect = form.querySelector('select[name*="tags"]');
                if (tagsSelect) {
                    const selectedValues = Array.from(tagsSelect.selectedOptions).map(opt => opt.value);
                    formData['post_tags'] = selectedValues;
                }

                // Save category (multiple selectors)
                const categorySelect = form.querySelector('select[name*="category"], #user-submitted-category');
                if (categorySelect) {
                    formData['post_category'] = categorySelect.value;
                }

                console.log('USP Form saved:', formData);
                localStorage.setItem('usp_form_backup', JSON.stringify(formData));
                localStorage.setItem('usp_form_timestamp', Date.now().toString());
            }

            // Restore form data from localStorage
            function restoreUSPFormData() {
                const savedData = localStorage.getItem('usp_form_backup');
                const timestamp = localStorage.getItem('usp_form_timestamp');

                if (!savedData || !timestamp) return;

                // Only restore if saved within last 10 minutes
                const tenMinutes = 10 * 60 * 1000;
                if (Date.now() - parseInt(timestamp) > tenMinutes) {
                    localStorage.removeItem('usp_form_backup');
                    localStorage.removeItem('usp_form_timestamp');
                    return;
                }

                const form = document.querySelector('#usp_form');
                if (!form) return;

                try {
                    const formData = JSON.parse(savedData);
                    console.log('USP Form restoring:', formData);

                    // Restore text inputs
                    Object.keys(formData).forEach(name => {
                        if (name === 'post_content' || name === 'post_tags' || name === 'post_category') return;
                        const input = form.querySelector('input[name="' + name + '"], textarea[name="' + name + '"], #' + name);
                        if (input) input.value = formData[name];
                    });

                    // Restore WordPress editor / Content
                    if (formData['post_content']) {
                        const contentField = form.querySelector('textarea[name*="content"], #user-submitted-content, textarea#usp-content');
                        if (contentField) {
                            contentField.value = formData['post_content'];

                            // If TinyMCE is active, set content there too
                            if (typeof tinymce !== 'undefined') {
                                const editorId = contentField.id;
                                let attempts = 0;
                                const checkEditor = setInterval(function() {
                                    attempts++;
                                    const editor = tinymce.get(editorId);
                                    if (editor) {
                                        editor.setContent(formData['post_content']);
                                        console.log('Content restored to TinyMCE');
                                        clearInterval(checkEditor);
                                    } else if (attempts > 30) {
                                        clearInterval(checkEditor);
                                    }
                                }, 100);
                            }
                        }
                    }

                    // Restore tags (Select2) - with delay for Select2 initialization
                    if (formData['post_tags']) {
                        setTimeout(function() {
                            const tagsSelect = form.querySelector('select[name*="tags"]');
                            if (tagsSelect && typeof jQuery !== 'undefined') {
                                jQuery(tagsSelect).val(formData['post_tags']).trigger('change');
                                console.log('Tags restored');
                            }
                        }, 500);
                    }

                    // Restore category - with delay
                    if (formData['post_category']) {
                        setTimeout(function() {
                            const categorySelect = form.querySelector('select[name*="category"], #user-submitted-category');
                            if (categorySelect) {
                                categorySelect.value = formData['post_category'];
                                // Trigger change event for any listeners
                                const event = new Event('change', {
                                    bubbles: true
                                });
                                categorySelect.dispatchEvent(event);
                                console.log('Category restored:', formData['post_category']);
                            }
                        }, 500);
                    }

                } catch (e) {
                    console.error('USP form restore error:', e);
                }
            }

            // Clear saved data after successful submission
            function clearUSPFormData() {
                localStorage.removeItem('usp_form_backup');
                localStorage.removeItem('usp_form_timestamp');
            }

            // Initialize
            document.addEventListener('DOMContentLoaded', function() {
                const form = document.querySelector('#usp_form');
                if (!form) return;

                // Restore data on page load (if user was redirected back due to error)
                restoreUSPFormData();

                // Save data before submit
                form.addEventListener('submit', function() {
                    saveUSPFormData();
                });

                // Auto-save every 30 seconds
                setInterval(function() {
                    const titleInput = form.querySelector('input[name="user-submitted-title"]');
                    if (titleInput && titleInput.value) {
                        saveUSPFormData();
                    }
                }, 30000);

                // Clear saved data if form is successfully submitted (no errors)
                // Check for success message after page load
                setTimeout(function() {
                    const successMsg = document.querySelector('.usp-success');
                    if (successMsg && successMsg.textContent.trim()) {
                        clearUSPFormData();
                    }
                }, 500);
            });
        })();
    </script>
<?php
});


add_action('admin_menu', function () {
    global $menu;

    if (!is_array($menu)) return;

    // Keep Dashboard fixed at top
    $dashboard = null;
    foreach ($menu as $index => $item) {
        if ($item[2] === 'index.php') {
            $dashboard = $item;
            unset($menu[$index]);
            break;
        }
    }

    // Alphabetical sort (A → Z)
    usort($menu, function ($a, $b) {
        return strcasecmp(
            wp_strip_all_tags($a[0]),
            wp_strip_all_tags($b[0])
        );
    });

    // Put Dashboard back
    if ($dashboard) {
        array_unshift($menu, $dashboard);
    }

}, 9999);
