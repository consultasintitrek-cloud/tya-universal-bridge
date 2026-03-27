<?php
/**
 * Plugin Name: TYA Ultimate Zero-Delay Bridge
 * Description: Instant execution via shutdown hook. No Cron delays. Hardened for all booking systems.
 * Version: 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define('TYA_MASTER_WEBHOOK', 'https://federally-unreproachable-love.ngrok-free.dev/webhook/Customer-Service');

function tya_master_dispatch($payload) {
    // ANTI-DUPLICATE SHIELD: Prevent sending the exact same booking twice
    static $sent_records = [];
    
    // Find the ID to track (checks different possible keys depending on the system)
    $tracker_id = $payload['id'] ?? $payload['record_id'] ?? ($payload['data']['id'] ?? null);
    
    if ($tracker_id) {
        if (in_array($tracker_id, $sent_records)) return; // Stop if already sent
        $sent_records[] = $tracker_id;
    }

    $payload['site_url'] = get_site_url();
    $payload['timestamp'] = current_time('mysql');
    
    wp_remote_post(TYA_MASTER_WEBHOOK, [
        'method'    => 'POST',
        'blocking'  => false, 
        'headers'   => ['Content-Type' => 'application/json'],
        'body'      => json_encode($payload),
    ]);
}

/** 1. THE ZERO-DELAY GLOBAL NET (Replaces WP Cron) **/
global $tya_pending_posts;
$tya_pending_posts = [];

// Step A: Catch the ID immediately
add_action('wp_insert_post', function($post_id, $post, $update) {
    if ($update || wp_is_post_revision($post_id)) return;

    $monitored_types = ['amelia_booking', 'hb_booking', 'shop_order', 'tour_master_order', 'booking', 'trip-booking'];

    if (in_array($post->post_type, $monitored_types)) {
        global $tya_pending_posts;
        $tya_pending_posts[$post_id] = $post->post_type;
    }
}, 10, 3);

// Step B: Fire the webhook at the very end of the server process. 
// No Cron delay, 100% instant, and guarantees all meta is saved.
add_action('shutdown', function() {
    global $tya_pending_posts;
    if (empty($tya_pending_posts)) return;

    foreach ($tya_pending_posts as $post_id => $post_type) {
        $raw_meta = get_post_meta($post_id);
        $clean_meta = [];
        foreach ($raw_meta as $key => $value) {
            $clean_meta[$key] = maybe_unserialize($value[0]);
        }
        tya_master_dispatch([
            'source'    => 'Global-Watcher',
            'type'      => $post_type,
            'record_id' => $post_id,
            'data'      => $clean_meta 
        ]);
    }
});

/** 2. VIKBOOKING (Deep SQL Sniper) **/
add_action('vikbooking_booking_conversion_tracking', function($d) {
    global $wpdb;
    $room_name = $wpdb->get_var($wpdb->prepare("
        SELECT r.name FROM {$wpdb->prefix}vikbooking_rooms r 
        INNER JOIN {$wpdb->prefix}vikbooking_orders o ON r.id = o.idItem 
        WHERE o.id = %d", $d['id'] ?? 0));
    $d['fetched_room_name'] = $room_name ?: 'Accommodation';
    tya_master_dispatch(['source' => 'VikBooking-Direct', 'data' => $d]);
});

/** 3. WP TRAVEL ENGINE (Instant Direct Hook) **/
add_action('wte_after_booking_data_save', function($id) {
    $meta = get_post_custom($id);
    tya_master_dispatch([
        'source' => 'WPTE-Direct',
        'id' => $id,
        'billing' => maybe_unserialize($meta['billing_info'][0] ?? ''),
        'cart' => maybe_unserialize($meta['cart_info'][0] ?? ''),
        'trip_date' => $meta['trip_datetime'][0] ?? 'N/A',
        'payable' => $meta['wp_travel_engine_payable_amount'][0] ?? 0
    ]);
}, 10, 1);

/** 4. LATEPOINT (Direct Bridge) **/
add_action('latepoint_booking_created', function($b) {
    tya_master_dispatch([
        'source' => 'LatePoint-Direct',
        'id' => $b->id,
        'service_name' => $b->service->name ?? 'Service',
        'customer_name' => $b->customer->full_name ?? 'Guest',
        'customer_phone' => $b->customer->phone ?? '',
        'total_price' => $b->price ?? 0,
        'start_date' => $b->start_date,
        'start_time' => $b->start_time
    ]);
});