<?php
/**
 * Plugin Name: Welcart Tiered Discounts
 * Description: 登録販売価格に応じたステップ割引と、注文時の条件を保持した受注編集。
 * Version: 1.0.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: Welcart Tiered Discounts contributors
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: welcart-tiered-discounts
 *
 * @package Welcart_Tiered_Discounts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WTD_FILE', __FILE__ );
define( 'WTD_VERSION', '1.0.0' );

require_once __DIR__ . '/includes/discount.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/welcart.php';
require_once __DIR__ . '/includes/orders.php';

/** Register after Welcart has loaded, independently of plugin ordering. */
function wtd_load() {
	wtd_settings_hooks();
	if ( ! class_exists( 'usc_e_shop' ) || ! isset( $GLOBALS['usces'] ) ) {
		add_action( 'admin_notices', 'wtd_dependency_notice' );
		return;
	}
	wtd_welcart_hooks();
}
add_action( 'plugins_loaded', 'wtd_load', 20 );

/** Explain a missing dependency without affecting the rest of WordPress. */
function wtd_dependency_notice() {
	if ( current_user_can( 'activate_plugins' ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html__( 'ステップ割引を利用するには Welcart を有効にしてください。', 'welcart-tiered-discounts' ) . '</p></div>';
	}
}
