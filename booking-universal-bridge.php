<?php
/**
 * Plugin Name: TYA Universal Slinger (Enterprise SaaS Edition)
 * Description: Master Data Bus with HMAC Security, 5-Strike Retry, and Client License Routing.
 * Version: 3.1.0
 * Author: TYA Enterprise
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class TYA_Universal_Slinger {
    private static $instance = null;
    
    // --- MASTER SETTINGS ---
    private $api_key      = 'JohnTech_security_050888'; // Your Master Password for n8n
    private $endpoint     = 'https://federally-unreproachable-love.ngrok-free.dev/webhook/Customer-Service';
    private $admin_email  = 'consultasintitrek@gmail.com'; // Where 5-strike failures go
    private $max_retries  = 5;
    
    private $table_name;

    public static function get_instance() {
        if (self::$instance == null) self::$instance = new TYA_Universal_Slinger();
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'tya_slinger_queue';

        register_activation_hook(__FILE__, [$this, 'install_system']);
        add_filter('cron_schedules', [$this, 'add_cron_interval']);
        
        add_action('tya_process_queue_hook', [$this, 'process_retry_queue']);
        if (!wp_next_scheduled('tya_process_queue_hook')) {
            wp_schedule_event(time(), 'tya_five_minutes', 'tya_process_queue_hook');
        }

        $this->load_adapters();
    }

    /* CORE: THE SLINGER ENGINE */
    public function sling($source, $data, $record_id, $is_retry = false, $queue_id = null) {
        $timestamp = time();
        $domain = parse_url(get_site_url(), PHP_URL_HOST);
        
        // The SaaS Identity Check: Looks for a License Key, defaults to Domain if none exists yet.
        $client_id = get_option('tya_slinger_license_key', $domain);

        $payload = [
            'v'         => '10.0.0',
            'client_id' => $client_id,
            'source'    => $source,
            'id'        => $record_id,
            'data'      => $data,
            'timestamp' => $timestamp
        ];

        // HMAC-SHA256 Security Lock
        $signature = hash_hmac('sha256', $timestamp . json_encode($payload), $this->api_key);

        $response = wp_remote_post($this->endpoint, [
            'method'    => 'POST',
            'timeout'   => 15,
            'blocking'  => true,
            'headers'   => [
                'Content-Type'    => 'application/json',
                'X-TYA-Signature' => $signature,
                'X-TYA-Timestamp' => $timestamp,
                'X-TYA-Client-ID' => $client_id // Tells n8n exactly who this is
            ],
            'body'      => json_encode($payload),
        ]);

        $code = wp_remote_retrieve_response_code($response);

        // Success vs Failure Logic
        if ($code === 200) {
            if ($is_retry && $queue_id) {
                global $wpdb;
                $wpdb->update($this->table_name, ['status' => 'resolved'], ['id' => $queue_id]);
            }
        } else {
            if (!$is_retry) {
                $this->add_to_queue($source, $record_id, $data);
            }
        }
    }

    /* QUEUE: THE SAFETY NET */
    private function add_to_queue($source, $record_id, $data) {
        global $wpdb;
        $wpdb->insert($this->table_name, [
            'source'    => $source,
            'record_id' => $record_id,
            'payload'   => json_encode($data),
            'status'    => 'pending',
            'attempts'  => 0
        ]);
    }

    /* THE CRON JOB (Wakes up every 5 mins ONLY to check for failures) */
    public function process_retry_queue() {
        global $wpdb;
        // Only grabs bookings that actually failed. If empty, it does nothing.
        $tasks = $wpdb->get_results("SELECT * FROM {$this->table_name} WHERE status = 'pending' LIMIT 5");

        if (empty($tasks)) return; // System goes back to sleep if queue is clean.

        foreach ($tasks as $task) {
            $new_attempts = intval($task->attempts) + 1;
            
            if ($new_attempts >= $this->max_retries) {
                $wpdb->update($this->table_name, ['status' => 'failed', 'attempts' => $new_attempts], ['id' => $task->id]);
                
                $subject = "URGENT: Slinger Connection Failed - " . get_bloginfo('name');
                $message = "n8n is unreachable. 5 attempts failed.\n\nClient Domain: " . get_site_url() . "\nSource: {$task->source}\nBooking ID: {$task->record_id}\n\nCheck your ngrok/n8n server.";
                wp_mail($this->admin_email, $subject, $message);
                
            } else {
                $wpdb->update($this->table_name, ['attempts' => $new_attempts], ['id' => $task->id]);
                $this->sling($task->source, json_decode($task->payload, true), $task->record_id, true, $task->id);
            }
        }
    }

    /* DETECTIVE: RECURSIVE DATA MINING */
    public function tya_recursive_detective($data, &$found = []) {
        if (is_string($data)) $data = maybe_unserialize($data);
        if (!is_array($data) && !is_object($data)) return $found;

        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $this->tya_recursive_detective($value, $found);
            } else {
                $k = strtolower($key);
                if (preg_match('/(phone|mobile|tel|whatsapp|celular)/', $k)) $found['phone'] = $value;
                if (preg_match('/(name|first|full|guest|customer)/', $k)) $found['name'] = $value;
                if (preg_match('/(email|mail)/', $k)) $found['email'] = $value;
                if (preg_match('/(total|price|amount|cost)/', $k)) $found['total'] = $value;
            }
        }
        return $found;
    }

    /* ADAPTERS: THE PLUG-INS */
    private function load_adapters() {
        // VIKBOOKING
        add_action('vikbooking_booking_conversion_tracking', function($d) {
            $this->sling('VikBooking', $d, $d['id'] ?? '0');
        });

        // LATEPOINT
        add_action('latepoint_booking_created', function($b) {
            $this->sling('LatePoint', ['name' => $b->customer->full_name, 'phone' => $b->customer->phone], $b->id);
        });

        // WP TRAVEL ENGINE
        add_action('wte_after_booking_data_save', function($id) {
            $raw = get_post_custom($id);
            $clean = $this->tya_recursive_detective($raw);
            $this->sling('WPTE', $clean, $id);
        });

        // THE SCOUT (The Watcher)
        add_action('wp_insert_post', function($post_id, $post, $update) {
            if ($update || wp_is_post_revision($post_id)) return;
            if (strpos(strtolower($post->post_type), 'booking') !== false || $post->post_type === 'shop_order') {
                $meta = get_post_custom($post_id);
                $clean = $this->tya_recursive_detective($meta);
                $this->sling('Scout-Discovery', $clean, $post_id);
            }
        }, 10, 3);
    }

    /* SYSTEM INSTALL */
    public function add_cron_interval($schedules) {
        $schedules['tya_five_minutes'] = ['interval' => 300, 'display' => 'Every 5 Minutes'];
        return $schedules;
    }

    public function install_system() {
        global $wpdb;
        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            source varchar(100),
            record_id varchar(100),
            payload longtext,
            status varchar(20) DEFAULT 'pending',
            attempts int(2) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) {$wpdb->get_charset_collate()};";
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

TYA_Universal_Slinger::get_instance();