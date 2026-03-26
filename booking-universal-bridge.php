<?php
/*
Plugin Name: TYA Deep-Sight Universal Watcher
Version: 1.4.0
Description: High-speed, deep-data bridge for WPTE, Vik, LatePoint, Amelia, and more.
*/

if (!defined('ABSPATH')) exit;

define('TYA_MASTER_WEBHOOK', 'https://federally-unreproachable-love.ngrok-free.dev/webhook/Customer-Service');

function tya_dispatch($source, $data) {
    wp_safe_remote_post(TYA_MASTER_WEBHOOK, [
        'method'    => 'POST',
        'headers'   => ['Content-Type' => 'application/json'],
        'body'      => json_encode([
            'source'    => $source,
            'site_url'  => get_site_url(),
            'timestamp' => current_time('mysql'),
            'data'      => $data
        ]),
        'timeout'   => 10,
        'blocking'  => false,
    ]);
}

/** 1. VIKBOOKING (Hotel) - Deep SQL Capture **/
add_action('vikbooking_booking_conversion_tracking', function($d) {
    global $wpdb;
    if (isset($d['id'])) {
        $room_name = $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}vikbooking_rooms r JOIN {$wpdb->prefix}vikbooking_orders o ON r.id = o.idItem WHERE o.id = %d", $d['id']));
        $d['fetched_room_name'] = $room_name ?: 'Accommodation';
    }
    tya_dispatch('VikBooking-Direct', $d);
});

/** 2. LATEPOINT (Spa) - Full Appointment Capture **/
add_action('latepoint_booking_created', function($booking) {
    tya_dispatch('LatePoint-Direct', [
        'booking_id'     => $booking->id,
        'service_name'   => $booking->service->name ?? 'Spa Treatment',
        'customer_name'  => $booking->customer->full_name ?? 'Guest',
        'customer_phone' => $booking->customer->phone ?? '',
        'total_price'    => $booking->price ?? 0,
        'start_date'     => $booking->start_date,
        'start_time'     => $booking->start_time
    ]);
});

/** 3. WP TRAVEL ENGINE (Tours) - High Priority **/
add_action('wp_travel_engine_after_booking_success', 'tya_wpte_deep_capture', 5, 1);
function tya_wpte_deep_capture($booking_id) {
    if (!$booking_id) return;
    $meta = get_post_custom($booking_id);
    // Unserialize specifically for n8n
    $billing = maybe_unserialize($meta['billing_info'][0] ?? '');
    $cart = maybe_unserialize($meta['cart_info'][0] ?? '');
    
    tya_dispatch('WPTE-Direct', [
        'booking_id'   => $booking_id,
        'billing'      => $billing,
        'cart'         => $cart,
        'trip_date'    => $meta['trip_datetime'][0] ?? 'N/A',
        'total'        => $meta['due_amount'][0] ?? $meta['wp_travel_engine_payable_amount'][0] ?? 0
    ]);
}

/** 4. THE SMART WATCHER (Amelia, Hotel Booking Plugin, etc.) **/
add_action('wp_insert_post', function($post_id, $post, $update) {
    if ($update || wp_is_post_revision($post_id)) return;
    
    $monitored = ['amelia_booking', 'hb_booking', 'shop_order', 'tour_master_order'];
    if (in_array($post->post_type, $monitored)) {
        // Direct call to avoid Cron delay, using a tiny 1-second buffer
        wp_schedule_single_event(time(), 'tya_watcher_event', [$post_id, $post->post_type]);
    }
}, 10, 3);

add_action('tya_watcher_event', function($id, $type) {
    tya_dispatch('Global-Watcher', [
        'record_id' => $id,
        'post_type' => $type,
        'all_meta'  => get_post_custom($id)
    ]);
});