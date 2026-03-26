<?php
/*
Plugin Name: TYA Ironclad Universal Watcher
Version: 1.3.0
Author: TYA Developer
Description: Scalable Lead-to-Loyalty bridge for WPTE, VikBooking, LatePoint, WooCommerce, and all Custom Post Types.
*/

if (!defined('ABSPATH')) exit;

define('TYA_MASTER_WEBHOOK', 'https://federally-unreproachable-love.ngrok-free.dev/webhook/Customer-Service');

/**
 * ENGINE 1: THE DISPATCHER
 * Sends data to n8n with a small safety delay to avoid database race conditions.
 */
function tya_ironclad_sender($source, $data) {
    $payload = [
        'source'    => $source,
        'site_url'  => get_site_url(),
        'timestamp' => current_time('mysql'),
        'data'      => $data
    ];
    
    wp_safe_remote_post(TYA_MASTER_WEBHOOK, [
        'method'    => 'POST',
        'headers'   => ['Content-Type' => 'application/json'],
        'body'      => json_encode($payload),
        'timeout'   => 20,
        'blocking'  => false,
    ]);
}

/**
 * ENGINE 2: WP TRAVEL ENGINE (WPTE) - THE MASTER HOOK
 * Using the high-priority save hook to ensure we get the full "Booking Data" object.
 */
add_action('wte_after_booking_data_save', function($booking_id) {
    if (!$booking_id) return;
    tya_ironclad_sender('WPTE-Direct', [
        'booking_id'   => $booking_id,
        'master_info'  => get_post_meta($booking_id, 'wp_travel_engine_booking_data', true),
        'cart_info'    => get_post_meta($booking_id, 'wp_travel_engine_cart_info', true),
        'billing_info' => get_post_meta($booking_id, 'wp_travel_engine_billing_info', true),
        'payable'      => get_post_meta($booking_id, 'wp_travel_engine_payable_amount', true)
    ]);
}, 20, 1);

/**
 * ENGINE 3: VIKBOOKING - CONVERSION HOOK
 */
add_action('vikbooking_booking_conversion_tracking', function($d) {
    global $wpdb;
    if (isset($d['id'])) {
        // Direct SQL query to fetch Room Name since Vik hooks sometimes miss it
        $room_id = $wpdb->get_var($wpdb->prepare("SELECT idItem FROM {$wpdb->prefix}vikbooking_orders WHERE id = %d", $d['id']));
        $d['fetched_room_name'] = $room_id ? $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}vikbooking_rooms WHERE id = %d", $room_id)) : 'Accommodation';
    }
    tya_ironclad_sender('VikBooking-Direct', $d);
});

/**
 * ENGINE 4: LATEPOINT - APPOINTMENT HOOK
 */
add_action('latepoint_booking_created', function($booking) {
    if (!$booking) return;
    tya_ironclad_sender('LatePoint-Direct', [
        'booking_id'     => $booking->id,
        'service_name'   => $booking->service->name ?? 'Service',
        'customer_name'  => $booking->customer->full_name ?? 'Guest',
        'customer_phone' => $booking->customer->phone ?? '',
        'total_price'    => $booking->price ?? 0,
        'start_date'     => $booking->start_date,
        'start_time'     => $booking->start_time
    ]);
});

/**
 * ENGINE 5: THE GLOBAL WATCHER (WooCommerce, Tour Master, Amelia, etc.)
 * Monitors for NEW posts and waits 5 seconds for metadata to populate.
 */
add_action('wp_insert_post', function($post_id, $post, $update) {
    // LAYER 1: Stop duplicates on updates
    if ($update || wp_is_post_revision($post_id)) return;

    $monitored_types = ['shop_order', 'tour_master_order', 'amelia_booking', 'booking'];
    
    if (in_array($post->post_type, $monitored_types)) {
        wp_schedule_single_event(time() + 5, 'tya_ironclad_delayed_catchall', [$post_id, $post->post_type]);
    }
}, 10, 3);

add_action('tya_ironclad_delayed_catchall', function($post_id, $post_type) {
    $data = ['record_id' => $post_id, 'post_type' => $post_type];
    
    // Add WooCommerce data if applicable
    if ($post_type === 'shop_order' && function_exists('wc_get_order')) {
        $order = wc_get_order($post_id);
        if ($order) {
            $data['total'] = $order->get_total();
            $data['billing'] = $order->get_address('billing');
        }
    }
    
    // Generic Metadata Capture for any other plugin
    $data['all_meta'] = get_post_custom($post_id);

    tya_ironclad_sender('Global-Watcher', $data);
}, 10, 2);