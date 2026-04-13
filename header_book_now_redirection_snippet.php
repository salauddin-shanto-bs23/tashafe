<?php
/**
 * Snippet Name: Header Book Now language-based redirection
 * Purpose: Redirect header Book Now and Contact Us buttons based on current page language.
 *
 * Targets only Elementor header button widget:
 * - .elementor-element-5297bb2 .elementor-button
 * - .elementor-element-263a1d8 .elementor-button
 * - .elementor-element-b16ae74 .elementor-button
 */

if (!function_exists('tanafs_header_book_now_redirection')) {
    function tanafs_header_book_now_redirection()
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

                function getBookNowTargetPath() {
                    if (isArabicPage()) {
                        return '/retreat-arabic/';
                    }

                    if (isEnglishPage()) {
                        return '/en/retreat/';
                    }

                    return null;
                }

                function getContactTargetPath() {
                    if (isArabicPage()) {
                        return '/contact-arabic/';
                    }

                    if (isEnglishPage()) {
                        return '/en/contact/';
                    }

                    return null;
                }

                function applyRedirect(selector, targetPath) {
                    if (!targetPath) {
                        return;
                    }

                    var links = document.querySelectorAll(selector);
                    if (!links.length) {
                        return;
                    }

                    links.forEach(function(link) {
                        link.setAttribute('href', targetPath);

                        link.addEventListener('click', function(event) {
                            event.preventDefault();
                            window.location.href = targetPath;
                        });
                    });
                }

                function applyHeaderRedirects() {
                    applyRedirect('.elementor-element-5297bb2 .elementor-button', getBookNowTargetPath());
                    applyRedirect('.elementor-element-263a1d8 .elementor-button', getContactTargetPath());
                    applyRedirect('.elementor-element-b16ae74 .elementor-button', getContactTargetPath());
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', applyHeaderRedirects);
                } else {
                    applyHeaderRedirects();
                }
            })();
        </script>
        <?php
    }
}

add_action('wp_footer', 'tanafs_header_book_now_redirection', 99);
