<?php
/**
 * Local integration-test data setup.
 *
 * This file is mounted only into the local integration-test environment. It
 * deliberately uses WordPress and Welcart APIs so that the fixture follows the
 * same data model as a product created through the Welcart admin screen.
 *
 * @package Welcart_Tiered_Discounts
 */

if ( '1' !== getenv( 'WTD_TEST_MODE' ) || ! defined( 'ABSPATH' ) ) {
	return;
}

if ( ! function_exists( 'wp_insert_post' ) || ! function_exists( 'wel_update_item_data' ) || ! function_exists( 'wel_add_sku_data' ) ) {
	throw new RuntimeException( 'WordPress and Welcart must be loaded before the test fixture.' );
}

/**
 * Create or repair one deterministic Welcart test product.
 *
 * @param array $definition Product definition.
 * @return int Product post ID.
 */
function wtd_test_ensure_product( array $definition ) {
	$post_id = 0;

	if ( function_exists( 'wel_get_id_by_item_code' ) ) {
		$post_id = (int) wel_get_id_by_item_code( $definition['item_code'], false );
	}

	if ( 0 === $post_id ) {
		$matches = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_itemCode',
				'meta_value'     => $definition['item_code'],
			)
		);
		if ( ! empty( $matches ) ) {
			$post_id = (int) reset( $matches );
		}
	}

	if ( 0 === $post_id ) {
		$post_id = wp_insert_post(
			array(
				'post_author'    => 1,
				'post_content'   => 'Welcart Tiered Discounts integration fixture.',
				'post_excerpt'   => '',
				'post_name'      => sanitize_title( $definition['item_code'] ),
				'post_status'    => 'publish',
				'post_title'     => $definition['title'],
				'post_type'      => 'post',
				'post_mime_type' => 'item',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			throw new RuntimeException( 'Unable to create Welcart fixture product: ' . $post_id->get_error_code() );
		}
		$post_id = (int) $post_id;
	} else {
		$updated = wp_update_post(
			array(
				'ID'            => $post_id,
				'post_status'   => 'publish',
				'post_title'    => $definition['title'],
				'post_mime_type' => 'item',
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			throw new RuntimeException( 'Unable to update Welcart fixture product: ' . $updated->get_error_code() );
		}
	}

	$item_data = array(
		'itemCode'              => $definition['item_code'],
		'itemName'              => $definition['title'],
		'itemPointrate'         => 0,
		'itemOrderAcceptable'   => 0,
		'itemShipping'          => 0,
		'itemDeliveryMethod'    => array( '0' ),
		'itemShippingCharge'    => 0,
		'itemIndividualSCharge' => 0,
		'item_charging_type'    => 0,
		'item_division'         => 'shipped',
	);
	if ( false === wel_update_item_data( $item_data, $post_id ) ) {
		throw new RuntimeException( 'Unable to update Welcart fixture item data.' );
	}

	$sku = function_exists( 'wel_get_sku' ) ? wel_get_sku( $post_id, $definition['sku_code'], false ) : false;
	$sku_data = array(
		'code'     => $definition['sku_code'],
		'name'     => '',
		'cprice'   => $definition['list_price'],
		'price'    => $definition['price'],
		'stocknum' => '99',
		'stock'    => '0',
		'unit'     => '個',
		'gp'       => '0',
		'taxrate'  => $definition['taxrate'],
		'advance'  => array(),
	);

	if ( is_array( $sku ) && ! empty( $sku['meta_id'] ) && function_exists( 'wel_update_sku_data_by_id' ) ) {
		$sku_data['meta_id'] = $sku['meta_id'];
		if ( false === wel_update_sku_data_by_id( $sku['meta_id'], $post_id, $sku_data ) ) {
			throw new RuntimeException( 'Unable to update Welcart fixture SKU data.' );
		}
	} elseif ( ! wel_add_sku_data( $post_id, $sku_data ) ) {
		throw new RuntimeException( 'Unable to create Welcart fixture SKU data.' );
	}

	if ( function_exists( 'term_exists' ) && function_exists( 'wp_set_object_terms' ) && term_exists( 'item', 'category' ) ) {
		wp_set_object_terms( $post_id, array( 'item' ), 'category' );
	}

	return $post_id;
}

/**
 * Ensure the local shop has deterministic options for checkout tests.
 *
 * @return void
 */
function wtd_test_configure_shop() {
	$usces_options = get_option( 'usces', array() );
	if ( ! is_array( $usces_options ) ) {
		$usces_options = array();
	}

	$usces_options['delivery_method'] = array(
		array(
			'id'            => 0,
			'name'          => 'WTD Test Delivery',
			'time'          => '',
			'charge'        => 0,
			'days'          => -1,
			'nocod'         => '0',
			'intl'          => '0',
			'cool_category' => 0,
		),
	);
	$shipping_by_prefecture = array();
	$jp_prefectures        = isset( $GLOBALS['usces_states']['JP'] ) && is_array( $GLOBALS['usces_states']['JP'] ) ? $GLOBALS['usces_states']['JP'] : array();
	foreach ( $jp_prefectures as $index => $prefecture ) {
		if ( 0 !== (int) $index && '' !== $prefecture ) {
			$shipping_by_prefecture[ $prefecture ] = 0;
		}
	}
	$usces_options['shipping_charge'] = array(
		array(
			'id'   => 0,
			'name' => 'WTD Test Shipping',
			'JP'   => $shipping_by_prefecture,
		),
	);
	$usces_options['membersystem_state'] = 'activate';
	$usces_options['membersystem_point'] = 'activate';
	$usces_options['point_coverage']     = 1;
	$usces_options['point_assign']       = 1;
	$usces_options['tax_display']        = 'activate';
	$usces_options['applicable_taxrate'] = 'standard';
	$usces_options['tax_rate']           = '10';
	$usces_options['tax_rate_reduced']   = '8';
	$usces_options['tax_method']         = 'cutting';
	$usces_options['tax_mode']           = 'include';
	$usces_options['tax_target']         = 'products';
	$usces_options['system']             = isset( $usces_options['system'] ) && is_array( $usces_options['system'] ) ? $usces_options['system'] : array();
	$usces_options['system']['front_lang']    = 'ja';
	$usces_options['system']['currency']      = 'JP';
	$usces_options['system']['addressform']   = 'JP';
	$usces_options['system']['target_market'] = array( 'JP' );
	$usces_options['system']['base_country']  = 'JP';
	$usces_options['province']['JP']          = $jp_prefectures;
	update_option( 'usces', $usces_options, false );
	update_option( 'WPLANG', 'ja', false );
	update_option( 'timezone_string', 'Asia/Tokyo', false );
	update_option( 'usces_currency_symbol', '¥', false );

	$payment = get_option( 'usces_payment_method', array() );
	if ( ! is_array( $payment ) ) {
		$payment = array();
	}
	$payment_id = null;
	foreach ( $payment as $id => $method ) {
		if ( isset( $method['name'] ) && 'WTD Test Bank Transfer' === $method['name'] ) {
			$payment_id = $id;
			break;
		}
	}
	if ( null === $payment_id ) {
		$payment_id = 0;
		while ( array_key_exists( $payment_id, $payment ) ) {
			$payment_id++;
		}
	}
	$payment_sort = 0;
	foreach ( $payment as $method ) {
		if ( isset( $method['sort'] ) ) {
			$payment_sort = max( $payment_sort, (int) $method['sort'] + 1 );
		}
	}
	$payment[ $payment_id ] = array(
		'name'        => 'WTD Test Bank Transfer',
		'explanation' => 'Offline payment for local integration tests.',
		'settlement'  => 'transferAdvance',
		'module'      => '',
		'sort'        => $payment_sort,
		'use'         => 'activate',
	);
	update_option( 'usces_payment_method', $payment, false );

	global $usces;
	if ( isset( $usces ) && is_object( $usces ) && property_exists( $usces, 'options' ) ) {
		$usces->options = $usces_options;
	}
}

/**
 * Create or reset one native Welcart member for HTTP checkout tests.
 *
 * @param string $email Member email.
 * @param string $password Member password.
 * @param int    $point Point balance to expose to the checkout.
 * @return array{ID:int,email:string,password:string,point:int}
 * @throws RuntimeException When the native member API cannot create or update the member.
 */
function wtd_test_ensure_member( $email, $password, $point = 10000 ) {
	global $usces;

	if ( ! is_object( $usces ) || ! method_exists( $usces, 'get_memberid_by_email' ) || ! method_exists( $usces, 'set_member_info' ) || ! function_exists( 'usces_new_memberdata' ) ) {
		throw new RuntimeException( 'Welcart member APIs are unavailable.' );
	}

	$member_id = (int) $usces->get_memberid_by_email( $email );
	if ( 0 === $member_id ) {
		$old_post    = $_POST;
		$old_request = $_REQUEST;
		$result      = false;
		try {
			$_POST = array(
				'member' => array(
					'email'    => $email,
					'password' => $password,
					'status'   => 0,
					'point'    => 0,
					'name1'    => 'HTTP',
					'name2'    => 'ポイント',
					'name3'    => 'エイチティーティーピー',
					'name4'    => 'ポイント',
					'zipcode'  => '1000001',
					'pref'     => '東京都',
					'address1' => '千代田区',
					'address2' => '1-1',
					'address3' => 'テスト',
					'tel'     => '0312345678',
					'fax'     => '',
					'country' => 'JP',
				),
			);
			$result = usces_new_memberdata();
			$member_id = isset( $_REQUEST['member_id'] ) ? (int) $_REQUEST['member_id'] : 0;
		} finally {
			$_POST    = $old_post;
			$_REQUEST = $old_request;
		}

		if ( 1 !== $result || 0 === $member_id ) {
			throw new RuntimeException( 'Unable to create the integration-test Welcart member.' );
		}
	}

	if ( false === $usces->set_member_info( array( 'mem_point' => (int) $point ), $member_id ) ) {
		throw new RuntimeException( 'Unable to reset the integration-test member point balance.' );
	}

	return array(
		'ID'       => $member_id,
		'email'    => $email,
		'password' => $password,
		'point'    => (int) $point,
	);
}

/**
 * Create all data needed by the local integration tests.
 *
 * @return array{products:array{standard:int,six:int,reduced:int},subscriber:int}
 */
function wtd_test_setup() {
	if ( '1' !== getenv( 'WTD_TEST_MODE' ) || ! defined( 'ABSPATH' ) ) {
		throw new RuntimeException( 'WTD_TEST_MODE=1 and a loaded WordPress installation are required.' );
	}
	if ( ! function_exists( 'wp_create_user' ) || ! function_exists( 'wp_update_user' ) ) {
		throw new RuntimeException( 'WordPress user APIs are unavailable.' );
	}

	wtd_test_configure_shop();
	$products = array(
		'standard' => wtd_test_ensure_product(
			array(
				'item_code' => 'WTD-TEST-STANDARD-10000',
				'sku_code'  => 'wtd-standard',
				'title'     => 'WTD Test Standard 10000',
				'list_price'=> 12000,
				'price'     => 10000,
				'taxrate'   => 'standard',
			)
		),
		'six'      => wtd_test_ensure_product(
			array(
				'item_code' => 'WTD-TEST-STANDARD-6000',
				'sku_code'  => 'wtd-six',
				'title'     => 'WTD Test Standard 6000',
				'list_price'=> 6000,
				'price'     => 6000,
				'taxrate'   => 'standard',
			)
		),
		'reduced'  => wtd_test_ensure_product(
			array(
				'item_code' => 'WTD-TEST-REDUCED-4000',
				'sku_code'  => 'wtd-reduced',
				'title'     => 'WTD Test Reduced 4000',
				'list_price'=> 4000,
				'price'     => 4000,
				'taxrate'   => 'reduced',
			)
		),
	);

	$subscriber_login = 'wtd-test-subscriber';
	$subscriber_email = 'wtd-test-subscriber@example.test';
	$subscriber       = get_user_by( 'login', $subscriber_login );
	if ( ! $subscriber ) {
		$subscriber = get_user_by( 'email', $subscriber_email );
	}
	if ( $subscriber ) {
		$subscriber_id = (int) $subscriber->ID;
		$updated       = wp_update_user(
			array(
				'ID'           => $subscriber_id,
				'user_email'   => $subscriber_email,
				'role'         => 'subscriber',
				'display_name' => 'WTD Test Subscriber',
			)
		);
		if ( is_wp_error( $updated ) ) {
			throw new RuntimeException( 'Unable to update the integration-test subscriber: ' . $updated->get_error_code() );
		}
	} else {
		$subscriber_id = wp_create_user( $subscriber_login, 'wtd-test-password-123!', $subscriber_email );
		if ( is_wp_error( $subscriber_id ) ) {
			throw new RuntimeException( 'Unable to create the integration-test subscriber: ' . $subscriber_id->get_error_code() );
		}
		$subscriber_id = (int) $subscriber_id;
		wp_update_user(
			array(
				'ID'           => $subscriber_id,
				'role'         => 'subscriber',
				'display_name' => 'WTD Test Subscriber',
			)
		);
	}

	return array(
		'products'   => $products,
		'subscriber' => $subscriber_id,
	);
}
