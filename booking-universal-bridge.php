<?php
/**
 * Plugin Name: TYA Universal - THE CORE
 * Version: 1.2.1
 * Description: Zero-Knowledge Collector + Relational Stitching & Rebel Hooks.
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
 * PART 1: THE UNIVERSAL WATCHER (WPTE, Amelia, etc.)
 */
add_action('wp_insert_post', function($post_id, $post, $update) {
    if ($update) return;
    $ignore = ['post', 'page', 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request'];

    if (!in_array($post->post_type, $ignore)) {
        wp_schedule_single_event(time() + 5, 'tya_core_capture_event', [$post_id, $post->post_type, $post->post_title]);
    }
}, 10, 3);

add_action('tya_core_capture_event', function($post_id, $type, $title) {
    $all_meta = get_post_meta($post_id);
    $clean_data = [];

    foreach ($all_meta as $key => $value) {
        $clean_data[$key] = maybe_unserialize($value[0]);
    }

    // --- WPTE STITCHER: If this is a payment, grab the parent booking tour info! ---
    if (isset($clean_data['booking_id'])) {
        $parent_id = $clean_data['booking_id'];
        $parent_meta = get_post_meta($parent_id);
        foreach ($parent_meta as $pkey => $pvalue) {
            // We prefix it so you know it came from the parent tour booking
            $clean_data['tour_details_' . $pkey] = maybe_unserialize($pvalue[0]);
        }
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
 * PART 2: THE REBEL HOOKS (LatePoint & VikBooking)
 */

// REBEL 1: VikBooking (With Room Name SQL Lookup)
add_action('vikbooking_booking_conversion_tracking', function($d) {
    global $wpdb;
    
    // Attempt to look up the room name from VikBooking's custom tables
    $room_name = 'Unknown Room';
    if (isset($d['id'])) {
        $order_table = $wpdb->prefix . 'vikbooking_orders';
        $room_table = $wpdb->prefix . 'vikbooking_rooms';
        
        // Find the room ID linked to this order, then get the room name
        $item_id = $wpdb->get_var($wpdb->prepare("SELECT idItem FROM {$order_table} WHERE id = %d", $d['id']));
        if ($item_id) {
            $found_room = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$room_table} WHERE id = %d", $item_id));
            if ($found_room) $room_name = $found_room;
        }
    }
    
    $d['fetched_room_name'] = $room_name; // Adds the room name to your webhook payload!

    tya_universal_sender([
        'source'      => 'VikBooking-Hook',
        'plugin_type' => 'vikbooking',
        'data'        => $d
    ]);
});

// REBEL 2: LatePoint (Full Data Capture)
add_action('latepoint_booking_created', function($booking) {
    if (!$booking) return;
    
    // We are manually telling the plugin: "Go grab these specific things"
    $data = [
        'booking_id'     => $booking->id,
        'service_name'   => isset($booking->service) ? $booking->service->name : 'Unknown Service',
        'customer_name'  => isset($booking->customer) ? $booking->customer->full_name : 'Unknown',
        'customer_email' => isset($booking->customer) ? $booking->customer->email : 'Unknown',
        'customer_phone' => isset($booking->customer) ? $booking->customer->phone : '', // THE MISSING LINK 1
        'total_price'    => isset($booking->price) ? $booking->price : 0,               // THE MISSING LINK 2
        'start_date'     => $booking->start_date,
        'start_time'     => $booking->start_time,
        'status'         => $booking->status
    ];

    tya_universal_sender([
        'source'      => 'LatePoint-Hook',
        'plugin_type' => 'latepoint',
        'data'        => $data
    ]);
});