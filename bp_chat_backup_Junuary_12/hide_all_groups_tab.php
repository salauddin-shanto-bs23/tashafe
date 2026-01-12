<?php
/**
 * BuddyPress Groups directory hardening:
 * - For non-admins: default to "My Groups" behavior on /groups (no redirect, URL stays /groups)
 * - Prevents listing ANY groups outside the user’s memberships (including private groups)
 * - Keeps "All Groups" tab available for admins/moderators
 *
 * Paste into a snippets plugin (e.g., WPCode) as a PHP snippet.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Treat BuddyPress moderators/admins as allowed to use "All Groups".
 */
function cm_groups_is_directory_admin_user(): bool {
    if ( function_exists( 'bp_current_user_can' ) && bp_current_user_can( 'bp_moderate' ) ) {
        return true;
    }

    return current_user_can( 'manage_options' );
}

/**
 * Force groups directory queries (and its AJAX) to only show the logged-in user’s groups.
 */
add_filter( 'bp_ajax_querystring', function ( $querystring, $object ) {
    if ( 'groups' !== $object ) {
        return $querystring;
    }

    if ( ! is_user_logged_in() || cm_groups_is_directory_admin_user() ) {
        return $querystring;
    }

    if ( function_exists( 'bp_is_groups_directory' ) && ! bp_is_groups_directory() ) {
        // Don’t touch group loops outside the Groups Directory screen.
        return $querystring;
    }

    $args = array();
    if ( is_string( $querystring ) && $querystring !== '' ) {
        parse_str( $querystring, $args );
    }

    // Core behavior: only show groups the user is enrolled in.
    $args['scope']   = 'personal';                // BuddyPress "My Groups" scope
    $args['user_id'] = get_current_user_id();

    // Ensure enrolled users can still see groups even if they are private/hidden.
    // (This does NOT make hidden groups visible to non-members.)
    $args['show_hidden'] = 1;

    // Rebuild querystring, preserving other existing args (type, search_terms, etc).
    return http_build_query( $args );
}, 20, 2 );

/**
 * Make the "My Groups" tab look active by default on /groups (without redirecting).
 * This only adjusts the UI state; the query behavior is enforced server-side above.
 */
add_action( 'wp_footer', function () {
    if ( ! is_user_logged_in() || cm_groups_is_directory_admin_user() ) {
        return;
    }

    if ( ! function_exists( 'bp_is_groups_directory' ) || ! bp_is_groups_directory() ) {
        return;
    }

    if ( ! function_exists( 'bp_get_groups_directory_permalink' ) ) {
        return;
    }

    $my_groups_url = trailingslashit( bp_get_groups_directory_permalink() ) . 'my-groups/';

    ?>
    <script>
    (function () {
        var subnav = document.getElementById('subnav');
        if (!subnav) return;

        // BuddyPress typically marks the active tab via LI classes (current/selected).
        var allLis = subnav.querySelectorAll('li');
        for (var i = 0; i < allLis.length; i++) {
            allLis[i].classList.remove('current');
            allLis[i].classList.remove('selected');
        }

        var myUrl = <?php echo wp_json_encode( esc_url_raw( $my_groups_url ) ); ?>;
        var myLink = subnav.querySelector('a[href="' + myUrl + '"]') || subnav.querySelector('a[href*="/my-groups/"]');
        if (myLink && myLink.parentElement) {
            myLink.parentElement.classList.add('current');
            myLink.parentElement.classList.add('selected');
        }
    })();
    </script>
    <?php
}, 99 );