<?php
function participation_conditions_form_shortcode() {
    ob_start();

    if (!session_id()) {
        session_start();
    }

    // Check if user failed assessment
//     if (isset($_SESSION['assessment_passed']) && $_SESSION['assessment_passed'] === 'false') {
//         echo '<div style="max-width:800px;margin:50px auto;padding:20px;background:#ffd6d6;border:1px solid #ff4c4c;border-radius:8px;font-size:18px;color:#333;">
//                 We are sorry, but based on the assessment you completed, you do not meet the requirements to access the Terms & Conditions at this time.
// This particular assessment is tailored to specific concerns or groups. It is possible that your situation aligns better with a different category.
// We kindly suggest trying the assessments for other groups — you may qualify under one of those and be able to proceed further.
// If you need help choosing the right assessment or have any questions, please feel free to reach out to our support team.
// Thank you for your understanding!
//                 <br><br>
//               <a href="' . home_url('/en/therapy-groups') . '" style="color:#fff;background:#635ba3;padding:10px 20px;border-radius:5px;text-decoration:none;">Go Back to Group Therapy</a>
//               </div>';
//         unset($_SESSION['assessment_passed']); // clear session
//         return ob_get_clean(); // stop rendering form
//     }
    ?>
    <form id="conditionsForm">
      
      <div class="checkbox-group">
        <label class="section-title">Participation Conditions:</label>
        <div class="checkbox-item">
          <input type="checkbox" name="participantConditions" value="1" id="pc1" />
          <label for="pc1">1. The participant must be 18 years or older.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="participantConditions" value="2" id="pc2" />
          <label for="pc2">2. Commitment to attending all scheduled sessions within the program (80% of sessions).</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="participantConditions" value="3" id="pc3" />
          <label for="pc3">3. Respecting the group's privacy, confidentiality, and safety, and refraining from recording or sharing anything discussed within the group.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="participantConditions" value="4" id="pc4" />
          <label for="pc4">4. Willingness to participate honestly and openly within personal comfort boundaries.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="participantConditions" value="5" id="pc5" />
          <label for="pc5">5. Absence of severe or unstable psychological disorders (e.g., active psychosis or untreated suicidal ideation).</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="participantConditions" value="6" id="pc6" />
          <label for="pc6">6. Payment of group psychotherapy fees before the first session (through our payment channels).</label>
        </div>
      </div>
      
      <div class="checkbox-group">
        <label class="section-title">Ethical Commitments of Participants:</label>
        <div class="checkbox-item">
          <input type="checkbox" name="ethicalConditions" value="1" id="ec1" />
          <label for="ec1">1. Maintaining complete confidentiality regarding what is shared within the group.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="ethicalConditions" value="2" id="ec2" />
          <label for="ec2">2. Respecting differing opinions and experiences without judgment.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="ethicalConditions" value="3" id="ec3" />
          <label for="ec3">3. Allowing space for group members without interrupting or unwanted interference.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="ethicalConditions" value="4" id="ec4" />
          <label for="ec4">4. Interacting with respectful and mindful language.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="ethicalConditions" value="5" id="ec5" />
          <label for="ec5">5. Not using any information shared within the group outside its context.</label>
        </div>
      </div>
    
      <div class="checkbox-group">
        <label class="section-title">Participants' Rights:</label>
        <div class="checkbox-item">
          <input type="checkbox" name="participantsRights" value="1" id="pr1" />
          <label for="pr1">1. Access to a safe and supportive environment.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="participantsRights" value="2" id="pr2" />
          <label for="pr2">2. Freedom to participate or refrain without obligation.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="participantsRights" value="3" id="pr3" />
          <label for="pr3">3. Professional guidance from a licensed and certified specialist/therapist.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="participantsRights" value="4" id="pr4" />
          <label for="pr4">4. Receiving individual support when needed, if necessary.</label>
        </div>
      </div>
    
      <button type="submit">Accept Terms & Conditions</button>
    </form>
    
    <style>
      #conditionsForm {
        direction: ltr;
        text-align: left;
        max-width: 800px;
        margin: 0 auto;
        padding: 20px;
        font-family: 'Segoe UI', 'Tahoma', 'Geneva', 'Verdana', sans-serif;
        line-height: 1.6;
      }
      
      .section-title {
        color: #635ba3;
        font-size: 32px;
        font-weight: bold;
        margin-bottom: 15px;
        margin-top: 30px;
        display: block;
        border-bottom: 2px solid #635ba3;
        padding-bottom: 10px;
      }
      
      .checkbox-group {
        margin-bottom: 30px;
      }
      
      .checkbox-item {
        display: flex;
        align-items: flex-start;
        margin-bottom: 15px;
        padding: 10px;
        background: #bcd7dc;
        border-radius: 8px;
        border-left: 4px solid #635ba3;
        transition: background-color 0.2s ease;
      }
      
      .checkbox-item:hover {
        background: #d8e8eb;
      }
      
      .checkbox-item input[type="checkbox"] {
        margin-right: 12px;
        margin-top: 3px;
        transform: scale(1.2);
        cursor: pointer;
      }
      
      .checkbox-item label {
        flex: 1;
        font-size: 16px;
        color: #333;
        cursor: pointer;
        margin: 0;
        line-height: 1.5;
      }
      
      button {
        background: #635ba3;
        color: white;
        border: none;
        padding: 15px 30px;
        font-size: 18px;
        border-radius: 8px;
        cursor: pointer;
        display: block;
        margin: 30px auto 0 auto;
        transition: background-color 0.3s ease;
        font-family: inherit;
      }
      
      button:hover {
        background: #b1d1c0;
      }
      
      button:active {
        transform: translateY(1px);
      }
      
      /* Responsive design */
      @media (max-width: 768px) {
        .section-title {
          font-size: 24px;
        }
        
        .checkbox-item label {
          font-size: 14px;
        }
        
        button {
          width: 100%;
          padding: 12px;
          font-size: 16px;
        }
      }
    </style>
    
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        document.getElementById('conditionsForm').addEventListener('submit', function(event) {
          event.preventDefault();
          
          function allChecked(name) {
            const checkboxes = document.querySelectorAll(`input[name="${name}"]`);
            return Array.from(checkboxes).every(cb => cb.checked);
          }
          
          if (
            allChecked('participantConditions') &&
            allChecked('ethicalConditions') &&
            allChecked('participantsRights')
          ) {
            window.location.href = "/register/";
          } else {
            alert('Please check all the boxes before proceeding.');
          }
        });
      });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('participation_conditions_form', 'participation_conditions_form_shortcode');


function participation_conditions_ar_form() {
    ob_start();

    if (!session_id()) {
        session_start();
    }

    // Check if user failed assessment
//     if (isset($_SESSION['assessment_passed']) && $_SESSION['assessment_passed'] === 'false') {
//         echo '<div style="max-width:800px;margin:50px auto;padding:20px;background:#ffd6d6;border:1px solid #ff4c4c;border-radius:8px;font-size:18px;color:#333;">
//                 عذرًا، ولكن بناءً على التقييم الذي أكملته، فإنك لا تستوفي متطلبات الوصول إلى الشروط والأحكام في الوقت الحالي.
// تم تصميم هذا التقييم الخاص لاهتمامات أو مجموعات محددة. من الممكن أن يكون وضعك متوافقًا بشكل أفضل مع فئة مختلفة.
// نقترح عليك تجربة التقييمات لمجموعات أخرى - فقد تكون مؤهلاً ضمن إحدى هذه التقييمات وتكون قادرًا على المضي قدمًا.
// إذا كنت بحاجة إلى مساعدة في اختيار التقييم المناسب أو لديك أي أسئلة، فلا تتردد في التواصل مع فريق الدعم لدينا.
// شكرا لتفهمك!
//                 <br><br>
//                 <a href="' . home_url('/therapy-groups-arabic') . '" style="color:#fff;background:#635ba3;padding:10px 20px;border-radius:5px;text-decoration:none;">العودة إلى العلاج الجماعي</a>
//               </div>';
//         unset($_SESSION['assessment_passed']); // clear session
//         return ob_get_clean(); // stop rendering form
//     }
    ?>
    <form id="conditionsFormArabic" dir="rtl" style="text-align: right; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">
      
      <div class="checkbox-group">
        <label class="section-title">شروط المشاركة:</label>
        <div class="checkbox-item">
          <input type="checkbox" name="participantConditions" value="1" id="pc1" />
          <label for="pc1">1. يجب أن يكون المشارك 18 عامًا أو أكثر.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="participantConditions" value="2" id="pc2" />
          <label for="pc2">2. الالتزام بحضور جميع الجلسات المقررة ضمن البرنامج (80% من الجلسات).</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="participantConditions" value="3" id="pc3" />
          <label for="pc3">3. احترام خصوصية المجموعة وسريتها وسلامتها، والامتناع عن تسجيل أو مشاركة أي شيء يتم مناقشته داخل المجموعة.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="participantConditions" value="4" id="pc4" />
          <label for="pc4">4. الاستعداد للمشاركة بصدق وانفتاح ضمن حدود الراحة الشخصية.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="participantConditions" value="5" id="pc5" />
          <label for="pc5">5. عدم وجود اضطرابات نفسية حادة أو غير مستقرة (مثل الذهان النشط أو الأفكار الانتحارية غير المعالجة).</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="participantConditions" value="6" id="pc6" />
          <label for="pc6">6. دفع رسوم العلاج الجماعي قبل الجلسة الأولى (عبر قنوات الدفع لدينا).</label>
        </div>
      </div>
      
      <div class="checkbox-group">
        <label class="section-title">الالتزامات الأخلاقية للمشاركين:</label>
        <div class="checkbox-item">
          <input type="checkbox" name="ethicalConditions" value="1" id="ec1" />
          <label for="ec1">1. الحفاظ على السرية التامة لما يتم مشاركته داخل المجموعة.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="ethicalConditions" value="2" id="ec2" />
          <label for="ec2">2. احترام الآراء والتجارب المختلفة دون حكم أو نقد.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="ethicalConditions" value="3" id="ec3" />
          <label for="ec3">3. إتاحة المجال لأعضاء المجموعة دون مقاطعة أو تدخل غير مرغوب فيه.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="ethicalConditions" value="4" id="ec4" />
          <label for="ec4">4. التعامل بلغة محترمة ومدروسة.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="ethicalConditions" value="5" id="ec5" />
          <label for="ec5">5. عدم استخدام أي معلومات تم مشاركتها داخل المجموعة خارج سياقها.</label>
        </div>
      </div>
    
      <div class="checkbox-group">
        <label class="section-title">حقوق المشاركين:</label>
        <div class="checkbox-item">
          <input type="checkbox" name="participantsRights" value="1" id="pr1" />
          <label for="pr1">1. الوصول إلى بيئة آمنة وداعمة.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="participantsRights" value="2" id="pr2" />
          <label for="pr2">2. الحرية في المشاركة أو الامتناع بدون أي التزام.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="participantsRights" value="3" id="pr3" />
          <label for="pr3">3. الإرشاد المهني من متخصص/معالج مرخص ومعتمد.</label>
        </div>
        <div class="checkbox-item">
          <input type="checkbox" name="participantsRights" value="4" id="pr4" />
          <label for="pr4">4. تلقي الدعم الفردي عند الحاجة، إذا اقتضى الأمر.</label>
        </div>
      </div>
    
      <button type="submit">أوافق على الشروط والأحكام</button>
    </form>
    
    <style>
      #conditionsFormArabic {
        direction: rtl;
        text-align: right;
        max-width: 800px;
        margin: 0 auto;
        padding: 20px;
        font-family: 'Segoe UI', 'Tahoma', 'Geneva', 'Verdana', sans-serif;
        line-height: 1.6;
      }
      
      .section-title {
        color: #374F21;
        font-size: 32px;
        font-weight: bold;
        margin-bottom: 15px;
        margin-top: 30px;
        display: block;
        border-bottom: 2px solid #374F21;
        padding-bottom: 10px;
      }
      
      .checkbox-group {
        margin-bottom: 30px;
      }
      
      .checkbox-item {
        display: flex;
        align-items: flex-start;
        margin-bottom: 15px;
        padding: 10px;
        background: #f9f9f9;
        border-radius: 8px;
        border-right: 4px solid #374F21;
        transition: background-color 0.2s ease;
      }
      
      .checkbox-item:hover {
        background: #f0f0f0;
      }
      
      .checkbox-item input[type="checkbox"] {
        margin-left: 12px;
        margin-top: 3px;
        transform: scale(1.2);
        cursor: pointer;
      }
      
      .checkbox-item label {
        flex: 1;
        font-size: 16px;
        color: #333;
        cursor: pointer;
        margin: 0;
        line-height: 1.5;
      }
      
      button {
        background: #374F21;
        color: white;
        border: none;
        padding: 15px 30px;
        font-size: 18px;
        border-radius: 8px;
        cursor: pointer;
        display: block;
        margin: 30px auto 0 auto;
        transition: background-color 0.3s ease;
        font-family: inherit;
      }
      
      button:hover {
        background: #2d3e1a;
      }
      
      button:active {
        transform: translateY(1px);
      }
      
      /* RTL specific adjustments */
      @media (max-width: 768px) {
        .section-title {
          font-size: 24px;
        }
        
        .checkbox-item label {
          font-size: 14px;
        }
        
        button {
          width: 100%;
          padding: 12px;
          font-size: 16px;
        }
      }
    </style>
    
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        document.getElementById('conditionsFormArabic').addEventListener('submit', function(event) {
          event.preventDefault();
          
          function allChecked(name) {
            const checkboxes = document.querySelectorAll(`input[name="${name}"]`);
            return Array.from(checkboxes).every(cb => cb.checked);
          }
          
          if (
            allChecked('participantConditions') &&
            allChecked('ethicalConditions') &&
            allChecked('participantsRights')
          ) {
            window.location.href = "/ar/register-2/";
          } else {
            alert('يرجى تحديد جميع المربعات قبل المتابعة.');
          }
        });
      });
    </script>
    <?php
    return ob_get_clean();
}
add_shortcode('participation_conditions_ar', 'participation_conditions_ar_form');
add_shortcode('debug_session_data', function () {
    if (!session_id()) {
        session_start();
    }

    ob_start();
    echo '<h3>Session Debug Info</h3>';
    echo '<pre>';
    print_r($_SESSION);
    echo '</pre>';
    return ob_get_clean();
});
