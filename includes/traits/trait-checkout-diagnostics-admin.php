<?php
if (!defined('ABSPATH')) {
    exit;
}

trait Checkout_Diagnostics_Admin_Trait
{
    public static function register_admin_page()
    {
        add_submenu_page(
            'woocommerce',
            __('Checkout Diagnostics', 'checkout-diagnostics'),
            __('Checkout Diagnostics', 'checkout-diagnostics'),
            'manage_woocommerce',
            'checkout-diagnostics',
            array(__CLASS__, 'render_admin_page')
        );
    }

    /**
     * Handle admin actions from the report page.
     *
     * @return void
     */
    public static function handle_admin_actions()
    {
        if (!is_admin() || !current_user_can('manage_woocommerce')) {
            return;
        }

        if (!empty($_POST['checkout_diagnostics_settings_submit'])) {
            self::handle_settings_update();
        }

        if (!empty($_POST['checkout_diagnostics_export_submit'])) {
            self::handle_export();
        }

        if (!empty($_POST['checkout_diagnostics_import_submit'])) {
            self::handle_import();
        }

        if (!empty($_POST['checkout_diagnostics_delete_all_submit'])) {
            self::handle_delete_all_data();
        }
    }

    /**
     * Save the admin tracking toggle from the report page.
     *
     * @return void
     */
    private static function handle_settings_update()
    {
        check_admin_referer('checkout_diagnostics_settings');

        $enabled = !empty($_POST['track_privileged_users']) ? '1' : '0';

        update_option(self::OPTION_TRACK_PRIVILEGED_USERS, $enabled, false);

        $redirect_url = add_query_arg(
            array(
                'page'             => 'checkout-diagnostics',
                'settings-updated' => '1',
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Export diagnostics data as JSON.
     *
     * @return void
     */
    private static function handle_export()
    {
        check_admin_referer('checkout_diagnostics_export');

        global $wpdb;

        $export = array(
            'generated_at' => current_time('mysql'),
            'plugin_version' => self::VERSION,
            'events' => $wpdb->get_results(
                "SELECT *
                FROM " . self::table_name() . "
                ORDER BY id ASC",
                ARRAY_A
            ),
            'sessions' => $wpdb->get_results(
                "SELECT *
                FROM " . self::sessions_table_name() . "
                ORDER BY id ASC",
                ARRAY_A
            ),
        );

        nocache_headers();
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        header('Content-Disposition: attachment; filename=checkout-diagnostics-' . gmdate('Y-m-d-H-i-s') . '.json');

        echo wp_json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Import diagnostics data from a JSON export.
     *
     * @return void
     */
    private static function handle_import()
    {
        check_admin_referer('checkout_diagnostics_import');

        if (empty($_FILES['checkout_diagnostics_import_file']['tmp_name'])) {
            self::redirect_with_admin_flag('import-error', 'missing-file');
        }

        $raw = file_get_contents($_FILES['checkout_diagnostics_import_file']['tmp_name']);
        $payload = json_decode((string) $raw, true);

        if (!is_array($payload) || !isset($payload['events']) || !isset($payload['sessions'])) {
            self::redirect_with_admin_flag('import-error', 'invalid-file');
        }

        self::replace_all_diagnostics_data($payload);

        self::redirect_with_admin_flag('imported', '1');
    }

    /**
     * Delete all diagnostics rows from both plugin tables.
     *
     * @return void
     */
    private static function handle_delete_all_data()
    {
        check_admin_referer('checkout_diagnostics_delete_all');

        global $wpdb;

        $wpdb->query("TRUNCATE TABLE " . self::table_name());
        $wpdb->query("TRUNCATE TABLE " . self::sessions_table_name());

        self::redirect_with_admin_flag('deleted', '1');
    }

    /**
     * Render the admin diagnostics report.
     *
     * @return void
     */
    public static function render_admin_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        global $wpdb;

        $table_name = self::table_name();
        $sessions_table_name = self::sessions_table_name();
        $date_range = self::get_requested_date_range();
        $start_datetime = $date_range['start'] . ' 00:00:00';
        $end_datetime = $date_range['end'] . ' 23:59:59';

        $event_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_type, COUNT(*) AS total
                FROM {$table_name}
                WHERE created_at BETWEEN %s AND %s
                GROUP BY event_type",
                $start_datetime,
                $end_datetime
            ),
            ARRAY_A
        );

        $event_counts = array_fill_keys(
            array(
                'checkout_view',
                'checkout_refresh',
                'place_order_click',
                'shipping_method_change',
                'payment_method_change',
                'company_toggle_change',
                'validation_failed',
                'validation_error',
                'order_success',
            ),
            0
        );

        foreach ($event_rows as $event_row) {
            $event_counts[$event_row['event_type']] = (int) $event_row['total'];
        }

        $top_errors = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT error_code, field_key, COUNT(*) AS total
                FROM {$table_name}
                WHERE event_type = 'validation_error'
                  AND created_at BETWEEN %s AND %s
                GROUP BY error_code, field_key
                ORDER BY total DESC
                LIMIT 10",
                $start_datetime,
                $end_datetime
            ),
            ARRAY_A
        );

        $shipping_usage = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT shipping_method, COUNT(*) AS total
                FROM {$table_name}
                WHERE event_type = 'order_success'
                  AND shipping_method <> ''
                  AND created_at BETWEEN %s AND %s
                GROUP BY shipping_method
                ORDER BY total DESC
                LIMIT 10",
                $start_datetime,
                $end_datetime
            ),
            ARRAY_A
        );

        $payment_usage = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT payment_method, COUNT(*) AS total
                FROM {$table_name}
                WHERE event_type = 'order_success'
                  AND payment_method <> ''
                  AND created_at BETWEEN %s AND %s
                GROUP BY payment_method
                ORDER BY total DESC
                LIMIT 10",
                $start_datetime,
                $end_datetime
            ),
            ARRAY_A
        );

        $device_usage = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT device_type, COUNT(*) AS total
                FROM {$sessions_table_name}
                WHERE last_seen_at BETWEEN %s AND %s
                  AND device_type <> ''
                GROUP BY device_type
                ORDER BY total DESC
                LIMIT 10",
                $start_datetime,
                $end_datetime
            ),
            ARRAY_A
        );

        $os_usage = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT os_name, COUNT(*) AS total
                FROM {$sessions_table_name}
                WHERE last_seen_at BETWEEN %s AND %s
                  AND os_name <> ''
                GROUP BY os_name
                ORDER BY total DESC
                LIMIT 10",
                $start_datetime,
                $end_datetime
            ),
            ARRAY_A
        );

        $language_usage = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT language, COUNT(*) AS total
                FROM {$sessions_table_name}
                WHERE last_seen_at BETWEEN %s AND %s
                  AND language <> ''
                GROUP BY language
                ORDER BY total DESC
                LIMIT 10",
                $start_datetime,
                $end_datetime
            ),
            ARRAY_A
        );

        $views = max(0, $event_counts['checkout_view']);
        $orders = max(0, $event_counts['order_success']);
        $view_to_order = $views > 0 ? round(($orders / $views) * 100, 1) : 0;
        $track_privileged_users = self::should_track_privileged_users();
        $recent_sessions = self::get_recent_sessions($start_datetime, $end_datetime);
        $selected_session_key = self::get_selected_session_key();
        $selected_session = $selected_session_key ? self::get_session_by_key($selected_session_key) : null;
        $selected_session_events = $selected_session_key ? self::get_session_events($selected_session_key, $start_datetime, $end_datetime) : array();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Checkout Diagnostics', 'checkout-diagnostics'); ?></h1>
            <p><?php esc_html_e('A lightweight view of checkout usage, validation pain points, and successful orders.', 'checkout-diagnostics'); ?></p>

            <?php if (!empty($_GET['settings-updated'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Checkout Diagnostics settings saved.', 'checkout-diagnostics'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($_GET['imported'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('Checkout diagnostics data imported successfully.', 'checkout-diagnostics'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($_GET['deleted'])) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e('All checkout diagnostics data was deleted.', 'checkout-diagnostics'); ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($_GET['import-error'])) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php esc_html_e('Import failed. Please upload a valid checkout diagnostics export file.', 'checkout-diagnostics'); ?></p>
                </div>
            <?php endif; ?>

            <form method="get" style="margin: 1rem 0 1.5rem;">
                <input type="hidden" name="page" value="checkout-diagnostics">
                <label for="checkout-diagnostics-start" style="display: inline-block; margin-right: 1rem;">
                    <?php esc_html_e('Start date', 'checkout-diagnostics'); ?><br>
                    <input id="checkout-diagnostics-start" type="date" name="start_date" value="<?php echo esc_attr($date_range['start']); ?>">
                </label>
                <label for="checkout-diagnostics-end" style="display: inline-block; margin-right: 1rem;">
                    <?php esc_html_e('End date', 'checkout-diagnostics'); ?><br>
                    <input id="checkout-diagnostics-end" type="date" name="end_date" value="<?php echo esc_attr($date_range['end']); ?>">
                </label>
                <button type="submit" class="button button-primary" style="margin-top: 1.3rem;">
                    <?php esc_html_e('Filter', 'checkout-diagnostics'); ?>
                </button>
            </form>

            <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom: 1.5rem;">
                <?php self::render_stat_card(__('Checkout views', 'checkout-diagnostics'), $views); ?>
                <?php self::render_stat_card(__('Validation failures', 'checkout-diagnostics'), $event_counts['validation_failed']); ?>
                <?php self::render_stat_card(__('Successful orders', 'checkout-diagnostics'), $orders); ?>
                <?php self::render_stat_card(__('View to order', 'checkout-diagnostics'), $view_to_order . '%'); ?>
            </div>

            <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));">
                <div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem;">
                    <h2 style="margin-top: 0;"><?php esc_html_e('Top validation errors', 'checkout-diagnostics'); ?></h2>
                    <?php self::render_validation_errors_table($top_errors); ?>
                </div>

                <div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem;">
                    <h2 style="margin-top: 0;"><?php esc_html_e('Shipping methods on successful orders', 'checkout-diagnostics'); ?></h2>
                    <?php self::render_usage_table($shipping_usage, 'shipping_method'); ?>
                </div>

                <div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem;">
                    <h2 style="margin-top: 0;"><?php esc_html_e('Payment methods on successful orders', 'checkout-diagnostics'); ?></h2>
                    <?php self::render_usage_table($payment_usage, 'payment_method'); ?>
                </div>
            </div>

            <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); margin-top: 1rem;">
                <div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem;">
                    <h2 style="margin-top: 0;"><?php esc_html_e('Device types on sessions', 'checkout-diagnostics'); ?></h2>
                    <?php self::render_usage_table($device_usage, 'device_type'); ?>
                </div>

                <div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem;">
                    <h2 style="margin-top: 0;"><?php esc_html_e('Operating systems on sessions', 'checkout-diagnostics'); ?></h2>
                    <?php self::render_usage_table($os_usage, 'os_name'); ?>
                </div>

                <div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem;">
                    <h2 style="margin-top: 0;"><?php esc_html_e('Languages on sessions', 'checkout-diagnostics'); ?></h2>
                    <?php self::render_usage_table($language_usage, 'language'); ?>
                </div>
            </div>

            <div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem; margin-top: 1.5rem;">
                <h2 style="margin-top: 0;"><?php esc_html_e('Recent sessions', 'checkout-diagnostics'); ?></h2>
                <?php self::render_sessions_table($recent_sessions, $date_range, $selected_session_key); ?>
            </div>

            <?php if ($selected_session) : ?>
                <div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem; margin-top: 1.5rem;">
                    <h2 style="margin-top: 0;"><?php esc_html_e('Session details', 'checkout-diagnostics'); ?></h2>
                    <?php self::render_session_details($selected_session, $selected_session_events); ?>
                </div>
            <?php endif; ?>

            <form method="post" style="margin: 1.5rem 0 0; background: #fff; border: 1px solid #dcdcde; padding: 1rem;">
                <h2 style="margin-top: 0;"><?php esc_html_e('Tracking settings', 'checkout-diagnostics'); ?></h2>
                <?php wp_nonce_field('checkout_diagnostics_settings'); ?>
                <label for="checkout-diagnostics-track-privileged-users">
                    <input
                        id="checkout-diagnostics-track-privileged-users"
                        type="checkbox"
                        name="track_privileged_users"
                        value="1"
                        <?php checked($track_privileged_users); ?>
                    >
                    <?php esc_html_e('Track admin and shop manager checkout activity', 'checkout-diagnostics'); ?>
                </label>
                <p style="margin: 0.5rem 0 1rem; color: #50575e;">
                    <?php esc_html_e('Useful for testing. You can turn this back off once the live checkout is verified.', 'checkout-diagnostics'); ?>
                </p>
                <button type="submit" name="checkout_diagnostics_settings_submit" value="1" class="button">
                    <?php esc_html_e('Save tracking setting', 'checkout-diagnostics'); ?>
                </button>
            </form>

            <div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); margin-top: 1.5rem;">
                <form method="post" style="background: #fff; border: 1px solid #dcdcde; padding: 1rem;">
                    <h2 style="margin-top: 0;"><?php esc_html_e('Export data', 'checkout-diagnostics'); ?></h2>
                    <p style="color: #50575e;">
                        <?php esc_html_e('Download all checkout diagnostics rows as a JSON file for moving data between environments.', 'checkout-diagnostics'); ?>
                    </p>
                    <?php wp_nonce_field('checkout_diagnostics_export'); ?>
                    <button type="submit" name="checkout_diagnostics_export_submit" value="1" class="button button-primary">
                        <?php esc_html_e('Export JSON', 'checkout-diagnostics'); ?>
                    </button>
                </form>

                <form method="post" enctype="multipart/form-data" style="background: #fff; border: 1px solid #dcdcde; padding: 1rem;">
                    <h2 style="margin-top: 0;"><?php esc_html_e('Import data', 'checkout-diagnostics'); ?></h2>
                    <p style="color: #50575e;">
                        <?php esc_html_e('Replace all current diagnostics data with an exported JSON file. This does a clean import and removes existing rows first.', 'checkout-diagnostics'); ?>
                    </p>
                    <?php wp_nonce_field('checkout_diagnostics_import'); ?>
                    <input type="file" name="checkout_diagnostics_import_file" accept="application/json" required>
                    <p style="margin-top: 1rem;">
                        <button type="submit" name="checkout_diagnostics_import_submit" value="1" class="button">
                            <?php esc_html_e('Import JSON', 'checkout-diagnostics'); ?>
                        </button>
                    </p>
                </form>
            </div>

            <form method="post" style="margin: 1.5rem 0 0; background: #fff; border: 1px solid #dcdcde; padding: 1rem;">
                <h2 style="margin-top: 0;"><?php esc_html_e('Danger zone', 'checkout-diagnostics'); ?></h2>
                <p style="color: #50575e;">
                    <?php esc_html_e('Delete all stored checkout diagnostics events and session summaries. This cannot be undone.', 'checkout-diagnostics'); ?>
                </p>
                <?php wp_nonce_field('checkout_diagnostics_delete_all'); ?>
                <button
                    type="submit"
                    name="checkout_diagnostics_delete_all_submit"
                    value="1"
                    class="button button-secondary"
                    onclick="return window.confirm('Delete all checkout diagnostics data?');"
                >
                    <?php esc_html_e('Delete all data', 'checkout-diagnostics'); ?>
                </button>
            </form>
        </div>
        <?php
    }

    /**
     * Render one summary stat card.
     *
     * @param string $label Stat label.
     * @param mixed  $value Stat value.
     * @return void
     */
    private static function render_stat_card($label, $value)
    {
        ?>
        <div style="background: #fff; border: 1px solid #dcdcde; padding: 1rem;">
            <div style="font-size: 0.875rem; color: #50575e; margin-bottom: 0.35rem;">
                <?php echo esc_html($label); ?>
            </div>
            <div style="font-size: 1.75rem; font-weight: 600; line-height: 1.1;">
                <?php echo esc_html((string) $value); ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the validation errors report table.
     *
     * @param array $rows Table rows.
     * @return void
     */
    private static function render_validation_errors_table($rows)
    {
        if (empty($rows)) {
            echo '<p>' . esc_html__('No validation errors found in this range.', 'checkout-diagnostics') . '</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Error code', 'checkout-diagnostics') . '</th><th>' . esc_html__('Field', 'checkout-diagnostics') . '</th><th>' . esc_html__('Count', 'checkout-diagnostics') . '</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row['error_code'] ? $row['error_code'] : '—') . '</td>';
            echo '<td>' . esc_html($row['field_key'] ? $row['field_key'] : '—') . '</td>';
            echo '<td>' . esc_html((string) (int) $row['total']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render a simple grouped usage table.
     *
     * @param array  $rows  Table rows.
     * @param string $field Field key.
     * @return void
     */
    private static function render_usage_table($rows, $field)
    {
        if (empty($rows)) {
            echo '<p>' . esc_html__('No data found in this range.', 'checkout-diagnostics') . '</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Value', 'checkout-diagnostics') . '</th><th>' . esc_html__('Count', 'checkout-diagnostics') . '</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($row[$field]) . '</td>';
            echo '<td>' . esc_html((string) (int) $row['total']) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render the recent sessions table.
     *
     * @param array  $rows                 Session rows.
     * @param array  $date_range           Current date range.
     * @param string $selected_session_key Selected session key.
     * @return void
     */
    private static function render_sessions_table($rows, $date_range, $selected_session_key)
    {
        if (empty($rows)) {
            echo '<p>' . esc_html__('No sessions found in this range.', 'checkout-diagnostics') . '</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Last seen', 'checkout-diagnostics') . '</th>';
        echo '<th>' . esc_html__('Session', 'checkout-diagnostics') . '</th>';
        echo '<th>' . esc_html__('Name', 'checkout-diagnostics') . '</th>';
        echo '<th>' . esc_html__('Views', 'checkout-diagnostics') . '</th>';
        echo '<th>' . esc_html__('Refreshes', 'checkout-diagnostics') . '</th>';
        echo '<th>' . esc_html__('Clicks', 'checkout-diagnostics') . '</th>';
        echo '<th>' . esc_html__('Failures', 'checkout-diagnostics') . '</th>';
        echo '<th>' . esc_html__('Errors', 'checkout-diagnostics') . '</th>';
        echo '<th>' . esc_html__('Orders', 'checkout-diagnostics') . '</th>';
        echo '<th>' . esc_html__('Shipping', 'checkout-diagnostics') . '</th>';
        echo '<th>' . esc_html__('Payment', 'checkout-diagnostics') . '</th>';
        echo '<th>' . esc_html__('Status', 'checkout-diagnostics') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $session_key = self::sanitize_session_key($row['session_key']);
            $details_url = add_query_arg(
                array(
                    'page' => 'checkout-diagnostics',
                    'start_date' => $date_range['start'],
                    'end_date' => $date_range['end'],
                    'session_key' => $session_key,
                ),
                admin_url('admin.php')
            );
            $row_style = $session_key === $selected_session_key ? ' style="background: #f0f6fc;"' : '';

            echo '<tr' . $row_style . '>';
            echo '<td>' . esc_html(wp_date('Y-m-d H:i', strtotime($row['last_seen_at']))) . '</td>';
            echo '<td><a href="' . esc_url($details_url) . '"><code>' . esc_html($session_key) . '</code></a></td>';
            echo '<td>' . esc_html($row['first_name'] ? $row['first_name'] : '—') . '</td>';
            echo '<td>' . esc_html((string) (int) $row['view_count']) . '</td>';
            echo '<td>' . esc_html((string) (int) $row['refresh_count']) . '</td>';
            echo '<td>' . esc_html((string) (int) $row['place_order_clicks']) . '</td>';
            echo '<td>' . esc_html((string) (int) $row['validation_failures']) . '</td>';
            echo '<td>' . esc_html((string) (int) $row['validation_errors']) . '</td>';
            echo '<td>' . esc_html((string) (int) $row['successful_orders']) . '</td>';
            echo '<td>' . esc_html($row['shipping_method'] ? $row['shipping_method'] : '—') . '</td>';
            echo '<td>' . esc_html($row['payment_method'] ? $row['payment_method'] : '—') . '</td>';
            echo '<td>' . esc_html(self::get_session_status_label($row)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render one selected session with its counts and event history.
     *
     * @param array $session Session row.
     * @param array $events  Session events.
     * @return void
     */
    private static function render_session_details($session, $events)
    {
        echo '<div style="display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom: 1rem;">';
        self::render_stat_card(__('Views', 'checkout-diagnostics'), (int) $session['view_count']);
        self::render_stat_card(__('Refreshes', 'checkout-diagnostics'), (int) $session['refresh_count']);
        self::render_stat_card(__('Clicks', 'checkout-diagnostics'), (int) $session['place_order_clicks']);
        self::render_stat_card(__('Validation failures', 'checkout-diagnostics'), (int) $session['validation_failures']);
        self::render_stat_card(__('Validation errors', 'checkout-diagnostics'), (int) $session['validation_errors']);
        self::render_stat_card(__('Successful orders', 'checkout-diagnostics'), (int) $session['successful_orders']);
        echo '</div>';

        echo '<p><strong>' . esc_html__('Session', 'checkout-diagnostics') . ':</strong> <code>' . esc_html($session['session_key']) . '</code></p>';
        echo '<p><strong>' . esc_html__('Status', 'checkout-diagnostics') . ':</strong> ' . esc_html(self::get_session_status_label($session)) . '</p>';
        echo '<p><strong>' . esc_html__('First name', 'checkout-diagnostics') . ':</strong> ' . esc_html($session['first_name'] ? $session['first_name'] : '—') . '</p>';
        echo '<p><strong>' . esc_html__('IP address', 'checkout-diagnostics') . ':</strong> ' . esc_html($session['ip_address'] ? $session['ip_address'] : '—') . '</p>';
        echo '<p><strong>' . esc_html__('Device type', 'checkout-diagnostics') . ':</strong> ' . esc_html($session['device_type'] ? $session['device_type'] : '—') . '</p>';
        echo '<p><strong>' . esc_html__('Operating system', 'checkout-diagnostics') . ':</strong> ' . esc_html($session['os_name'] ? $session['os_name'] : '—') . '</p>';
        echo '<p><strong>' . esc_html__('Language', 'checkout-diagnostics') . ':</strong> ' . esc_html($session['language'] ? $session['language'] : '—') . '</p>';
        echo '<p><strong>' . esc_html__('Shipping', 'checkout-diagnostics') . ':</strong> ' . esc_html($session['shipping_method'] ? $session['shipping_method'] : '—') . '</p>';
        echo '<p><strong>' . esc_html__('Payment', 'checkout-diagnostics') . ':</strong> ' . esc_html($session['payment_method'] ? $session['payment_method'] : '—') . '</p>';
        echo '<p><strong>' . esc_html__('Cart total', 'checkout-diagnostics') . ':</strong> ' . esc_html($session['cart_total'] ? $session['cart_total'] : '—') . '</p>';
        echo '<p><strong>' . esc_html__('Last error', 'checkout-diagnostics') . ':</strong> ' . esc_html($session['last_error_message'] ? $session['last_error_message'] : '—') . '</p>';

        self::render_session_cart_items($session);

        if (empty($events)) {
            echo '<p>' . esc_html__('No events found for this session in the selected range.', 'checkout-diagnostics') . '</p>';
            return;
        }

        echo '<h3>' . esc_html__('Event history', 'checkout-diagnostics') . '</h3>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Time', 'checkout-diagnostics') . '</th>';
        echo '<th>' . esc_html__('Event', 'checkout-diagnostics') . '</th>';
        echo '<th>' . esc_html__('Shipping', 'checkout-diagnostics') . '</th>';
        echo '<th>' . esc_html__('Payment', 'checkout-diagnostics') . '</th>';
        echo '<th>' . esc_html__('Message', 'checkout-diagnostics') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($events as $event) {
            $meta = self::decode_event_data($event['meta']);
            $message = isset($meta['message']) ? $meta['message'] : '';

            echo '<tr>';
            echo '<td>' . esc_html(wp_date('Y-m-d H:i:s', strtotime($event['created_at']))) . '</td>';
            $event_label = esc_html($event['event_type']);

            if ('place_order_click' === $event['event_type']) {
                $event_label = '<strong>' . $event_label . '</strong>';
            }

            echo '<td>' . $event_label . '</td>';
            echo '<td>' . esc_html($event['shipping_method'] ? $event['shipping_method'] : '—') . '</td>';
            echo '<td>' . esc_html($event['payment_method'] ? $event['payment_method'] : '—') . '</td>';
            echo '<td>' . esc_html($message ? $message : '—') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render the latest cart snapshot stored on the session summary.
     *
     * @param array $session Session row.
     * @return void
     */
    private static function render_session_cart_items($session)
    {
        $cart_items = array();

        if (!empty($session['cart_items'])) {
            $cart_items = self::decode_event_data($session['cart_items']);
        }

        if (empty($cart_items) || !is_array($cart_items)) {
            return;
        }

        echo '<h3>' . esc_html__('Latest cart snapshot', 'checkout-diagnostics') . '</h3>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Product', 'checkout-diagnostics') . '</th>';
        echo '<th>' . esc_html__('Qty', 'checkout-diagnostics') . '</th>';
        echo '<th>' . esc_html__('Line total', 'checkout-diagnostics') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($cart_items as $item) {
            if (!is_array($item)) {
                continue;
            }

            echo '<tr>';
            echo '<td>' . esc_html(!empty($item['product_name']) ? $item['product_name'] : '—') . '</td>';
            echo '<td>' . esc_html(isset($item['quantity']) ? (string) (int) $item['quantity'] : '0') . '</td>';
            echo '<td>' . esc_html(!empty($item['line_total']) ? $item['line_total'] : '—') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Get the admin report date range or default to the current month.
     *
     * @return array
     */
    private static function get_requested_date_range()
    {
        $today = wp_date('Y-m-d');
        $month_start = wp_date('Y-m-01');

        $start = isset($_GET['start_date']) ? sanitize_text_field(wp_unslash($_GET['start_date'])) : $month_start;
        $end = isset($_GET['end_date']) ? sanitize_text_field(wp_unslash($_GET['end_date'])) : $today;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start)) {
            $start = $month_start;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            $end = $today;
        }

        if ($start > $end) {
            $start = $month_start;
            $end = $today;
        }

        return array(
            'start' => $start,
            'end'   => $end,
        );
    }

    /**
     * Get distinct non-empty session keys for one event type in the selected range.
     *
     * @param string $event_type      Event type.
     * @param string $start_datetime  Range start.
     * @param string $end_datetime    Range end.
     * @return array
     */
    private static function get_distinct_sessions_for_event($event_type, $start_datetime, $end_datetime)
    {
        global $wpdb;

        $sessions = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT session_key
                FROM " . self::table_name() . "
                WHERE event_type = %s
                  AND session_key <> ''
                  AND created_at BETWEEN %s AND %s",
                sanitize_key($event_type),
                $start_datetime,
                $end_datetime
            )
        );

        return array_values(array_filter(array_map(array(__CLASS__, 'sanitize_session_key'), (array) $sessions)));
    }

    /**
     * Get recent session summaries for the current report range.
     *
     * @param string $start_datetime Range start.
     * @param string $end_datetime   Range end.
     * @return array
     */
    private static function get_recent_sessions($start_datetime, $end_datetime)
    {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT *
                FROM " . self::sessions_table_name() . "
                WHERE last_seen_at BETWEEN %s AND %s
                ORDER BY last_seen_at DESC
                LIMIT 50",
                $start_datetime,
                $end_datetime
            ),
            ARRAY_A
        );
    }

    /**
     * Read the selected session key from the report query string.
     *
     * @return string
     */
    private static function get_selected_session_key()
    {
        return isset($_GET['session_key']) ? self::sanitize_session_key(wp_unslash($_GET['session_key'])) : '';
    }

    /**
     * Fetch one session summary row by session key.
     *
     * @param string $session_key Session key.
     * @return array|null
     */
    private static function get_session_by_key($session_key)
    {
        global $wpdb;

        if (!$session_key) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT *
                FROM " . self::sessions_table_name() . "
                WHERE session_key = %s
                LIMIT 1",
                $session_key
            ),
            ARRAY_A
        );

        return is_array($row) ? $row : null;
    }

    /**
     * Fetch all events for one session in the selected date range.
     *
     * @param string $session_key     Session key.
     * @param string $start_datetime  Range start.
     * @param string $end_datetime    Range end.
     * @return array
     */
    private static function get_session_events($session_key, $start_datetime, $end_datetime)
    {
        global $wpdb;

        if (!$session_key) {
            return array();
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT created_at, event_type, shipping_method, payment_method, meta
                FROM " . self::table_name() . "
                WHERE session_key = %s
                  AND created_at BETWEEN %s AND %s
                ORDER BY created_at ASC",
                $session_key,
                $start_datetime,
                $end_datetime
            ),
            ARRAY_A
        );
    }

    private static function redirect_with_admin_flag($key, $value)
    {
        $redirect_url = add_query_arg(
            array(
                'page' => 'checkout-diagnostics',
                $key   => $value,
            ),
            admin_url('admin.php')
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    private static function get_session_status_label($row)
    {
        if (!empty($row['successful_orders'])) {
            return __('Converted', 'checkout-diagnostics');
        }

        $last_seen = !empty($row['last_seen_at']) ? strtotime($row['last_seen_at']) : 0;
        $is_old = $last_seen && $last_seen < (time() - HOUR_IN_SECONDS);

        if ($is_old) {
            return __('Dropped off', 'checkout-diagnostics');
        }

        if (!empty($row['validation_failures']) || !empty($row['validation_errors'])) {
            return __('Validation failed', 'checkout-diagnostics');
        }

        if (!empty($row['place_order_clicks'])) {
            return __('Clicked place order', 'checkout-diagnostics');
        }

        return __('Viewed checkout', 'checkout-diagnostics');
    }
}
