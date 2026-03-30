<?php
/**
 * Plugin Name: AI Publisher Bridge
 * Plugin URI:  https://www.taterli.com
 * Description: 使用AI自动发布/管理你的小破站,需配合apb-worker工作.
 * Version:     1.0.0
 * Author:      TaterLi
 * License:     GPL-2.0+
 * Text Domain: ai-publisher-bridge
 * Domain Path: /languages
 *
 * Requires at least: 6.0
 * Requires PHP: 8.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'APB_VERSION', '1.0.0' );
define( 'APB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'APB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'APB_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'APB_OPTION_KEY', 'apb_settings' );
define( 'APB_TABLE_JOBS', 'apb_jobs' );
define( 'APB_TABLE_USAGE', 'apb_usage_logs' );
define( 'APB_REST_NAMESPACE', 'apb/v1' );

require_once APB_PLUGIN_DIR . 'includes/class-apb-activator.php';
require_once APB_PLUGIN_DIR . 'includes/class-apb-job-repository.php';
require_once APB_PLUGIN_DIR . 'includes/class-apb-post-publisher.php';
require_once APB_PLUGIN_DIR . 'includes/class-apb-usage-repository.php';
require_once APB_PLUGIN_DIR . 'includes/class-apb-admin.php';
require_once APB_PLUGIN_DIR . 'includes/class-apb-rest.php';
require_once APB_PLUGIN_DIR . 'includes/class-apb-plugin.php';

register_activation_hook( __FILE__, array( 'APB_Activator', 'activate' ) );

APB_Plugin::get_instance();
