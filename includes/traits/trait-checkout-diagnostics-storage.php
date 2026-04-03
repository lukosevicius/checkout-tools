<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Checkout_Diagnostics_Storage_Trait
{
    private static function insert_event($event)
    {
        global $wpdb;

        $meta = isset($event['meta']) ? self::decode_event_data($event['meta']) : array();
        $sanitized_event = array(
            'created_at'      => isset($event['created_at']) ? $event['created_at'] : current_time('mysql'),
            'session_key'     => isset($event['session_key']) ? self::sanitize_session_key($event['session_key']) : '',
            'event_type'      => isset($event['event_type']) ? sanitize_key($event['event_type']) : '',
            'order_id'        => isset($event['order_id']) ? absint($event['order_id']) : null,
            'error_code'      => isset($event['error_code']) ? sanitize_text_field((string) $event['error_code']) : null,
            'field_key'       => isset($event['field_key']) && $event['field_key'] ? sanitize_text_field((string) $event['field_key']) : null,
            'shipping_method' => isset($event['shipping_method']) ? self::sanitize_method_value($event['shipping_method']) : '',
            'payment_method'  => isset($event['payment_method']) ? self::sanitize_method_value($event['payment_method']) : '',
            'meta'            => $meta,
        );

        $wpdb->insert(
            self::table_name(),
            array(
                'created_at'      => $sanitized_event['created_at'],
                'session_key'     => $sanitized_event['session_key'],
                'event_type'      => $sanitized_event['event_type'],
                'order_id'        => $sanitized_event['order_id'],
                'error_code'      => $sanitized_event['error_code'],
                'field_key'       => $sanitized_event['field_key'],
                'shipping_method' => $sanitized_event['shipping_method'],
                'payment_method'  => $sanitized_event['payment_method'],
                'meta'            => wp_json_encode($sanitized_event['meta']),
            ),
            array(
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
            )
        );

        if ($sanitized_event['session_key']) {
            self::upsert_session_summary($sanitized_event);
        }
    }

    /**
     * Build the custom table name.
     *
     * @return string
     */
    private static function table_name()
    {
        global $wpdb;

        return $wpdb->prefix . 'checkout_diagnostics_events';
    }

    /**
     * Build the session summaries table name.
     *
     * @return string
     */
    private static function sessions_table_name()
    {
        global $wpdb;

        return $wpdb->prefix . 'checkout_diagnostics_sessions';
    }

    private static function rebuild_session_summaries()
    {
        global $wpdb;

        $wpdb->query("TRUNCATE TABLE " . self::sessions_table_name());

        $events = $wpdb->get_results(
            "SELECT created_at, session_key, event_type, order_id, error_code, field_key, shipping_method, payment_method, meta
            FROM " . self::table_name() . "
            WHERE session_key <> ''
            ORDER BY created_at ASC",
            ARRAY_A
        );

        foreach ($events as $event) {
            $event['meta'] = self::decode_event_data($event['meta']);
            $event['session_key'] = self::sanitize_session_key($event['session_key']);

            if (!$event['session_key']) {
                continue;
            }

            self::upsert_session_summary($event);
        }
    }

    /**
     * Replace all diagnostics data with an imported payload.
     *
     * @param array $payload Import payload.
     * @return void
     */
    private static function replace_all_diagnostics_data($payload)
    {
        global $wpdb;

        $events = isset($payload['events']) && is_array($payload['events']) ? $payload['events'] : array();
        $sessions = isset($payload['sessions']) && is_array($payload['sessions']) ? $payload['sessions'] : array();

        $wpdb->query("TRUNCATE TABLE " . self::table_name());
        $wpdb->query("TRUNCATE TABLE " . self::sessions_table_name());

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $wpdb->insert(
                self::table_name(),
                array(
                    'created_at'      => isset($event['created_at']) ? sanitize_text_field($event['created_at']) : current_time('mysql'),
                    'session_key'     => isset($event['session_key']) ? self::sanitize_session_key($event['session_key']) : '',
                    'event_type'      => isset($event['event_type']) ? sanitize_key($event['event_type']) : '',
                    'order_id'        => isset($event['order_id']) ? absint($event['order_id']) : null,
                    'error_code'      => isset($event['error_code']) ? sanitize_text_field((string) $event['error_code']) : null,
                    'field_key'       => isset($event['field_key']) ? sanitize_text_field((string) $event['field_key']) : null,
                    'shipping_method' => isset($event['shipping_method']) ? self::sanitize_method_value($event['shipping_method']) : '',
                    'payment_method'  => isset($event['payment_method']) ? self::sanitize_method_value($event['payment_method']) : '',
                    'meta'            => isset($event['meta']) ? wp_json_encode(self::decode_event_data($event['meta'])) : null,
                ),
                array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
            );
        }

        foreach ($sessions as $session) {
            if (!is_array($session)) {
                continue;
            }

            $wpdb->insert(
                self::sessions_table_name(),
                array(
                    'session_key'         => isset($session['session_key']) ? self::sanitize_session_key($session['session_key']) : '',
                    'created_at'          => isset($session['created_at']) ? sanitize_text_field($session['created_at']) : current_time('mysql'),
                    'last_seen_at'        => isset($session['last_seen_at']) ? sanitize_text_field($session['last_seen_at']) : current_time('mysql'),
                    'first_name'          => isset($session['first_name']) ? self::sanitize_first_name($session['first_name']) : '',
                    'ip_address'          => isset($session['ip_address']) ? self::sanitize_ip_address($session['ip_address']) : '',
                    'device_type'         => isset($session['device_type']) ? self::sanitize_device_type($session['device_type']) : '',
                    'os_name'             => isset($session['os_name']) ? self::sanitize_os_name($session['os_name']) : '',
                    'language'            => isset($session['language']) ? self::sanitize_language($session['language']) : '',
                    'cart_total'          => isset($session['cart_total']) ? sanitize_text_field((string) $session['cart_total']) : '',
                    'cart_items'          => isset($session['cart_items']) ? wp_json_encode(self::decode_event_data($session['cart_items'])) : null,
                    'view_count'          => isset($session['view_count']) ? absint($session['view_count']) : 0,
                    'refresh_count'       => isset($session['refresh_count']) ? absint($session['refresh_count']) : 0,
                    'place_order_clicks'  => isset($session['place_order_clicks']) ? absint($session['place_order_clicks']) : 0,
                    'validation_failures' => isset($session['validation_failures']) ? absint($session['validation_failures']) : 0,
                    'validation_errors'   => isset($session['validation_errors']) ? absint($session['validation_errors']) : 0,
                    'successful_orders'   => isset($session['successful_orders']) ? absint($session['successful_orders']) : 0,
                    'shipping_method'     => isset($session['shipping_method']) ? self::sanitize_method_value($session['shipping_method']) : '',
                    'payment_method'      => isset($session['payment_method']) ? self::sanitize_method_value($session['payment_method']) : '',
                    'last_error_message'  => isset($session['last_error_message']) ? sanitize_text_field((string) $session['last_error_message']) : null,
                    'status'              => isset($session['status']) ? sanitize_text_field((string) $session['status']) : '',
                    'order_id'            => isset($session['order_id']) ? absint($session['order_id']) : null,
                    'meta'                => isset($session['meta']) ? wp_json_encode(self::decode_event_data($session['meta'])) : null,
                ),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s')
            );
        }
    }

    private static function upsert_session_summary($event)
    {
        global $wpdb;

        $session_key = isset($event['session_key']) ? self::sanitize_session_key($event['session_key']) : '';

        if (!$session_key) {
            return;
        }

        $table_name = self::sessions_table_name();
        $session = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE session_key = %s LIMIT 1",
                $session_key
            ),
            ARRAY_A
        );

        $meta = isset($event['meta']) && is_array($event['meta']) ? $event['meta'] : array();
        $first_name = self::sanitize_first_name(isset($meta['first_name']) ? $meta['first_name'] : '');
        $message = isset($meta['message']) ? sanitize_text_field((string) $meta['message']) : '';
        $is_company = isset($meta['is_company']) ? sanitize_text_field((string) $meta['is_company']) : '';
        $ip_address = isset($meta['ip_address']) ? self::sanitize_ip_address($meta['ip_address']) : '';
        $device_type = isset($meta['device_type']) ? self::sanitize_device_type($meta['device_type']) : '';
        $os_name = isset($meta['os_name']) ? self::sanitize_os_name($meta['os_name']) : '';
        $language = isset($meta['language']) ? self::sanitize_language($meta['language']) : '';
        $cart_total = isset($meta['cart_total']) ? sanitize_text_field((string) $meta['cart_total']) : '';
        $cart_items = isset($meta['cart_items']) && is_array($meta['cart_items']) ? self::decode_event_data($meta['cart_items']) : array();
        $created_at = isset($event['created_at']) ? $event['created_at'] : current_time('mysql');
        $event_type = isset($event['event_type']) ? sanitize_key($event['event_type']) : '';

        $payload = array(
            'session_key'         => $session_key,
            'created_at'          => $session && !empty($session['created_at']) ? $session['created_at'] : $created_at,
            'last_seen_at'        => $created_at,
            'first_name'          => $first_name ? $first_name : ($session['first_name'] ?? ''),
            'ip_address'          => $ip_address ? $ip_address : ($session['ip_address'] ?? ''),
            'device_type'         => $device_type ? $device_type : ($session['device_type'] ?? ''),
            'os_name'             => $os_name ? $os_name : ($session['os_name'] ?? ''),
            'language'            => $language ? $language : ($session['language'] ?? ''),
            'cart_total'          => $cart_total ? $cart_total : ($session['cart_total'] ?? ''),
            'cart_items'          => !empty($cart_items) ? wp_json_encode($cart_items) : ($session['cart_items'] ?? null),
            'view_count'          => isset($session['view_count']) ? (int) $session['view_count'] : 0,
            'refresh_count'       => isset($session['refresh_count']) ? (int) $session['refresh_count'] : 0,
            'place_order_clicks'  => isset($session['place_order_clicks']) ? (int) $session['place_order_clicks'] : 0,
            'validation_failures' => isset($session['validation_failures']) ? (int) $session['validation_failures'] : 0,
            'validation_errors'   => isset($session['validation_errors']) ? (int) $session['validation_errors'] : 0,
            'successful_orders'   => isset($session['successful_orders']) ? (int) $session['successful_orders'] : 0,
            'shipping_method'     => isset($event['shipping_method']) && $event['shipping_method'] ? self::sanitize_method_value($event['shipping_method']) : ($session['shipping_method'] ?? ''),
            'payment_method'      => isset($event['payment_method']) && $event['payment_method'] ? self::sanitize_method_value($event['payment_method']) : ($session['payment_method'] ?? ''),
            'last_error_message'  => $message ? $message : ($session['last_error_message'] ?? null),
            'status'              => isset($session['status']) ? $session['status'] : 'viewed',
            'order_id'            => !empty($event['order_id']) ? absint($event['order_id']) : (!empty($session['order_id']) ? absint($session['order_id']) : null),
            'meta'                => wp_json_encode(
                array(
                    'last_event_type' => $event_type,
                    'is_company'      => $is_company,
                )
            ),
        );

        switch ($event_type) {
            case 'checkout_view':
                $payload['view_count']++;
                if (empty($payload['status'])) {
                    $payload['status'] = 'viewed';
                }
                break;
            case 'checkout_refresh':
                $payload['refresh_count']++;
                break;
            case 'place_order_click':
                $payload['place_order_clicks']++;
                $payload['status'] = 'clicked';
                break;
            case 'validation_failed':
                $payload['validation_failures']++;
                $payload['status'] = 'validation_failed';
                break;
            case 'validation_error':
                $payload['validation_errors']++;
                if ('converted' !== $payload['status']) {
                    $payload['status'] = 'validation_failed';
                }
                break;
            case 'order_success':
                $payload['successful_orders']++;
                $payload['status'] = 'converted';
                break;
            default:
                break;
        }

        if ($session) {
            $wpdb->update(
                $table_name,
                $payload,
                array('session_key' => $session_key),
                array(
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%d',
                    '%d',
                    '%d',
                    '%d',
                    '%d',
                    '%d',
                    '%s',
                    '%s',
                    '%s',
                    '%s',
                    '%d',
                    '%s',
                ),
                array('%s')
            );

            return;
        }

        $wpdb->insert(
            $table_name,
            $payload,
            array(
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%d',
                '%d',
                '%d',
                '%d',
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
                '%d',
                '%s',
            )
        );
    }
}
