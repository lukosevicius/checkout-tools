<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Checkout_Diagnostics_Tracking_Trait
{
    public static function enqueue_checkout_assets()
    {
        if (!function_exists('is_checkout') || !is_checkout() || is_order_received_page()) {
            return;
        }

        wp_enqueue_script(
            'checkout-diagnostics',
            plugins_url('assets/js/checkout-diagnostics.js', CHECKOUT_DIAGNOSTICS_PLUGIN_FILE),
            array(),
            self::VERSION,
            true
        );

        wp_localize_script(
            'checkout-diagnostics',
            'checkoutDiagnostics',
            array(
                'ajaxUrl'          => admin_url('admin-ajax.php'),
                'nonce'            => wp_create_nonce(self::AJAX_ACTION),
                'action'           => self::AJAX_ACTION,
                'sessionFieldName' => self::SESSION_FIELD,
                'shouldTrack'      => !self::should_skip_tracking(),
            )
        );
    }

    /**
     * Output a hidden session field inside the checkout form.
     *
     * @return void
     */
    public static function render_session_field()
    {
        if (self::should_skip_tracking()) {
            return;
        }

        echo '<input type="hidden" id="' . esc_attr(self::SESSION_FIELD) . '" name="' . esc_attr(self::SESSION_FIELD) . '" value="">';
    }

    /**
     * Handle AJAX event tracking from checkout JavaScript.
     *
     * @return void
     */
    public static function handle_track_event()
    {
        check_ajax_referer(self::AJAX_ACTION, 'nonce');

        if (self::should_skip_tracking()) {
            wp_send_json_success(array('skipped' => true));
        }

        $event_type = isset($_POST['event_type']) ? sanitize_key(wp_unslash($_POST['event_type'])) : '';
        $allowed_events = array(
            'checkout_view',
            'checkout_refresh',
            'place_order_click',
            'shipping_method_change',
            'payment_method_change',
            'company_toggle_change',
            'first_name_capture',
        );

        if (!in_array($event_type, $allowed_events, true)) {
            wp_send_json_error(array('message' => 'Invalid event type.'), 400);
        }

        $session_key = self::sanitize_session_key(isset($_POST['session_key']) ? wp_unslash($_POST['session_key']) : '');
        $event_data = self::decode_event_data(isset($_POST['event_data']) ? wp_unslash($_POST['event_data']) : '');
        $event_data = self::with_request_context($event_data);

        self::insert_event(
            array(
                'created_at'      => current_time('mysql'),
                'session_key'     => $session_key,
                'event_type'      => $event_type,
                'order_id'        => null,
                'error_code'      => null,
                'field_key'       => isset($event_data['field_key']) ? sanitize_key($event_data['field_key']) : null,
                'shipping_method' => self::sanitize_method_value(isset($event_data['shipping_method']) ? $event_data['shipping_method'] : ''),
                'payment_method'  => self::sanitize_method_value(isset($event_data['payment_method']) ? $event_data['payment_method'] : ''),
                'meta'            => wp_json_encode($event_data),
            )
        );

        wp_send_json_success(array('tracked' => true));
    }

    /**
     * Log one server-side checkout submit attempt before validation runs.
     *
     * @return void
     */
    public static function log_submit_attempt()
    {
        if (self::should_skip_tracking()) {
            return;
        }

        $data = wp_unslash($_POST);
        $session_key = self::get_session_key_from_data($data);

        if ($session_key) {
            self::$active_request_session_key = $session_key;
        }

        self::insert_event(
            array(
                'created_at'      => current_time('mysql'),
                'session_key'     => $session_key,
                'event_type'      => 'place_order_click',
                'order_id'        => null,
                'error_code'      => null,
                'field_key'       => null,
                'shipping_method' => self::extract_shipping_method($data),
                'payment_method'  => isset($data['payment_method']) ? self::sanitize_method_value($data['payment_method']) : '',
                'meta'            => wp_json_encode(
                    self::with_request_context(
                        array(
                            'source'     => 'server',
                            'is_company' => !empty($data['billing_is_company']) ? '1' : '0',
                            'first_name' => self::sanitize_first_name(isset($data['billing_first_name']) ? $data['billing_first_name'] : ''),
                        )
                    )
                ),
            )
        );
    }

    /**
     * Log validation failures and each individual checkout error.
     *
     * @param array    $data   Submitted checkout data.
     * @param WP_Error $errors Validation errors.
     * @return void
     */
    public static function log_validation_results($data, $errors)
    {
        if (self::should_skip_tracking()) {
            return;
        }

        $session_key = self::get_session_key_from_data($data);
        $shipping_method = self::extract_shipping_method($data);
        $payment_method = isset($data['payment_method']) ? self::sanitize_method_value($data['payment_method']) : '';
        $error_codes = $errors instanceof WP_Error ? $errors->get_error_codes() : array();
        $error_notices = wc_get_notices('error');

        if (empty($error_codes) && empty($error_notices)) {
            return;
        }

        $logged_messages = array();

        self::insert_event(
            array(
                'created_at'      => current_time('mysql'),
                'session_key'     => $session_key,
                'event_type'      => 'validation_failed',
                'order_id'        => null,
                'error_code'      => null,
                'field_key'       => null,
                'shipping_method' => $shipping_method,
                'payment_method'  => $payment_method,
                'meta'            => wp_json_encode(
                    self::with_request_context(
                        array(
                            'error_count' => max(count($error_codes), count($error_notices)),
                            'first_name'  => self::sanitize_first_name(isset($data['billing_first_name']) ? $data['billing_first_name'] : ''),
                        )
                    )
                ),
            )
        );

        foreach ($error_codes as $error_code) {
            $messages = $errors->get_error_messages($error_code);

            if (empty($messages)) {
                $messages = array('');
            }

            foreach ($messages as $message) {
                $normalized_message = self::normalize_notice_message($message);
                $logged_messages[$normalized_message] = true;

                self::insert_event(
                    array(
                        'created_at'      => current_time('mysql'),
                        'session_key'     => $session_key,
                        'event_type'      => 'validation_error',
                        'order_id'        => null,
                        'error_code'      => sanitize_text_field((string) $error_code),
                        'field_key'       => self::detect_field_key($error_code, $message),
                        'shipping_method' => $shipping_method,
                        'payment_method'  => $payment_method,
                        'meta'            => wp_json_encode(
                            self::with_request_context(
                                array(
                                    'message'    => wp_strip_all_tags((string) $message),
                                    'first_name' => self::sanitize_first_name(isset($data['billing_first_name']) ? $data['billing_first_name'] : ''),
                                )
                            )
                        ),
                    )
                );
            }
        }

        foreach ($error_notices as $notice) {
            $message = isset($notice['notice']) ? (string) $notice['notice'] : '';
            $normalized_message = self::normalize_notice_message($message);

            if ('' === $normalized_message || isset($logged_messages[$normalized_message])) {
                continue;
            }

            $field_key = self::detect_field_key('', $message);
            $error_code = !empty($notice['data']['id'])
                ? sanitize_text_field((string) $notice['data']['id'])
                : self::detect_error_code_from_notice($message, $field_key);

            self::insert_event(
                array(
                    'created_at'      => current_time('mysql'),
                    'session_key'     => $session_key,
                    'event_type'      => 'validation_error',
                    'order_id'        => null,
                    'error_code'      => $error_code,
                    'field_key'       => $field_key,
                    'shipping_method' => $shipping_method,
                    'payment_method'  => $payment_method,
                    'meta'            => wp_json_encode(
                        self::with_request_context(
                            array(
                                'message'    => wp_strip_all_tags($message),
                                'source'     => 'wc_notice',
                                'first_name' => self::sanitize_first_name(isset($data['billing_first_name']) ? $data['billing_first_name'] : ''),
                            )
                        )
                    ),
                )
            );
        }
    }

    /**
     * Log successful checkout submissions after the order is created.
     *
     * @param int      $order_id     Created order ID.
     * @param array    $posted_data  Posted checkout data.
     * @param WC_Order $order        Order object.
     * @return void
     */
    public static function log_order_success($order_id, $posted_data, $order)
    {
        if (self::should_skip_tracking()) {
            return;
        }

        if (!is_array($posted_data)) {
            parse_str((string) $posted_data, $posted_data);
        }

        $shipping_method = self::extract_shipping_method($posted_data);
        $payment_method = $order instanceof WC_Order ? self::sanitize_method_value($order->get_payment_method()) : '';

        self::insert_event(
            array(
                'created_at'      => current_time('mysql'),
                'session_key'     => self::get_session_key_from_data($posted_data),
                'event_type'      => 'order_success',
                'order_id'        => absint($order_id),
                'error_code'      => null,
                'field_key'       => null,
                'shipping_method' => $shipping_method,
                'payment_method'  => $payment_method,
                'meta'            => wp_json_encode(
                    self::with_request_context(
                        array(
                            'order_status' => $order instanceof WC_Order ? sanitize_text_field($order->get_status()) : '',
                            'first_name'   => self::sanitize_first_name(isset($posted_data['billing_first_name']) ? $posted_data['billing_first_name'] : ''),
                        )
                    )
                ),
            )
        );
    }

    private static function sanitize_session_key($session_key)
    {
        return substr(preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $session_key), 0, 64);
    }

    /**
     * Sanitize shipping or payment method IDs.
     *
     * @param string $value Method ID.
     * @return string
     */
    private static function sanitize_method_value($value)
    {
        return substr(sanitize_text_field((string) $value), 0, 191);
    }

    /**
     * Sanitize a captured first name.
     *
     * @param string $value Name value.
     * @return string
     */
    private static function sanitize_first_name($value)
    {
        return substr(sanitize_text_field((string) $value), 0, 191);
    }

    /**
     * Sanitize a captured IP address.
     *
     * @param string $value IP address value.
     * @return string
     */
    private static function sanitize_ip_address($value)
    {
        $value = trim((string) $value);

        if (!$value || !filter_var($value, FILTER_VALIDATE_IP)) {
            return '';
        }

        return substr($value, 0, 45);
    }

    /**
     * Sanitize a device type label.
     *
     * @param string $value Device type value.
     * @return string
     */
    private static function sanitize_device_type($value)
    {
        return substr(sanitize_text_field((string) $value), 0, 32);
    }

    /**
     * Sanitize an OS label.
     *
     * @param string $value OS label.
     * @return string
     */
    private static function sanitize_os_name($value)
    {
        return substr(sanitize_text_field((string) $value), 0, 64);
    }

    /**
     * Sanitize a language code value.
     *
     * @param string $value Language value.
     * @return string
     */
    private static function sanitize_language($value)
    {
        return substr(sanitize_text_field((string) $value), 0, 32);
    }

    /**
     * Decode and sanitize event meta payloads.
     *
     * @param mixed $raw Raw meta input.
     * @return array
     */
    private static function decode_event_data($raw)
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : array();
        }

        if (!is_array($raw)) {
            return array();
        }

        $clean = array();

        foreach ($raw as $key => $value) {
            $clean_key = sanitize_key((string) $key);

            if ('' === $clean_key) {
                continue;
            }

            if (is_array($value)) {
                $clean[$clean_key] = self::decode_event_data($value);
                continue;
            }

            if (is_bool($value)) {
                $clean[$clean_key] = $value ? 1 : 0;
                continue;
            }

            if (is_scalar($value) || null === $value) {
                $clean[$clean_key] = sanitize_text_field((string) $value);
            }
        }

        return $clean;
    }

    /**
     * Merge request context into one event meta payload.
     *
     * @param array $data Event data.
     * @return array
     */
    private static function with_request_context($data)
    {
        if (!is_array($data)) {
            $data = array();
        }

        return array_merge(self::get_request_context(), $data);
    }

    /**
     * Read and normalize request context useful for diagnostics.
     *
     * @return array
     */
    private static function get_request_context()
    {
        $context = array(
            'ip_address'  => self::get_request_ip_address(),
            'device_type' => self::detect_device_type(),
            'os_name'     => self::detect_os_name(),
            'language'    => self::get_request_language(),
        );

        $cart_snapshot = self::get_cart_snapshot();

        if (!empty($cart_snapshot)) {
            $context = array_merge($context, $cart_snapshot);
        }

        return array_filter(
            $context,
            static function ($value) {
                return '' !== $value && null !== $value;
            }
        );
    }

    /**
     * Detect client IP address from common request headers.
     *
     * @return string
     */
    private static function get_request_ip_address()
    {
        $candidates = array();

        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $candidates[] = wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']);
        }

        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded = explode(',', wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));

            foreach ($forwarded as $value) {
                $candidates[] = trim($value);
            }
        }

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $candidates[] = wp_unslash($_SERVER['REMOTE_ADDR']);
        }

        foreach ($candidates as $candidate) {
            $ip_address = self::sanitize_ip_address($candidate);

            if ($ip_address) {
                return $ip_address;
            }
        }

        return '';
    }

    /**
     * Detect a simple device type from the user agent.
     *
     * @return string
     */
    private static function detect_device_type()
    {
        $user_agent = function_exists('mb_strtolower')
            ? mb_strtolower((string) (isset($_SERVER['HTTP_USER_AGENT']) ? wp_unslash($_SERVER['HTTP_USER_AGENT']) : ''))
            : strtolower((string) (isset($_SERVER['HTTP_USER_AGENT']) ? wp_unslash($_SERVER['HTTP_USER_AGENT']) : ''));

        if (!$user_agent) {
            return '';
        }

        if (false !== strpos($user_agent, 'ipad') || false !== strpos($user_agent, 'tablet')) {
            return 'tablet';
        }

        if (
            false !== strpos($user_agent, 'mobile') ||
            false !== strpos($user_agent, 'iphone') ||
            false !== strpos($user_agent, 'android')
        ) {
            return 'mobile';
        }

        return 'desktop';
    }

    /**
     * Detect a simple operating system label from the user agent.
     *
     * @return string
     */
    private static function detect_os_name()
    {
        $user_agent = function_exists('mb_strtolower')
            ? mb_strtolower((string) (isset($_SERVER['HTTP_USER_AGENT']) ? wp_unslash($_SERVER['HTTP_USER_AGENT']) : ''))
            : strtolower((string) (isset($_SERVER['HTTP_USER_AGENT']) ? wp_unslash($_SERVER['HTTP_USER_AGENT']) : ''));

        if (!$user_agent) {
            return '';
        }

        if (false !== strpos($user_agent, 'iphone') || false !== strpos($user_agent, 'ipad') || false !== strpos($user_agent, 'ipod')) {
            return 'iOS';
        }

        if (false !== strpos($user_agent, 'android')) {
            return 'Android';
        }

        if (false !== strpos($user_agent, 'windows')) {
            return 'Windows';
        }

        if (false !== strpos($user_agent, 'cros')) {
            return 'Chrome OS';
        }

        if (false !== strpos($user_agent, 'mac os') || false !== strpos($user_agent, 'macintosh')) {
            return 'macOS';
        }

        if (false !== strpos($user_agent, 'linux')) {
            return 'Linux';
        }

        return '';
    }

    /**
     * Read the preferred request language.
     *
     * @return string
     */
    private static function get_request_language()
    {
        $locale = function_exists('determine_locale') ? determine_locale() : get_locale();

        return self::sanitize_language($locale);
    }

    /**
     * Read a lightweight snapshot of the current cart.
     *
     * @return array
     */
    private static function get_cart_snapshot()
    {
        if (!function_exists('WC') || !WC() || !WC()->cart) {
            return array();
        }

        $cart = WC()->cart;
        $items = array();

        foreach ($cart->get_cart() as $cart_item) {
            $product_name = '';

            if (!empty($cart_item['data']) && $cart_item['data'] instanceof WC_Product) {
                $product_name = $cart_item['data']->get_name();
            } elseif (!empty($cart_item['product_id'])) {
                $product_name = get_the_title((int) $cart_item['product_id']);
            }

            $items[] = array(
                'product_name' => sanitize_text_field((string) $product_name),
                'quantity'     => isset($cart_item['quantity']) ? absint($cart_item['quantity']) : 0,
                'line_total'   => isset($cart_item['line_total']) ? wp_strip_all_tags(wc_price((float) $cart_item['line_total'])) : '',
            );
        }

        return array(
            'cart_total' => wp_strip_all_tags((string) $cart->get_total()),
            'cart_items' => $items,
        );
    }

    /**
     * Read the tracking session key from checkout data.
     *
     * @param array $data Posted data.
     * @return string
     */
    private static function get_session_key_from_data($data)
    {
        if (is_array($data) && !empty($data[self::SESSION_FIELD])) {
            return self::sanitize_session_key($data[self::SESSION_FIELD]);
        }

        if (!empty(self::$active_request_session_key)) {
            return self::sanitize_session_key(self::$active_request_session_key);
        }

        if (!empty($_COOKIE[self::SESSION_FIELD])) {
            return self::sanitize_session_key(wp_unslash($_COOKIE[self::SESSION_FIELD]));
        }

        return '';
    }

    /**
     * Get the selected shipping method from checkout data.
     *
     * @param array $data Posted data.
     * @return string
     */
    private static function extract_shipping_method($data)
    {
        if (!is_array($data) || empty($data['shipping_method'])) {
            return '';
        }

        if (is_array($data['shipping_method'])) {
            return self::sanitize_method_value(reset($data['shipping_method']));
        }

        return self::sanitize_method_value($data['shipping_method']);
    }

    /**
     * Map checkout errors to a field where possible.
     *
     * @param string $error_code Error code.
     * @param string $message    Error message.
     * @return string|null
     */
    private static function detect_field_key($error_code, $message)
    {
        $error_code = (string) $error_code;
        $message = function_exists('mb_strtolower')
            ? mb_strtolower((string) $message)
            : strtolower((string) $message);

        if (preg_match('/^(billing|shipping)_[a-z0-9_]+/', $error_code, $matches)) {
            return $matches[0];
        }

        if (false !== strpos($error_code, 'terms') || false !== strpos($message, 'taisykl')) {
            return 'terms';
        }

        if (
            false !== strpos($error_code, 'venipak') ||
            false !== strpos($message, 'paštomat') ||
            false !== strpos($message, 'atsiėmimo') ||
            false !== strpos($message, 'pickup point') ||
            false !== strpos($message, 'select pickup')
        ) {
            return 'venipak_pickup_point';
        }

        if (false !== strpos($error_code, 'payment') || false !== strpos($message, 'mokėj')) {
            return 'payment_method';
        }

        if (false !== strpos($error_code, 'shipping') || false !== strpos($message, 'pristat')) {
            return 'shipping_method';
        }

        if (
            false !== strpos($error_code, 'address') ||
            false !== strpos($message, 'billing address') ||
            false !== strpos($message, 'gatv') ||
            false !== strpos($message, 'adresas')
        ) {
            return 'billing_address_1';
        }

        return null;
    }

    private static function normalize_notice_message($message)
    {
        return trim(wp_strip_all_tags((string) $message));
    }

    /**
     * Build a readable fallback error code from the notice message.
     *
     * @param string      $message   Error message.
     * @param string|null $field_key Matched field key.
     * @return string
     */
    private static function detect_error_code_from_notice($message, $field_key = null)
    {
        if (!empty($field_key)) {
            return sanitize_text_field((string) $field_key);
        }

        $normalized_message = self::normalize_notice_message($message);
        $message_slug = sanitize_title($normalized_message);

        if ($message_slug) {
            return substr($message_slug, 0, 191);
        }

        return 'checkout_notice';
    }

    /**
     * Skip tracking for admins and shop managers.
     *
     * @return bool
     */
    private static function should_skip_tracking()
    {
        return self::current_user_is_privileged() && !self::should_track_privileged_users();
    }

    /**
     * Check whether the current visitor is an admin or shop manager.
     *
     * @return bool
     */
    private static function current_user_is_privileged()
    {
        return is_user_logged_in() && current_user_can('manage_woocommerce');
    }

    /**
     * Read whether privileged users should be tracked.
     *
     * @return bool
     */
    private static function should_track_privileged_users()
    {
        return '1' === get_option(self::OPTION_TRACK_PRIVILEGED_USERS, '0');
    }
}
