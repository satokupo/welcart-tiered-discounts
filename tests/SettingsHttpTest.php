<?php
/**
 * Settings API HTTP integration tests.
 *
 * @package Welcart_Tiered_Discounts
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/HttpClient.php';
require_once __DIR__ . '/fixtures/setup.php';

/**
 * Verify Settings API authorization and sanitization through HTTP.
 *
 * @group integration
 */
final class SettingsHttpTest extends TestCase {
	/**
	 * Verify the complete settings save boundary through WordPress HTTP.
	 *
	 * @return void
	 */
	public function test_settings_api_enforces_nonce_capability_and_atomic_sanitization(): void {
		wtd_test_setup();
		$original = $this->read_option_snapshot();

		try {
			$admin = $this->login_client( getenv( 'WTD_TEST_ADMIN_USER' ), getenv( 'WTD_TEST_ADMIN_PASSWORD' ) );
			$page  = $admin->request( '/wp-admin/options-general.php?page=welcart-tiered-discounts' );
			$this->assertSame( 200, $page['status'] );
			$fields = WtdTestHttp::formFields( $page['body'] );
			$this->assertArrayHasKey( '_wpnonce', $fields );
			$this->assertArrayHasKey( 'welcart_tiered_discounts[schema_version]', $fields );

			$valid = $this->tier_fields( $fields, '10000', 'fixed', '500', '1' );
			$saved = $admin->request( '/wp-admin/options.php', $valid );
			$this->assertSame( 200, $saved['status'] );
			$this->assertStringContainsString( 'settings-updated=true', $saved['url'] );
			$this->assertSame(
				array(
					'schema_version' => 1,
					'target'         => array( 'mode' => 'all' ),
					'tiers'          => array(
						array(
							'threshold' => 10000,
							'type'      => 'fixed',
							'value'     => 500,
							'enabled'   => true,
						),
					),
				),
				$this->read_option_snapshot()['value']
			);
			$expected_saved = $this->read_option_snapshot()['value'];

			$without_nonce = $valid;
			unset( $without_nonce['_wpnonce'] );
			$nonce_rejected = $admin->request( '/wp-admin/options.php', $without_nonce );
			$this->assertSame( 403, $nonce_rejected['status'] );
			$this->assertSame( $expected_saved, $this->read_option_snapshot()['value'] );

			$subscriber          = $this->login_client( 'wtd-test-subscriber', 'wtd-test-password-123!' );
			$capability_rejected = $subscriber->request( '/wp-admin/options.php', $valid );
			$this->assertSame( 403, $capability_rejected['status'] );
			$this->assertSame( $expected_saved, $this->read_option_snapshot()['value'] );

			$page         = $admin->request( '/wp-admin/options-general.php?page=welcart-tiered-discounts' );
			$fields       = WtdTestHttp::formFields( $page['body'] );
			$invalid      = $this->tier_fields( $fields, '10000', 'fixed', '0', '1' );
			$invalid_save = $admin->request( '/wp-admin/options.php', $invalid );
			$this->assertSame( 200, $invalid_save['status'] );
			$this->assertStringContainsString( '入力を確認', $invalid_save['body'] );
			$this->assertSame( $expected_saved, $this->read_option_snapshot()['value'] );

			$page    = $admin->request( '/wp-admin/options-general.php?page=welcart-tiered-discounts' );
			$missing = $this->remove_tier_fields( WtdTestHttp::formFields( $page['body'] ) );
			$this->assertArrayNotHasKey( 'welcart_tiered_discounts[tiers][0][threshold]', $missing );
			$missing_save = $admin->request( '/wp-admin/options.php', $missing );
			$this->assertSame( 200, $missing_save['status'] );
			$this->assertSame( $expected_saved, $this->read_option_snapshot()['value'] );

			$page  = $admin->request( '/wp-admin/options-general.php?page=welcart-tiered-discounts' );
			$empty = $this->remove_tier_fields( WtdTestHttp::formFields( $page['body'] ) );
			$empty['welcart_tiered_discounts[tiers][__empty]'] = '1';
			$empty_save                                        = $admin->request( '/wp-admin/options.php', $empty );
			$this->assertSame( 200, $empty_save['status'] );
			$this->assertStringContainsString( 'settings-updated=true', $empty_save['url'] );
			$this->assertSame(
				array(
					'schema_version' => 1,
					'target'         => array( 'mode' => 'all' ),
					'tiers'          => array(),
				),
				$this->read_option_snapshot()['value']
			);
		} finally {
			$this->restore_option_snapshot( $original );
		}
	}

	/**
	 * Verify percentage form values are normalized once through the Settings API.
	 *
	 * @param string $initial_state Initial option state.
	 * @param string $value         Percentage form value.
	 * @param int    $stored_value  Expected hundredths-of-a-percent value.
	 * @param string $display_value Expected redisplayed percentage.
	 * @param string $discount      Expected discount for a 10,000 yen subtotal.
	 * @return void
	 * @dataProvider percentage_save_cases
	 */
	public function test_settings_api_persists_percentage_values_once(
		string $initial_state,
		string $value,
		int $stored_value,
		string $display_value,
		string $discount
	): void {
		wtd_test_setup();
		$original = $this->read_option_snapshot();

		try {
			if ( 'saved-empty' === $initial_state ) {
				update_option( 'welcart_tiered_discounts', wtd_default_settings(), false );
				$this->assertSame( wtd_default_settings(), $this->read_option_snapshot()['value'] );
			} else {
				delete_option( 'welcart_tiered_discounts' );
				$this->assertFalse( $this->read_option_snapshot()['exists'] );
			}
			$this->clear_option_cache();

			$admin  = $this->login_client( getenv( 'WTD_TEST_ADMIN_USER' ), getenv( 'WTD_TEST_ADMIN_PASSWORD' ) );
			$page   = $admin->request( '/wp-admin/options-general.php?page=welcart-tiered-discounts' );
			$fields = WtdTestHttp::formFields( $page['body'] );
			$this->assertSame( 200, $page['status'] );

			$save_fields = $this->tier_fields( $fields, '10000', 'percentage', $value, '1' );
			$saved       = $admin->request( '/wp-admin/options.php', $save_fields );
			$this->assertSame( 200, $saved['status'] );
			$this->assertStringContainsString( 'settings-updated=true', $saved['url'] );

			$stored = $this->read_option_snapshot();
			$this->assertTrue( $stored['exists'] );
			$this->assertSame(
				array(
					'schema_version' => 1,
					'target'         => array( 'mode' => 'all' ),
					'tiers'          => array(
						array(
							'threshold' => 10000,
							'type'      => 'percentage',
							'value'     => $stored_value,
							'enabled'   => true,
						),
					),
				),
				$stored['value']
			);
			$this->assertSame( $discount, wtd_calculate( $stored['value']['tiers'], '10000' )['discount'] );

			$redisplay        = $admin->request( '/wp-admin/options-general.php?page=welcart-tiered-discounts' );
			$redisplay_fields = WtdTestHttp::formFields( $redisplay['body'] );
			$this->assertSame( 200, $redisplay['status'] );
			$this->assertSame( $display_value, $redisplay_fields['welcart_tiered_discounts[tiers][0][value]'] );

			$resave_fields = $this->tier_fields(
				$redisplay_fields,
				$redisplay_fields['welcart_tiered_discounts[tiers][0][threshold]'],
				$redisplay_fields['welcart_tiered_discounts[tiers][0][type]'],
				$redisplay_fields['welcart_tiered_discounts[tiers][0][value]'],
				'1'
			);
			$resaved = $admin->request( '/wp-admin/options.php', $resave_fields );
			$this->assertSame( 200, $resaved['status'] );
			$this->assertStringContainsString( 'settings-updated=true', $resaved['url'] );
			$this->assertSame( $stored['value'], $this->read_option_snapshot()['value'] );
		} finally {
			$this->restore_option_snapshot( $original );
		}
	}

	/**
	 * Return percentage form values and option states that exercise one save.
	 *
	 * @return array<string,array{string,string,int,string,string}>
	 */
	public static function percentage_save_cases(): array {
		return array(
			'absent option 0.5 percent'      => array( 'absent', '0.5', 50, '0.50', '50' ),
			'saved empty option 0.5 percent' => array( 'saved-empty', '0.5', 50, '0.50', '50' ),
			'absent option 10 percent'       => array( 'absent', '10', 1000, '10', '1000' ),
			'saved empty option 10 percent'  => array( 'saved-empty', '10', 1000, '10', '1000' ),
		);
	}

	/**
	 * Create an authenticated HTTP client.
	 *
	 * @param string|false $username WordPress login name.
	 * @param string|false $password WordPress password.
	 * @return WtdTestHttp
	 */
	private function login_client( $username, $password ): WtdTestHttp {
		$this->assertIsString( $username );
		$this->assertIsString( $password );
		$client = new WtdTestHttp();
		$login  = $client->login( $username, $password );
		$this->assertSame( 200, $login['status'] );
		return $client;
	}

	/**
	 * Add one tier to literal bracket-name form fields.
	 *
	 * @param array<string,mixed> $fields Existing form fields.
	 * @param string              $threshold Threshold input.
	 * @param string              $type Discount type.
	 * @param string              $value Discount value.
	 * @param string              $enabled Checkbox value.
	 * @return array<string,mixed>
	 */
	private function tier_fields( array $fields, string $threshold, string $type, string $value, string $enabled ): array {
		$fields = $this->remove_tier_fields( $fields );
		$fields['welcart_tiered_discounts[tiers][0][threshold]'] = $threshold;
		$fields['welcart_tiered_discounts[tiers][0][type]']      = $type;
		$fields['welcart_tiered_discounts[tiers][0][value]']     = $value;
		$fields['welcart_tiered_discounts[tiers][0][enabled]']   = $enabled;
		return $fields;
	}

	/**
	 * Remove tier controls while preserving Settings API fields.
	 *
	 * @param array<string,mixed> $fields Existing form fields.
	 * @return array<string,mixed>
	 */
	private function remove_tier_fields( array $fields ): array {
		foreach ( array_keys( $fields ) as $field_name ) {
			if ( 0 === strpos( $field_name, 'welcart_tiered_discounts[tiers]' ) ) {
				unset( $fields[ $field_name ] );
			}
		}
		return $fields;
	}

	/**
	 * Read the raw option without collapsing a stored false value into absence.
	 *
	 * @return array{exists:bool,value:mixed}
	 */
	private function read_option_snapshot(): array {
		$this->clear_option_cache();
		$missing = new stdClass();
		$value   = get_option( 'welcart_tiered_discounts', $missing );
		return array(
			'exists' => $missing !== $value,
			'value'  => $value,
		);
	}

	/**
	 * Restore the option state present before this test.
	 *
	 * @param array{exists:bool,value:mixed} $snapshot Original option state.
	 * @return void
	 */
	private function restore_option_snapshot( array $snapshot ): void {
		$this->clear_option_cache();
		if ( $snapshot['exists'] ) {
			update_option( 'welcart_tiered_discounts', $snapshot['value'] );
		} else {
			delete_option( 'welcart_tiered_discounts' );
		}
	}

	/**
	 * Clear this process's option cache after an HTTP request changes the DB.
	 *
	 * @return void
	 */
	private function clear_option_cache(): void {
		wp_cache_delete( 'welcart_tiered_discounts', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
