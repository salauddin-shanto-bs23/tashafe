<?php
add_action('template_redirect', function () {
    if (!function_exists('pll_current_language')) {
        return;
    }

    $lang = pll_current_language();

    if (
        $lang === 'en' &&
        is_404() &&
        strpos($_SERVER['REQUEST_URI'], '/en/groups') === 0
    ) {
        wp_redirect(home_url('/groups'));
        exit;
    }
});

add_filter('pll_the_language_link', function ($url, $slug, $locale) {

    // If currently on BuddyPress Groups page
    if (function_exists('bp_is_groups_component') && bp_is_groups_component()) {
        return home_url('/groups');
    }

    return $url;
}, 10, 3);
