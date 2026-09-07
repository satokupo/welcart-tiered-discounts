<?php
/**
 * Settings validation tests.
 *
 * @package Welcart_Tiered_Discounts
 */

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {
	public function test_stored_schema_is_normalized_in_threshold_order(): void {
		$input = array(
			'schema_version' => 1,
			'target' => array('mode' => 'all'),
			'tiers' => array(
				array(
					'threshold' => 30000,
					'type' => 'fixed',
					'value' => 2000,
					'enabled' => false,
				),
				array(
					'threshold' => 10000,
					'type' => 'percentage',
					'value' => 1025,
					'enabled' => true,
				),
			),
		);

		$this->assertSame(
			array(
				'schema_version' => 1,
				'target' => array('mode' => 'all'),
				'tiers' => array(
					array(
						'threshold' => 10000,
						'type' => 'percentage',
						'value' => 1025,
						'enabled' => true,
					),
					array(
						'threshold' => 30000,
						'type' => 'fixed',
						'value' => 2000,
						'enabled' => false,
					),
				),
			),
			wtd_validate_settings($input)
		);
	}

	public function test_form_values_are_converted_to_stored_types(): void {
		$input = array(
			'schema_version' => '1',
			'target' => array('mode' => 'all'),
			'tiers' => array(
				array(
					'threshold' => '10000',
					'type' => 'percentage',
					'value' => '10.25',
					'enabled' => '1',
				),
				array(
					'threshold' => '30000',
					'type' => 'fixed',
					'value' => '2000',
					'enabled' => '0',
				),
			),
		);

		$this->assertSame(
			array(
				'schema_version' => 1,
				'target' => array('mode' => 'all'),
				'tiers' => array(
					array(
						'threshold' => 10000,
						'type' => 'percentage',
						'value' => 1025,
						'enabled' => true,
					),
					array(
						'threshold' => 30000,
						'type' => 'fixed',
						'value' => 2000,
						'enabled' => false,
					),
				),
			),
			wtd_validate_settings($input, true)
		);
	}

	public function test_empty_tier_list_is_valid_default_shape(): void {
		$this->assertSame(
			array(
				'schema_version' => 1,
				'target' => array('mode' => 'all'),
				'tiers' => array(),
			),
			wtd_validate_settings(
				array(
					'schema_version' => 1,
					'target' => array('mode' => 'all'),
					'tiers' => array(),
				)
			)
		);
	}

	public function test_form_empty_sentinel_is_normalized_to_empty_tier_list(): void {
		$this->assertSame(
			wtd_default_settings(),
			wtd_validate_settings(
				array(
					'schema_version' => '1',
					'target'         => array( 'mode' => 'all' ),
					'tiers'          => array( '__empty' => '1' ),
				),
				true
			)
		);
	}

	public function test_invalid_schema_is_rejected_with_safe_reason(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('invalid_schema');

		wtd_validate_settings(
			array(
				'schema_version' => 1,
				'target' => array('mode' => 'all'),
				'unknown' => true,
				'tiers' => array(),
			)
		);
	}

	/**
	 * @dataProvider invalid_stored_values
	 */
	public function test_stored_values_require_exact_types_and_shape($input): void {
		try {
			wtd_validate_settings($input);
			$this->fail('Expected InvalidArgumentException.');
		} catch (InvalidArgumentException $exception) {
			$this->assertStringContainsString('invalid_', $exception->getMessage());
		}
	}

	public static function invalid_stored_values(): array {
		$valid = array(
			'schema_version' => 1,
			'target' => array('mode' => 'all'),
			'tiers' => array(
				array(
					'threshold' => 10000,
					'type' => 'fixed',
					'value' => 500,
					'enabled' => true,
				),
			),
		);

		return array(
			'not an array' => array('invalid'),
			'false is not a stored schema' => array(false),
			'stored empty marker is not a schema' => array_replace($valid, array('tiers' => array('__empty' => '1'))),
			'wrong schema version type' => array_merge($valid, array('schema_version' => '1')),
			'missing target' => array(
				'schema_version' => 1,
				'tiers' => $valid['tiers'],
			),
			'unknown target key' => array(
				'schema_version' => 1,
				'target' => array('mode' => 'all', 'extra' => true),
				'tiers' => array(),
			),
			'wrong tier threshold type' => array_replace_recursive(
				$valid,
				array('tiers' => array(array('threshold' => '10000')))
			),
			'wrong tier enabled type' => array_replace_recursive(
				$valid,
				array('tiers' => array(array('enabled' => 1)))
			),
			'unknown tier key' => array_replace_recursive(
				$valid,
				array('tiers' => array(array('extra' => 'x')))
			),
			'non sequential tiers' => array(
				'schema_version' => 1,
				'target' => array('mode' => 'all'),
				'tiers' => array(1 => $valid['tiers'][0]),
			),
		);
	}

	/**
	 * @dataProvider invalid_tier_values
	 */
	public function test_tier_boundaries_and_duplicates_are_rejected($tiers, string $expected_reason): void {
		try {
			wtd_validate_settings(
				array(
					'schema_version' => 1,
					'target' => array('mode' => 'all'),
					'tiers' => $tiers,
				)
			);
			$this->fail('Expected InvalidArgumentException.');
		} catch (InvalidArgumentException $exception) {
			$this->assertStringContainsString($expected_reason, $exception->getMessage());
			$this->assertStringContainsString('row', $exception->getMessage());
		}
	}

	public static function invalid_tier_values(): array {
		$tier = array(
			'threshold' => 10000,
			'type' => 'fixed',
			'value' => 500,
			'enabled' => true,
		);

		return array(
			'zero threshold' => array(array(array_replace($tier, array('threshold' => 0))), 'invalid_tier'),
			'large threshold' => array(array(array_replace($tier, array('threshold' => 100000000))), 'invalid_tier'),
			'zero fixed value' => array(array(array_replace($tier, array('value' => 0))), 'invalid_tier'),
			'large fixed value' => array(array(array_replace($tier, array('value' => 100000000))), 'invalid_tier'),
			'bad type' => array(array(array_replace($tier, array('type' => 'rate'))), 'invalid_tier'),
			'duplicate disabled threshold' => array(
				array(
					$tier,
					array_replace($tier, array('enabled' => false)),
				),
				'invalid_tier',
			),
		);
	}

	/**
	 * @dataProvider invalid_form_values
	 */
	public function test_form_values_reject_malformed_numeric_input($tiers, string $expected_reason): void {
		try {
			wtd_validate_settings(
				array(
					'schema_version' => '1',
					'target' => array('mode' => 'all'),
					'tiers' => $tiers,
				),
				true
			);
			$this->fail('Expected InvalidArgumentException.');
		} catch (InvalidArgumentException $exception) {
			$this->assertStringContainsString($expected_reason, $exception->getMessage());
		}
	}

	public static function invalid_form_values(): array {
		$tier = array(
			'threshold' => '10000',
			'type' => 'percentage',
			'value' => '10.25',
			'enabled' => '1',
		);

		return array(
			'missing threshold' => array(array(array_diff_key($tier, array('threshold' => true))), 'invalid_tier'),
			'negative threshold' => array(array(array_replace($tier, array('threshold' => '-1'))), 'invalid_tier'),
			'decimal threshold' => array(array(array_replace($tier, array('threshold' => '1.5'))), 'invalid_tier'),
			'exponent threshold' => array(array(array_replace($tier, array('threshold' => '1e3'))), 'invalid_tier'),
			'third decimal percentage' => array(array(array_replace($tier, array('value' => '10.256'))), 'invalid_tier'),
			'zero percentage' => array(array(array_replace($tier, array('value' => '0'))), 'invalid_tier'),
			'over percentage' => array(array(array_replace($tier, array('value' => '100.01'))), 'invalid_tier'),
			'bad enabled value' => array(array(array_replace($tier, array('enabled' => 'on'))), 'invalid_tier'),
		);
	}
}
