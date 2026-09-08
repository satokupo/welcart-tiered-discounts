<?php
/** Local integration fixtures; never distributed with the plugin. */
if ( '1' !== getenv( 'WTD_TEST_MODE' ) || ! defined( 'ABSPATH' ) ) {
	return;
}

/*
 * Failure injection is deliberately narrower than a general query filter.
 * It is available only in the local test runtime, for an authenticated order
 * administrator saving the dedicated order, and only when the test names a
 * single SQL target through the explicit request header.
 */
$wtd_test_saved_order_id = (int) get_option( 'wtd_test_saved_order_id', 0 );
$wtd_test_saved_cart_ids = array();
if ( 0 < $wtd_test_saved_order_id ) {
	global $wpdb;
	$wtd_test_saved_cart_ids = array_map(
		'intval',
		(array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT cart_id FROM {$wpdb->prefix}usces_ordercart WHERE order_id = %d",
				$wtd_test_saved_order_id
			)
		)
	);
}

add_action(
	'admin_init',
	static function () use ( $wtd_test_saved_order_id, $wtd_test_saved_cart_ids ) {
		if ( 0 >= $wtd_test_saved_order_id || $wtd_test_saved_order_id !== absint( $_REQUEST['order_id'] ?? 0 ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'usces_orderlist' !== ( $_REQUEST['page'] ?? '' ) || 'editpost' !== ( $_REQUEST['order_action'] ?? '' ) || ! current_user_can( 'wel_manage_order' ) ) {
			return;
		}
		$target = isset( $_SERVER['HTTP_X_WTD_TEST_FAIL_SQL'] ) ? strtolower( trim( (string) $_SERVER['HTTP_X_WTD_TEST_FAIL_SQL'] ) ) : '';
		if ( ! in_array( $target, array( 'order', 'cart', 'tax', 'history' ), true ) ) {
			return;
		}
		add_filter(
			'query',
			static function ( $query ) use ( $target, $wtd_test_saved_order_id, $wtd_test_saved_cart_ids ) {
				static $injected = false;

				if ( $injected || ! preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $query ) ) {
					return $query;
				}

				global $wpdb;
				$order_table = preg_quote( $wpdb->prefix . 'usces_order', '/' );
				$cart_table  = preg_quote( $wpdb->prefix . 'usces_ordercart', '/' );
				$meta_table  = preg_quote( $wpdb->prefix . 'usces_order_meta', '/' );
				$matches     = false;
				if ( 'order' === $target ) {
					$order_id_pattern = preg_quote( (string) $wtd_test_saved_order_id, '/' );
					$matches          = (bool) preg_match( '/\b' . $order_table . '\b/is', $query ) && (bool) preg_match( '/`?ID`?\s*=\s*' . $order_id_pattern . '\b/is', $query );
				} elseif ( 'cart' === $target ) {
					$matches = (bool) preg_match( '/\b' . $cart_table . '\b/i', $query );
					if ( $matches && preg_match( '/`?cart_id`?\s*=\s*(\d+)/i', $query, $cart_match ) ) {
						$matches = in_array( (int) $cart_match[1], $wtd_test_saved_cart_ids, true );
					}
				} elseif ( 'tax' === $target ) {
					$matches = (bool) preg_match( '/\b' . $meta_table . '\b/i', $query ) && (bool) preg_match( '/\b(?:tax_standard|tax_reduced)\b/i', $query ) && (bool) preg_match( '/\b' . preg_quote( (string) $wtd_test_saved_order_id, '/' ) . '\b/i', $query );
				} elseif ( 'history' === $target ) {
					$matches = (bool) preg_match( '/\b' . $meta_table . '\b/i', $query ) && (bool) preg_match( '/\bwtd_discount_history\b/i', $query ) && (bool) preg_match( '/\b' . preg_quote( (string) $wtd_test_saved_order_id, '/' ) . '\b/i', $query );
				}
				if ( ! $matches ) {
					return $query;
				}

				$injected = true;
				$GLOBALS['wtd_test_failure_injected'] = $target;
				return 'INSERT INTO wp_wtd_test_sql_failure__missing__ (id) VALUES (1)';
			},
			1
		);
	},
	9999
);

/*
 * Inject one late checkout write failure after native order, stock and point
 * writes have run. The header is accepted only on the final purchase POST.
 */
$wtd_test_checkout_failure = isset( $_SERVER['HTTP_X_WTD_TEST_FAIL_CHECKOUT_SQL'] ) ? strtolower( trim( (string) $_SERVER['HTTP_X_WTD_TEST_FAIL_CHECKOUT_SQL'] ) ) : '';
if ( 'snapshot' === $wtd_test_checkout_failure && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['purchase'] ) && '4' === (string) ( $_REQUEST['page_id'] ?? '' ) ) {
	add_filter(
		'query',
		static function ( $query ) {
			static $injected = false;
			global $wpdb;
			$meta_table = preg_quote( $wpdb->prefix . 'usces_order_meta', '/' );
			if ( $injected || ! preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $query ) || ! preg_match( '/\b' . $meta_table . '\b/i', $query ) || ! preg_match( '/\bwtd_discount_snapshot\b/i', $query ) ) {
				return $query;
			}
			$injected = true;
			$GLOBALS['wtd_test_failure_injected'] = 'checkout_snapshot';
			return 'INSERT INTO wp_wtd_test_sql_failure__missing__ (id) VALUES (1)';
		},
		1
	);
}

add_filter( 'wp_mail', function ( $attributes ) {
    // WP 5.6 lacks pre_wp_mail; capture before the local sendmail sink discards delivery.
    $messages = get_option( 'wtd_test_mail', array() );
    $messages[] = $attributes;
    update_option( 'wtd_test_mail', $messages, false );
    return $attributes;
}, 1 );
add_filter( 'pre_wp_mail', '__return_true', 1 );
