<?php
/**
 * Plugin Name: MM Promo Pints
 * Description: Campaign-based signup and single-use redemption flow for promotions (email claim link, staff redeem button, optional QR scan).
 * Version: 1.0.1
 * Author: Meridian Media
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('MMPP_VERSION', '1.0.1');
define('MMPP_SLUG', 'mm-promo-pints');
define('MMPP_PATH', plugin_dir_path(__FILE__));
define('MMPP_URL', plugin_dir_url(__FILE__));

define('MMPP_TABLE_CAMPAIGNS', 'mmpp_campaigns');
define('MMPP_TABLE_ENTRIES', 'mmpp_entries');

require_once MMPP_PATH . 'includes/class-mmpp-db.php';
require_once MMPP_PATH . 'includes/class-mmpp-admin.php';
require_once MMPP_PATH . 'includes/class-mmpp-rest.php';
require_once MMPP_PATH . 'includes/class-mmpp-shortcodes.php';

register_activation_hook(__FILE__, ['MMPP_DB', 'activate']);

add_action('plugins_loaded', function () {
  MMPP_DB::init();
  MMPP_Admin::init();
  MMPP_Rest::init();
  MMPP_Shortcodes::init();
});

add_action('wp_enqueue_scripts', function () {
  wp_register_style('mmpp-front', MMPP_URL . 'assets/front.css', [], MMPP_VERSION);
  wp_register_script('mmpp-front', MMPP_URL . 'assets/front.js', [], MMPP_VERSION, true);

  wp_localize_script('mmpp-front', 'MMPP', [
    'restUrl' => esc_url_raw(rest_url('mmpp/v1')),
    'nonce'   => wp_create_nonce('wp_rest'),
  ]);
});

add_action('admin_enqueue_scripts', function ($hook) {
  if (strpos($hook, 'mmpp') === false) return;
  wp_enqueue_style('mmpp-admin', MMPP_URL . 'assets/admin.css', [], MMPP_VERSION);
});
