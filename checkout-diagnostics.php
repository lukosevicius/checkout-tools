<?php
/**
 * Plugin Name: Checkout Diagnostics
 * Description: Tracks checkout views, clicks, validation failures, and successful orders for the classic WooCommerce checkout.
 * Version: 0.2.5
 * Author: Codex
 * Text Domain: checkout-diagnostics
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CHECKOUT_DIAGNOSTICS_PLUGIN_FILE')) {
    define('CHECKOUT_DIAGNOSTICS_PLUGIN_FILE', __FILE__);
}

require_once plugin_dir_path(__FILE__) . 'includes/traits/trait-checkout-diagnostics-schema.php';
require_once plugin_dir_path(__FILE__) . 'includes/traits/trait-checkout-diagnostics-storage.php';
require_once plugin_dir_path(__FILE__) . 'includes/traits/trait-checkout-diagnostics-tracking.php';
require_once plugin_dir_path(__FILE__) . 'includes/traits/trait-checkout-diagnostics-admin.php';

final class Checkout_Diagnostics_Plugin
{
    use Checkout_Diagnostics_Schema_Trait;
    use Checkout_Diagnostics_Storage_Trait;
    use Checkout_Diagnostics_Tracking_Trait;
    use Checkout_Diagnostics_Admin_Trait;

    const VERSION = '0.2.5';
    const AJAX_ACTION = 'checkout_diagnostics_track';
    const SESSION_FIELD = 'checkout_diagnostics_session_key';
    const OPTION_TRACK_PRIVILEGED_USERS = 'checkout_diagnostics_track_privileged_users';
    const OPTION_DB_VERSION = 'checkout_diagnostics_db_version';
    private static $active_request_session_key = '';

    /**
     * Register plugin hooks.
     *
     * @return void
     */
    public static function init()
    {
        register_activation_hook(__FILE__, array(__CLASS__, 'activate'));

        add_action('plugins_loaded', array(__CLASS__, 'bootstrap'));
    }

    /**
     * Create the custom events table on activation.
     *
     * @return void
     */
    public static function activate()
    {
        self::ensure_database_schema(true);
    }

    /**
     * Attach runtime hooks.
     *
     * @return void
     */
    public static function bootstrap()
    {
        self::ensure_database_schema();

        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_checkout_assets'));
        add_action('woocommerce_checkout_before_order_review_heading', array(__CLASS__, 'render_session_field'));
        add_action('wp_ajax_' . self::AJAX_ACTION, array(__CLASS__, 'handle_track_event'));
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION, array(__CLASS__, 'handle_track_event'));
        add_action('woocommerce_checkout_process', array(__CLASS__, 'log_submit_attempt'));
        add_action('woocommerce_after_checkout_validation', array(__CLASS__, 'log_validation_results'), 20, 2);
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'log_order_success'), 10, 3);
        add_action('admin_menu', array(__CLASS__, 'register_admin_page'));
        add_action('admin_init', array(__CLASS__, 'handle_admin_actions'));
    }
}

Checkout_Diagnostics_Plugin::init();
