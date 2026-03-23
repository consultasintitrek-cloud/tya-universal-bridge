<?php
/**
 * Plugin Name: TYA Universal Agency Bridge
 * Description: Master connector for WPTE, LatePoint, VikBooking, and more.
 * Version: 1.1.1
 * Author: TYA Digital Automation
 */

// 1. Include the Update Checker Library
require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// 2. Point it to your GitHub Repo
$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/consultasintitrek-cloud/tya-universal-bridge/', 
    __FILE__, 
    'tya-universal-bridge' 
);

// 3. Set the branch
$myUpdateChecker->setBranch('main');

if ( ! defined( 'ABSPATH' ) ) exit;

// 1. CONFIGURATION: Your n8n Production Webhook URL
define('TYA_MASTER_WEBHOOK', 'https://federally-unreproachable-love.ngrok-free.dev/webhook/Customer-Service');

// 2. THE SENDER (Now including Site URL for your Group Architecture)
function tya_send_to_n8n($payload) {
    $payload['site_url'] = get_site_url(); // <--- THIS SOLVES THE GROUP ISSUE

    wp_remote_post(TYA_MASTER_WEBHOOK, [
        'method'    => 'POST',
        'blocking'  => false, 
        'headers'   => ['Content-Type' => 'application/json'],
        'body'      => json_encode($payload),
    ]);
}

// --- LISTENERS ---

// WP Travel Engine
add_action('wp_travel_engine_after_booking_success', function($booking_id) {
    $booking = new WP_Travel_Engine_Booking($booking_id);
    $data = $booking->get_booking_data();
    tya_send_to_n8n([
        'source' => 'WPTE',
        'full_name' => ($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''),
        'email' => $data['email'] ?? '',
        'total' => $data['total_price'] ?? 0,
        'record_id' => $booking_id
    ]);
});

// LatePoint
add_action('latepoint_booking_created', function($booking) {
    tya_send_to_n8n([
        'source' => 'LatePoint',
        'full_name' => $booking->customer->full_name,
        'email' => $booking->customer->email,
        'total' => $booking->price,
        'service' => $booking->service->name,
        'record_id' => $booking->id
    ]);
});

// VikBooking
add_action('vikbooking_after_save_order', function($order) {
    tya_send_to_n8n([
        'source' => 'VikBooking',
        'full_name' => $order->cust_name,
        'email' => $order->cust_mail,
        'total' => $order->total_price,
        'record_id' => $order->id
    ]);
});