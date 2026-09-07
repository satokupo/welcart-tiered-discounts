<?php
/**
 * Atomic Welcart order storage and order-time discount history.
 *
 * @package Welcart_Tiered_Discounts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Connect the verified native save boundaries. */
function wtd_order_hooks() {
	add_action( 'usces_pre_reg_orderdata', 'wtd_before_new_order', 20 );
	add_action( 'usces_action_reg_orderdata', 'wtd_store_new_snapshot', 100 );
	add_action( 'usces_post_reg_orderdata', 'wtd_after_new_order', 100 );
	add_filter( 'query', 'wtd_watch_order_write', PHP_INT_MAX );
	add_action( 'shutdown', 'wtd_rollback_order_write', 0 );
	add_action( 'wp_ajax_wtd_preview_order', 'wtd_ajax_preview_order' );
	add_action( 'wp_ajax_order_item_ajax', 'wtd_guard_native_preview', 5 );
	add_action( 'wp_ajax_order_item2cart_ajax', 'wtd_stage_order_item', 5 );
	add_filter( 'usces_filter_ordereditform_carttable', 'wtd_order_panel', 20, 2 );
	add_action( 'usces_pre_update_orderdata', 'wtd_before_edit_order', 20 );
	add_action( 'usces_after_update_orderdata', 'wtd_after_edit_order', 100, 2 );
	add_action( 'admin_init', 'wtd_preflight_edit_order', 20 );
}

/** Check protected edit submissions before WordPress sends the admin header. */
function wtd_preflight_edit_order() {
	global $usces;
	// The native controller later reads these same request routing fields.
	if ( 'post' !== sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) || 'usces_orderlist' !== sanitize_key( wp_unslash( $_REQUEST['page'] ?? '' ) ) || 'editpost' !== sanitize_key( wp_unslash( $_REQUEST['order_action'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Routing only; both nonces are checked before starting a write.
		return;
	}
	$order_id = isset( $_REQUEST['order_id'] ) ? absint( $_REQUEST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- ID selects the existing nonce-protected order.
	if ( null !== $usces->get_order_meta_value( 'wtd_discount_snapshot', $order_id ) ) {
		check_admin_referer( 'order_edit', 'wc_nonce' );
		wtd_before_edit_order( $order_id );
	}
}

/**
 * Map the shared quote to Welcart's native amount names.
 *
 * @param array $quote Server quote.
 * @return array Native amount names.
 */
function wtd_quote_amounts( $quote ) {
	return array(
		'total_items_price' => $quote['subtotal'],
		'discount'          => wtd_money_string( -wtd_money_cents( $quote['discount'] ) ),
		'shipping_charge'   => $quote['shipping_charge'],
		'cod_fee'           => $quote['cod_fee'],
		'tax'               => $quote['tax'],
		'usedpoint'         => $quote['usedpoint'],
		'getpoint'          => $quote['getpoint'],
		'total_full_price'  => $quote['total'],
	);
}

/**
 * Lock and recheck a displayed quote before any native order mutation.
 *
 * @param int $order_id Existing order ID.
 * @throws RuntimeException Caught here and shown as an error.
 * @throws InvalidArgumentException Caught here and shown as an error.
 */
function wtd_before_edit_order( $order_id ) {
	global $wpdb, $usces;
	$order_id = (int) $order_id;
	if ( ! empty( $GLOBALS['wtd_transaction'] ) && 'edit' === $GLOBALS['wtd_transaction']['kind'] && $order_id === $GLOBALS['wtd_transaction']['order_id'] ) {
		return;
	}
	if ( null === $usces->get_order_meta_value( 'wtd_discount_snapshot', $order_id ) ) {
		return;
	}
	try {
		if ( ! current_user_can( 'wel_manage_order' ) || ! isset( $_POST['wtd_nonce'] ) || ! is_string( $_POST['wtd_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wtd_nonce'] ) ), 'wtd_order_' . $order_id ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
		wtd_begin_order_write( 'edit', $order_id );
		// Lock the native order before checking the version to serialize competing edits.
		$locked      = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->prefix}usces_order WHERE ID = %d FOR UPDATE", $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Native order row is the existing per-order lock boundary.
		$record      = wtd_order_record( $order_id );
		$input       = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Row ownership and all accepted money inputs are validated by wtd_order_quote.
		$fingerprint = wtd_order_fingerprint( $record );
		if ( ! $locked || ! isset( $input['wtd_fingerprint'], $input['wtd_preview'] ) || ! is_string( $input['wtd_fingerprint'] ) || ! is_string( $input['wtd_preview'] ) || ! hash_equals( $fingerprint, $input['wtd_fingerprint'] ) || isset( $input['delButtonAdmin'] ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
		$quote = wtd_order_quote( $record, $input );
		if ( ! hash_equals( wtd_preview_token( $order_id, $fingerprint, $quote ), $input['wtd_preview'] ) ) {
			wtd_order_failure( 'invalid_snapshot', $order_id, $quote );
		}
		$snapshot = wtd_read_snapshot( $record['meta']['wtd_discount_snapshot'] );
		$history  = null === $record['meta']['wtd_discount_history'] ? array() : json_decode( $record['meta']['wtd_discount_history'], true );
		if ( ! is_array( $history ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
		$allowed_meta = array();
		foreach ( $record['cart_meta'] as $meta ) {
			$allowed_meta = array_merge( $allowed_meta, array_column( $meta['option'], 'cartmeta_id' ) );
		}
		if ( isset( $input['itemOption'] ) && ( ! is_array( $input['itemOption'] ) || array_diff( array_keys( $input['itemOption'] ), $allowed_meta ) ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
		$GLOBALS['wtd_transaction']['before']  = $record;
		$GLOBALS['wtd_transaction']['quote']   = $quote;
		$GLOBALS['wtd_transaction']['history'] = $history;
		$usces->options                        = array_replace( $usces->options, $snapshot['condition'] );
		$quote                                 = wtd_apply_order_rows( $order_id, $record, $quote );
		$GLOBALS['wtd_transaction']['quote']   = $quote;
		foreach ( wtd_quote_amounts( $quote ) as $key => $value ) {
			$_POST['offer'][ $key ] = $value;
		}
		foreach ( $quote['tax_parts'] as $key => $value ) {
			$_POST[ 'order_' . $key ] = $value;
		}
		foreach ( $quote['lines'] as $line ) {
			$_POST['skuPrice'][ $line['cart_id'] ] = $line['price'];
			$_POST['quant'][ $line['cart_id'] ]    = $line['quantity'];
		}
		if ( isset( $_POST['offer']['taio'] ) && 'cancel' === $_POST['offer']['taio'] ) {
			$money_changed = false;
			try {
				wtd_verify_order_money( $record, wtd_quote_amounts( $quote ) );
				wtd_verify_order_cart( $record['cart'], $quote['lines'] );
			} catch ( RuntimeException $error ) {
				$money_changed = true;
			}
			if ( $money_changed ) {
				// Native cancellation returns before saving money. Save its financial change
				// in the same transaction, then let the normal cancellation restore points.
				$cancel_post = $_POST; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve native escaping across its two calls.
				try {
					$_POST['offer']['taio'] = '';
					if ( false === usces_update_orderdata() ) {
						throw new RuntimeException( 'order_save_failed' );
					}
				} finally {
					$_POST = $cancel_post;
				}
			}
		}
	} catch ( Exception $error ) {
		wtd_order_failure( $error->getMessage(), $order_id );
	}
}

/**
 * Materialize only confirmed additions and removals through native cart APIs.
 *
 * @param int   $order_id Locked order.
 * @param array $record Before state.
 * @param array $quote Confirmed quote.
 * @return array Quote with assigned native row IDs.
 * @throws RuntimeException If a native row write did not persist.
 */
function wtd_apply_order_rows( $order_id, $record, $quote ) {
	global $usces;
	foreach ( $quote['removed'] as $cart_id ) {
		if ( $cart_id > 0 ) {
			do_action( 'usces_admin_delete_orderrow', $cart_id, $order_id, $record['cart'] );
			usces_delete_ordercartdata( $cart_id );
		}
	}
	foreach ( $quote['lines'] as &$line ) {
		if ( $line['cart_id'] >= 0 ) {
			continue;
		}
		$old_post = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Restore the untouched native form after the validated add call.
		try {
			$old_ids = array_column( usces_get_ordercartdata( $order_id ), 'cart_id' );
			$_POST   = array(
				'order_id'   => $order_id,
				'post_id'    => $line['post_id'],
				'sku'        => rawurlencode( $line['sku_code'] ),
				'itemOption' => $line['new_options'],
			);
			if ( ! usces_add_ordercartdata() ) {
				throw new RuntimeException( 'order_save_failed' );
			}
			$new_ids = array_values( array_diff( array_column( usces_get_ordercartdata( $order_id ), 'cart_id' ), $old_ids ) );
			if ( 1 !== count( $new_ids ) ) {
				throw new RuntimeException( 'order_save_failed' );
			}
			$line['cart_id'] = $new_ids[0];
			// The native update expects new option IDs, while the add form uses names.
			foreach ( usces_get_ordercart_meta( 'option', $line['cart_id'] ) as $meta ) {
				if ( isset( $line['new_options'][ $meta['meta_key'] ] ) ) {
					$old_post['itemOption'][ $meta['cartmeta_id'] ] = $line['new_options'][ $meta['meta_key'] ];
				}
			}
		} finally {
			$_POST = $old_post;
		}
	}
	unset( $line );
	// Replace ID-indexed arrays so native update cannot touch removed or draft IDs.
	$_POST['skuPrice'] = array();
	$_POST['quant']    = array();
	$_POST['postId']   = array();
	foreach ( $quote['lines'] as $line ) {
		$_POST['postId'][ $line['cart_id'] ] = $line['post_id'];
	}
	if ( ! $quote['lines'] ) {
		// Native get_total_price([]) otherwise falls back to the admin's shopping cart.
		$usces->cart->crear_cart();
	}
	return $quote;
}

/**
 * Verify all native writes, append immutable before/after history, then commit.
 *
 * @param int   $order_id Existing order ID.
 * @param mixed $result Native save result; zero can mean unchanged values.
 * @throws RuntimeException Caught here and shown as an error.
 */
function wtd_after_edit_order( $order_id, $result ) {
	global $usces;
	if ( empty( $GLOBALS['wtd_transaction'] ) || 'edit' !== $GLOBALS['wtd_transaction']['kind'] ) {
		return;
	}
	try {
		$transaction = $GLOBALS['wtd_transaction'];
		if ( false === $result || (int) $order_id !== $transaction['order_id'] ) {
			throw new RuntimeException( 'order_save_failed' );
		}
		$record = wtd_order_record( $order_id );
		wtd_verify_order_money( $record, wtd_quote_amounts( $transaction['quote'] ) );
		wtd_verify_order_cart( $record['cart'], $transaction['quote']['lines'] );
		$snapshot = wtd_read_snapshot( $record['meta']['wtd_discount_snapshot'] );
		if ( $record['meta']['wtd_discount_snapshot'] !== $transaction['before']['meta']['wtd_discount_snapshot'] || $record['order']['order_condition'] !== $transaction['before']['order']['order_condition'] ) {
			throw new RuntimeException( 'order_save_failed' );
		}
		if ( 'reduced' === $snapshot['condition']['applicable_taxrate'] && 'deactivate' !== $snapshot['condition']['tax_display'] ) {
			wtd_verify_order_tax( $record, $transaction['quote']['tax_parts'] );
		}
		$before  = $transaction['before'];
		$changed = $record['cart'] !== $before['cart'];
		foreach ( array( 'order_item_total_price', 'order_discount', 'order_shipping_charge', 'order_cod_fee', 'order_tax', 'order_usedpoint', 'order_getpoint' ) as $column ) {
			$changed = $changed || $record['order'][ $column ] !== $before['order'][ $column ];
		}
		if ( $changed ) {
			$history   = $transaction['history'];
			$history[] = array(
				'at'        => current_time( 'mysql', true ),
				'user_id'   => get_current_user_id(),
				'condition' => $snapshot['condition'],
				'before'    => array(
					'cart'    => $before['cart'],
					'amounts' => wtd_record_amounts( $before ),
				),
				'after'     => array(
					'cart'    => $record['cart'],
					'amounts' => wtd_record_amounts( $record ),
					'tier'    => $transaction['quote']['tier'],
				),
			);
			$json      = wp_json_encode( $history );
			if ( false === $json || false === $usces->set_order_meta_value( 'wtd_discount_history', $json, $order_id ) || $json !== $usces->get_order_meta_value( 'wtd_discount_history', $order_id ) ) {
				throw new RuntimeException( 'order_save_failed' );
			}
		}
		wtd_commit_order_write();
	} catch ( Exception $error ) {
		wtd_order_failure( $error->getMessage(), $order_id );
	}
}

/**
 * Keep financial history free of customer contact and address data.
 *
 * @param array $record Native order record.
 * @return array Native money columns.
 */
function wtd_record_amounts( $record ) {
	return array_intersect_key( $record['order'], array_flip( array( 'order_item_total_price', 'order_discount', 'order_shipping_charge', 'order_cod_fee', 'order_tax', 'order_usedpoint', 'order_getpoint' ) ) );
}

/**
 * Build the current input values to allow unchanged native status/note saves.
 *
 * @param array $record Saved order.
 * @return array Input values accepted by the quote calculator.
 */
function wtd_record_input( $record ) {
	$input = array(
		'skuPrice' => array(),
		'quant'    => array(),
		'postId'   => array(),
		'offer'    => array(),
	);
	foreach ( $record['cart'] as $row ) {
		$input['skuPrice'][ $row['cart_id'] ] = $row['price'];
		$input['quant'][ $row['cart_id'] ]    = $row['quantity'];
		$input['postId'][ $row['cart_id'] ]   = $row['post_id'];
	}
	foreach ( array( 'shipping_charge', 'cod_fee', 'usedpoint' ) as $key ) {
		$input['offer'][ $key ] = $record['order'][ 'order_' . $key ];
	}
	return $input;
}

/**
 * Format financial audit data as human-readable rows rather than storage JSON.
 *
 * @param array $cart Historical order rows.
 * @param array $amounts Historical native money columns.
 * @return string Escaped compact audit table.
 */
function wtd_history_values( $cart, $amounts ) {
	$html = '<ul>';
	foreach ( $cart as $row ) {
		$html .= '<li>' . esc_html( $row['item_name'] . ' / ' . $row['sku_code'] . '：' . $row['price'] . ' × ' . $row['quantity'] ) . '</li>';
	}
	$html  .= '</ul><dl>';
	$labels = array(
		'order_item_total_price' => __( '商品小計', 'welcart-tiered-discounts' ),
		'order_discount'         => __( 'ステップ割引', 'welcart-tiered-discounts' ),
		'order_shipping_charge'  => __( '送料', 'welcart-tiered-discounts' ),
		'order_cod_fee'          => __( '手数料', 'welcart-tiered-discounts' ),
		'order_tax'              => __( '加算税', 'welcart-tiered-discounts' ),
		'order_usedpoint'        => __( '利用ポイント', 'welcart-tiered-discounts' ),
		'order_getpoint'         => __( '付与ポイント', 'welcart-tiered-discounts' ),
	);
	foreach ( $labels as $key => $label ) {
		$html .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $amounts[ $key ] ) . '</dd>';
	}
	return $html . '</dl>';
}

/**
 * Serve a read-only quote after verifying the order-bound administrator nonce.
 *
 * @throws InvalidArgumentException Caught here and shown as an error.
 */
function wtd_ajax_preview_order() {
	$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
	if ( ! current_user_can( 'wel_manage_order' ) || ! check_ajax_referer( 'wtd_order_' . $order_id, 'wtd_nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( '受注の再計算権限を確認できません。画面を再読み込みしてください。', 'welcart-tiered-discounts' ) ), 403 );
	}
	try {
		$record      = wtd_order_record( $order_id );
		$input       = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Money and row ownership are strictly validated by wtd_order_quote.
		$fingerprint = wtd_order_fingerprint( $record );
		if ( ! isset( $input['wtd_fingerprint'] ) || ! is_string( $input['wtd_fingerprint'] ) || ! hash_equals( $fingerprint, $input['wtd_fingerprint'] ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
		$quote = wtd_order_quote( $record, $input );
		wp_send_json_success(
			array(
				'quote'     => $quote,
				'token'     => wtd_preview_token( $order_id, $fingerprint, $quote ),
				'tier_text' => wtd_tier_text( $quote['tier'] ),
			)
		);
	} catch ( Exception $error ) {
		wtd_log_error( $error->getMessage(), 'order_preview', $order_id );
		wp_send_json_error( array( 'message' => __( '再計算できません。入力値をご確認ください。他の画面で保存された場合は、この画面を再読み込みしてください。', 'welcart-tiered-discounts' ) ), 409 );
	}
}

/** Stop native recalculation AJAX from changing tax data before a confirmed save. */
function wtd_guard_native_preview() {
	global $usces;
	$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- This guard only rejects requests; native handlers validate their own unrelated operations.
	$mode     = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Rejection requires no write authority.
	if ( $order_id && in_array( $mode, array( 'recalculation', 'recalculation_reduced' ), true ) && null !== $usces->get_order_meta_value( 'wtd_discount_snapshot', $order_id ) ) {
		wp_send_json_error( array( 'message' => __( 'この受注はステップ割引の再計算ボタンから確認してください。', 'welcart-tiered-discounts' ) ), 409 );
	}
}

/**
 * Stage native product-add requests as signed form rows without a DB write.
 *
 * @throws InvalidArgumentException Caught here and shown as an error.
 */
function wtd_stage_order_item() {
	global $usces;
	$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Select the nonce-protected order before any processing.
	if ( null === $usces->get_order_meta_value( 'wtd_discount_snapshot', $order_id ) ) {
		return;
	}
	try {
		if ( ! current_user_can( 'wel_manage_order' ) || ! isset( $_POST['wtd_form'] ) || ! is_string( $_POST['wtd_form'] ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
		parse_str( wp_unslash( $_POST['wtd_form'] ), $input ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed fields undergo the same strict quote and nonce validation as the edit form.
		if ( ! isset( $input['wtd_nonce'] ) || ! is_string( $input['wtd_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( $input['wtd_nonce'] ), 'wtd_order_' . $order_id ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
		$record      = wtd_order_record( $order_id );
		$fingerprint = wtd_order_fingerprint( $record );
		if ( ! isset( $input['wtd_fingerprint'] ) || ! is_string( $input['wtd_fingerprint'] ) || ! hash_equals( $fingerprint, $input['wtd_fingerprint'] ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
		$quote    = wtd_order_quote( $record, $input );
		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$sku_code = isset( $_POST['sku'] ) && is_string( $_POST['sku'] ) ? urldecode( sanitize_text_field( wp_unslash( $_POST['sku'] ) ) ) : '';
		$skus     = $usces->get_skus( $post_id, 'code', false );
		if ( ! is_array( $skus ) || ! isset( $skus[ $sku_code ] ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
		$sku     = $skus[ $sku_code ];
		$product = wel_get_product( $post_id );
		$options = isset( $_POST['itemOption'] ) ? map_deep( wp_unslash( $_POST['itemOption'] ), 'sanitize_text_field' ) : array();
		if ( ! is_array( $options ) || array_diff( array_keys( $options ), array_keys( usces_get_opts( $post_id, 'name', false ) ) ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
		$drafts  = $quote['new_items'];
		$cart_id = -1;
		foreach ( $drafts as $draft ) {
			$cart_id = min( $cart_id, (int) $draft['row']['cart_id'] - 1 );
		}
		$row      = array(
			'cart_id'        => $cart_id,
			'order_id'       => $order_id,
			'row_index'      => count( $quote['lines'] ),
			'post_id'        => $post_id,
			'item_code'      => $product['itemCode'],
			'item_name'      => $product['itemName'],
			'sku_code'       => $sku_code,
			'sku'            => $sku_code,
			'sku_name'       => $sku['name'],
			'price'          => wtd_money_string( wtd_money_cents( $sku['price'] ) ),
			'cprice'         => $sku['cprice'],
			'quantity'       => '1',
			'unit'           => $sku['unit'],
			'tax'            => 0,
			'destination_id' => 0,
			'cart_serial'    => '',
			'taxrate'        => isset( $sku['taxrate'] ) && 'reduced' === $sku['taxrate'] ? 'reduced' : 'standard',
			'new_options'    => $options,
		);
		$drafts[] = array(
			'row'       => $row,
			'signature' => wtd_preview_token( $order_id, $fingerprint, array( 'new' => $row ) ),
		);
		$rows     = $quote['lines'];
		$rows[]   = $row;
		$html     = usces_get_ordercart_row( $order_id, $rows );
		$html    .= '<tr hidden><td><input type="hidden" name="wtd_new" value="' . esc_attr( wp_json_encode( $drafts ) ) . '"></td></tr>';
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Native row renderer escapes its fields; the additional JSON is attribute-escaped above.
		wp_die();
	} catch ( Exception $error ) {
		wtd_log_error( $error->getMessage(), 'order_add_preview', $order_id );
		wp_send_json_error( array( 'message' => __( '商品を追加できません。受注画面を再読み込みしてから操作してください。', 'welcart-tiered-discounts' ) ), 409 );
	}
}

/**
 * Add the immutable original, tier list and saved history inside the native form.
 *
 * @param string $html Native cart table.
 * @param array  $args Native order context.
 * @return string Table and discount controls.
 * @throws InvalidArgumentException Caught here and shown as an error.
 */
function wtd_order_panel( $html, $args ) {
	$order_id = isset( $args['order_id'] ) ? absint( $args['order_id'] ) : 0;
	if ( ! $order_id ) {
		return $html;
	}
	try {
		$record = wtd_order_record( $order_id );
		if ( null === $record['meta']['wtd_discount_snapshot'] ) {
			return $html . '<p>' . esc_html__( '注文時の割引一覧がないため、ステップ割引の再計算は利用できません。', 'welcart-tiered-discounts' ) . '</p>';
		}
		$snapshot      = wtd_read_snapshot( $record['meta']['wtd_discount_snapshot'] );
		$fingerprint   = wtd_order_fingerprint( $record );
		$initial       = wtd_order_quote( $record, wtd_record_input( $record ) );
		$initial_token = '';
		try {
			wtd_verify_order_money( $record, wtd_quote_amounts( $initial ) );
			$initial_token = wtd_preview_token( $order_id, $fingerprint, $initial );
		} catch ( RuntimeException $error ) {
			$initial_token = ''; // A differing stored amount needs an explicit preview.
		}
		wp_enqueue_script( 'wtd-orders', plugins_url( 'assets/orders.js', WTD_FILE ), array( 'jquery' ), WTD_VERSION, true );
		$panel = '<section id="wtd-order-panel" aria-label="' . esc_attr__( 'ステップ割引', 'welcart-tiered-discounts' ) . '"><h3>' . esc_html__( 'ステップ割引・注文時の条件', 'welcart-tiered-discounts' ) . '</h3><ul>';
		foreach ( $snapshot['settings']['tiers'] as $tier ) {
			$panel .= '<li>' . esc_html( wtd_tier_text( $tier ) . ( $tier['enabled'] ? '' : __( '（無効）', 'welcart-tiered-discounts' ) ) ) . '</li>';
		}
		$panel           .= '</ul><p>' . esc_html__( '数量・単価・送料・手数料・ポイントを変更したら、再計算して表示を確認してから保存してください。', 'welcart-tiered-discounts' ) . '</p>';
		$panel           .= '<p id="wtd-preview-status" role="status" aria-live="polite">' . esc_html( $initial_token ? __( '保存済みの金額を表示しています。', 'welcart-tiered-discounts' ) : __( '保存前に再計算して金額を確認してください。', 'welcart-tiered-discounts' ) ) . '</p><button type="button" id="wtd-preview-button" class="button">' . esc_html__( 'ステップ割引を再計算', 'welcart-tiered-discounts' ) . '</button>';
		$panel           .= '<input type="hidden" name="wtd_fingerprint" value="' . esc_attr( $fingerprint ) . '"><input type="hidden" name="wtd_preview" value="' . esc_attr( $initial_token ) . '"><input type="hidden" name="wtd_removed" value="[]">' . wp_nonce_field( 'wtd_order_' . $order_id, 'wtd_nonce', false, false );
		$original_amounts = array();
		foreach ( array(
			'total_items_price' => 'order_item_total_price',
			'discount'          => 'order_discount',
			'shipping_charge'   => 'order_shipping_charge',
			'cod_fee'           => 'order_cod_fee',
			'tax'               => 'order_tax',
			'usedpoint'         => 'order_usedpoint',
			'getpoint'          => 'order_getpoint',
		) as $key => $column ) {
			$original_amounts[ $column ] = $snapshot['original']['amounts'][ $key ];
		}
		$panel  .= '<details><summary>' . esc_html__( '元注文と保存済み変更履歴', 'welcart-tiered-discounts' ) . '</summary><h4>' . esc_html__( '元注文（変更されません）', 'welcart-tiered-discounts' ) . '</h4>' . wtd_history_values( $snapshot['original_cart'], $original_amounts );
		$history = json_decode( $record['meta']['wtd_discount_history'] ? $record['meta']['wtd_discount_history'] : '[]', true );
		if ( ! is_array( $history ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
		foreach ( $history as $change ) {
			/* translators: 1: UTC save time, 2: administrator user ID. */
			$panel .= '<h4>' . esc_html( sprintf( __( '%1$s UTC／管理者ID %2$d', 'welcart-tiered-discounts' ), $change['at'], $change['user_id'] ) ) . '</h4><h5>' . esc_html__( '変更前', 'welcart-tiered-discounts' ) . '</h5>' . wtd_history_values( $change['before']['cart'], $change['before']['amounts'] ) . '<h5>' . esc_html__( '変更後', 'welcart-tiered-discounts' ) . '</h5>' . wtd_history_values( $change['after']['cart'], $change['after']['amounts'] );
		}
		$panel .= '</details></section>';
		wp_localize_script(
			'wtd-orders',
			'wtdOrders',
			array(
				'historicalTax' => __( '注文時の税条件で再計算します。', 'welcart-tiered-discounts' ),
				'url'           => admin_url( 'admin-ajax.php' ),
				'dirty'         => __( '入力が変わりました。再計算して金額を確認してから保存してください。', 'welcart-tiered-discounts' ),
				'failed'        => __( '再計算できませんでした。画面を再読み込みしてご確認ください。', 'welcart-tiered-discounts' ),
				'ready'         => __( '再計算済み（未保存）', 'welcart-tiered-discounts' ),
				'labels'        => array( __( '割引', 'welcart-tiered-discounts' ), __( '税', 'welcart-tiered-discounts' ), __( '内税', 'welcart-tiered-discounts' ), __( '利用ポイント', 'welcart-tiered-discounts' ), __( '付与ポイント', 'welcart-tiered-discounts' ), __( '請求額', 'welcart-tiered-discounts' ) ),
			)
		);
		return $html . $panel;
	} catch ( Exception $error ) {
		wtd_log_error( $error->getMessage(), 'order_display', $order_id );
		return $html . '<p role="alert">' . esc_html__( '注文時の割引情報が破損しています。この受注は保存できません。', 'welcart-tiered-discounts' ) . '</p>';
	}
}

/**
 * Decode the immutable order-time inputs without falling back to shop settings.
 *
 * @param string $json Stored snapshot.
 * @return array Validated snapshot.
 * @throws InvalidArgumentException On missing or corrupt fields.
 */
function wtd_read_snapshot( $json ) {
	$value = is_string( $json ) ? json_decode( $json, true ) : null;
	if ( ! is_array( $value ) || ! isset( $value['schema_version'], $value['settings'], $value['condition'], $value['original'], $value['original_cart'] ) || 1 !== $value['schema_version'] || ! is_array( $value['condition'] ) || ! is_array( $value['original'] ) || ! is_array( $value['original_cart'] ) ) {
		throw new InvalidArgumentException( 'invalid_snapshot' );
	}
	wtd_validate_settings( $value['settings'] );
	foreach ( array( 'total_items_price', 'discount', 'shipping_charge', 'cod_fee', 'tax', 'usedpoint', 'getpoint', 'total_full_price' ) as $key ) {
		if ( ! isset( $value['original']['amounts'][ $key ] ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
		wtd_money_cents( $value['original']['amounts'][ $key ], 'discount' === $key );
	}
	foreach ( $value['original_cart'] as $row ) {
		if ( ! is_array( $row ) || ! isset( $row['item_name'], $row['sku_code'], $row['price'], $row['quantity'] ) || ! is_string( $row['item_name'] ) || ! is_string( $row['sku_code'] ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
		wtd_money_cents( $row['price'] );
		wtd_normalize_decimal( $row['quantity'] );
	}
	return $value;
}

/**
 * Bind displayed server results to the order, user and original DB version.
 *
 * @param int    $order_id Order ID.
 * @param string $fingerprint Original DB fingerprint.
 * @param array  $quote Validated inputs and server result.
 * @return string Signed confirmation token.
 */
function wtd_preview_token( $order_id, $fingerprint, $quote ) {
	return hash_hmac( 'sha256', wp_json_encode( array( (int) $order_id, get_current_user_id(), $fingerprint, $quote ) ), wp_salt( 'nonce' ) );
}

/**
 * Fingerprint the current record including original snapshot and history.
 *
 * @param array $record Saved record.
 * @return string Database version fingerprint.
 */
function wtd_order_fingerprint( $record ) {
	return hash( 'sha256', wp_json_encode( $record ) );
}

/**
 * Recalculate submitted money inputs against the immutable order-time tiers.
 *
 * @param array $record Current native order and metadata.
 * @param array $input Unsplashed form fields.
 * @return array Validated lines and server-calculated amounts.
 * @throws InvalidArgumentException For corrupt inputs or foreign row IDs.
 */
function wtd_order_quote( $record, $input ) {
	global $usces;
	$snapshot = wtd_read_snapshot( $record['meta']['wtd_discount_snapshot'] );
	foreach ( array( 'wtd_new', 'wtd_removed' ) as $field ) {
		if ( isset( $input[ $field ] ) && ! is_string( $input[ $field ] ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
	}
	$new_items = isset( $input['wtd_new'] ) && is_string( $input['wtd_new'] ) ? json_decode( $input['wtd_new'], true ) : array();
	$removed   = isset( $input['wtd_removed'] ) && is_string( $input['wtd_removed'] ) ? json_decode( $input['wtd_removed'], true ) : array();
	if ( ! is_array( $new_items ) || ! is_array( $removed ) ) {
		throw new InvalidArgumentException( 'invalid_snapshot' );
	}
	$fingerprint = wtd_order_fingerprint( $record );
	$seen        = array();
	foreach ( $new_items as $draft ) {
		if ( ! is_array( $draft ) || ! isset( $draft['row'], $draft['signature'], $draft['row']['cart_id'] ) || ! is_array( $draft['row'] ) || ! is_string( $draft['signature'] ) || ! is_int( $draft['row']['cart_id'] ) || $draft['row']['cart_id'] >= 0 || isset( $seen[ $draft['row']['cart_id'] ] ) || ! hash_equals( wtd_preview_token( $record['order']['ID'], $fingerprint, array( 'new' => $draft['row'] ) ), $draft['signature'] ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
		$row                                    = $draft['row'];
		$seen[ $row['cart_id'] ]                = true;
		$record['cart'][]                       = $row;
		$record['cart_meta'][ $row['cart_id'] ] = array(
			'option'  => array(),
			'taxrate' => array( array( 'meta_key' => $row['taxrate'] ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- This is in-memory native row metadata, not a query.
		);
	}
	foreach ( $removed as $id ) {
		if ( ! is_int( $id ) || ! in_array( (string) $id, array_map( 'strval', array_column( $record['cart'], 'cart_id' ) ), true ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
	}
	$record['cart'] = array_values(
		array_filter(
			$record['cart'],
			static function ( $row ) use ( $removed ) {
				return ! in_array( (int) $row['cart_id'], $removed, true );
			}
		)
	);
	foreach ( array( 'skuPrice', 'quant', 'postId', 'offer' ) as $field ) {
		if ( empty( $record['cart'] ) && 'offer' !== $field && ! isset( $input[ $field ] ) ) {
			$input[ $field ] = array();
		}
		if ( ! isset( $input[ $field ] ) || ! is_array( $input[ $field ] ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
	}
	$ids = array_map( 'strval', array_column( $record['cart'], 'cart_id' ) );
	foreach ( array( 'skuPrice', 'quant', 'postId' ) as $field ) {
		$keys = array_map( 'strval', array_keys( $input[ $field ] ) );
		if ( array_diff( $keys, $ids ) || array_diff( $ids, $keys ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
	}
	$lines            = array();
	$financial_change = ! empty( $new_items ) || ! empty( $removed );
	foreach ( $record['cart'] as $row ) {
		$id = $row['cart_id'];
		if ( (string) $row['post_id'] !== (string) $input['postId'][ $id ] ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
		$financial_change = $financial_change || wtd_money_cents( $row['price'] ) !== wtd_money_cents( $input['skuPrice'][ $id ] ) || wtd_normalize_decimal( $row['quantity'] ) !== wtd_normalize_decimal( $input['quant'][ $id ] );
		$row['price']     = wtd_money_string( wtd_money_cents( $input['skuPrice'][ $id ] ) );
		$row['quantity']  = wtd_normalize_decimal( $input['quant'][ $id ] );
		// Welcart formats quantities through wpdb %f before its FLOAT column.
		if ( wtd_normalize_decimal( sprintf( '%.6f', (float) $row['quantity'] ) ) !== $row['quantity'] ) {
			throw new InvalidArgumentException( 'amount_out_of_range' );
		}
		$row['taxrate'] = 'standard';
		foreach ( $record['cart_meta'][ $id ]['taxrate'] as $meta ) {
			if ( 'reduced' === $meta['meta_key'] ) {
				$row['taxrate'] = 'reduced';
			}
		}
		$lines[] = $row;
	}
	$fees = array();
	foreach ( array( 'shipping_charge', 'cod_fee', 'usedpoint' ) as $field ) {
		if ( ! array_key_exists( $field, $input['offer'] ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
		$fees[ $field ]   = $input['offer'][ $field ];
		$financial_change = $financial_change || wtd_money_cents( $fees[ $field ] ) !== wtd_money_cents( $record['order'][ 'order_' . $field ] ?? 0 );
	}
	$result              = wtd_order_amounts( $lines, $snapshot['settings'], $snapshot['condition'], $fees );
	$result['lines']     = $lines;
	$result['removed']   = $removed;
	$result['new_items'] = $new_items;
	$result['getpoint']  = isset( $record['order']['order_getpoint'] ) ? (int) $record['order']['order_getpoint'] : 0;
	if ( $financial_change && ! $lines ) {
		$result['getpoint'] = 0;
	}
	if ( $financial_change && ! empty( $record['order']['mem_id'] ) && $lines ) {
		$old_options = $usces->options;
		$old_session = $_SESSION;
		try {
			$usces->options = array_replace( $usces->options, $snapshot['condition'] );
			$usces->cart->set_order_entry( array( 'usedpoint' => $result['usedpoint'] ) );
			$result['getpoint'] = (int) $usces->get_order_point( $record['order']['mem_id'], $snapshot['condition']['display_mode'], $lines );
		} finally {
			$usces->options = $old_options;
			$_SESSION       = $old_session;
		}
	}
	return $result;
}

/**
 * Start the one-order transaction on Welcart's existing WordPress connection.
 *
 * @param string $kind New order or administrator edit.
 * @param int    $order_id Existing order when editing.
 * @throws RuntimeException If the transaction cannot start.
 */
function wtd_begin_order_write( $kind, $order_id = 0 ) {
	global $wpdb, $usces;
	if ( ! empty( $GLOBALS['wtd_transaction'] ) || false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Native save callbacks share this connection and lack their own transaction.
		throw new RuntimeException( 'order_save_failed' );
	}
	$GLOBALS['wtd_transaction'] = array(
		'kind'       => $kind,
		'order_id'   => (int) $order_id,
		'session'    => isset( $_SESSION ) ? $_SESSION : array(),
		'options'    => $usces->options,
		'sql_failed' => false,
	);
	if ( 'edit' === $kind ) {
		// Welcart writes admin HTML before its after-save hook. Keep errors able to
		// send HTTP 409 instead of returning an already-sent success response.
		$GLOBALS['wtd_transaction']['output_level'] = ob_get_level();
		ob_start();
	}
}

/**
 * Catch native write failures not aggregated by usces_update_orderdata.
 *
 * @param string $query The next query, before wpdb clears last_error.
 * @return string Unchanged SQL.
 */
function wtd_watch_order_write( $query ) {
	global $wpdb;
	if ( ! empty( $GLOBALS['wtd_transaction'] ) && $wpdb->last_error && preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $wpdb->last_query ) ) {
		$GLOBALS['wtd_transaction']['sql_failed'] = true;
	}
	return $query;
}

/** Roll back unfinished writes, session changes and request-local caches. */
function wtd_rollback_order_write() {
	global $wpdb, $usces;
	if ( empty( $GLOBALS['wtd_transaction'] ) ) {
		return;
	}
	$transaction = $GLOBALS['wtd_transaction'];
	$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Roll back only this plugin's active one-order transaction.
	unset( $GLOBALS['wtd_transaction'] );
	if ( isset( $transaction['output_level'] ) ) {
		while ( ob_get_level() > $transaction['output_level'] ) {
			ob_end_clean();
		}
	}
	$_SESSION       = $transaction['session'];
	$usces->options = $transaction['options'];
	if ( function_exists( 'wp_cache_flush_runtime' ) ) {
		wp_cache_flush_runtime();
	} else {
		wp_cache_flush();
	}
}

/**
 * Commit only after native records and supplemental metadata were read back.
 *
 * @throws RuntimeException On a native SQL failure.
 */
function wtd_commit_order_write() {
	global $wpdb, $usces;
	if ( empty( $GLOBALS['wtd_transaction'] ) || $GLOBALS['wtd_transaction']['sql_failed'] || $wpdb->last_error ) {
		throw new RuntimeException( 'order_save_failed' );
	}
	if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Native order callbacks have completed on the same connection.
		throw new RuntimeException( 'order_save_failed' );
	}
	$usces->options = $GLOBALS['wtd_transaction']['options'];
	$output_level   = $GLOBALS['wtd_transaction']['output_level'] ?? null;
	unset( $GLOBALS['wtd_transaction'] );
	if ( null !== $output_level ) {
		while ( ob_get_level() > $output_level ) {
			ob_end_flush();
		}
	}
}

/**
 * Stop before success rendering and mail when a required save fails.
 *
 * @param string $reason Non-secret reason.
 * @param int    $order_id Affected order.
 * @param array  $quote Current validated inputs, when a preview is stale.
 */
function wtd_order_failure( $reason, $order_id = 0, $quote = array() ) {
	wtd_rollback_order_write();
	wtd_log_error( $reason, 'order_save', $order_id );
	// wp_die skips its status_header call after admin_head, even with output buffered.
	if ( ! headers_sent() ) {
		status_header( 409 );
		nocache_headers();
	}
	$message = esc_html__( '受注を保存できませんでした。変更は確定していません。画面を再読み込みして内容をご確認ください。', 'welcart-tiered-discounts' );
	if ( $quote ) {
		$message = '<p>' . esc_html__( '入力が確認済みの金額から変わっています。保存は行っていません。戻るボタンで受注画面へ戻り、再計算して以下の金額を確認してから保存してください。', 'welcart-tiered-discounts' ) . '</p><dl>';
		foreach ( array(
			'subtotal'     => __( '商品小計', 'welcart-tiered-discounts' ),
			'discount'     => __( '割引', 'welcart-tiered-discounts' ),
			'tax'          => __( '加算税', 'welcart-tiered-discounts' ),
			'internal_tax' => __( '内税', 'welcart-tiered-discounts' ),
			'usedpoint'    => __( '利用ポイント', 'welcart-tiered-discounts' ),
			'getpoint'     => __( '付与ポイント', 'welcart-tiered-discounts' ),
			'total'        => __( '請求額', 'welcart-tiered-discounts' ),
		) as $key => $label ) {
			$message .= '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $quote[ $key ] ) . '</dd>';
		}
		$message .= '</dl>';
	}
	wp_die(
		$message, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Each dynamic label and value is escaped above.
		esc_html__( '受注の保存エラー', 'welcart-tiered-discounts' ),
		array(
			'response'  => 409,
			'back_link' => true,
		)
	);
}

/**
 * Begin before Welcart inserts the order, stock and member point writes.
 *
 * @throws RuntimeException Caught here and shown as an error.
 */
function wtd_before_new_order() {
	try {
		if ( empty( $GLOBALS['wtd_verified_purchase'] ) ) {
			throw new RuntimeException( 'invalid_snapshot' );
		}
		wtd_begin_order_write( 'new' );
		$GLOBALS['wtd_transaction']['expected'] = $GLOBALS['wtd_verified_purchase'];
	} catch ( RuntimeException $error ) {
		wtd_order_failure( $error->getMessage() );
	}
}

/**
 * Read native order, cart and the exact supplemental values without caches.
 *
 * @param int $order_id Order ID.
 * @return array Persisted state.
 * @throws InvalidArgumentException If the order does not exist.
 */
function wtd_order_record( $order_id ) {
	global $usces;
	$order = $usces->get_order_data( $order_id, 'direct' );
	if ( ! is_array( $order ) ) {
		throw new InvalidArgumentException( 'invalid_snapshot' );
	}
	$meta = array();
	foreach ( array( 'subtotal_standard', 'subtotal_reduced', 'discount_standard', 'discount_reduced', 'tax_standard', 'tax_reduced', 'wtd_discount_snapshot', 'wtd_discount_history' ) as $key ) {
		$meta[ $key ] = $usces->get_order_meta_value( $key, $order_id );
	}
	$cart      = usces_get_ordercartdata( $order_id );
	$cart_meta = array();
	foreach ( $cart as $row ) {
		$cart_meta[ $row['cart_id'] ] = array(
			'taxrate' => usces_get_ordercart_meta( 'taxrate', $row['cart_id'] ),
			'option'  => usces_get_ordercart_meta( 'option', $row['cart_id'] ),
		);
	}
	return array(
		'order'     => $order,
		'cart'      => $cart,
		'meta'      => $meta,
		'cart_meta' => $cart_meta,
	);
}

/**
 * Compare the native money columns with the confirmed quote.
 *
 * @param array $record Saved native state.
 * @param array $amounts Confirmed amounts.
 * @throws RuntimeException On any mismatch, including a silently failed write.
 */
function wtd_verify_order_money( $record, $amounts ) {
	$mapping = array(
		'total_items_price' => 'order_item_total_price',
		'discount'          => 'order_discount',
		'shipping_charge'   => 'order_shipping_charge',
		'cod_fee'           => 'order_cod_fee',
		'tax'               => 'order_tax',
	);
	foreach ( $mapping as $key => $column ) {
		if ( wtd_money_cents( $record['order'][ $column ], 'discount' === $key ) !== wtd_money_cents( $amounts[ $key ], 'discount' === $key ) ) {
			throw new RuntimeException( 'order_save_failed' );
		}
	}
	foreach ( array( 'usedpoint', 'getpoint' ) as $key ) {
		if ( isset( $amounts[ $key ] ) && (int) $record['order'][ 'order_' . $key ] !== (int) $amounts[ $key ] ) {
			throw new RuntimeException( 'order_save_failed' );
		}
	}
	$total = wtd_money_cents( $record['order']['order_item_total_price'] ) + wtd_money_cents( $record['order']['order_discount'], true ) + wtd_money_cents( $record['order']['order_shipping_charge'] ) + wtd_money_cents( $record['order']['order_cod_fee'] ) + wtd_money_cents( $record['order']['order_tax'] ) - (int) $record['order']['order_usedpoint'] * 100;
	if ( wtd_money_cents( $amounts['total_full_price'] ) !== $total ) {
		throw new RuntimeException( 'order_save_failed' );
	}
}

/**
 * Verify every required tax metadata value, including native silent failures.
 *
 * @param array $record Saved record.
 * @param array $expected Expected tax metadata; empty for single-rate orders.
 * @throws RuntimeException When a required tax value is missing or incorrect.
 */
function wtd_verify_order_tax( $record, $expected ) {
	foreach ( $expected as $key => $value ) {
		if ( ! isset( $record['meta'][ $key ] ) || wtd_money_cents( $record['meta'][ $key ], true ) !== wtd_money_cents( $value, true ) ) {
			throw new RuntimeException( 'order_save_failed' );
		}
	}
}

/**
 * Verify native cart persistence against its input, including fractional quantity.
 *
 * @param array $actual Saved rows.
 * @param array $expected Rows passed to the native save.
 * @throws RuntimeException On missing or rounded rows.
 */
function wtd_verify_order_cart( $actual, $expected ) {
	if ( count( $actual ) !== count( $expected ) ) {
		throw new RuntimeException( 'order_save_failed' );
	}
	foreach ( array_values( $expected ) as $index => $line ) {
		$saved = $actual[ $index ];
		$sku   = isset( $line['sku_code'] ) ? $line['sku_code'] : urldecode( $line['sku'] );
		if ( (int) $saved['post_id'] !== (int) $line['post_id'] || $saved['sku_code'] !== $sku || wtd_money_cents( $saved['price'] ) !== wtd_money_cents( $line['price'] ) || wtd_normalize_decimal( $saved['quantity'] ) !== wtd_normalize_decimal( $line['quantity'] ) ) {
			throw new RuntimeException( 'order_save_failed' );
		}
	}
}

/**
 * Save the immutable quote after native order-cart callbacks at priority ten.
 *
 * @param array $args Native registration arguments.
 * @throws RuntimeException Caught here and shown as an error.
 */
function wtd_store_new_snapshot( $args ) {
	global $usces;
	if ( empty( $GLOBALS['wtd_transaction'] ) || 'new' !== $GLOBALS['wtd_transaction']['kind'] ) {
		return;
	}
	$order_id = isset( $args['order_id'] ) ? (int) $args['order_id'] : 0;
	try {
		$state  = $GLOBALS['wtd_transaction']['expected'];
		$record = wtd_order_record( $order_id );
		wtd_verify_order_money( $record, $state['amounts'] );
		wtd_verify_order_cart( $record['cart'], $args['cart'] );
		wtd_verify_order_tax( $record, $state['tax_parts'] );
		if ( maybe_unserialize( $record['order']['order_condition'] ) !== $state['condition'] ) {
			throw new RuntimeException( 'order_save_failed' );
		}
		$snapshot = array(
			'schema_version' => 1,
			'settings'       => $state['calculation']['settings'],
			'condition'      => $state['condition'],
			'original'       => $state,
			'original_cart'  => $record['cart'],
		);
		$json     = wp_json_encode( $snapshot );
		if ( false === $json || null !== $record['meta']['wtd_discount_snapshot'] || false === $usces->set_order_meta_value( 'wtd_discount_snapshot', $json, $order_id ) || $json !== $usces->get_order_meta_value( 'wtd_discount_snapshot', $order_id ) ) {
			throw new RuntimeException( 'order_save_failed' );
		}
		$GLOBALS['wtd_transaction']['order_id'] = $order_id;
		$GLOBALS['wtd_transaction']['snapshot'] = $json;
		$GLOBALS['wtd_transaction']['cart']     = $args['cart'];
	} catch ( Exception $error ) {
		wtd_order_failure( $error->getMessage(), $order_id );
	}
}

/**
 * Read back and commit before order_processing reaches its mail call.
 *
 * @param int $order_id New order.
 * @throws RuntimeException Caught here and shown as an error.
 */
function wtd_after_new_order( $order_id ) {
	try {
		$transaction = isset( $GLOBALS['wtd_transaction'] ) ? $GLOBALS['wtd_transaction'] : array();
		if ( ! $order_id || empty( $transaction['snapshot'] ) || (int) $order_id !== $transaction['order_id'] ) {
			throw new RuntimeException( 'order_save_failed' );
		}
		$record = wtd_order_record( $order_id );
		wtd_verify_order_money( $record, $transaction['expected']['amounts'] );
		wtd_verify_order_cart( $record['cart'], $transaction['cart'] );
		wtd_verify_order_tax( $record, $transaction['expected']['tax_parts'] );
		if ( $record['meta']['wtd_discount_snapshot'] !== $transaction['snapshot'] ) {
			throw new RuntimeException( 'order_save_failed' );
		}
		wtd_commit_order_write();
		unset( $_SESSION['wtd_confirmation'], $GLOBALS['wtd_verified_purchase'] );
	} catch ( Exception $error ) {
		wtd_order_failure( $error->getMessage(), $order_id );
	}
}
