<?php
/**
 * Pure subtotal and tier discount calculation.
 *
 * @package Welcart_Tiered_Discounts
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Normalize a finite, nonnegative number to a canonical decimal string.
 *
 * The normalizer intentionally avoids a float conversion for decimal strings.
 * This keeps fractional quantities and prices exact through subtotal
 * calculation and also gives integration tests one public round-trip token.
 *
 * @param mixed $value Number to normalize.
 * @return string Canonical decimal string.
 * @throws InvalidArgumentException If the value is not a finite nonnegative number.
 */
function wtd_normalize_decimal( $value ): string {
	return wtd_decimal_format( wtd_decimal_parse( $value ) );
}

/**
 * Calculate the registered-price subtotal for cart lines.
 *
 * A line may contain additional Welcart metadata; only its price and quantity
 * participate in this pure calculation.
 *
 * @param array<mixed> $lines Cart lines containing price and quantity.
 * @return string Canonical nonnegative decimal subtotal.
 * @throws InvalidArgumentException For malformed lines or an unsafe subtotal.
 */
function wtd_subtotal( array $lines ): string {
	$total   = array(
		'digits' => '0',
		'scale'  => 0,
	);
	$maximum = array(
		'digits' => '9999999999',
		'scale'  => 2,
	);

	foreach ( $lines as $line ) {
		if ( ! is_array( $line ) || ! array_key_exists( 'price', $line ) || ! array_key_exists( 'quantity', $line ) ) {
			throw new InvalidArgumentException( 'invalid_line' );
		}

		$price    = wtd_decimal_parse( $line['price'] );
		$quantity = wtd_decimal_parse( $line['quantity'] );
		if ( wtd_decimal_is_zero( $price ) || wtd_decimal_is_zero( $quantity ) ) {
			continue;
		}

		$line_total = wtd_decimal_multiply( $price, $quantity );
		if ( wtd_decimal_compare( $line_total, $maximum ) > 0 ) {
			throw new InvalidArgumentException( 'amount_out_of_range' );
		}

		$total = wtd_decimal_add( $total, $line_total );
		if ( wtd_decimal_compare( $total, $maximum ) > 0 ) {
			throw new InvalidArgumentException( 'amount_out_of_range' );
		}
	}

	return wtd_decimal_format( $total );
}

/**
 * Select and calculate the highest eligible tier.
 *
 * @param array<mixed> $tiers Validated tier-shaped values.
 * @param mixed        $subtotal Registered-price subtotal.
 * @return array{subtotal: string, discount: string, tier: array<string, mixed>|null} Calculation result.
 * @throws InvalidArgumentException For malformed tiers or an unsafe subtotal.
 */
function wtd_calculate( array $tiers, $subtotal ): array {
	if ( ! wtd_decimal_is_list( $tiers ) ) {
		throw new InvalidArgumentException( 'invalid_tiers' );
	}

	$amount  = wtd_decimal_parse( $subtotal );
	$maximum = array(
		'digits' => '9999999999',
		'scale'  => 2,
	);
	if ( wtd_decimal_compare( $amount, $maximum ) > 0 ) {
		throw new InvalidArgumentException( 'amount_out_of_range' );
	}

	$selected        = null;
	$seen_thresholds = array();
	foreach ( $tiers as $tier ) {
		if ( ! is_array( $tier ) ) {
			throw new InvalidArgumentException( 'invalid_tier' );
		}
		wtd_validate_tier( $tier );

		$threshold = $tier['threshold'];
		if ( isset( $seen_thresholds[ $threshold ] ) ) {
			throw new InvalidArgumentException( 'invalid_tier' );
		}
		$seen_thresholds[ $threshold ] = true;

		if ( ! $tier['enabled'] || wtd_decimal_compare(
			array(
				'digits' => (string) $threshold,
				'scale'  => 0,
			),
			$amount
		) > 0 ) {
			continue;
		}
		if ( null === $selected || $threshold > $selected['threshold'] ) {
			$selected = $tier;
		}
	}

	if ( null === $selected ) {
		return array(
			'subtotal' => wtd_decimal_format( $amount ),
			'discount' => '0',
			'tier'     => null,
		);
	}

	if ( 'fixed' === $selected['type'] ) {
		$discount = array(
			'digits' => (string) $selected['value'],
			'scale'  => 0,
		);
	} else {
		$discount = wtd_decimal_percentage_floor( $amount, $selected['value'] );
	}
	if ( wtd_decimal_compare( $discount, $amount ) > 0 ) {
		$discount = $amount;
	}

	return array(
		'subtotal' => wtd_decimal_format( $amount ),
		'discount' => wtd_decimal_format( $discount ),
		'tier'     => $selected,
	);
}

/**
 * Validate one tier without depending on the settings module.
 *
 * @param array<mixed> $tier Tier to validate.
 * @return void
 * @throws InvalidArgumentException If the tier shape or value is invalid.
 */
function wtd_validate_tier( array $tier ): void {
	$actual   = array_keys( $tier );
	$expected = array( 'threshold', 'type', 'value', 'enabled' );
	sort( $actual );
	sort( $expected );
	if ( $actual !== $expected ) {
		throw new InvalidArgumentException( 'invalid_tier' );
	}
	if ( ! is_int( $tier['threshold'] ) || $tier['threshold'] < 1 || $tier['threshold'] > 99999999 ) {
		throw new InvalidArgumentException( 'invalid_tier' );
	}
	if ( ! is_string( $tier['type'] ) || ! in_array( $tier['type'], array( 'fixed', 'percentage' ), true ) ) {
		throw new InvalidArgumentException( 'invalid_tier' );
	}
	if ( ! is_int( $tier['value'] ) || $tier['value'] < 1 ) {
		throw new InvalidArgumentException( 'invalid_tier' );
	}
	if ( 'fixed' === $tier['type'] && $tier['value'] > 99999999 ) {
		throw new InvalidArgumentException( 'invalid_tier' );
	}
	if ( 'percentage' === $tier['type'] && $tier['value'] > 10000 ) {
		throw new InvalidArgumentException( 'invalid_tier' );
	}
	if ( ! is_bool( $tier['enabled'] ) ) {
		throw new InvalidArgumentException( 'invalid_tier' );
	}
}

/**
 * Parse a decimal or exponent-form number into unsigned integer digits and a scale.
 *
 * @param mixed $value Number to parse.
 * @return array{digits: string, scale: int} Parsed decimal.
 * @throws InvalidArgumentException If the value is malformed or non-finite.
 */
function wtd_decimal_parse( $value ): array {
	if ( is_int( $value ) ) {
		if ( $value < 0 ) {
			throw new InvalidArgumentException( 'invalid_number' );
		}
		$text = (string) $value;
	} elseif ( is_float( $value ) ) {
		if ( ! is_finite( $value ) || $value < 0 ) {
			throw new InvalidArgumentException( 'invalid_number' );
		}
		$text = (string) $value;
	} elseif ( is_string( $value ) ) {
		$text = $value;
	} else {
		throw new InvalidArgumentException( 'invalid_number' );
	}

	if ( '' === $text || strlen( $text ) > 10000 || ! preg_match( '/\A\+?(?:(?:[0-9]+(?:\.[0-9]*)?)|(?:\.[0-9]+))(?:[eE][+-]?[0-9]+)?\z/D', $text ) ) {
		throw new InvalidArgumentException( 'invalid_number' );
	}

	$text = ltrim( $text, '+' );
	preg_match( '/\A([^eE]*)(?:[eE]([+-]?[0-9]+))?\z/D', $text, $parts );
	$mantissa = $parts[1];
	$exponent = 0;
	if ( isset( $parts[2] ) && '' !== $parts[2] ) {
		$exponent_text = $parts[2];
		$negative      = '-' === $exponent_text[0];
		if ( $negative || '+' === $exponent_text[0] ) {
			$exponent_text = substr( $exponent_text, 1 );
		}
		$exponent_text = ltrim( $exponent_text, '0' );
		$exponent_text = '' === $exponent_text ? '0' : $exponent_text;
		if ( strlen( $exponent_text ) > 5 || ( strlen( $exponent_text ) === 5 && strcmp( $exponent_text, '10000' ) > 0 ) ) {
			throw new InvalidArgumentException( 'invalid_number' );
		}
		$exponent = (int) $exponent_text;
		if ( $negative ) {
			$exponent = -$exponent;
		}
	}

	$decimal_parts = explode( '.', $mantissa, 2 );
	$integer       = $decimal_parts[0];
	$fraction      = isset( $decimal_parts[1] ) ? $decimal_parts[1] : '';
	$digits        = ltrim( $integer . $fraction, '0' );
	if ( '' === $digits ) {
		return array(
			'digits' => '0',
			'scale'  => 0,
		);
	}

	$scale = strlen( $fraction ) - $exponent;
	if ( $scale < 0 ) {
		$append = -$scale;
		if ( strlen( $digits ) + $append > 10000 ) {
			throw new InvalidArgumentException( 'invalid_number' );
		}
		$digits .= str_repeat( '0', $append );
		$scale   = 0;
	}
	if ( $scale > 10000 || strlen( $digits ) + $scale > 10000 ) {
		throw new InvalidArgumentException( 'invalid_number' );
	}

	return wtd_decimal_trim(
		array(
			'digits' => $digits,
			'scale'  => $scale,
		)
	);
}

/**
 * Add two unsigned decimal representations.
 *
 * @param array{digits: string, scale: int} $left  Left operand.
 * @param array{digits: string, scale: int} $right Right operand.
 * @return array{digits: string, scale: int} Sum.
 */
function wtd_decimal_add( array $left, array $right ): array {
	$scale        = max( $left['scale'], $right['scale'] );
	$left_digits  = $left['digits'] . str_repeat( '0', $scale - $left['scale'] );
	$right_digits = $right['digits'] . str_repeat( '0', $scale - $right['scale'] );
	$sum          = '';
	$left_index   = strlen( $left_digits ) - 1;
	$right_index  = strlen( $right_digits ) - 1;
	$carry        = 0;
	while ( $left_index >= 0 || $right_index >= 0 || $carry > 0 ) {
		$digit = $carry;
		if ( $left_index >= 0 ) {
			$digit += ord( $left_digits[ $left_index ] ) - 48;
			--$left_index;
		}
		if ( $right_index >= 0 ) {
			$digit += ord( $right_digits[ $right_index ] ) - 48;
			--$right_index;
		}
		$sum  .= (string) ( $digit % 10 );
		$carry = intdiv( $digit, 10 );
	}

	return wtd_decimal_trim(
		array(
			'digits' => strrev( $sum ),
			'scale'  => $scale,
		)
	);
}

/**
 * Multiply two unsigned decimal representations.
 *
 * @param array{digits: string, scale: int} $left  Left operand.
 * @param array{digits: string, scale: int} $right Right operand.
 * @return array{digits: string, scale: int} Product.
 */
function wtd_decimal_multiply( array $left, array $right ): array {
	if ( wtd_decimal_is_zero( $left ) || wtd_decimal_is_zero( $right ) ) {
		return array(
			'digits' => '0',
			'scale'  => 0,
		);
	}

	$left_length  = strlen( $left['digits'] );
	$right_length = strlen( $right['digits'] );
	$result       = array_fill( 0, $left_length + $right_length, 0 );
	for ( $left_index = $left_length - 1; $left_index >= 0; --$left_index ) {
		for ( $right_index = $right_length - 1; $right_index >= 0; --$right_index ) {
			$result[ $left_index + $right_index + 1 ] += ( ord( $left['digits'][ $left_index ] ) - 48 ) * ( ord( $right['digits'][ $right_index ] ) - 48 );
		}
	}
	for ( $index = count( $result ) - 1; $index > 0; --$index ) {
		$carry                 = intdiv( $result[ $index ], 10 );
		$result[ $index ]     %= 10;
		$result[ $index - 1 ] += $carry;
	}

	return wtd_decimal_trim(
		array(
			'digits' => ltrim( implode( '', $result ), '0' ),
			'scale'  => $left['scale'] + $right['scale'],
		)
	);
}

/**
 * Calculate floor(S * basis points / 10000) as whole currency units.
 *
 * @param array{digits: string, scale: int} $subtotal Subtotal.
 * @param int                               $value    Percentage in hundredths of a percent.
 * @return array{digits: string, scale: int} Whole-unit discount.
 */
function wtd_decimal_percentage_floor( array $subtotal, int $value ): array {
	$product = wtd_decimal_multiply_integer( $subtotal['digits'], $value );
	$cut     = $subtotal['scale'] + 4;
	if ( $cut >= strlen( $product ) ) {
		return array(
			'digits' => '0',
			'scale'  => 0,
		);
	}

	return wtd_decimal_trim(
		array(
			'digits' => substr( $product, 0, strlen( $product ) - $cut ),
			'scale'  => 0,
		)
	);
}

/**
 * Multiply an unsigned integer string by a small positive integer.
 *
 * @param string $digits Integer digits.
 * @param int    $value  Multiplier.
 * @return string Integer product digits.
 */
function wtd_decimal_multiply_integer( string $digits, int $value ): string {
	if ( 0 === $value || '0' === $digits ) {
		return '0';
	}
	$result = '';
	$carry  = 0;
	for ( $index = strlen( $digits ) - 1; $index >= 0; --$index ) {
		$product = ( ord( $digits[ $index ] ) - 48 ) * $value + $carry;
		$result .= (string) ( $product % 10 );
		$carry   = intdiv( $product, 10 );
	}
	while ( $carry > 0 ) {
		$result .= (string) ( $carry % 10 );
		$carry   = intdiv( $carry, 10 );
	}

	return strrev( $result );
}

/**
 * Compare two unsigned decimal representations.
 *
 * @param array{digits: string, scale: int} $left  Left operand.
 * @param array{digits: string, scale: int} $right Right operand.
 * @return int -1, 0, or 1.
 */
function wtd_decimal_compare( array $left, array $right ): int {
	$left                 = wtd_decimal_trim( $left );
	$right                = wtd_decimal_trim( $right );
	$left_integer_length  = strlen( $left['digits'] ) - $left['scale'];
	$right_integer_length = strlen( $right['digits'] ) - $right['scale'];
	if ( $left_integer_length !== $right_integer_length ) {
		return $left_integer_length < $right_integer_length ? -1 : 1;
	}

	$scale        = max( $left['scale'], $right['scale'] );
	$left_digits  = $left['digits'] . str_repeat( '0', $scale - $left['scale'] );
	$right_digits = $right['digits'] . str_repeat( '0', $scale - $right['scale'] );
	$length       = max( strlen( $left_digits ), strlen( $right_digits ) );
	$left_digits  = str_pad( $left_digits, $length, '0', STR_PAD_LEFT );
	$right_digits = str_pad( $right_digits, $length, '0', STR_PAD_LEFT );
	return strcmp( $left_digits, $right_digits ) <=> 0;
}

/**
 * Format a decimal representation without a sign or redundant zeroes.
 *
 * @param array{digits: string, scale: int} $number Decimal representation.
 * @return string Canonical decimal string.
 */
function wtd_decimal_format( array $number ): string {
	$number = wtd_decimal_trim( $number );
	if ( '0' === $number['digits'] ) {
		return '0';
	}
	if ( 0 === $number['scale'] ) {
		return $number['digits'];
	}
	if ( $number['scale'] >= strlen( $number['digits'] ) ) {
		return '0.' . str_repeat( '0', $number['scale'] - strlen( $number['digits'] ) ) . $number['digits'];
	}

	$position = strlen( $number['digits'] ) - $number['scale'];
	return substr( $number['digits'], 0, $position ) . '.' . substr( $number['digits'], $position );
}

/**
 * Remove leading integer zeroes and insignificant fractional trailing zeroes.
 *
 * @param array{digits: string, scale: int} $number Decimal representation.
 * @return array{digits: string, scale: int} Trimmed representation.
 */
function wtd_decimal_trim( array $number ): array {
	$digits = ltrim( $number['digits'], '0' );
	if ( '' === $digits ) {
		return array(
			'digits' => '0',
			'scale'  => 0,
		);
	}
	$scale = $number['scale'];
	while ( $scale > 0 && '0' === substr( $digits, -1 ) ) {
		$digits = substr( $digits, 0, -1 );
		--$scale;
	}

	return array(
		'digits' => '' === $digits ? '0' : $digits,
		'scale'  => $scale,
	);
}

/**
 * Check whether a decimal representation is zero.
 *
 * @param array{digits: string, scale: int} $number Decimal representation.
 * @return bool Whether the number is zero.
 */
function wtd_decimal_is_zero( array $number ): bool {
	return '0' === $number['digits'];
}

/**
 * Check whether an array is a zero-based sequential list.
 *
 * @param array<mixed> $value Candidate list.
 * @return bool Whether keys are 0..n-1.
 */
function wtd_decimal_is_list( array $value ): bool {
	if ( array() === $value ) {
		return true;
	}

	return array_keys( $value ) === range( 0, count( $value ) - 1 );
}
