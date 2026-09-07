<?php
/**
 * Pure subtotal and tier calculation tests.
 *
 * @package Welcart_Tiered_Discounts
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Verify exact subtotal and tier calculation behavior.
 */
final class DiscountTest extends TestCase {
	/** Verify that an empty cart has no amount to discount. */
	public function test_empty_lines_have_zero_subtotal(): void {
		$this->assertSame( '0', wtd_subtotal( array() ) );
	}

	/**
	 * Verify subtotal multiplication and addition retain decimal values.
	 *
	 * @param array  $lines    Cart lines.
	 * @param string $expected Expected subtotal.
	 * @dataProvider subtotal_cases
	 */
	public function test_subtotal_preserves_decimal_arithmetic( $lines, string $expected ): void {
		$this->assertSame( $expected, wtd_subtotal( $lines ) );
	}

	/** Return representative exact subtotal inputs. */
	public static function subtotal_cases(): array {
		return array(
			'integer line'                    => array(
				array(
					array(
						'price'    => '5000',
						'quantity' => '2',
					),
				),
				'10000',
			),
			'multiple lines'                  => array(
				array(
					array(
						'price'    => '5000',
						'quantity' => '2',
					),
					array(
						'price'    => '1250',
						'quantity' => '4',
					),
				),
				'15000',
			),
			'filtered line keys remain valid' => array(
				array(
					1 => array(
						'price'    => '1.25',
						'quantity' => '2',
					),
					3 => array(
						'price'    => '0.50',
						'quantity' => '1',
					),
				),
				'3',
			),
			'fractional price and quantity'   => array(
				array(
					array(
						'price'    => '12.50',
						'quantity' => '1.2',
					),
				),
				'15',
			),
			'fractional quantity precision'   => array(
				array(
					array(
						'price'    => '1',
						'quantity' => '0.3333333333',
					),
				),
				'0.3333333333',
			),
			'exponent notation'               => array(
				array(
					array(
						'price'    => '1e2',
						'quantity' => '2',
					),
				),
				'200',
			),
			'float input'                     => array(
				array(
					array(
						'price'    => 12.5,
						'quantity' => 0.8,
					),
				),
				'10',
			),
			'upper bound'                     => array(
				array(
					array(
						'price'    => '99999999.99',
						'quantity' => '1',
					),
				),
				'99999999.99',
			),
			'large intermediate product'      => array(
				array(
					array(
						'price'    => '99999999.99',
						'quantity' => '0.0000001',
					),
				),
				'9.999999999',
			),
		);
	}

	/** Verify the canonical decimal token used by integration round-trip checks. */
	public function test_decimal_normalizer_is_public_for_round_trip_checks(): void {
		$this->assertSame( '1.23', wtd_normalize_decimal( '001.2300' ) );
		$this->assertSame( '0', wtd_normalize_decimal( '0.000' ) );
		$this->assertSame( '1.2', wtd_normalize_decimal( 1.2 ) );
	}

	/**
	 * Verify malformed and overflowing cart numbers fail safely.
	 *
	 * @param array  $lines  Invalid cart lines.
	 * @param string $reason Expected error code.
	 * @dataProvider invalid_subtotal_inputs
	 */
	public function test_subtotal_rejects_invalid_or_unsafe_numbers( $lines, string $reason ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $reason );

		wtd_subtotal( $lines );
	}

	/** Return malformed subtotal inputs and their error codes. */
	public static function invalid_subtotal_inputs(): array {
		return array(
			'null line'         => array(
				array( null ),
				'invalid_line',
			),
			'non array line'    => array(
				array( 'invalid' ),
				'invalid_line',
			),
			'missing price'     => array(
				array( array( 'quantity' => '1' ) ),
				'invalid_line',
			),
			'malformed price'   => array(
				array(
					array(
						'price'    => '12 yen',
						'quantity' => '1',
					),
				),
				'invalid_number',
			),
			'negative quantity' => array(
				array(
					array(
						'price'    => '12',
						'quantity' => '-1',
					),
				),
				'invalid_number',
			),
			'nan string'        => array(
				array(
					array(
						'price'    => 'NAN',
						'quantity' => '1',
					),
				),
				'invalid_number',
			),
			'infinite float'    => array(
				array(
					array(
						'price'    => INF,
						'quantity' => '1',
					),
				),
				'invalid_number',
			),
			'over maximum'      => array(
				array(
					array(
						'price'    => '100000000',
						'quantity' => '1',
					),
				),
				'amount_out_of_range',
			),
		);
	}

	/** Verify that negative decimal values cannot enter the calculator. */
	public function test_normalizer_rejects_negative_values(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_number' );

		wtd_normalize_decimal( '-0.01' );
	}

	/**
	 * Verify one highest eligible tier is selected and calculated.
	 *
	 * @param array      $tiers            Tier list.
	 * @param string     $subtotal         Subtotal.
	 * @param string     $expected_discount Expected discount.
	 * @param array|null $expected_tier    Expected selected tier.
	 * @dataProvider calculation_cases
	 */
	public function test_calculate_selects_one_highest_eligible_tier( $tiers, $subtotal, $expected_discount, $expected_tier ): void {
		$expected = array(
			'subtotal' => $subtotal,
			'discount' => $expected_discount,
			'tier'     => $expected_tier,
		);

		$this->assertSame( $expected, wtd_calculate( $tiers, $subtotal ) );
	}

	/** Return tier selection and rounding cases. */
	public static function calculation_cases(): array {
		$fixed500   = array(
			'threshold' => 10000,
			'type'      => 'fixed',
			'value'     => 500,
			'enabled'   => true,
		);
		$fixed2000  = array(
			'threshold' => 30000,
			'type'      => 'fixed',
			'value'     => 2000,
			'enabled'   => true,
		);
		$percentage = array(
			'threshold' => 1000,
			'type'      => 'percentage',
			'value'     => 1000,
			'enabled'   => true,
		);

		return array(
			'below first tier'                          => array( array( $fixed500 ), '9999', '0', null ),
			'at first threshold'                        => array( array( $fixed500 ), '10000', '500', $fixed500 ),
			'at upper threshold'                        => array( array( $fixed500, $fixed2000 ), '30000', '2000', $fixed2000 ),
			'upper tier disabled'                       => array(
				array( $fixed500, array_replace( $fixed2000, array( 'enabled' => false ) ) ),
				'30000',
				'500',
				$fixed500,
			),
			'percentage floors fractional yen'          => array( array( $percentage ), '1009', '100', $percentage ),
			'percentage basis points'                   => array(
				array(
					array_replace(
						$percentage,
						array(
							'threshold' => 10000,
							'value'     => 1025,
						)
					),
				),
				'10001',
				'1025',
				array_replace(
					$percentage,
					array(
						'threshold' => 10000,
						'value'     => 1025,
					)
				),
			),
			'upper threshold wins over larger lower discount' => array(
				array(
					array_replace( $fixed500, array( 'value' => 2000 ) ),
					array_replace( $fixed2000, array( 'value' => 500 ) ),
				),
				'30000',
				'500',
				array_replace( $fixed2000, array( 'value' => 500 ) ),
			),
			'fixed discount is capped'                  => array(
				array( array_replace( $fixed500, array( 'threshold' => 1 ) ) ),
				'100',
				'100',
				array_replace( $fixed500, array( 'threshold' => 1 ) ),
			),
			'percentage discount is capped'             => array(
				array(
					array_replace(
						$percentage,
						array(
							'threshold' => 1,
							'value'     => 10000,
						)
					),
				),
				'100',
				'100',
				array_replace(
					$percentage,
					array(
						'threshold' => 1,
						'value'     => 10000,
					)
				),
			),
			'fractional subtotal keeps canonical input' => array( array( $percentage ), '1000.5', '100', $percentage ),
		);
	}

	/** Verify repeated calculation starts from the original subtotal each time. */
	public function test_recalculation_uses_original_subtotal_without_accumulation(): void {
		$tiers = array(
			array(
				'threshold' => 10000,
				'type'      => 'fixed',
				'value'     => 500,
				'enabled'   => true,
			),
		);

		$first  = wtd_calculate(
			$tiers,
			wtd_subtotal(
				array(
					array(
						'price'    => '5000',
						'quantity' => '2',
					),
				)
			)
		);
		$second = wtd_calculate(
			$tiers,
			wtd_subtotal(
				array(
					array(
						'price'    => '5000',
						'quantity' => '2',
					),
				)
			)
		);

		$this->assertSame( '10000', $first['subtotal'] );
		$this->assertSame( '500', $first['discount'] );
		$this->assertSame( $first, $second );
	}

	/** Verify that a negative subtotal is rejected. */
	public function test_calculate_rejects_invalid_subtotal(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( 'invalid_number' );

		wtd_calculate( array(), '-1' );
	}

	/**
	 * Verify malformed tier definitions fail safely.
	 *
	 * @param array  $tiers  Invalid tier list.
	 * @param string $reason Expected error code.
	 * @dataProvider invalid_tier_lists
	 */
	public function test_calculate_rejects_invalid_tiers( $tiers, string $reason ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $reason );

		wtd_calculate( $tiers, '10000' );
	}

	/** Return malformed tier lists and their error codes. */
	public static function invalid_tier_lists(): array {
		$tier = array(
			'threshold' => 10000,
			'type'      => 'fixed',
			'value'     => 500,
			'enabled'   => true,
		);

		return array(
			'non sequential tiers'    => array( array( 1 => $tier ), 'invalid_tiers' ),
			'missing tier field'      => array( array( array( 'threshold' => 10000 ) ), 'invalid_tier' ),
			'wrong threshold type'    => array( array( array_replace( $tier, array( 'threshold' => '10000' ) ) ), 'invalid_tier' ),
			'unsupported type'        => array( array( array_replace( $tier, array( 'type' => 'rate' ) ) ), 'invalid_tier' ),
			'percentage out of range' => array(
				array(
					array_replace(
						$tier,
						array(
							'type'  => 'percentage',
							'value' => 10001,
						)
					),
				),
				'invalid_tier',
			),
			'wrong enabled type'      => array( array( array_replace( $tier, array( 'enabled' => 1 ) ) ), 'invalid_tier' ),
			'duplicate threshold'     => array( array( $tier, $tier ), 'invalid_tier' ),
		);
	}
}
