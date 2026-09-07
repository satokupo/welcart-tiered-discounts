<?php
/**
 * HTTP checkout integration tests.
 *
 * @package Welcart_Tiered_Discounts
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/HttpClient.php';
require_once __DIR__ . '/fixtures/setup.php';

/**
 * HTTP checkout integration tests.
 *
 * @group integration
 */
final class CheckoutHttpTest extends TestCase {
	/**
	 * Verify the native cart, checkout and order-mail paths with a confirmed quote.
	 *
	 * @return void
	 */
	public function test_http_checkout_saves_the_confirmed_snapshot_and_captured_mail(): void {
		wtd_test_setup();
		$settings = $this->read_option_snapshot( 'welcart_tiered_discounts' );
		$mail     = $this->read_option_snapshot( 'wtd_test_mail' );
		$email    = 'wtd-http-checkout-confirmed@example.test';

		try {
			$this->set_discount( 10000 );
			$before_orders = $this->order_ids_for_email( $email );
			$before_mail   = $this->mail_messages();
			$checkout      = $this->prepare_checkout( $email );
			$this->write_evidence( 'confirm', $checkout['confirm']['body'] );

			$this->assertStringContainsString( 'ステップ割引', $checkout['confirm']['body'] );
			$this->assertStringContainsString( '¥-500', $checkout['confirm']['body'] );
			$this->assertStringContainsString( '¥863', $checkout['confirm']['body'] );
			$this->assertStringContainsString( '¥9,500', $checkout['confirm']['body'] );
			$this->assertArrayHasKey( 'wc_purchase_nonce', $checkout['fields'] );
			$this->assertArrayHasKey( 'wtd_confirmation', $checkout['fields'] );

			$fields             = $checkout['fields'];
			$fields['purchase'] = '購入する';
			$completion         = $checkout['client']->request( '/?page_id=4', $fields );
			$this->write_evidence( 'completion', $completion['body'] );
			$this->assertSame( 200, $completion['status'] );
			$this->assertStringContainsString( '注文完了', $completion['body'] );

			$messages = $this->mail_messages();
			$this->assertGreaterThan( count( $before_mail ), count( $messages ) );
			$message = $this->find_mail( $messages, $email );
			$this->assertIsArray( $message );
			$this->assertSame( $email, $message['to'] );
			$this->assertStringContainsString( 'WTD Test Standard 10000', $message['message'] );
			$this->assertStringContainsString( '¥-500', $message['message'] );
			$this->assertStringContainsString( '¥863', $message['message'] );
			$this->assertStringContainsString( '¥9,500', $message['message'] );

			$order_id = $this->order_id_from_mail( $message['message'] );
			$this->assertGreaterThan( 0, $order_id );
			$order_ids = $this->order_ids_for_email( $email );
			$this->assertCount( count( $before_orders ) + 1, $order_ids );
			$this->assertContains( $order_id, $order_ids );
			$this->assert_order_snapshot( $order_id, 10000 );
		} finally {
			$this->restore_option_snapshot( 'welcart_tiered_discounts', $settings );
			$this->restore_option_snapshot( 'wtd_test_mail', $mail );
		}
	}

	/**
	 * Verify stale tokens and same-amount setting changes stop saving until reconfirmed.
	 *
	 * @return void
	 */
	public function test_http_checkout_reconfirms_after_a_stale_token_and_changed_settings(): void {
		wtd_test_setup();
		$settings = $this->read_option_snapshot( 'welcart_tiered_discounts' );
		$mail     = $this->read_option_snapshot( 'wtd_test_mail' );
		$email    = 'wtd-http-checkout-reconfirm@example.test';

		try {
			$this->set_discount( 10000 );
			$before_orders = $this->order_ids_for_email( $email );
			$before_mail   = $this->mail_messages();
			$checkout      = $this->prepare_checkout( $email );
			$old_token     = $checkout['fields']['wtd_confirmation'];

			$stale_fields                     = $checkout['fields'];
			$stale_fields['wtd_confirmation'] = 'stale-token';
			$stale_fields['purchase']         = '購入する';
			$stale                            = $checkout['client']->request( '/?page_id=4', $stale_fields );
			$this->write_evidence( 'stale-token', $stale['body'] );
			$this->assertSame( 200, $stale['status'] );
			$this->assertStringContainsString( '再確認', $stale['body'] );
			$this->assertSame( $before_orders, $this->order_ids_for_email( $email ) );
			$this->assertCount( count( $before_mail ), $this->mail_messages() );

			$reconfirm_fields = $this->form_fields_by_id( $stale['body'], 'purchase_form' );
			$this->assertArrayHasKey( 'wtd_confirmation', $reconfirm_fields );
			$this->assertNotSame( $old_token, $reconfirm_fields['wtd_confirmation'] );

			// The threshold changes while the displayed amount remains 500 yen.
			$this->set_discount( 9000 );
			$changed_fields             = $reconfirm_fields;
			$changed_fields['purchase'] = '購入する';
			$changed                    = $checkout['client']->request( '/?page_id=4', $changed_fields );
			$this->write_evidence( 'changed-settings', $changed['body'] );
			$this->assertSame( 200, $changed['status'] );
			$this->assertStringContainsString( '再確認', $changed['body'] );
			$this->assertStringContainsString( '¥-500', $changed['body'] );
			$this->assertStringContainsString( '¥9,500', $changed['body'] );
			$this->assertSame( $before_orders, $this->order_ids_for_email( $email ) );
			$this->assertCount( count( $before_mail ), $this->mail_messages() );

			$fresh_fields             = $this->form_fields_by_id( $changed['body'], 'purchase_form' );
			$fresh_fields['purchase'] = '購入する';
			$completion               = $checkout['client']->request( '/?page_id=4', $fresh_fields );
			$this->write_evidence( 'reconfirmed-completion', $completion['body'] );
			$this->assertSame( 200, $completion['status'] );
			$this->assertStringContainsString( '注文完了', $completion['body'] );

			$message = $this->find_mail( $this->mail_messages(), $email );
			$this->assertIsArray( $message );
			$order_id = $this->order_id_from_mail( $message['message'] );
			$this->assertGreaterThan( 0, $order_id );
			$this->assertCount( count( $before_orders ) + 1, $this->order_ids_for_email( $email ) );
			$this->assert_order_snapshot( $order_id, 9000 );
		} finally {
			$this->restore_option_snapshot( 'welcart_tiered_discounts', $settings );
			$this->restore_option_snapshot( 'wtd_test_mail', $mail );
		}
	}

	/**
	 * Verify a native member checkout applies points, saves member history and
	 * keeps that history stable when the current tier settings change.
	 *
	 * @return void
	 */
	public function test_http_member_checkout_applies_points_and_preserves_history_after_settings_change(): void {
		wtd_test_setup();
		$settings = $this->read_option_snapshot( 'welcart_tiered_discounts' );
		$mail     = $this->read_option_snapshot( 'wtd_test_mail' );
		$email    = 'wtd-http-member-points@example.test';
		$password = 'wtd-http-member-password-123!';

		try {
			$this->set_discount( 10000, 500 );
			$member        = wtd_test_ensure_member( $email, $password, 10000 );
			$before_orders = $this->order_ids_for_email( $email );
			$before_mail   = $this->mail_messages();
			$checkout      = $this->prepare_member_checkout( $email, $password );
			$with_points   = $this->use_points( $checkout, 1000 );
			$this->write_evidence( 'member-points-confirm', $with_points['confirm']['body'] );

			$this->assertSame( 200, $with_points['confirm']['status'] );
			$this->assertStringContainsString( 'ステップ割引', $with_points['confirm']['body'] );
			$this->assertStringContainsString( '¥-500', $with_points['confirm']['body'] );
			$this->assertStringContainsString( '¥863', $with_points['confirm']['body'] );
			$this->assertStringContainsString( '¥8,500', $with_points['confirm']['body'] );
			$this->assertArrayHasKey( 'wc_purchase_nonce', $with_points['fields'] );
			$this->assertArrayHasKey( 'wtd_confirmation', $with_points['fields'] );

			$fields             = $with_points['fields'];
			$fields['purchase'] = '購入する';
			$completion         = $with_points['client']->request( '/?page_id=4', $fields );
			$this->write_evidence( 'member-points-completion', $completion['body'] );
			$this->assertSame( 200, $completion['status'] );
			$this->assertStringContainsString( '注文完了', $completion['body'] );

			$messages = $this->mail_messages();
			$this->assertGreaterThan( count( $before_mail ), count( $messages ) );
			$message = $this->find_mail( $messages, $email );
			$this->assertIsArray( $message );
			$this->assertSame( $email, $message['to'] );
			$this->assertStringContainsString( 'WTD Test Standard 10000', $message['message'] );
			$this->assertStringContainsString( '¥-500', $message['message'] );
			$this->assertStringContainsString( '¥863', $message['message'] );
			$this->assertStringContainsString( '¥8,500', $message['message'] );

			$order_id = $this->order_id_from_mail( $message['message'] );
			$this->assertGreaterThan( 0, $order_id );
			$order_ids = $this->order_ids_for_email( $email );
			$this->assertCount( count( $before_orders ) + 1, $order_ids );
			$this->assertContains( $order_id, $order_ids );
			$this->assert_order_snapshot( $order_id, 10000, 500, 1000, 8500 );
			$this->assertSame( $member['ID'], (int) wtd_order_record( $order_id )['order']['mem_id'] );

			$history = $this->member_history_row( $member['ID'], $order_id );
			$this->assertSame( '-500.00', (string) $history['discount'] );
			$this->assertSame( '1000', (string) $history['usedpoint'] );
			$this->assertSame( 8500, $this->member_history_total( $history ) );

			// A later tier change must not recalculate the saved native history row.
			$this->set_discount( 30000, 2000 );
			$history_after_change = $this->member_history_row( $member['ID'], $order_id );
			$this->assertSame( '-500.00', (string) $history_after_change['discount'] );
			$this->assertSame( '1000', (string) $history_after_change['usedpoint'] );
			$this->assertSame( 8500, $this->member_history_total( $history_after_change ) );
			$member_page = $with_points['client']->request( '/?page_id=5' );
			$this->write_evidence( 'member-history-after-tier-change', $member_page['body'] );
			$this->assertSame( 200, $member_page['status'] );
			$this->assertStringContainsString( '¥-500', $member_page['body'] );
			$this->assertStringContainsString( '¥8,500', $member_page['body'] );
		} finally {
			$this->restore_option_snapshot( 'welcart_tiered_discounts', $settings );
			$this->restore_option_snapshot( 'wtd_test_mail', $mail );
		}
	}


	/**
	 * Verify a late checkout SQL failure rolls back the order, stock, points and
	 * captured mail after native writes have already been attempted.
	 *
	 * @return void
	 */
	public function test_http_member_checkout_sql_failure_rolls_back_order_stock_points_and_mail(): void {
		wtd_test_setup();
		$settings = $this->read_option_snapshot( 'welcart_tiered_discounts' );
		$mail     = $this->read_option_snapshot( 'wtd_test_mail' );
		$email    = 'wtd-http-member-points-failure@example.test';
		$password = 'wtd-http-member-failure-password-123!';

		try {
			$this->set_discount( 10000, 500 );
			$fixture       = wtd_test_setup();
			$member        = wtd_test_ensure_member( $email, $password, 10000 );
			$before_orders = $this->order_ids_for_email( $email );
			$before_mail   = $this->mail_messages();
			$before_stock  = $this->sku_stock( $fixture['products']['standard'], 'wtd-standard' );
			$before_points = $this->member_points( $member['ID'] );

			$checkout    = $this->prepare_member_checkout( $email, $password );
			$with_points = $this->use_points( $checkout, 1000 );
			$fields      = $with_points['fields'];
			$fields['purchase'] = '購入する';
			$failed = $with_points['client']->request(
				'/?page_id=4',
				$fields,
				array( 'X-WTD-Test-Fail-Checkout-SQL: snapshot' )
			);
			$this->write_evidence( 'member-points-sql-failure', $failed['body'] );

			$this->assertSame( 409, $failed['status'], $failed['body'] );
			$this->assertStringContainsString( '受注を保存できませんでした', $failed['body'] );
			$this->assertSame( $before_orders, $this->order_ids_for_email( $email ) );
			$this->assertSame( $before_stock, $this->sku_stock( $fixture['products']['standard'], 'wtd-standard' ) );
			$this->assertSame( $before_points, $this->member_points( $member['ID'] ) );
			$this->assertSame( $before_mail, $this->mail_messages() );
		} finally {
			$this->restore_option_snapshot( 'welcart_tiered_discounts', $settings );
			$this->restore_option_snapshot( 'wtd_test_mail', $mail );
		}
	}

	/**
	 * Verify a point amount accepted by the first quote is reset and rejected
	 * for purchase when a changed discount lowers its upper bound.
	 *
	 * @return void
	 */
	public function test_http_member_points_are_rechecked_before_purchase_after_discount_change(): void {
		wtd_test_setup();
		$settings = $this->read_option_snapshot( 'welcart_tiered_discounts' );
		$mail     = $this->read_option_snapshot( 'wtd_test_mail' );
		$email    = 'wtd-http-member-points-stale@example.test';
		$password = 'wtd-http-member-stale-password-123!';

		try {
			$this->set_discount( 10000, 500 );
			$member        = wtd_test_ensure_member( $email, $password, 10000 );
			$before_orders = $this->order_ids_for_email( $email );
			$before_mail   = $this->mail_messages();
			$checkout      = $this->prepare_member_checkout( $email, $password );
			$with_points   = $this->use_points( $checkout, 9500 );
			$this->write_evidence( 'member-points-limit-confirm', $with_points['confirm']['body'] );
			$this->assertStringContainsString( '¥-500', $with_points['confirm']['body'] );
			$this->assertStringContainsString( '¥863', $with_points['confirm']['body'] );
			$this->assertStringContainsString( '¥0', $with_points['confirm']['body'] );
			$this->assertStringContainsString( '9,500', $with_points['confirm']['body'] );
			$this->assertArrayHasKey( 'wtd_confirmation', $with_points['fields'] );

			$this->set_discount( 10000, 2000 );
			$stale_fields             = $with_points['fields'];
			$stale_fields['purchase'] = '購入する';
			$stale                    = $with_points['client']->request( '/?page_id=4', $stale_fields );
			$this->write_evidence( 'member-points-changed-settings', $stale['body'] );
			$this->assertSame( 200, $stale['status'] );
			$this->assertStringContainsString( '再確認', $stale['body'] );
			$this->assertStringContainsString( '¥-2,000', $stale['body'] );
			$this->assertStringContainsString( '¥8,000', $stale['body'] );
			$this->assertSame( $before_orders, $this->order_ids_for_email( $email ) );
			$this->assertCount( count( $before_mail ), $this->mail_messages() );

			$fresh_fields             = $this->form_fields_by_id( $stale['body'], 'purchase_form' );
			$fresh_fields['purchase'] = '購入する';
			$completion               = $with_points['client']->request( '/?page_id=4', $fresh_fields );
			$this->write_evidence( 'member-points-reconfirmed-completion', $completion['body'] );
			$this->assertSame( 200, $completion['status'] );
			$this->assertStringContainsString( '注文完了', $completion['body'] );

			$message = $this->find_mail( $this->mail_messages(), $email );
			$this->assertIsArray( $message );
			$this->assertStringContainsString( '¥-2,000', $message['message'] );
			$this->assertStringContainsString( '¥8,000', $message['message'] );
			$order_id = $this->order_id_from_mail( $message['message'] );
			$this->assertGreaterThan( 0, $order_id );
			$this->assertCount( count( $before_orders ) + 1, $this->order_ids_for_email( $email ) );
			$this->assert_order_snapshot( $order_id, 10000, 2000, 0, 8000 );
			$this->assertSame( $member['ID'], (int) wtd_order_record( $order_id )['order']['mem_id'] );
			$history = $this->member_history_row( $member['ID'], $order_id );
			$this->assertSame( '-2000.00', (string) $history['discount'] );
			$this->assertSame( '0', (string) $history['usedpoint'] );
			$this->assertSame( 8000, $this->member_history_total( $history ) );
		} finally {
			$this->restore_option_snapshot( 'welcart_tiered_discounts', $settings );
			$this->restore_option_snapshot( 'wtd_test_mail', $mail );
		}
	}

	/**
	 * Prepare a guest checkout through every native HTTP step up to confirmation.
	 *
	 * @param string $email Customer email.
	 * @return array{client:WtdTestHttp,confirm:array,fields:array}
	 */
	private function prepare_checkout( string $email ): array {
		$data   = wtd_test_setup();
		$client = new WtdTestHttp();

		$product = $client->request( '/?p=' . $data['products']['standard'] );
		$this->assertSame( 200, $product['status'] );
		$product_fields = $this->form_fields_by_action( $product['body'], 'page_id=4', 'quant[' );
		$product_fields[ 'inCart[' . $data['products']['standard'] . '][wtd-standard]' ] = 'カートへ入れる';

		$cart = $client->request( '/?page_id=4', $product_fields );
		$this->assertSame( 200, $cart['status'] );
		$this->assertStringContainsString( 'WTD Test Standard 10000', $cart['body'] );
		$cart_fields                 = $this->form_fields_by_action( $cart['body'], 'page_id=4', 'quant[' );
		$cart_fields['customerinfo'] = '次へ';

		$customer = $client->request( '/?page_id=4', $cart_fields );
		$this->assertSame( 200, $customer['status'] );
		$customer_fields = $this->form_fields_by_action( $customer['body'], 'page_id=4', 'customer[mailaddress1]' );
		$customer_fields = array_merge(
			$customer_fields,
			array(
				'customer[mailaddress1]' => $email,
				'customer[mailaddress2]' => $email,
				'customer[name1]'        => 'HTTP',
				'customer[name2]'        => '購入',
				'customer[name3]'        => 'エイチティーティーピー',
				'customer[name4]'        => 'コウニュウ',
				'customer[zipcode]'      => '1000001',
				'customer[pref]'         => '東京都',
				'customer[address1]'     => '千代田区',
				'customer[address2]'     => '1-1',
				'customer[address3]'     => 'テスト',
				'customer[tel]'          => '0312345678',
				'deliveryinfo'           => '次へ',
			)
		);

		$delivery = $client->request( '/?page_id=4', $customer_fields );
		$this->assertSame( 200, $delivery['status'] );
		$delivery_fields            = $this->form_fields_by_action( $delivery['body'], 'page_id=4', 'offer[payment_name]' );
		$delivery_fields['confirm'] = '確認する';

		$confirm = $client->request( '/?page_id=4', $delivery_fields );
		$this->assertSame( 200, $confirm['status'] );
		$this->assertStringContainsString( '内容確認', $confirm['body'] );
		$confirm_fields = $this->form_fields_by_id( $confirm['body'], 'purchase_form' );

		return array(
			'client'  => $client,
			'confirm' => $confirm,
			'fields'  => $confirm_fields,
		);
	}

	/**
	 * Prepare a logged-in member checkout through confirmation.
	 *
	 * @param string $email Member email.
	 * @param string $password Member password.
	 * @return array{client:WtdTestHttp,confirm:array,fields:array}
	 */
	private function prepare_member_checkout( string $email, string $password ): array {
		$data   = wtd_test_setup();
		$client = $this->login_member( $email, $password );

		$product = $client->request( '/?p=' . $data['products']['standard'] );
		$this->assertSame( 200, $product['status'] );
		$product_fields = $this->form_fields_by_action( $product['body'], 'page_id=4', 'quant[' );
		$product_fields[ 'inCart[' . $data['products']['standard'] . '][wtd-standard]' ] = 'カートへ入れる';

		$cart = $client->request( '/?page_id=4', $product_fields );
		$this->assertSame( 200, $cart['status'] );
		$this->assertStringContainsString( 'WTD Test Standard 10000', $cart['body'] );
		$cart_fields                 = $this->form_fields_by_action( $cart['body'], 'page_id=4', 'quant[' );
		$cart_fields['customerinfo'] = '次へ';

		$delivery = $client->request( '/?page_id=4', $cart_fields );
		$this->assertSame( 200, $delivery['status'] );
		$delivery_fields            = $this->form_fields_by_action( $delivery['body'], 'page_id=4', 'offer[payment_name]' );
		$delivery_fields['confirm'] = '確認する';

		$confirm = $client->request( '/?page_id=4', $delivery_fields );
		$this->assertSame( 200, $confirm['status'] );
		$this->assertStringContainsString( '内容確認', $confirm['body'] );
		$confirm_fields = $this->form_fields_by_id( $confirm['body'], 'purchase_form' );

		return array(
			'client'  => $client,
			'confirm' => $confirm,
			'fields'  => $confirm_fields,
		);
	}

	/**
	 * Log in through Welcart's native member form.
	 *
	 * @param string $email Member email.
	 * @param string $password Member password.
	 * @return WtdTestHttp
	 */
	private function login_member( string $email, string $password ): WtdTestHttp {
		$client = new WtdTestHttp();
		$login  = $client->request( '/?page_id=5' );
		$this->assertSame( 200, $login['status'] );
		$fields                 = $this->form_fields_by_id( $login['body'], 'loginform' );
		$fields['loginmail']    = $email;
		$fields['loginpass']    = $password;
		$fields['member_login'] = 'ログイン';
		$logged_in              = $client->request( '/?page_id=5', $fields );
		$this->assertSame( 200, $logged_in['status'] );
		$this->assertStringContainsString( $email, $logged_in['body'] );
		return $client;
	}

	/**
	 * Apply points using Welcart's separate confirmation form.
	 *
	 * @param array $checkout Checkout state.
	 * @param int   $points Requested points.
	 * @return array{client:WtdTestHttp,confirm:array,fields:array}
	 */
	private function use_points( array $checkout, int $points ): array {
		$point_fields                     = $this->form_fields_by_action( $checkout['confirm']['body'], 'page_id=4', 'offer[usedpoint]' );
		$point_fields['offer[usedpoint]'] = (string) $points;
		$point_fields['use_point']        = 'ポイントを使用する';
		$confirm                          = $checkout['client']->request( '/?page_id=4', $point_fields );
		$this->assertSame( 200, $confirm['status'] );
		$this->assertStringContainsString( '内容確認', $confirm['body'] );
		return array(
			'client'  => $checkout['client'],
			'confirm' => $confirm,
			'fields'  => $this->form_fields_by_id( $confirm['body'], 'purchase_form' ),
		);
	}

	/**
	 * Return one native purchase-history row for the member and order.
	 *
	 * @param int $member_id Native member ID.
	 * @param int $order_id Native order ID.
	 * @return array<string,mixed>
	 */
	private function member_history_row( int $member_id, int $order_id ): array {
		global $usces;
		$this->assertTrue( is_object( $usces ) && method_exists( $usces, 'get_member_history' ) );
		$history = $usces->get_member_history( $member_id, true );
		foreach ( (array) $history as $row ) {
			if ( isset( $row['ID'] ) && $order_id === (int) $row['ID'] ) {
				return $row;
			}
		}
		$this->fail( 'The member purchase history did not contain order ' . $order_id . '.' );
	}

	/**
	 * Calculate the native history total from its persisted amount fields.
	 *
	 * @param array<string,mixed> $history Native history row.
	 * @return int Total in whole yen.
	 */
	private function member_history_total( array $history ): int {
		return (int) $history['total_items_price'] - (int) $history['usedpoint'] + (int) $history['discount'] + (int) $history['shipping_charge'] + (int) $history['cod_fee'] + (int) $history['tax'];
	}

	/**
	 * Assert the native amount columns and immutable WTD order snapshot.
	 *
	 * @param int $order_id Order ID.
	 * @param int $threshold Stored threshold after the final confirmation.
	 * @param int $discount Stored discount amount.
	 * @param int $usedpoint Stored used point amount.
	 * @param int $total Stored total amount.
	 * @return void
	 */
	private function assert_order_snapshot( int $order_id, int $threshold, int $discount = 500, int $usedpoint = 0, int $total = 9500 ): void {
		$this->assertTrue( function_exists( 'wtd_order_record' ) );
		$record = wtd_order_record( $order_id );
		$this->assertSame( 'WTD Test Bank Transfer', $record['order']['order_payment_name'] );
		$this->assertEquals( 10000, (float) $record['order']['order_item_total_price'] );
		$this->assertEquals( -$discount, (float) $record['order']['order_discount'] );
		$this->assertEquals( 0, (float) $record['order']['order_tax'] );
		$this->assertEquals( $usedpoint, (int) $record['order']['order_usedpoint'] );
		$this->assertEquals( $total, (float) $record['order']['order_item_total_price'] + (float) $record['order']['order_discount'] - (float) $record['order']['order_usedpoint'] );
		$this->assertCount( 1, $record['cart'] );
		$this->assertSame( 'wtd-standard', $record['cart'][0]['sku_code'] );
		$this->assertEquals( 10000, (float) $record['cart'][0]['price'] );

		$snapshot = json_decode( $record['meta']['wtd_discount_snapshot'], true );
		$this->assertIsArray( $snapshot );
		$this->assertSame( 1, $snapshot['schema_version'] );
		$this->assertSame( $threshold, $snapshot['settings']['tiers'][0]['threshold'] );
		$this->assertSame( 'include', $snapshot['condition']['tax_mode'] );
		$this->assertSame( '0.00', $snapshot['original']['amounts']['tax'] );
		$this->assertSame( number_format( $total, 2, '.', '' ), $snapshot['original']['amounts']['total_full_price'] );
		$this->assertSame( (string) $usedpoint, (string) $snapshot['original']['amounts']['usedpoint'] );
	}

	/**
	 * Set one fixed discount tier for the current HTTP checkout.
	 *
	 * @param int $threshold Eligibility threshold.
	 * @param int $value Fixed discount amount.
	 * @return void
	 */
	private function set_discount( int $threshold, int $value = 500 ): void {
		update_option(
			'welcart_tiered_discounts',
			array(
				'schema_version' => 1,
				'target'         => array( 'mode' => 'all' ),
				'tiers'          => array(
					array(
						'threshold' => $threshold,
						'type'      => 'fixed',
						'value'     => $value,
						'enabled'   => true,
					),
				),
			),
			false
		);
	}

	/**
	 * Return fields from the form whose action and field contract match.
	 *
	 * @param string      $html Page HTML.
	 * @param string      $action_fragment Action URL fragment.
	 * @param string|null $field_fragment Required field-name fragment.
	 * @return array<string,mixed>
	 */
	private function form_fields_by_action( string $html, string $action_fragment, ?string $field_fragment = null ): array {
		$form_html = $this->find_form_html( $html, $action_fragment, $field_fragment );
		return WtdTestHttp::formFields( $form_html );
	}

	/**
	 * Return fields from a form identified by its DOM id.
	 *
	 * @param string $html Page HTML.
	 * @param string $form_id Form id.
	 * @return array<string,mixed>
	 * @throws RuntimeException When the required form is absent.
	 */
	private function form_fields_by_id( string $html, string $form_id ): array {
		$previous = libxml_use_internal_errors( true );
		$document = new DOMDocument();
		$document->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		foreach ( $document->getElementsByTagName( 'form' ) as $form ) {
			if ( $form_id === $form->getAttribute( 'id' ) ) {
				return WtdTestHttp::formFields( $document->saveHTML( $form ), $form_id );
			}
		}
		throw new RuntimeException( 'Required checkout form was not found: ' . esc_html( $form_id ) );
	}

	/**
	 * Find one form in a page without assuming the theme's search form order.
	 *
	 * @param string      $html Page HTML.
	 * @param string      $action_fragment Action URL fragment.
	 * @param string|null $field_fragment Required field-name fragment.
	 * @return string Serialized form HTML.
	 * @throws RuntimeException When the required form is absent.
	 */
	private function find_form_html( string $html, string $action_fragment, ?string $field_fragment = null ): string {
		$previous = libxml_use_internal_errors( true );
		$document = new DOMDocument();
		$document->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		foreach ( $document->getElementsByTagName( 'form' ) as $form ) {
			if ( false === strpos( $form->getAttribute( 'action' ), $action_fragment ) ) {
				continue;
			}
			$form_html = $document->saveHTML( $form );
			if ( null === $field_fragment || false !== strpos( $form_html, $field_fragment ) ) {
				return $form_html;
			}
		}
		throw new RuntimeException( 'Required checkout form was not found: ' . esc_html( $action_fragment ) . ' ' . esc_html( (string) $field_fragment ) );
	}

	/**
	 * Read captured local mail messages.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function mail_messages(): array {
		global $wpdb;
		// The message is written by a separate HTTP process, so bypass this
		// PHPUnit process's option cache when reading the captured evidence.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only test evidence must observe the other HTTP process immediately.
		$serialized = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wtd_test_mail' ) );
		$messages   = null === $serialized ? array() : maybe_unserialize( $serialized );
		return is_array( $messages ) ? array_values( $messages ) : array();
	}

	/**
	 * Find a captured message by recipient.
	 *
	 * @param array<int,array<string,mixed>> $messages Captured messages.
	 * @param string                         $email Recipient.
	 * @return array<string,mixed>|null
	 */
	private function find_mail( array $messages, string $email ): ?array {
		foreach ( array_reverse( $messages ) as $message ) {
			if ( isset( $message['to'] ) && $email === $message['to'] ) {
				return $message;
			}
		}
		return null;
	}


	/** Read a fixture SKU stock value without relying on the WordPress cache. */
	private function sku_stock( int $post_id, string $sku_code ): string {
		global $wpdb;
		$table = $wpdb->prefix . 'usces_skus';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only HTTP rollback evidence uses the native SKU table.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT stocknum FROM {$table} WHERE post_id = %d AND code = %s", $post_id, $sku_code ) );
		$this->assertNotNull( $value );
		return (string) $value;
	}

	/** Read a fixture member point balance without relying on the Welcart cache. */
	private function member_points( int $member_id ): int {
		global $wpdb;
		$table = usces_get_tablename( 'usces_member' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only HTTP rollback evidence uses the native member table.
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT mem_point FROM {$table} WHERE ID = %d", $member_id ) );
		$this->assertNotNull( $value );
		return (int) $value;
	}

	/**
	 * Read order IDs for an email through the test database connection.
	 *
	 * @param string $email Order email.
	 * @return array<int,int>
	 */
	private function order_ids_for_email( string $email ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'usces_order';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Read-only test evidence needs the native order table, whose identifier cannot be a placeholder.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$table} WHERE order_email = %s ORDER BY ID ASC", $email ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Extract a numeric Welcart order ID from captured mail.
	 *
	 * @param string $message Captured message body.
	 * @return int Order ID or zero when absent.
	 */
	private function order_id_from_mail( string $message ): int {
		if ( preg_match( '/注文番号\s*:\s*0*([0-9]+)/u', $message, $matches ) ) {
			return (int) $matches[1];
		}
		return 0;
	}

	/**
	 * Save dummy HTML evidence under the local temporary work directory.
	 *
	 * @param string $name Evidence name.
	 * @param string $html Response HTML.
	 * @return void
	 */
	private function write_evidence( string $name, string $html ): void {
		$prefix = getenv( 'WTD_TEST_SESSION_PREFIX' );
		$prefix = is_string( $prefix ) && preg_match( '/^[A-Za-z0-9_-]+$/', $prefix ) ? $prefix : 'checkout-http';
		$dir    = dirname( __DIR__ ) . '/temp/' . $prefix . '_checkout';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test evidence is an isolated local artifact outside the plugin runtime.
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0775, true ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test evidence is an isolated local artifact outside the plugin runtime.
		file_put_contents( $dir . '/' . sanitize_title( $name ) . '.html', $html );
	}

	/**
	 * Read one option while preserving a missing option.
	 *
	 * @param string $key Option key.
	 * @return array{exists:bool,value:mixed}
	 */
	private function read_option_snapshot( string $key ): array {
		$missing = new stdClass();
		$value   = get_option( $key, $missing );
		return array(
			'exists' => $missing !== $value,
			'value'  => $value,
		);
	}

	/**
	 * Restore one option to its original state.
	 *
	 * @param string                         $key Option key.
	 * @param array{exists:bool,value:mixed} $snapshot Original option state.
	 * @return void
	 */
	private function restore_option_snapshot( string $key, array $snapshot ): void {
		if ( $snapshot['exists'] ) {
			update_option( $key, $snapshot['value'], false );
		} else {
			delete_option( $key );
		}
	}
}
