<?php
/**
 * Plugin Name: Content Rebrand Tracker
 * Description: Scan site content for configurable terms across posts, meta, and options. Admin-only, with term editing, context tabs, term filter, pagination.
 * Version:     2.4
 * Author:      Brian (via ChatGPT)
 * Text Domain: rebrand-tracker
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Admin Menu ────────────────────────────────────────────────────────
add_action( 'admin_menu', 'rebrand_tracker_add_admin_menu' );
function rebrand_tracker_add_admin_menu() {
    // Removed deletion of search terms and matches to allow persistent results across requests
    add_menu_page(
        __( 'Rebrand Tracker', 'rebrand-tracker' ),
        __( 'Rebrand Tracker', 'rebrand-tracker' ),
        'manage_options',
        'rebrand-tracker',
        'rebrand_tracker_page',
        'dashicons-search',
        100
    );
}

// Enqueue React App
function rebrand_tracker_admin_enqueue() {
    $script_asset_path = plugin_dir_path( __FILE__ ) . 'js/build/index.asset.php';
    
    if ( ! file_exists( $script_asset_path ) ) {
        // Fallback if the asset file doesn't exist
        $script_asset = array(
            'dependencies' => array( 'wp-element' ),
            'version'      => filemtime( plugin_dir_path( __FILE__ ) . 'js/build/index.js' ) ?: '1.0'
        );
    } else {
        $script_asset = require( $script_asset_path );
    }

    wp_enqueue_script(
        'rebrand-tracker-admin-app',
        plugins_url( 'js/build/index.js', __FILE__ ),
        $script_asset['dependencies'],
        $script_asset['version'],
        true
    );
    wp_localize_script( 'rebrand-tracker-admin-app', 'RebrandTrackerData', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('rebrand_tracker_nonce'),
    ) );
}
add_action( 'admin_enqueue_scripts', 'rebrand_tracker_admin_enqueue' );

// AJAX Endpoint for React App Data
add_action('wp_ajax_rebrand_tracker_get_data', 'rebrand_tracker_get_data');
function rebrand_tracker_get_data() {
    if ( ! check_ajax_referer( 'rebrand_tracker_nonce', 'nonce', false ) ) {
        wp_send_json_error('Invalid nonce');
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error('Permission denied');
    }
    $terms = rebrand_tracker_get_terms();
    $matches = array_values(rebrand_tracker_get_matches());
    wp_send_json_success(array(
        'terms'   => $terms,
        'matches' => $matches,
    ));
}

// ── Terms Storage ─────────────────────────────────────────────────────
function rebrand_tracker_get_terms() {
    $terms = get_option( 'rebrand_tracker_terms', [] );
    if ( ! is_array( $terms ) ) {
        $terms = [];
    }
    return $terms;
}

function rebrand_tracker_set_terms( $terms ) {
    $clean = array_filter( array_map( 'sanitize_text_field', $terms ) );
    update_option( 'rebrand_tracker_terms', $clean );
    delete_transient( 'rebrand_tracker_matches' );
}

// AJAX handler to update search terms
add_action('wp_ajax_rebrand_tracker_set_terms', 'rebrand_tracker_set_terms_ajax');
function rebrand_tracker_set_terms_ajax() {
    if ( ! check_ajax_referer( 'rebrand_tracker_nonce', 'nonce', false ) ) {
        wp_send_json_error( 'Invalid nonce' );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    $terms = isset( $_POST['terms'] ) ? json_decode( wp_unslash( $_POST['terms'] ), true ) : [];
    rebrand_tracker_set_terms( $terms );
    wp_send_json_success();
}

// ── Match Scanning with Caching ──────────────────────────────────────
function rebrand_tracker_get_matches( $filter_term = null ) {
    // Always recalc matches (disable caching temporarily to fix matching issues)
    delete_transient( 'rebrand_tracker_matches' );
    $all = false;
    if ( $all === false ) {
        global $wpdb;
        $terms = rebrand_tracker_get_terms();
        $all   = [];
        foreach ( $terms as $term ) {
            $pattern = '/\\b'.preg_quote($term,'/').'\\b/i';

            // Posts & Pages
            $posts = get_posts([
                'post_type'   => ['post','page'],
                'post_status' => 'publish',
                'numberposts' => -1
            ]);
            foreach ( $posts as $post ) {
                if ( preg_match( $pattern, $post->post_content ) ) {
                    $key = "post-{$post->ID}-{$term}";
                    $instance_count = substr_count(strtolower($post->post_content), strtolower($term)); // Count instances of the term in the content
                    $all[ $key ] = [
                        'context'  => 'post',
                        'ID'       => $post->ID,
                        'label'    => $post->post_title,
                        'author'   => get_the_author_meta( 'display_name', $post->post_author ),
                        'term'     => $term,
                        'instances' => $instance_count, // Add the instance count
                        'edit_url' => admin_url( "post.php?post={$post->ID}&action=edit" ),
                        'view_url' => get_permalink( $post->ID ),
                    ];
                }
            }

            // Postmeta
            $meta_rows = $wpdb->get_results( "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta}" );
            foreach ( $meta_rows as $r ) {
                if ( preg_match( $pattern, $r->meta_value ) ) {
                    $key = "meta-{$r->meta_id}-{$term}";
                    $all[ $key ] = [
                        'context'  => 'meta',
                        'post_id'  => $r->post_id,
                        'meta_key' => $r->meta_key,
                        'ID'       => $r->meta_id,
                        'label'    => "Post {$r->post_id} — {$r->meta_key}",
                        'term'     => $term,
                        'edit_url' => admin_url( "post.php?post={$r->post_id}&action=edit" ),
                        'view_url' => get_permalink( $r->post_id ),
                    ];
                }
            }

            // Options
            $opt_rows = $wpdb->get_results( "SELECT option_id, option_name, option_value FROM {$wpdb->options}" );
            foreach ( $opt_rows as $r ) {
                if ( preg_match( $pattern, $r->option_value ) ) {
                    $key = "option-{$r->option_id}-{$term}";
                    $all[ $key ] = [
                        'context'     => 'option',
                        'option_name' => $r->option_name,
                        'ID'          => $r->option_id,
                        'label'       => "Option {$r->option_name}",
                        'term'        => $term,
                        'edit_url'    => admin_url( 'options-general.php' ),
                        'view_url'    => admin_url( 'options-general.php' ),
                    ];
                }
            }

        }
        set_transient( 'rebrand_tracker_matches', $all, HOUR_IN_SECONDS );
    }
    return $all;
}

// Updated Admin Page with React Container
function rebrand_tracker_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Permission denied', 'rebrand-tracker' ) );
    }
    ?>
    <style>
        /* Container styling */
        #rebrand-tracker-app {
            max-width: 1200px;
            margin: 25px auto;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
        /* Header */
        #rebrand-tracker-app h1 {
            font-size: 24px;
            margin-bottom: 20px;
            color: #333;
        }
        /* Filter bar */
        .filter-bar {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 20px;
        }
        .filter-bar label {
            font-weight: bold;
        }
        .filter-bar select,
        .filter-bar button {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #f9f9f9;
        }
        .filter-bar button {
            background: #0073aa;
            color: #fff;
            border-color: #0073aa;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .filter-bar button:hover {
            background: #005177;
        }
        /* Search & Replace Bar */
        .search-replace {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-bottom: 20px;
        }
        .search-replace input {
            flex: 1;
            min-width: 150px;
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #fff;
        }
        .search-replace button {
            padding: 8px 12px;
            border: 1px solid #0073aa;
            border-radius: 4px;
            background: #0073aa;
            color: #fff;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .search-replace button:hover {
            background: #005177;
        }
        /* Data table */
        .table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        .table th,
        .table td {
            padding: 12px;
            border-bottom: 1px solid #ddd;
            text-align: left;
        }
        .table th {
            background: #f1f1f1;
        }
        /* Zebra striping and row hover */
        .table tr:nth-child(even) { background: #f9f9f9; }
        .table tr:hover { background: #f1f1f1; }
        /* Edit link styling */
        .table a { color: #0073aa; text-decoration: none; }
        .table a:hover { text-decoration: underline; }
        /* Pagination */
        .pagination {
            display: flex;
            flex-wrap: nowrap;
            overflow-x: auto;
            gap: 5px;
            justify-content: flex-start;
            margin-bottom: 20px;
            padding-bottom: 10px;
            -webkit-overflow-scrolling: touch;
        }
        .pagination::-webkit-scrollbar {
            height: 6px;
        }
        .pagination::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 3px;
        }
        .pagination::-webkit-scrollbar-thumb {
            background: #ccc;
            border-radius: 3px;
        }
        .pagination::-webkit-scrollbar-thumb:hover {
            background: #aaa;
        }
        .pagination button {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #f9f9f9;
            cursor: pointer;
        }
        .pagination button[disabled] {
            background: #0073aa;
            color: #fff;
            border-color: #0073aa;
            cursor: default;
        }
        .pagination button:not([disabled]):hover {
            background: #e9e9e9;
        }
    </style>
    <div id="rebrand-tracker-app"></div>
    <?php
        // Display dynamic version info: plugin and JS build timestamps
        $php_time   = filemtime( __FILE__ );
        $js_path    = plugin_dir_path( __FILE__ ) . 'js/build/index.js';
        $js_time    = file_exists( $js_path ) ? filemtime( $js_path ) : 0;
        $php_date   = date_i18n( 'Y-m-d H:i:s', $php_time );
        $js_date    = $js_time ? date_i18n( 'Y-m-d H:i:s', $js_time ) : __( 'N/A', 'rebrand-tracker' );
        echo '<p style="text-align:center;color:#888;font-size:12px;margin-top:10px;">Plugin: ' . esc_html( $php_date ) . ' | JS Build: ' . esc_html( $js_date ) . '</p>';
    ?>
    <?php
}

// AJAX handler to replace a single match
add_action('wp_ajax_rebrand_tracker_replace_item', 'rebrand_tracker_replace_item_ajax');
function rebrand_tracker_replace_item_ajax() {
    if ( ! check_ajax_referer( 'rebrand_tracker_nonce', 'nonce', false ) ) {
        wp_send_json_error( 'Invalid nonce' );
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Permission denied' );
    }
    $context = isset($_POST['context']) ? sanitize_text_field($_POST['context']) : '';
    $id      = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $term    = isset($_POST['term']) ? sanitize_text_field($_POST['term']) : '';
    $replace = isset($_POST['replace']) ? sanitize_text_field($_POST['replace']) : '';

    global $wpdb;
    switch ( $context ) {
        case 'post':
            $post = get_post( $id );
            if ( ! $post ) wp_send_json_error( 'Post not found' );
            $content = $post->post_content;
            $new     = str_ireplace( $term, $replace, $content );
            if ( $new === $content ) wp_send_json_error( 'Term not found' );
            // explicitly save a revision of the original post
            if ( function_exists( 'wp_save_post_revision' ) ) {
                wp_save_post_revision( $id );
            }
            // now update with replaced content
            wp_update_post([ 'ID' => $id, 'post_content' => $new ]);
            break;
        case 'meta':
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_id = %d", $id
            ), ARRAY_A );
            if ( ! $row ) wp_send_json_error( 'Meta not found' );
            $new = str_ireplace( $term, $replace, $row['meta_value'] );
            if ( $new === $row['meta_value'] ) wp_send_json_error( 'Term not found in meta' );
            update_post_meta( $row['post_id'], $row['meta_key'], $new );
            break;
        case 'option':
            $value = get_option( $r['option_name'] ?? '' );
            if ( $value === false ) wp_send_json_error( 'Option not found' );
            $new = str_ireplace( $term, $replace, $value );
            if ( $new === $value ) wp_send_json_error( 'Term not found in option' );
            update_option( $r['option_name'], $new );
            break;
        default:
            wp_send_json_error( 'Invalid context' );
    }
    // clear cache
    delete_transient( 'rebrand_tracker_matches' );
    wp_send_json_success();
}

// Front-end admin-bar dropdown for Rebrand controls
add_action('admin_bar_menu','rebrand_tracker_admin_bar_menu',100);
function rebrand_tracker_admin_bar_menu($wp_admin_bar){
    if(!is_user_logged_in() || !is_admin_bar_showing() || !isset($_GET['rebrand_term'])) return;
    $term = sanitize_text_field(wp_unslash($_GET['rebrand_term']));
    // read stored replace term from cookie
    $stored = isset($_COOKIE['rebrand_replaceTerm']) ? sanitize_text_field($_COOKIE['rebrand_replaceTerm']) : '';
    // Default toggle label; JS will update to Enable/Disable
    $toggle_label = 'Disable Highlight';
    // Parent menu
    $wp_admin_bar->add_node([ 'id'=>'rebrand', 'title'=>'Rebrand', 'href'=>false, 'meta'=>['class'=>'menupop'] ]);
    // Search term display
    $wp_admin_bar->add_node([ 'id'=>'rebrand_search', 'parent'=>'rebrand', 'title'=>'Search: ' . esc_html($term), 'href'=>false ]);
    // Toggle highlight button
    $wp_admin_bar->add_node([ 'id'=>'rebrand_toggle', 'parent'=>'rebrand', 'title'=>$toggle_label, 'href'=>'#' ]);
}
// Inject CSS to hide highlights when disabled
// add_action('wp_head','rebrand_tracker_highlight_toggle_css');
// function rebrand_tracker_highlight_toggle_css(){
//     if(!is_user_logged_in()||!is_admin_bar_showing()||!isset($_GET['rebrand_term'])) return;
//     echo "<style>
//         /* Visible highlight styling */
//         .rebrand-highlight { background: magenta; color: white; padding: 2px 4px; border: 2px dashed orange; box-shadow: 0 0 5px red; text-transform: uppercase; }
//         /* Hide marks when toggled off */
//         .rebrand-highlight-off mark { display: none !important; }
//     </style>";
// }
// Combined front-end highlight injection (CSS + JS)
add_action('wp_footer','rebrand_tracker_frontend_highlight',100);
function rebrand_tracker_frontend_highlight(){
    if ( ! isset($_GET['rebrand_term']) ) return;
    $term = sanitize_text_field(wp_unslash($_GET['rebrand_term']));
    ?>
    <style>
        /* Highlight styling */
        .rebrand-highlight { background: magenta; color: white; padding: 2px 4px; border: 2px dashed orange; text-transform: uppercase; }
        /* Disable highlight styling when toggled off, but keep text */
        .rebrand-highlight-off .rebrand-highlight {
            background: none !important;
            color: inherit !important;
            border: none !important;
            box-shadow: none !important;
            padding: 0 !important;
            text-transform: none !important;
        }
    </style>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var term = '<?php echo esc_js($term); ?>';
        var pattern = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        var regex = new RegExp('\\b' + pattern + '\\b', 'gi');
        var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
            acceptNode: node => regex.test(node.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT
        });
        var nodes = [], n;
        while (n = walker.nextNode()) nodes.push(n);
        nodes.forEach(function(textNode) {
            var span = document.createElement('span');
            span.innerHTML = textNode.nodeValue.replace(regex, m => '<mark class="rebrand-highlight">'+m+'</mark>');
            textNode.parentNode.replaceChild(span, textNode);
        });
    });
    </script>
    <?php
}

// Admin-bar submenu styling
add_action('admin_head','rebrand_tracker_admin_bar_style');
function rebrand_tracker_admin_bar_style(){
    echo '<style>
        /* Dark submenu background and white text */
        #wpadminbar .ab-submenu { background: #23282d !important; }
        #wpadminbar .ab-submenu .ab-item { color: #fff !important; padding: 4px 8px; }
        /* Submenu button styling */
        #wpadminbar .ab-submenu .button { display:block; width:100%; margin:4px 0; background:#0073aa; color:#fff; text-align:center; padding:6px; text-decoration:none; }
        #wpadminbar .ab-submenu .button:hover { background:#005177; }
    </style>';
}

// Footer script for Replace and Toggle actions
add_action('wp_footer','rebrand_tracker_admin_bar_js',100);
function rebrand_tracker_admin_bar_js(){
    if(!is_user_logged_in() || !is_admin_bar_showing() || !isset($_GET['rebrand_term'])) return;
    $term     = sanitize_text_field(wp_unslash($_GET['rebrand_term']));
    $ajax_url = admin_url('admin-ajax.php');
    $nonce    = wp_create_nonce('rebrand_tracker_nonce');
    ?>
    <script>
    document.addEventListener('DOMContentLoaded',function(){
        var term = '<?php echo esc_js($term);?>';
        // Toggle highlight by adding/removing CSS class
        var toggle = document.querySelector('#wp-admin-bar-rebrand_toggle > a');
        if(toggle){
            toggle.addEventListener('click', function(e){
                e.preventDefault();
                var html = document.documentElement;
                if(html.classList.contains('rebrand-highlight-off')){
                    html.classList.remove('rebrand-highlight-off');
                    toggle.textContent = 'Disable Highlight';
                } else {
                    html.classList.add('rebrand-highlight-off');
                    toggle.textContent = 'Enable Highlight';
                }
            });
        }
     });
     </script>
     <?php
}

// Clear plugin-specific data from options table
add_action('wp_ajax_clear_plugin_memory', 'clear_plugin_memory');
function clear_plugin_memory() {
    global $wpdb;

    // Check for nonce
    if (!check_ajax_referer('rebrand_tracker_nonce', 'nonce', false)) {
        wp_send_json_error('Invalid security token.');
        return;
    }

    // Delete plugin-specific data from wp_options
    delete_option('rebrand_tracker_terms');
    delete_transient('rebrand_tracker_matches');
    
    // Delete any other plugin-related options
    $wpdb->query(
        $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            'rebrand_tracker_%',
            'content_rebrand_tracker_%'
        )
    );

    wp_send_json_success('Memory cleared successfully.');
}
