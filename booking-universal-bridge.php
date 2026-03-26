<?php
/*
Plugin Name: TYA Universal Master Watcher
Version: 1.2.6
Author: TYA Developer
Description: Full integration for WPTE, VikBooking, LatePoint, and WooCommerce with deduplication.
*/

if (!defined('ABSPATH')) exit;

// 1. THE BRAIN: MASTER WEBHOOK CONFIG
// Fixed the missing closing quote below
define('TYA_MASTER_WEBHOOK', 'https://federally-unreproachable-love.ngrok-free.dev/webhook/Customer-Service');

// 2. THE HEART: THE SENDER ENGINE
function tya_universal_sender($payload) {
    $payload['site_url'] = get_site_url();
    $payload['timestamp'] = current_time('mysql');
    
    wp_safe_remote_post(TYA_MASTER_WEBHOOK, [
        'method'    => 'POST',
        'headers'   => ['Content-Type' => 'application/json'],
        'body'      => json_encode($payload),
        'timeout'   => 20,
        'blocking'  => false,
    ]);
}

/** * 3. THE UNIVERSAL WATCHER (WPTE & WooCommerce) 
 * Handles WP Travel Engine and standard shop orders.
 **/
add_action('wp_insert_post', function($post_id, $post, $update) {
    // LAYER 1: Stop duplicates on updates or revisions
    if ($update || wp_is_post_revision($post_id)) return;

    $target_types = ['wte_booking', 'shop_order']; // WPTE and WooCommerce
    
    if (in_array($post->post_type, $target_types)) {
        // LAYER 2: 5-second delay to ensure metadata is saved to DB
        wp_schedule_single_event(time() + 5, 'tya_delayed_capture_event', [$post_id, $post->post_type]);
    }
}, 10, 3);

add_action('tya_delayed_capture_event', function($post_id, $post_type) {
    $data = ['record_id' => $post_id, 'post_type' => $post_type];

    // MAPPING: WP Travel Engine Specifics
    if ($post_type === 'wte_booking') {
        $data['tour_details_cart_info'] = get_post_meta($post_id, 'wp_travel_engine_cart_info', true);
        $data['billing_info'] = get_post_meta($post_id, 'wp_travel_engine_billing_info', true);
        $data['payable'] = ['amount' => get_post_meta($post_id, 'wp_travel_engine_payable_amount', true)];
    }
    
    // MAPPING: WooCommerce Specifics
    if ($post_type === 'shop_order') {
        $order = function_exists('wc_get_order') ? wc_get_order($post_id) : null;
        if ($order) {
            $data['total'] = $order->get_total();
            $data['currency'] = $order->get_currency();
            $data['billing_info'] = $order->get_address('billing');
        }
    }

    tya_universal_sender(['source' => 'TYA-Core-Watcher', 'data' => $data]);
}, 10, 2);

/** * 4. THE VIKBOOKING HOOK (Hotel)
 **/
add_action('vikbooking_booking_conversion_tracking', function($d) {
    global $wpdb;
    if (isset($d['id'])) {
        // Direct SQL lookup for the Room Name
        $room_id = $wpdb->get_var($wpdb->prepare("SELECT idItem FROM {$wpdb->prefix}vikbooking_orders WHERE id = %d", $d['id']));
        $d['fetched_room_name'] = $room_id ? $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}vikbooking_rooms WHERE id = %d", $room_id)) : 'Couple Room';
    }
    tya_universal_sender(['source' => 'VikBooking-Hook', 'data' => $d]);
});

/** * 5. THE LATEPOINT HOOK (Spa/Appointments)
 **/
add_action('latepoint_booking_created', function($booking) {
    if (!$booking) return;
    tya_universal_sender([
        'source' => 'LatePoint-Hook',
        'data' => [
            'booking_id'     => $booking->id,
            'service_name'   => $booking->service->name ?? 'Spa Service',
            'customer_name'  => $booking->customer->full_name ?? 'Guest',
            'customer_email' => $booking->customer->email ?? '',
            'customer_phone' => $booking->customer->phone ?? '',
            'total_price'    => $booking->price ?? 0,
            'start_date'     => $booking->start_date,
            'start_time'     => $booking->start_time, // Standard 480 format
            'status'         => $booking->status
        ]
    ]);
});