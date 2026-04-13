<?php
/**
 * Snippet Name: Header text translation by page language
 * Purpose: Change only header labels based on page language.
 *
 * Source selectors verified from:
 * - home/Home_arabic.html
 * - home/home.html
 *
 * Header button widgets:
 * - .elementor-element-5297bb2 -> Book Now
 * - .elementor-element-263a1d8 -> Contact Us
 * - .elementor-element-b16ae74 -> Contact Us (responsive header variant)
 *
 * Header top-bar widgets:
 * - .elementor-element-e649d64 -> Riyadh, Saudi Arabia
 * - .elementor-element-c0f5a28 -> Follow us
 */

if (!function_exists('tanafs_localize_header_cta_texts')) {
    function tanafs_localize_header_cta_texts()
    {
        ?>
        <script>
            (function() {
                function isArabicPage() {
                    var htmlLang = (document.documentElement.getAttribute('lang') || '').toLowerCase();
                    var bodyClasses = (document.body && document.body.className ? document.body.className : '').toLowerCase();

                    return htmlLang.indexOf('ar') === 0 ||
                        bodyClasses.indexOf('lang-ar') !== -1 ||
                        bodyClasses.indexOf('rtl') !== -1;
                }

                function isEnglishPage() {
                    var htmlLang = (document.documentElement.getAttribute('lang') || '').toLowerCase();
                    var bodyClasses = (document.body && document.body.className ? document.body.className : '').toLowerCase();

                    return htmlLang.indexOf('en') === 0 || bodyClasses.indexOf('lang-en') !== -1;
                }

                function setText(selector, value) {
                    var element = document.querySelector(selector);
                    if (element) {
                        element.textContent = value;
                    }
                }

                function localizeHeaderTexts() {
                    if (isArabicPage()) {
                        setText('.elementor-element-5297bb2 .elementor-button-text', 'احجز الآن');
                        setText('.elementor-element-263a1d8 .elementor-button-text', 'اتصل بنا');
                        setText('.elementor-element-b16ae74 .elementor-button-text', 'اتصل بنا');
                        setText('.elementor-element-e649d64 .elementor-icon-list-text', 'الرياض، السعودية');
                        setText('.elementor-element-c0f5a28 .elementor-icon-list-item:first-child .elementor-icon-list-text', 'تابعنــا');
                        return;
                    }

                    if (isEnglishPage()) {
                        setText('.elementor-element-5297bb2 .elementor-button-text', 'Book Now');
                        setText('.elementor-element-263a1d8 .elementor-button-text', 'Contact Us');
                        setText('.elementor-element-b16ae74 .elementor-button-text', 'Contact Us');
                        setText('.elementor-element-e649d64 .elementor-icon-list-text', 'Riyadh, Saudi Arabia');
                        setText('.elementor-element-c0f5a28 .elementor-icon-list-item:first-child .elementor-icon-list-text', 'Follow us');
                    }
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', localizeHeaderTexts);
                } else {
                    localizeHeaderTexts();
                }
            })();
        </script>
        <?php
    }
}

add_action('wp_footer', 'tanafs_localize_header_cta_texts', 99);
