<?php
/**
 * Welcart calculation and checkout integration.
 *
 * @package Welcart_Tiered_Discounts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Register the verified native calculation entry. */
function wtd_welcart_hooks() {
	wtd_order_hooks();
	add_filter( 'usces_order_discount', 'wtd_discount_filter', 20, 2 );
	add_filter( 'usces_filter_cart_table_footer', 'wtd_cart_footer' );
	add_filter( 'usces_confirm_discount_label', 'wtd_discount_label', 20, 2 );
	add_filter( 'usces_filter_confirm_inform', 'wtd_confirmation_form', 20 );
	add_filter( 'usces_purchase_check', 'wtd_purchase_check', 20 );
}

/**
 * Build discount inputs from registered SKU prices, before other reductions.
 *
 * @param array $cart Welcart cart rows.
 * @return array Validated calculation lines.
 * @throws InvalidArgumentException For an unknown SKU or invalid number.
 */
function wtd_registered_lines( $cart ) {
	global $usces;
	$lines = array();
	foreach ( (array) $cart as $row ) {
		$sku  = isset( $row['sku_code'] ) ? $row['sku_code'] : urldecode( $row['sku'] );
		$skus = $usces->get_skus( (int) $row['post_id'], 'code', false );
		if ( ! is_array( $skus ) || ! isset( $skus[ $sku ]['price'] ) ) {
			throw new InvalidArgumentException( 'invalid_snapshot' );
		}
		$lines[] = array(
			'post_id'  => (int) $row['post_id'],
			'sku_code' => (string) $sku,
			'price'    => (string) $skus[ $sku ]['price'],
			'quantity' => (string) $row['quantity'],
			'taxrate'  => isset( $skus[ $sku ]['taxrate'] ) && 'reduced' === $skus[ $sku ]['taxrate'] ? 'reduced' : 'standard',
		);
	}
	return $lines;
}

/**
 * Calculate a cart using current or request-pinned settings.
 *
 * @param array|null $cart Cart rows, or the session cart.
 * @return array Discount and its original inputs.
 * @throws InvalidArgumentException For corrupt settings or amounts.
 */
function wtd_current_calculation( $cart = null ) {
	global $usces;
	$settings = isset( $GLOBALS['wtd_locked_settings'] ) ? $GLOBALS['wtd_locked_settings'] : wtd_get_settings();
	if ( is_wp_error( $settings ) ) {
		throw new InvalidArgumentException( 'invalid_schema' );
	}
	$lines              = wtd_registered_lines( null === $cart ? $usces->cart->get_cart() : $cart );
	$result             = wtd_calculate( $settings['tiers'], wtd_subtotal( $lines ) );
	$result['lines']    = $lines;
	$result['settings'] = $settings;
	return $result;
}

/**
 * Allocate the single discount over the two registered-price subtotals.
 *
 * @param array  $lines Calculation lines.
 * @param string $discount Nonnegative discount.
 * @return array Standard and reduced discount amounts.
 */
function wtd_discount_parts( $lines, $discount ) {
	$standard = array();
	$reduced  = array();
	foreach ( $lines as $line ) {
		if ( 'reduced' === $line['taxrate'] ) {
			$reduced[] = $line;
		} else {
			$standard[] = $line;
		}
	}
	$s = wtd_money_cents( wtd_subtotal( $standard ) );
	$r = wtd_money_cents( wtd_subtotal( $reduced ) );
	$d = wtd_money_cents( $discount );
	if ( 0 === $r ) {
		return array( $d / 100, 0.0 );
	}
	if ( 0 === $s ) {
		return array( 0.0, $d / 100 );
	}
	// Whole-yen allocation uses exact integer division; the bounded product fits PHP 64-bit.
	$product = intdiv( $d, 100 ) * $s;
	$whole   = intdiv( $product, $s + $r );
	$rest    = ( $product % ( $s + $r ) ) * 100 + ( $d % 100 ) * $s;
	$a       = min( $s, ( $whole + intdiv( $rest, 100 * ( $s + $r ) ) ) * 100 );
	$b       = min( $r, $d - $a );
	$a       = $d - $b;
	return array( $a / 100, $b / 100 );
}

/**
 * Verify that a decimal survives Welcart's two-decimal amount columns.
 *
 * @param mixed $value Amount.
 * @param bool  $signed Whether negative values are allowed.
 * @return int Amount in hundredths of a yen.
 * @throws InvalidArgumentException If the value cannot be saved exactly.
 */
function wtd_money_cents( $value, $signed = false ) {
	$negative = $signed && is_scalar( $value ) && '-' === substr( (string) $value, 0, 1 );
	$value    = $negative ? substr( (string) $value, 1 ) : $value;
	$decimal  = wtd_subtotal(
		array(
			array(
				'price'    => $value,
				'quantity' => '1',
			),
		)
	);
	$parts    = explode( '.', $decimal );
	if ( isset( $parts[1] ) && strlen( $parts[1] ) > 2 ) {
		throw new InvalidArgumentException( 'amount_out_of_range' );
	}
	$cents = (int) $parts[0] * 100 + (int) str_pad( isset( $parts[1] ) ? $parts[1] : '', 2, '0' );
	return $negative ? -$cents : $cents;
}

/**
 * Format validated cents for native amount columns and comparison tokens.
 *
 * @param int $cents Amount in hundredths of a yen.
 * @return string Fixed decimal amount.
 * @throws InvalidArgumentException On a column overflow.
 */
function wtd_money_string( $cents ) {
	if ( abs( $cents ) > 9999999999 ) {
		throw new InvalidArgumentException( 'amount_out_of_range' );
	}
	return ( $cents < 0 ? '-' : '' ) . intdiv( abs( $cents ), 100 ) . '.' . str_pad( (string) ( abs( $cents ) % 100 ), 2, '0', STR_PAD_LEFT );
}

/**
 * Read-only amounts for an order using its historical tax conditions.
 *
 * @param array $lines Submitted, validated order lines.
 * @param array $settings Order-time settings.
 * @param array $condition Order-time Welcart tax conditions.
 * @param array $fees Shipping, fee and requested points.
 * @return array Recalculated amounts and tax metadata.
 * @throws InvalidArgumentException On corrupt conditions or unsavable values.
 */
function wtd_order_amounts( $lines, $settings, $condition, $fees ) {
	$result = wtd_calculate( $settings['tiers'], wtd_subtotal( $lines ) );
	$s      = wtd_money_cents( $result['subtotal'] );
	$d      = wtd_money_cents( $result['discount'] );
	$ship   = wtd_money_cents( $fees['shipping_charge'] );
	$fee    = wtd_money_cents( $fees['cod_fee'] );
	$points = filter_var(
		$fees['usedpoint'],
		FILTER_VALIDATE_INT,
		array(
			'options' => array(
				'min_range' => 0,
				'max_range' => 99999999,
			),
		)
	);
	if ( false === $points || ! isset( $condition['tax_mode'], $condition['tax_target'], $condition['tax_rate'], $condition['tax_method'] ) || ! in_array( $condition['tax_mode'], array( 'include', 'exclude' ), true ) || ! in_array( $condition['tax_target'], array( 'products', 'all' ), true ) ) {
		throw new InvalidArgumentException( 'invalid_snapshot' );
	}
	$split    = wtd_discount_parts( $lines, $result['discount'] );
	$standard = array_values( array_filter( $lines, 'wtd_is_standard_line' ) );
	$reduced  = array_values( array_filter( $lines, 'wtd_is_reduced_line' ) );
	$base_s   = wtd_money_cents( wtd_subtotal( $standard ) );
	$base_r   = wtd_money_cents( wtd_subtotal( $reduced ) );
	$mixed    = isset( $condition['applicable_taxrate'] ) && 'reduced' === $condition['applicable_taxrate'];
	$coverage = ! empty( $condition['point_coverage'] );
	$amounts  = array(
		'subtotal_standard' => ( $base_s + ( 'all' === $condition['tax_target'] ? $ship + $fee : 0 ) ) / 100,
		'subtotal_reduced'  => $base_r / 100,
		'discount_standard' => -$split[0],
		'discount_reduced'  => -$split[1],
	);
	$tax_s    = 0;
	$tax_r    = 0;
	// The second pass uses adjusted points for the native pre-tax points branch.
	for ( $pass = 0; $pass < 2; ++$pass ) {
		if ( $mixed ) {
			$tax_s = wtd_native_tax( $amounts['subtotal_standard'] - $split[0], $condition['tax_rate'], $condition );
			$tax_r = wtd_native_tax( $amounts['subtotal_reduced'] - $split[1], $condition['tax_rate_reduced'], $condition );
		} else {
			$base = $s - $d + ( 'all' === $condition['tax_target'] ? $ship + $fee : 0 );
			if ( ! $coverage && 'all' === $condition['tax_target'] ) {
				$base -= $points * 100;
			}
			$tax_s = wtd_native_tax( max( 0, $base ) / 100, $condition['tax_rate'], $condition );
		}
		$tax    = 'exclude' === $condition['tax_mode'] ? wtd_money_cents( $tax_s + $tax_r ) : 0;
		$limit  = $s - $d + ( $coverage ? $ship + $fee + $tax : ( 'products' === $condition['tax_target'] ? $tax : 0 ) );
		$points = min( $points, intdiv( max( 0, $limit ), 100 ) );
	}
	$result['shipping_charge']    = wtd_money_string( $ship );
	$result['cod_fee']            = wtd_money_string( $fee );
	$result['usedpoint']          = $points;
	$result['tax']                = wtd_money_string( $tax );
	$result['internal_tax']       = 'include' === $condition['tax_mode'] ? wtd_money_string( wtd_money_cents( $tax_s + $tax_r ) ) : '0.00';
	$result['internal_tax_parts'] = array(
		'standard' => 'include' === $condition['tax_mode'] ? $tax_s : 0,
		'reduced'  => 'include' === $condition['tax_mode'] ? $tax_r : 0,
	);
	$result['total']              = wtd_money_string( $s - $d + $ship + $fee + $tax - $points * 100 );
	$amounts['tax_standard']      = 'include' === $condition['tax_mode'] ? 0 : $tax_s;
	$amounts['tax_reduced']       = 'include' === $condition['tax_mode'] ? 0 : $tax_r;
	$result['tax_parts']          = $amounts;
	return $result;
}

/**
 * Select a standard tax line.
 *
 * @param array $line Validated line.
 * @return bool Whether the line uses the standard rate.
 */
function wtd_is_standard_line( $line ) {
	return 'reduced' !== $line['taxrate'];
}

/**
 * Select a reduced tax line.
 *
 * @param array $line Validated line.
 * @return bool Whether the line uses the reduced rate.
 */
function wtd_is_reduced_line( $line ) {
	return 'reduced' === $line['taxrate'];
}

/**
 * Delegate one validated tax base to Welcart using historical rate and rounding.
 *
 * @param float $base Taxable amount.
 * @param mixed $rate Percentage rate.
 * @param array $condition Historical conditions.
 * @return float Tax, before distinguishing included and added tax.
 * @throws InvalidArgumentException For corrupt rates or rounding settings.
 */
function wtd_native_tax( $base, $rate, $condition ) {
	if ( ! is_numeric( $rate ) || $rate < 0 || $rate > 100 || ! in_array( $condition['tax_method'], array( 'cutting', 'bring', 'rounding' ), true ) ) {
		throw new InvalidArgumentException( 'invalid_snapshot' );
	}
	global $usces;
	$old_options = $usces->options;
	try {
		// This base already includes the selected rate's discount, fees and points.
		// Calling the cart-wide API would re-read current SKUs and lose draft tax classes.
		$usces->options = array_replace(
			$usces->options,
			$condition,
			array(
				'applicable_taxrate' => 'standard',
				'tax_rate'           => $rate,
				'tax_display'        => $condition['tax_display'] ?? 'activate',
			)
		);
		if ( 'include' === $condition['tax_mode'] && ( ! isset( $condition['applicable_taxrate'] ) || 'reduced' !== $condition['applicable_taxrate'] ) ) {
			$materials = array(
				'total_items_price' => max( 0, $base ),
				'discount'          => 0,
				'shipping_charge'   => 0,
				'cod_fee'           => 0,
				'use_point'         => 0,
				'condition'         => $usces->options,
			);
			return (float) usces_internal_tax( $materials, 'return' );
		}
		return $usces->getTax( max( 0, $base ) );
	} finally {
		$usces->options = $old_options;
	}
}

/**
 * Replace, rather than accumulate, the discount at every native recalculation.
 *
 * @param float $discount Native discount.
 * @param array $cart Cart rows.
 * @return float Negative discount.
 */
function wtd_discount_filter( $discount, $cart ) {
	try {
		$result = wtd_current_calculation( $cart );
		$parts  = wtd_discount_parts( $result['lines'], $result['discount'] );
		$tax    = Welcart_Tax::get_instance();
		// Welcart resets the singleton before each calculation, so sync inside this filter.
		$tax->discount_standard = -$parts[0];
		$tax->discount_reduced  = -$parts[1];
		$tax->discount          = - (float) $result['discount'];
		return $tax->discount;
	} catch ( InvalidArgumentException $error ) {
		$GLOBALS['wtd_calculation_error'] = $error->getMessage();
		return 0;
	}
}

/**
 * Explain a selected tier in the existing amount row.
 *
 * @param array|null $tier Selected tier.
 * @return string Plain text for later escaping.
 */
function wtd_tier_text( $tier ) {
	if ( null === $tier ) {
		return __( '適用なし', 'welcart-tiered-discounts' );
	}
	$value = 'fixed' === $tier['type'] ? number_format_i18n( $tier['value'] ) . __( '円引き', 'welcart-tiered-discounts' ) : rtrim( rtrim( number_format( $tier['value'] / 100, 2, '.', '' ), '0' ), '.' ) . '%';
	/* translators: 1: tier threshold, 2: discount amount or percentage. */
	return sprintf( __( '%1$s円以上：%2$s', 'welcart-tiered-discounts' ), number_format_i18n( $tier['threshold'] ), $value );
}

/**
 * Add one discount row to the native cart table.
 *
 * @param string $html Existing footer.
 * @return string Footer with the current discount.
 */
function wtd_cart_footer( $html ) {
	try {
		$result = wtd_current_calculation();
		if ( (float) $result['discount'] <= 0 ) {
			return $html;
		}
		$row = '<tr class="wtd-cart-discount"><th colspan="5" scope="row">' . esc_html__( 'ステップ割引', 'welcart-tiered-discounts' ) . ' <small>' . esc_html( wtd_tier_text( $result['tier'] ) ) . '</small></th><td class="aright">−' . esc_html( number_format_i18n( (float) $result['discount'] ) ) . '</td><td colspan="2"></td></tr>';
		return str_replace( '</tfoot>', $row . '</tfoot>', $html );
	} catch ( InvalidArgumentException $error ) {
		wtd_log_error( $error->getMessage(), 'cart' );
		return $html . '<p role="alert" class="wtd-error">' . esc_html__( '割引設定を確認できません。現在ご注文を確定できないため、店舗へお問い合わせください。', 'welcart-tiered-discounts' ) . '</p>';
	}
}

/**
 * Label native discount rows without recalculating historical orders.
 *
 * @param string   $label Existing label.
 * @param int|null $order_id Historical order when supplied by Welcart.
 * @return string Escaped discount label.
 */
function wtd_discount_label( $label, $order_id = null ) {
	if ( $order_id ) {
		global $usces;
		if ( null === $usces->get_order_meta_value( 'wtd_discount_snapshot', $order_id ) ) {
			return $label;
		}
		return esc_html__( 'ステップ割引', 'welcart-tiered-discounts' );
	}
	try {
		$result = wtd_current_calculation();
		return esc_html__( 'ステップ割引', 'welcart-tiered-discounts' ) . ' <small>' . esc_html( wtd_tier_text( $result['tier'] ) ) . '</small>';
	} catch ( InvalidArgumentException $error ) {
		return esc_html__( 'ステップ割引（確認できません）', 'welcart-tiered-discounts' );
	}
}

/**
 * Collect exactly the conditions and amounts that require buyer confirmation.
 *
 * @return array Current display state.
 * @throws InvalidArgumentException For corrupt or unsavable money.
 */
function wtd_checkout_state() {
	global $usces;
	$result    = wtd_current_calculation();
	$entry     = $usces->cart->get_entry();
	$order     = $entry['order'];
	$condition = $usces->get_condition();
	$amounts   = array();
	foreach ( array( 'total_items_price', 'discount', 'shipping_charge', 'cod_fee', 'tax', 'total_full_price' ) as $key ) {
		if ( ! isset( $order[ $key ] ) ) {
			throw new InvalidArgumentException( 'amount_out_of_range' );
		}
		$amounts[ $key ] = wtd_money_string( wtd_money_cents( $order[ $key ], 'discount' === $key ) );
	}
	if ( wtd_money_cents( $result['discount'] ) !== -wtd_money_cents( $order['discount'], true ) || (float) $order['total_items_price'] < (float) $result['discount'] ) {
		throw new InvalidArgumentException( 'amount_out_of_range' );
	}
	$amounts['usedpoint'] = isset( $order['usedpoint'] ) ? (int) $order['usedpoint'] : 0;
	$amounts['getpoint']  = isset( $order['getpoint'] ) ? (int) $order['getpoint'] : 0;
	$tax_parts            = array();
	if ( 'reduced' === $condition['applicable_taxrate'] && 'deactivate' !== $condition['tax_display'] ) {
		$tax = Welcart_Tax::get_instance();
		$tax->get_order_tax(
			array(
				'carts'             => $usces->cart->get_cart(),
				'condition'         => $condition,
				'total_items_price' => $order['total_items_price'],
				'shipping_charge'   => $order['shipping_charge'],
				'cod_fee'           => $order['cod_fee'],
				'use_point'         => $amounts['usedpoint'],
			)
		);
		foreach ( array( 'subtotal_standard', 'subtotal_reduced', 'discount_standard', 'discount_reduced', 'tax_standard', 'tax_reduced' ) as $key ) {
			$value             = 'include' === $condition['tax_mode'] && 0 === strpos( $key, 'tax_' ) ? 0 : $tax->$key;
			$tax_parts[ $key ] = wtd_money_string( wtd_money_cents( $value, true ) );
		}
	}
	return array(
		'calculation' => $result,
		'condition'   => $condition,
		'amounts'     => $amounts,
		'tax_parts'   => $tax_parts,
	);
}

/**
 * Attach a per-display token after Welcart calculates the confirmation table.
 *
 * @param string $html Native purchase form.
 * @return string Form and confirmation token.
 */
function wtd_confirmation_form( $html ) {
	try {
		$state                        = wtd_checkout_state();
		$token                        = wp_generate_password( 32, false, false );
		$_SESSION['wtd_confirmation'] = array(
			'token' => $token,
			'state' => $state,
		);
		return $html . '<input type="hidden" name="wtd_confirmation" value="' . esc_attr( $token ) . '" />';
	} catch ( InvalidArgumentException $error ) {
		unset( $_SESSION['wtd_confirmation'] );
		wtd_log_error( $error->getMessage(), 'confirmation' );
		return $html . '<p role="alert" class="wtd-error">' . esc_html__( '割引や金額を確認できないため、ご注文を確定できません。店舗へお問い合わせください。', 'welcart-tiered-discounts' ) . '</p>';
	}
}

/**
 * Recheck the displayed quote before native purchase or payment initiation.
 *
 * @param bool $allowed Earlier purchase checks.
 * @return bool Whether the verified purchase can continue.
 * @throws InvalidArgumentException Caught here to require confirmation again.
 */
function wtd_purchase_check( $allowed ) {
	global $usces;
	if ( ! $allowed ) {
		return false;
	}
	// This is a session-bound display token, in addition to Welcart's purchase nonce.
	$token = isset( $_POST['wtd_confirmation'] ) && is_string( $_POST['wtd_confirmation'] ) ? sanitize_text_field( wp_unslash( $_POST['wtd_confirmation'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Welcart handles the purchase nonce; the display token is checked below.
	$saved = isset( $_SESSION['wtd_confirmation'] ) ? $_SESSION['wtd_confirmation'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server-created state is compared exactly, never displayed.
	if ( ! is_array( $saved ) || ! isset( $saved['token'], $saved['state'] ) || ! hash_equals( $saved['token'], $token ) ) {
		return wtd_reconfirm();
	}
	try {
		$settings = wtd_get_settings();
		if ( is_wp_error( $settings ) ) {
			throw new InvalidArgumentException( 'invalid_schema' );
		}
		$GLOBALS['wtd_locked_settings'] = $settings;
		$member                         = $usces->get_member();
		$usces->set_cart_fees( $member, $usces->cart->get_entry() );
		$entry = $usces->cart->get_entry();
		if ( ! empty( $entry['order']['usedpoint'] ) ) {
			// point_check reads POST and its argument; pass the freshly recalculated entry.
			$old_offer = isset( $_POST['offer'] ) ? $_POST['offer'] : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Preserved and restored, never used as a trusted amount.
			try {
				$_POST['offer'] = array( 'usedpoint' => $entry['order']['usedpoint'] );
				$usces->point_check( $entry );
			} finally {
				if ( null === $old_offer ) {
					unset( $_POST['offer'] );
				} else {
					$_POST['offer'] = $old_offer;
				}
			}
			$usces->set_cart_fees( $member, $usces->cart->get_entry() );
		}
		$state = wtd_checkout_state();
		if ( $state !== $saved['state'] ) {
			return wtd_reconfirm();
		}
		$GLOBALS['wtd_verified_purchase'] = $state;
		return true;
	} catch ( InvalidArgumentException $error ) {
		wtd_log_error( $error->getMessage(), 'purchase' );
		return wtd_reconfirm();
	}
}

/** Return through Welcart's existing confirmation rendering path. */
function wtd_reconfirm() {
	global $usces;
	unset( $GLOBALS['wtd_verified_purchase'] );
	$usces->error_message = __( '割引条件または金額を再確認してください。表示された内容を確認してから、ご注文を確定してください。', 'welcart-tiered-discounts' );
	$usces->page          = 'confirm';
	add_action( 'the_post', array( $usces, 'action_cartFilter' ) );
	add_action( 'template_redirect', array( $usces, 'template_redirect' ) );
	return false;
}

/**
 * Record only an allowlisted reason, location and non-secret order identifier.
 *
 * @param string $reason Reason code.
 * @param string $location Processing location.
 * @param int    $order_id Optional order ID.
 */
function wtd_log_error( $reason, $location, $order_id = 0 ) {
	$allowed = array( 'invalid_schema', 'invalid_tier', 'invalid_snapshot', 'amount_out_of_range', 'order_save_failed' );
	$reason  = in_array( $reason, $allowed, true ) ? $reason : 'amount_out_of_range';
	$key     = $reason . ':' . $location . ':' . (int) $order_id;
	if ( empty( $GLOBALS['wtd_logged_errors'][ $key ] ) ) {
		$GLOBALS['wtd_logged_errors'][ $key ] = true;
		error_log( 'WTD ' . $reason . ' location=' . sanitize_key( $location ) . ' order_id=' . (int) $order_id ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Required local, non-secret operational error log.
	}
}
