<?php
/**
 * Plugin Name: TYA Universal Agency Bridge - Pro
 * Description: Aggressive Global Listener for WPTE, LatePoint, VikBooking, and WooCommerce.
 * Version: 1.1.5
 * Author: TYA Digital Automation
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// 1. CONFIGURATION
define('TYA_MASTER_WEBHOOK', 'https://federally-unreproachable-love.ngrok-free.dev/webhook/Customer-Service');

// 2. THE SENDER (The Truck)
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

// 3. THE GLOBAL WATCHER (Database-Level Monitoring)
add_action('wp_insert_post', function($post_id, $post, $update) {
    // Avoid double-sending if a post is simply updated
    if ($update) return;

    // The "Smart List" - We added 'booking' specifically for your WPTE site
    $booking_types = [
        'trip-booking',      // WP Travel Engine (Standard)
        'booking',           // WP Travel Engine (Your Specific Version)
        'lp_booking',        // LatePoint
        'vikbooking_order',  // VikBooking
        'shop_order'         // WooCommerce
    ];

    if (in_array($post->post_type, $booking_types)) {
        
        // UNPACK THE HARD STRINGS (Metadata)
        $raw_meta = get_post_meta($post_id);
        $clean_meta = [];

        foreach ($raw_meta as $key => $value) {
            // maybe_unserialize is the tool that decodes secret code into readable text
            $unpacked = maybe_unserialize($value[0]);
            $clean_meta[$key] = $unpacked;
        }

        tya_send_to_n8n([
            'source'    => 'Global-Watcher',
            'type'      => $post->post_type,
            'title'     => $post->post_title,
            'record_id' => $post_id,
            'data'      => $clean_meta 
        ]);
    }
}, 10, 3);

// 4. BACKUP HOOKS (For systems like VikBooking that use private tables)
add_action('wp_travel_engine_after_booking_success', function($id) { tya_send_to_n8n(['source' => 'WPTE-Hook', 'id' => $id]); });
add_action('latepoint_booking_created', function($b) { tya_send_to_n8n(['source' => 'LatePoint-Hook', 'id' => $b->id]); });
add_action('vikbooking_booking_conversion_tracking', function($d) { tya_send_to_n8n(['source' => 'VikBooking-Hook', 'data' => $d]); });
add_action('woocommerce_new_order', function($id) { tya_send_to_n8n(['source' => 'WooCommerce-Hook', 'id' => $id]); });

// Activation Heartbeat
register_activation_hook(__FILE__, function() {
    tya_send_to_n8n(['status' => 'Plugin Activated', 'version' => '1.1.5', 'test' => 'Success']);
});