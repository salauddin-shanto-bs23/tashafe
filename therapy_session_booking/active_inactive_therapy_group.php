<?php

// Helper function to get available session periods for an issue/gender
function get_available_session_periods($issue, $gender)
{
    $args = [
        'post_type' => 'therapy_group',
        'post_status' => 'publish',
        'posts_per_page' => -1,
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
        ]
    ];

    $groups = get_posts($args);
    $periods = [];

    foreach ($groups as $group) {
        $start = get_field('session_start_date', $group->ID);
        $end = get_field('session_expiry_date', $group->ID);
        $max = (int) get_field('max_members', $group->ID);

        // Count current members
        $count = count(get_users([
            'meta_key' => 'assigned_group',
            'meta_value' => $group->ID
        ]));

        if ($start && $end) {
            $period_key = $start . '_' . $end;

            // Only add unique periods
            if (!isset($periods[$period_key])) {
                $periods[$period_key] = [
                    'start' => $start,
                    'end' => $end,
                    'label' => date('M j', strtotime($start)) . ' - ' . date('M j, Y', strtotime($end)),
                    'group_id' => $group->ID,
                    'available' => $count < $max,
                    'current_count' => $count,
                    'max_members' => $max
                ];
            }
        }
    }

    return array_values($periods);
}

// ENGLISH SHORTCODE
function show_issue_gender_buttons_en($atts)
{
    $atts = shortcode_atts([
        'issue' => 'anxiety'
    ], $atts, 'issue_buttons');

    $issue = strtolower(trim($atts['issue']));

    $genders = [
        'male'   => 'Male',
        'female' => 'Female',
    ];

    ob_start();
    echo '<div class="gender-button-wrapper">';

    foreach ($genders as $gender => $label) {
        // Get available session periods for this gender/issue
        $periods = get_available_session_periods($issue, $gender);
        $has_sessions = !empty($periods);
        $disabled_message = 'Groups coming soon';

        // Encode periods data for JavaScript
        $periods_json = htmlspecialchars(json_encode($periods), ENT_QUOTES, 'UTF-8');

?>
        <div class="gender-btn-container">
            <button
                class="gender-btn <?php echo !$has_sessions ? 'disabled' : ''; ?>"
                <?php if ($has_sessions): ?>
                onclick="openSessionModal('<?php echo esc_js($issue); ?>', '<?php echo esc_js($gender); ?>', '<?php echo esc_attr($periods_json); ?>')"
                <?php else: ?>
                disabled
                <?php endif; ?>>
                <?php echo esc_html($label); ?>
            </button>
            <?php if (!$has_sessions): ?>
                <div class="gender-btn-message">
                    <?php echo esc_html($disabled_message); ?>
                </div>
            <?php endif; ?>
        </div>
    <?php
    }

    echo '</div>';

    // Add modal HTML (only once)
    echo '
    <div id="sessionModal" class="session-modal" style="display: none;">
        <div class="session-modal-overlay" onclick="closeSessionModal()"></div>
        <div class="session-modal-content">
            <div class="session-modal-header">
                <h3>Select Session Period</h3>
                <button class="session-modal-close" onclick="closeSessionModal()">&times;</button>
            </div>
            <div class="session-modal-body" id="sessionModalBody">
                <!-- Session options will be inserted here -->
            </div>
            <div class="session-modal-footer">
                <button class="waiting-list-footer-btn" id="waitingListFooterBtn">
                    Put me on the waiting list if other dates are available
                </button>
            </div>
        </div>
    </div>
    
    <!-- Waiting List Modal -->
    <div id="waitingListModal" class="session-modal" style="display: none;">
        <div class="session-modal-overlay" onclick="closeWaitingListModal()"></div>
        <div class="session-modal-content waiting-modal-content">
            <div class="session-modal-header">
                <h3>Join Waiting List</h3>
                <button class="session-modal-close" onclick="closeWaitingListModal()">&times;</button>
            </div>
            <div class="session-modal-body">
                <form id="waitingListForm" class="waiting-list-form">
                    <input type="text" name="name" placeholder="Full Name" required class="waiting-input">
                    <input type="email" name="email" placeholder="Email Address" required class="waiting-input">
                    <input type="text" name="phone" placeholder="Phone Number" class="waiting-input">
                    <input type="hidden" name="topic" id="waitingTopic">
                    <input type="hidden" name="gender" id="waitingGender">
                    <button type="submit" class="waiting-submit-btn">Submit</button>
                </form>
                <div class="waiting-success" style="display:none;">Thank you! You have been added to the waiting list.</div>
            </div>
        </div>
    </div>';

    return ob_get_clean();
}
add_shortcode('issue_buttons', 'show_issue_gender_buttons_en');

// ARABIC SHORTCODE
function show_issue_gender_buttons_ar($atts)
{
    $atts = shortcode_atts([
        'issue' => 'anxiety'
    ], $atts, 'issue_buttons_ar');

    $issue = strtolower(trim($atts['issue']));

    $genders = [
        'male'   => 'الذكور',
        'female' => 'الإناث',
    ];

    ob_start();
    echo '<div class="gender-button-wrapper">';

    foreach ($genders as $gender => $label) {
        // Get available session periods for this gender/issue
        $periods = get_available_session_periods($issue, $gender);
        $has_sessions = !empty($periods);
        $disabled_message = 'لا توجد جلسات';

        // Encode periods data for JavaScript
        $periods_json = htmlspecialchars(json_encode($periods), ENT_QUOTES, 'UTF-8');

    ?>
        <div class="gender-btn-container">
            <button
                class="gender-btn <?php echo !$has_sessions ? 'disabled' : ''; ?>"
                <?php if ($has_sessions): ?>
                onclick="openSessionModalAr('<?php echo esc_js($issue); ?>', '<?php echo esc_js($gender); ?>', '<?php echo esc_attr($periods_json); ?>')"
                <?php else: ?>
                disabled
                <?php endif; ?>>
                <?php echo esc_html($label); ?>
            </button>
            <?php if (!$has_sessions): ?>
                <div class="gender-btn-message">
                    <?php echo esc_html($disabled_message); ?>
                </div>
            <?php endif; ?>
        </div>
    <?php
    }

    echo '</div>';

    // Add modal HTML (only once)
    echo '
    <div id="sessionModalAr" class="session-modal" style="display: none;">
        <div class="session-modal-overlay" onclick="closeSessionModalAr()"></div>
        <div class="session-modal-content">
            <div class="session-modal-header">
                <h3>اختر فترة الجلسات</h3>
                <button class="session-modal-close" onclick="closeSessionModalAr()">&times;</button>
            </div>
            <div class="session-modal-body" id="sessionModalBodyAr">
                <!-- Session options will be inserted here -->
            </div>
            <div class="session-modal-footer">
                <button class="waiting-list-footer-btn" id="waitingListFooterBtnAr">
                    ضعني في قائمة الانتظار إذا كانت هناك تواريخ أخرى متاحة
                </button>
            </div>
        </div>
    </div>
    
    <!-- Waiting List Modal Arabic -->
    <div id="waitingListModalAr" class="session-modal" style="display: none;">
        <div class="session-modal-overlay" onclick="closeWaitingListModalAr()"></div>
        <div class="session-modal-content waiting-modal-content">
            <div class="session-modal-header">
                <h3>انضم إلى قائمة الانتظار</h3>
                <button class="session-modal-close" onclick="closeWaitingListModalAr()">&times;</button>
            </div>
            <div class="session-modal-body">
                <form id="waitingListFormAr" class="waiting-list-form">
                    <input type="text" name="name" placeholder="الاسم الكامل" required class="waiting-input">
                    <input type="email" name="email" placeholder="عنوان البريد الإلكتروني" required class="waiting-input">
                    <input type="text" name="phone" placeholder="رقم الهاتف" class="waiting-input">
                    <input type="hidden" name="topic" id="waitingTopicAr">
                    <input type="hidden" name="gender" id="waitingGenderAr">
                    <button type="submit" class="waiting-submit-btn">إرسال</button>
                </form>
                <div class="waiting-success" style="display:none;">شكراً لك! تمت إضافتك إلى قائمة الانتظار.</div>
            </div>
        </div>
    </div>';

    return ob_get_clean();
}
add_shortcode('issue_buttons_ar', 'show_issue_gender_buttons_ar');

// CSS and JavaScript
add_action('wp_enqueue_scripts', 'therapy_group_buttons_css');
function therapy_group_buttons_css()
{
    wp_register_style('therapy-group-buttons', false);
    wp_enqueue_style('therapy-group-buttons');
    wp_add_inline_style(
        'therapy-group-buttons',
        '
        .gender-button-wrapper {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 20px;
        }
        .gender-btn-container {
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .gender-btn {
            padding: 30px 40px;
            border-radius: 10px;
            border: none;
            font-size: 16px;
            cursor: pointer;
            background: linear-gradient(to bottom, #C3DDD2, #635ba3);
            color: #000;
            transition: none;
        }
        .gender-btn:hover,
        .gender-btn:focus,
        .gender-btn:active {
            background: linear-gradient(to bottom, #C3DDD2, #635ba3);
            box-shadow: none;
            transform: none;
            outline: none;
            opacity: 1;
        }
        .gender-btn.disabled {
            filter: blur(0.5px);
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }
        .gender-btn-message {
            margin-top: 6px;
            font-size: 13px;
            color: #555;
            text-align: center;
        }
        
        /* Modal Styles */
        .session-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .session-modal-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
        }
        .session-modal-content {
            position: relative;
            background: white;
            border-radius: 12px;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
            animation: modalSlideIn 0.3s ease-out;
        }
        @keyframes modalSlideIn {
            from {
                opacity: 0;
                transform: translateY(-50px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .session-modal-header {
            background: linear-gradient(to right, #C3DDD2, #635ba3);
            color: white;
            padding: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .session-modal-header h3 {
            margin: 0;
            font-size: 20px;
        }
        .session-modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 30px;
            cursor: pointer;
            line-height: 1;
            padding: 0;
            width: 30px;
            height: 30px;
        }
        .session-modal-body {
            padding: 20px;
            max-height: 60vh;
            overflow-y: auto;
        }
        .session-option {
            padding: 15px;
            margin-bottom: 12px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .session-option:hover {
            border-color: #635ba3;
            background: #f9f9f9;
        }
        .session-option.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            background: #f5f5f5;
        }
        .session-option-label {
            font-weight: 600;
            color: #333;
        }
        .session-option-info {
            font-size: 12px;
            color: #666;
        }
        .session-option-status {
            font-size: 11px;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
        }
        .session-option-status.available {
            background: #e8f5e9;
            color: #2e7d32;
        }
        .session-option-status.full {
            background: #ffebee;
            color: #c62828;
        }
        .session-modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #e0e0e0;
            text-align: center;
        }
        .waiting-list-footer-btn {
            background: linear-gradient(135deg, #C3DDD2, #6059A6);
            color: #fff;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: opacity 0.3s;
            width: 100%;
        }
        .waiting-list-footer-btn:hover {
            opacity: 0.9;
        }
        .waiting-modal-content {
            max-width: 450px;
        }
        .waiting-list-form {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .waiting-input {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            box-sizing: border-box;
        }
        .waiting-submit-btn {
            background: linear-gradient(135deg, #C3DDD2, #6059A6);
            color: #fff;
            border: none;
            padding: 12px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: opacity 0.3s;
        }
        .waiting-submit-btn:hover {
            opacity: 0.9;
        }
        .waiting-success {
            color: #2e7d32;
            font-weight: 600;
            text-align: center;
            padding: 15px;
            background: #e8f5e9;
            border-radius: 8px;
            margin-top: 10px;
        }
        '
    );
}

add_action('wp_footer', 'therapy_group_buttons_js');
function therapy_group_buttons_js()
{
    ?>
    <script>
        let currentIssue = '';
        let currentGender = '';

        function openSessionModal(issue, gender, periodsJson) {
            currentIssue = issue;
            currentGender = gender;

            const periods = JSON.parse(periodsJson);
            const modal = document.getElementById('sessionModal');
            const modalBody = document.getElementById('sessionModalBody');

            modalBody.innerHTML = '';

            periods.forEach(period => {
                const option = document.createElement('div');
                option.className = 'session-option' + (period.available ? '' : ' disabled');

                const statusClass = period.available ? 'available' : 'full';
                const statusText = period.available ? 'Available' : 'Full';

                option.innerHTML = `
                <div>
                    <div class="session-option-label">${period.label}</div>
                </div>
                <div class="session-option-status ${statusClass}">${statusText}</div>
            `;

                if (period.available) {
                    option.onclick = function() {
                        const url = '<?php echo site_url("/"); ?>' + issue + '-assessment?gender=' + gender + '&issue=' + issue + '&group_id=' + period.group_id;
                        window.location.href = url;
                    };
                }

                modalBody.appendChild(option);
            });

            modal.style.display = 'flex';
        }

        function closeSessionModal() {
            document.getElementById('sessionModal').style.display = 'none';
        }

        function openWaitingListModal() {
            document.getElementById('waitingTopic').value = currentIssue;
            document.getElementById('waitingGender').value = currentGender;
            document.getElementById('waitingListModal').style.display = 'flex';
        }

        function closeWaitingListModal() {
            document.getElementById('waitingListModal').style.display = 'none';
            document.getElementById('waitingListForm').reset();
            document.querySelector('#waitingListModal .waiting-success').style.display = 'none';
        }

        // Handle waiting list button click
        document.addEventListener('DOMContentLoaded', function() {
            const waitingBtn = document.getElementById('waitingListFooterBtn');
            if (waitingBtn) {
                waitingBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    openWaitingListModal();
                });
            }

            // Handle waiting list form submission
            jQuery('#waitingListForm').on('submit', function(e) {
                e.preventDefault();

                var formData = jQuery(this).serialize();

                jQuery.post('<?php echo admin_url("admin-ajax.php"); ?>', {
                    action: 'add_to_waiting_list',
                    form: formData
                }, function(response) {
                    if (response.success) {
                        jQuery('#waitingListForm')[0].reset();
                        jQuery('#waitingListModal .waiting-success').show();
                        setTimeout(function() {
                            closeWaitingListModal();
                        }, 2000);
                    } else {
                        alert('Error! Please try again.');
                    }
                });
            });
        });

        function openSessionModalAr(issue, gender, periodsJson) {
            currentIssue = issue;
            currentGender = gender;

            const periods = JSON.parse(periodsJson);
            const modal = document.getElementById('sessionModalAr');
            const modalBody = document.getElementById('sessionModalBodyAr');

            modalBody.innerHTML = '';

            periods.forEach(period => {
                const option = document.createElement('div');
                option.className = 'session-option' + (period.available ? '' : ' disabled');

                const statusClass = period.available ? 'available' : 'full';
                const statusText = period.available ? 'متاح' : 'ممتلئ';

                option.innerHTML = `
                <div>
                    <div class="session-option-label">${period.label}</div>
                </div>
                <div class="session-option-status ${statusClass}">${statusText}</div>
            `;

                if (period.available) {
                    option.onclick = function() {
                        const url = '<?php echo site_url("/ar/"); ?>' + issue + '-assessment-arabic?gender=' + gender + '&issue=' + issue + '&group_id=' + period.group_id;
                        window.location.href = url;
                    };
                }

                modalBody.appendChild(option);
            });

            modal.style.display = 'flex';
        }

        function closeSessionModalAr() {
            document.getElementById('sessionModalAr').style.display = 'none';
        }

        function openWaitingListModalAr() {
            document.getElementById('waitingTopicAr').value = currentIssue;
            document.getElementById('waitingGenderAr').value = currentGender;
            document.getElementById('waitingListModalAr').style.display = 'flex';
        }

        function closeWaitingListModalAr() {
            document.getElementById('waitingListModalAr').style.display = 'none';
            document.getElementById('waitingListFormAr').reset();
            document.querySelector('#waitingListModalAr .waiting-success').style.display = 'none';
        }

        // Handle Arabic waiting list button click
        document.addEventListener('DOMContentLoaded', function() {
            const waitingBtnAr = document.getElementById('waitingListFooterBtnAr');
            if (waitingBtnAr) {
                waitingBtnAr.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    openWaitingListModalAr();
                });
            }

            // Handle Arabic waiting list form submission
            jQuery('#waitingListFormAr').on('submit', function(e) {
                e.preventDefault();

                var formData = jQuery(this).serialize();

                jQuery.post('<?php echo admin_url("admin-ajax.php"); ?>', {
                    action: 'add_to_waiting_list',
                    form: formData
                }, function(response) {
                    if (response.success) {
                        jQuery('#waitingListFormAr')[0].reset();
                        jQuery('#waitingListModalAr .waiting-success').show();
                        setTimeout(function() {
                            closeWaitingListModalAr();
                        }, 2000);
                    } else {
                        alert('حدث خطأ! يرجى المحاولة مرة أخرى.');
                    }
                });
            });
        });

        // Close modal on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeSessionModal();
                closeSessionModalAr();
                closeWaitingListModal();
                closeWaitingListModalAr();
            }
        });
    </script>
<?php
}
