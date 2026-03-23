<?php
/**
 * Plugin Name: TYA Universal - THE CORE
 * Version: 1.2.0
 * Description: Anti-proof Zero-Knowledge Collector. 5s Delay for Meta-Sync.
 * Author: TYA Digital Automation
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define('TYA_MASTER_WEBHOOK', 'https://federally-unreproachable-love.ngrok-free.dev/webhook/Customer-Service');

function tya_universal_sender($payload) {
    $payload['site_url'] = get_site_url();
    $payload['timestamp'] = current_time('mysql');
    wp_remote_post(TYA_MASTER_WEBHOOK, [
        'method'    => 'POST',
        'blocking'  => false, 
        'headers'   => ['Content-Type' => 'application/json'],
        'body'      => json_encode($payload),
    ]);
}

/**
 * PART 1: THE UNIVERSAL WATCHER
 * Watches for NEW entries in the database (WPTE, LatePoint, Amelia, etc.)
 */
add_action('wp_insert_post', function($post_id, $post, $update) {
    // Only trigger on NEW bookings, not updates
    if ($update) return;

    // List of standard WP types to ignore
    $ignore = ['post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request'];

    if (!in_array($post->post_type, $ignore)) {
        // CRITICAL 5-SECOND DELAY: Gives plugins time to write "Pending" MetaData
        wp_schedule_single_event(time() + 5, 'tya_core_capture_event', [$post_id, $post->post_type, $post->post_title]);
    }
}, 10, 3);

/**
 * THE DATA PACKER
 * Runs 5 seconds after the booking is created
 */
add_action('tya_core_capture_event', function($post_id, $type, $title) {
    $all_meta = get_post_meta($post_id);
    $clean_data = [];

    foreach ($all_meta as $key => $value) {
        // Decodes WPTE/LatePoint "Hard Strings" automatically
        $clean_data[$key] = maybe_unserialize($value[0]);
    }

    tya_universal_sender([
        'source'      => 'TYA-Core-Watcher',
        'plugin_type' => $type, 
        'record_id'   => $post_id,
        'title'       => $title,
        'data'        => $clean_data
    ]);
}, 10, 3);

/**
 * PART 2: THE REBEL HOOKS
 * For plugins that don't use standard Post tables
 */
add_action('vikbooking_booking_conversion_tracking', function($d) {
    tya_universal_sender([
        'source'      => 'VikBooking-Hook',
        'plugin_type' => 'vikbooking',
        'data'        => $d
    ]);
});

// Activation Check
register_activation_hook(__FILE__, function() {
    tya_universal_sender(['status' => 'Core Activated', 'version' => '1.2.0']);
});