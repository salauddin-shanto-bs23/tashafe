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
 * For guests, force a groups query that cannot match any real group.
 * Note: `include=0` is treated as “no include filter” by BuddyPress.
 */
function cm_groups_force_empty_query_args_for_guests( array $args ): array {
    // Use an extremely large, non-existent group ID.
    $args['include']  = array( 999999999 );
    $args['per_page'] = 1;
    $args['page']     = 1;

    return $args;
}

/**
 * Force groups directory queries (and its AJAX) to only show the logged-in user’s groups.
 */
add_filter( 'bp_ajax_querystring', function ( $querystring, $object ) {
    if ( 'groups' !== $object ) {
        return $querystring;
    }

    if ( function_exists( 'bp_is_groups_directory' ) && ! bp_is_groups_directory() ) {
        // Don’t touch group loops outside the Groups Directory screen.
        return $querystring;
    }

    // For logged-out (guest) users on /groups: show nothing.
    if ( ! is_user_logged_in() ) {
        $args = array();
        if ( is_string( $querystring ) && $querystring !== '' ) {
            parse_str( $querystring, $args );
        }

        // Force an empty loop.
        $args = cm_groups_force_empty_query_args_for_guests( $args );

        return http_build_query( $args );
    }

    if ( cm_groups_is_directory_admin_user() ) {
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
 * Enforce the same behavior for non-AJAX group loops that pass args directly.
 */
add_filter( 'bp_before_has_groups_parse_args', function ( $args ) {
    if ( ! is_array( $args ) ) {
        return $args;
    }

    if ( function_exists( 'bp_is_groups_directory' ) && ! bp_is_groups_directory() ) {
        return $args;
    }

    if ( ! is_user_logged_in() ) {
        return cm_groups_force_empty_query_args_for_guests( $args );
    }

    if ( cm_groups_is_directory_admin_user() ) {
        return $args;
    }

    $args['scope']       = 'personal';
    $args['user_id']     = get_current_user_id();
    $args['show_hidden'] = 1;

    return $args;
}, 20 );

/**
 * Guest users: display a clear message on the Groups Directory.
 */
add_action( 'bp_before_directory_groups_content', function () {
    if ( is_user_logged_in() ) {
        return;
    }

    if ( ! function_exists( 'bp_is_groups_directory' ) || ! bp_is_groups_directory() ) {
        return;
    }

    echo '<div class="bp-feedback info">'
        . '<span class="bp-icon" aria-hidden="true"></span>'
        . '<p>'
        . esc_html__( 'You are not enrolled in any group chat.', 'therapy-session-chat' )
        . '</p>'
        . '</div>';
}, 5 );

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

add_action( 'wp_head', function () {
    if ( ! function_exists( 'bp_is_groups_directory' ) || ! bp_is_groups_directory() ) {
        return;
    }

    if ( cm_groups_is_directory_admin_user() ) {
        return;
    }

    echo '<style>'
        . '#subnav li a .count, #subnav li a .item-count, .dir-search .count, .groups-nav .count {'
        . 'display:none!important;'
        . '}'
        . '</style>';
}, 20 );