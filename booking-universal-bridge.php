<?php
/**
 * Plugin Name: TYA Universal Agency Bridge - Pro
 * Description: Global Listener for WPTE, LatePoint, VikBooking, and WooCommerce.
 * Version: 1.1.4
 * Author: TYA Digital Automation
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// 1. CONFIGURATION
define('TYA_MASTER_WEBHOOK', 'https://federally-unreproachable-love.ngrok-free.dev/webhook/Customer-Service');

// 2. THE SENDER
function tya_send_to_n8n($payload) {
    $payload['site_url'] = get_site_url();
    $payload['timestamp'] = current_time('mysql');

    wp_remote_post(TYA_MASTER_WEBHOOK, [
        'method'    => 'POST',
        'blocking'  => false, 
        'headers'   => ['Content-Type' => 'application/json'],
        'body'      => json_encode($payload),
    ]);
}

// 3. THE GLOBAL WATCHER (Database Level Monitoring)
add_action('wp_insert_post', function($post_id, $post, $update) {
    // Only catch NEW entries to avoid loops
    if ($update) return;

    // The "Smart List" of booking types
    $booking_types = [
        'trip-booking',      // WP Travel Engine
        'lp_booking',        // LatePoint
        'vikbooking_order',   // VikBooking
        'shop_order'         // WooCommerce
    ];

    if (in_array($post->post_type, $booking_types)) {
        
        // UNPACK THE HIDDEN STRINGS (Metadata)
        $raw_meta = get_post_meta($post_id);
        $clean_meta = [];

        foreach ($raw_meta as $key => $value) {
            // maybe_unserialize decodes the "strings" into readable data
            $unpacked = maybe_unserialize($value[0]);
            $clean_meta[$key] = $unpacked;
        }

        tya_send_to_n8n([
            'source'    => 'Global-Watcher',
            'type'      => $post->post_type,
            'title'     => $post->post_title,
            'record_id' => $post_id,
            'data'      => $clean_meta // This is the fully decoded info for n8n
        ]);
    }
}, 10, 3);

// 4. BACKUP LISTENERS (Redundancy Layer)
add_action('wp_travel_engine_after_booking_success', function($id) { tya_send_to_n8n(['source' => 'WPTE-Hook', 'id' => $id]); });
add_action('latepoint_booking_created', function($b) { tya_send_to_n8n(['source' => 'LatePoint-Hook', 'id' => $b->id]); });
add_action('vikbooking_booking_conversion_tracking', function($d) { tya_send_to_n8n(['source' => 'VikBooking-Hook', 'data' => $d]); });
add_action('woocommerce_new_order', function($id) { tya_send_to_n8n(['source' => 'WooCommerce-Hook', 'id' => $id]); });

// Activation Heartbeat
register_activation_hook(__FILE__, function() {
    tya_send_to_n8n(['status' => 'Plugin Activated', 'version' => '1.1.4', 'test' => 'Success']);
});