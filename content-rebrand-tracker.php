<?php
/**
 * Plugin Name: Content Rebrand Tracker
 * Description: Scan site content for configurable terms across posts, meta, and options. Admin-only, with term editing, context tabs, term filter, pagination, CSV export.
 * Version:     2.3
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
    // Use build file modification time for cache busting
    $script_path = plugin_dir_path( __FILE__ ) . 'js/build/index.js';
    $script_version = file_exists( $script_path ) ? filemtime( $script_path ) : '1.0';
    wp_enqueue_script(
        'rebrand-tracker-admin-app',
        plugins_url( 'js/build/index.js', __FILE__ ),
        array( 'wp-element' ),
        $script_version,
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
                    $all[ $key ] = [
                        'context'  => 'post',
                        'ID'       => $post->ID,
                        'label'    => $post->post_title,
                        'term'     => $term,
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

// ── CSV Export ────────────────────────────────────────────────────────
function rebrand_tracker_export_csv( $filter_term, $context ) {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( __( 'Permission denied', 'rebrand-tracker' ) );
    }
    check_admin_referer( 'rebrand_export', 'rebrand_nonce' );

    $matches = rebrand_tracker_get_matches( $filter_term );
    if ( $context !== 'all' ) {
        $matches = array_filter( $matches, function( $m ) use ( $context ) {
            return $m['context'] === $context;
        } );
    }

    header( 'Content-Type: text/csv; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename=rebrand_matches.csv' );
    $out = fopen( 'php://output', 'w' );
    fputcsv( $out, [ 'Context', 'ID', 'Label', 'Term', 'Edit Link' ] );

    foreach ( $matches as $m ) {
        fputcsv( $out, [
            $m['context'],
            $m['ID'],
            $m['label'],
            $m['term'],
            $m['edit_url'],
        ] );
    }
    fclose( $out );
    exit;
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

// front-end highlight script
add_action( 'wp_footer', 'rebrand_tracker_highlight_term_script' );
function rebrand_tracker_highlight_term_script() {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var params = new URLSearchParams(window.location.search);
        var term = params.get('rebrand_term');
        if (term) {
            // escape term and enforce whole-word with JS word boundaries
            var termEscaped = term.replace(/[.*+?^${}()|\\[\\]\\\\]/g, '\\$&');
            var regex = new RegExp('\\b' + termEscaped + '\\b', 'gi');
            var walker = document.createTreeWalker(
                document.body,
                NodeFilter.SHOW_TEXT,
                { acceptNode: function(node) {
                    if (!node.nodeValue.trim()) return NodeFilter.FILTER_REJECT;
                    return regex.test(node.nodeValue) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_REJECT;
                }}
            );
            var nodes = [];
            var current;
            while (current = walker.nextNode()) {
                nodes.push(current);
            }
            nodes.forEach(function(textNode) {
                var span = document.createElement('span');
                span.innerHTML = textNode.nodeValue.replace(regex, function(m) {
                    return '<mark style="background:magenta;color:white;font-size:150%;padding:2px 4px;border:2px dashed orange;box-shadow:0 0 5px red;transform:rotate(-2deg);display:inline-block;text-transform:uppercase;">' + m + '</mark>';
                });
                textNode.parentNode.replaceChild(span, textNode);
            });
        }
    });
    </script>
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
