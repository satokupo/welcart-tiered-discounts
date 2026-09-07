<?php
/**
 * HTTP failure and concurrency tests for administrator order edits.
 *
 * @package Welcart_Tiered_Discounts
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/fixtures/HttpClient.php';

/**
 * Verify that a failed or competing native order save cannot leave a partial
 * Welcart order behind.
 *
 * @group integration
 */
final class OrderFailureHttpTest extends TestCase {
	/** @var array<string,array<int,array<string,string>>> */
	private $before_database;

	/**
	 * Capture the dedicated order before each test so successful race winners
	 * can be restored without touching other orders or shop settings.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->assertSame( '1', getenv( 'WTD_TEST_MODE' ), 'The HTTP suite requires WTD_TEST_MODE=1.' );
		$order_id = $this->order_id();
		$this->before_database = $this->database_snapshot( $order_id );
	}

	/** Restore the dedicated order after each test. */
	protected function tearDown(): void {
		if ( isset( $this->before_database ) ) {
			$this->restore_database_snapshot( $this->before_database );
		}
		parent::tearDown();
	}

	/**
	 * Check each native or supplemental SQL write boundary through HTTP.
	 *
	 * @param string $target Failure injection target.
	 * @return void
	 * @dataProvider sql_failure_targets
	 */
	public function test_http_sql_failure_returns_conflict_and_restores_the_complete_order( string $target ): void {
		if ( 'tax' === $target ) {
			$this->enable_reduced_tax_metadata( $this->order_id() );
		}

		[ $client, $fields, $before ] = $this->edit_form( $this->order_id() );
		$cart_id = (string) $before['cart'][0]['cart_id'];
		$quantity = $this->changed_quantity( $before['cart'][0]['quantity'] );
		$fields[ 'quant[' . $cart_id . ']' ] = $quantity;
		$this->preview( $client, $this->order_id(), $fields );
		$expected = $this->database_snapshot( $this->order_id() );

		$response = $this->save_with_header( $client, $this->order_id(), $fields, $target );
		$this->write_evidence( $target, $response['body'] );
		$this->assertSame( 409, $response['status'], $response['body'] );
		$this->assertSame( $expected, $this->database_snapshot( $this->order_id() ) );
		$this->assertSame( $before, wtd_order_record( $this->order_id() ) );
	}

	/** Return the four SQL boundaries covered by the HTTP failure fixture. */
	public static function sql_failure_targets(): array {
		return array(
			'native order row' => array( 'order' ),
			'native order cart row' => array( 'cart' ),
			'native tax metadata' => array( 'tax' ),
			'discount history metadata' => array( 'history' ),
		);
	}

	/**
	 * A form carrying an old fingerprint must be rejected after another save.
	 *
	 * @return void
	 */
	public function test_http_old_fingerprint_is_rejected_after_another_save(): void {
		[ $winner, $winner_fields, $before ] = $this->edit_form( $this->order_id() );
		[ $stale, $stale_fields ]           = $this->edit_form( $this->order_id() );
		$cart_id                            = (string) $before['cart'][0]['cart_id'];
		$winner_quantity                    = $this->changed_quantity( $before['cart'][0]['quantity'] );
		$stale_quantity                     = $this->second_quantity( $before['cart'][0]['quantity'], $winner_quantity );

		$winner_fields[ 'quant[' . $cart_id . ']' ] = $winner_quantity;
		$stale_fields[ 'quant[' . $cart_id . ']' ]  = $stale_quantity;
		$this->preview( $winner, $this->order_id(), $winner_fields );
		$this->preview( $stale, $this->order_id(), $stale_fields );
		$saved = $winner->request( $this->edit_post_path( $this->order_id() ), $winner_fields );
		$this->assertSame( 200, $saved['status'], $saved['body'] );

		$rejected = $stale->request( $this->edit_post_path( $this->order_id() ), $stale_fields );
		$this->write_evidence( 'old-fingerprint', $rejected['body'] );
		$this->assertSame( 409, $rejected['status'], $rejected['body'] );

		$after = wtd_order_record( $this->order_id() );
		$this->assertSame( $winner_quantity, wtd_normalize_decimal( $after['cart'][0]['quantity'] ) );
		$this->assertSame( $before['meta']['wtd_discount_snapshot'], $after['meta']['wtd_discount_snapshot'] );
		$this->assertCount(
			count( json_decode( $before['meta']['wtd_discount_history'] ?: '[]', true ) ) + 1,
			json_decode( $after['meta']['wtd_discount_history'], true )
		);
	}

	/** A changed quantity without a new preview must not reach a native write. */
	public function test_http_changed_quantity_without_a_new_preview_is_rejected(): void {
		[ $client, $fields, $before ] = $this->edit_form( $this->order_id() );
		$cart_id                     = (string) $before['cart'][0]['cart_id'];
		$fields[ 'quant[' . $cart_id . ']' ] = $this->changed_quantity( $before['cart'][0]['quantity'] );
		$fields['update_order_edit'] = 'update';

		$response = $client->request( $this->edit_post_path( $this->order_id() ), $fields );
		$this->write_evidence( 'changed-without-preview', $response['body'] );
		$this->assertSame( 409, $response['status'], $response['body'] );
		$this->assertSame( $before, wtd_order_record( $this->order_id() ) );
		$this->assertSame( $this->before_database, $this->database_snapshot( $this->order_id() ) );
	}

	/**
	 * Native Welcart recalculation endpoints must remain read-only for saved
	 * discount orders. The dedicated preview is the only route that may
	 * calculate a quote, and it still does not write an order.
	 *
	 * @param string $mode Native recalculation mode.
	 * @return void
	 * @dataProvider native_recalculation_modes
	 */
	public function test_http_native_recalculation_modes_are_rejected_without_mutation( string $mode ): void {
		[ $client, $form, $before ] = $this->edit_form( $this->order_id() );
		$response = $client->request(
			'/wp-admin/admin-ajax.php',
			$this->native_recalculation_fields( $form, $before, $mode )
		);
		$this->assertSame( 409, $response['status'], $response['body'] );
		$this->assertSame( $before, wtd_order_record( $this->order_id() ) );
		$this->assertSame( $this->before_database, $this->database_snapshot( $this->order_id() ) );
	}

	/** Return both native recalculation endpoints guarded by the plugin. */
	public static function native_recalculation_modes(): array {
		return array(
			'standard recalculation with changed tax rate' => array( 'recalculation' ),
			'reduced recalculation with changed tax rate'  => array( 'recalculation_reduced' ),
		);
	}

	/**
	 * The server preview is restricted to an authenticated Welcart order
	 * administrator. Verify both an anonymous request and a subscriber request
	 * leave the dedicated order and its physical rows unchanged.
	 *
	 * @return void
	 */
	public function test_http_preview_rejects_anonymous_and_low_privilege_users(): void {
		$before   = wtd_order_record( $this->order_id() );
		$snapshot = $this->database_snapshot( $this->order_id() );
		$payload  = array(
			'action'   => 'wtd_preview_order',
			'order_id' => (string) $this->order_id(),
		);

		$anonymous = new WtdTestHttp();
		$denied    = $anonymous->request( '/wp-admin/admin-ajax.php', $payload );
		/*
		 * WordPress does not register a nopriv action for this endpoint, so
		 * anonymous admin-ajax requests are rejected as 400 before the plugin
		 * callback runs. Authenticated users without the capability receive
		 * the plugin's explicit 403 below.
		 */
		$this->assertContains( $denied['status'], array( 400, 403 ), $denied['body'] );

		$subscriber = new WtdTestHttp();
		$login      = $subscriber->login( 'wtd-test-subscriber', 'wtd-test-password-123!' );
		$this->assertSame( 200, $login['status'], $login['body'] );
		$denied = $subscriber->request( '/wp-admin/admin-ajax.php', $payload );
		$this->assertSame( 403, $denied['status'], $denied['body'] );

		$this->assertSame( $before, wtd_order_record( $this->order_id() ) );
		$this->assertSame( $snapshot, $this->database_snapshot( $this->order_id() ) );
	}

	/**
	 * Two independent authenticated saves use the same original fingerprint;
	 * row locking lets one commit and forces the other to conflict.
	 *
	 * @return void
	 */
	public function test_http_concurrent_saves_commit_once_and_append_one_history_entry(): void {
		[ $first, $first_fields, $before ] = $this->edit_form( $this->order_id() );
		[ $second, $second_fields ]         = $this->edit_form( $this->order_id() );
		$cart_id                            = (string) $before['cart'][0]['cart_id'];
		[ $first_quantity, $second_quantity ] = $this->race_quantities( $before['cart'][0]['quantity'] );
		$first_fields[ 'quant[' . $cart_id . ']' ]  = $first_quantity;
		$second_fields[ 'quant[' . $cart_id . ']' ] = $second_quantity;
		$this->preview( $first, $this->order_id(), $first_fields );
		$this->preview( $second, $this->order_id(), $second_fields );

		$responses = $this->concurrent_saves(
			array(
				array( $first, $first_fields ),
				array( $second, $second_fields ),
			)
		);
		$statuses = array_column( $responses, 'status' );
		$this->assertCount( 1, array_keys( $statuses, 200, true ), json_encode( $responses ) );
		$this->assertCount( 1, array_keys( $statuses, 409, true ), json_encode( $responses ) );
		foreach ( $responses as $index => $response ) {
			$this->write_evidence( 'concurrent-' . $index, $response['body'] );
		}

		$after = wtd_order_record( $this->order_id() );
		$this->assertContains( wtd_normalize_decimal( $after['cart'][0]['quantity'] ), array( $first_quantity, $second_quantity ) );
		$history = json_decode( $after['meta']['wtd_discount_history'], true );
		$this->assertCount( count( json_decode( $before['meta']['wtd_discount_history'] ?: '[]', true ) ) + 1, $history );
	}

	/** Return the dynamic order ID created by the native storage fixture. */
	private function order_id(): int {
		$order_id = (int) get_option( 'wtd_test_saved_order_id', 0 );
		$this->assertGreaterThan( 0, $order_id, 'Run NativeOrderStorageTest to create the dedicated order first.' );
		return $order_id;
	}

	/**
	 * Load and authenticate a native order edit form.
	 *
	 * @param int $order_id Order ID.
	 * @return array{0:WtdTestHttp,1:array<string,mixed>,2:array<string,mixed>}
	 */
	private function edit_form( int $order_id ): array {
		$http = new WtdTestHttp();
		$login = $http->login( getenv( 'WTD_TEST_ADMIN_USER' ), getenv( 'WTD_TEST_ADMIN_PASSWORD' ) );
		$this->assertSame( 200, $login['status'], $login['body'] );
		$list = $http->request( '/wp-admin/admin.php?page=usces_orderlist' );
		$this->assertSame( 200, $list['status'], $list['body'] );
		$this->assertSame( 1, preg_match( '/order_action=edit[^"\s]*?wc_nonce=([a-z0-9]+)/', $list['body'], $nonce ) );
		$page = $http->request( '/wp-admin/admin.php?page=usces_orderlist&order_action=edit&order_id=' . $order_id . '&wc_nonce=' . $nonce[1] );
		$this->assertSame( 200, $page['status'], $page['body'] );
		$fields = WtdTestHttp::formFields( $page['body'], 'order_editpost_form' );
		$this->assertArrayHasKey( 'wtd_nonce', $fields );
		$this->assertArrayHasKey( 'wtd_fingerprint', $fields );
		return array( $http, $fields, wtd_order_record( $order_id ) );
	}

	/** Calculate and attach a server preview token to an edit form. */
	private function preview( WtdTestHttp $http, int $order_id, array &$fields ): array {
		$response = $http->request(
			'/wp-admin/admin-ajax.php',
			array_merge( $fields, array( 'action' => 'wtd_preview_order', 'order_id' => (string) $order_id ) )
		);
		$this->assertSame( 200, $response['status'], $response['body'] );
		$payload = json_decode( $response['body'], true );
		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'data', $payload );
		$this->assertArrayHasKey( 'token', $payload['data'] );
		$fields['wtd_preview'] = $payload['data']['token'];
		return $payload['data'];
	}

	/**
	 * Build the same arrays sent by Welcart's native recalculation JavaScript.
	 *
	 * @param array  $form Native edit form fields.
	 * @param array  $record Dedicated order record.
	 * @param string $mode Native recalculation mode.
	 * @return array<string,mixed>
	 */
	private function native_recalculation_fields( array $form, array $record, string $mode ): array {
		$post_ids = array();
		$prices   = array();
		$quants   = array();
		$cart_ids = array();
		foreach ( $record['cart'] as $line ) {
			$post_ids[] = (string) $line['post_id'];
			$prices[]   = (string) $line['price'];
			$quants[]   = (string) $line['quantity'];
			$cart_ids[] = (string) $line['cart_id'];
		}

		$fields = array(
			'action'          => 'order_item_ajax',
			'mode'            => $mode,
			'wc_nonce'        => $form['wc_nonce'],
			'order_id'        => (string) $record['order']['ID'],
			'mem_id'          => (string) $record['order']['mem_id'],
			'post_ids'        => $post_ids,
			'prices'          => $prices,
			'quants'          => $quants,
			'cart_ids'        => $cart_ids,
			'upoint'          => (string) $record['order']['order_usedpoint'],
			'shipping_charge' => (string) $record['order']['order_shipping_charge'],
			'cod_fee'         => (string) $record['order']['order_cod_fee'],
			'change_taxrate'  => 'change',
		);
		if ( 'recalculation_reduced' === $mode ) {
			$fields['discount_standard'] = (string) ( $record['meta']['discount_standard'] ?? 0 );
			$fields['discount_reduced']  = (string) ( $record['meta']['discount_reduced'] ?? 0 );
		} else {
			$fields['discount'] = (string) $record['order']['order_discount'];
		}
		return $fields;
	}

	/** Submit a save while asking the local fixture to fail one named SQL. */
	private function save_with_header( WtdTestHttp $http, int $order_id, array $fields, string $target ): array {
		return $http->request(
			$this->edit_post_path( $order_id ),
			$fields,
			array( 'X-WTD-Test-Fail-SQL: ' . $target )
		);
	}

	/** Return the exact native edit-post route used by the admin form. */
	private function edit_post_path( int $order_id ): string {
		return '/wp-admin/admin.php?page=usces_orderlist&order_action=editpost&order_id=' . $order_id;
	}

	/** Return a quantity that changes the fixture's current first cart row. */
	private function changed_quantity( $current ): string {
		return 3.0 === (float) $current ? '1' : '3';
	}

	/** Select a second stale quantity distinct from the winning quantity. */
	private function second_quantity( $current, string $winner ): string {
		$candidates = 3.0 === (float) $current ? array( '2', '3' ) : array( '1', '2' );
		foreach ( $candidates as $candidate ) {
			if ( $candidate !== $winner ) {
				return $candidate;
			}
		}
		return '2';
	}

	/** Select two distinct changed quantities for the concurrent requests. */
	private function race_quantities( $current ): array {
		if ( 1.0 === (float) $current ) {
			return array( '2', '3' );
		}
		if ( 2.0 === (float) $current ) {
			return array( '1', '3' );
		}
		return array( '1', '2' );
	}

	/** Send two authenticated saves through curl_multi so they overlap. */
	private function concurrent_saves( array $requests ): array {
		$multi   = curl_multi_init();
		$handles = array();
		foreach ( $requests as $index => $request ) {
			[ $client, $fields ] = $request;
			$reflection = new ReflectionObject( $client );
			$cookie_property = $reflection->getProperty( 'cookie_jar' );
			$cookie_property->setAccessible( true );
			$port = $client->port();
			$handle = curl_init( $this->local_url( $this->edit_post_path( $this->order_id() ), $port ) );
			$this->assertNotFalse( $handle );
			$options = array(
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_COOKIEFILE => $cookie_property->getValue( $client ),
				CURLOPT_COOKIEJAR => $cookie_property->getValue( $client ),
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS => 5,
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => http_build_query( $fields, '', '&' ),
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT => 30,
				CURLOPT_USERAGENT => 'WtdFailureHttp/1.0',
			);
			if ( defined( 'CURLOPT_CONNECT_TO' ) ) {
				$options[ CURLOPT_CONNECT_TO ] = array( '127.0.0.1:' . $port . ':wordpress:80' );
			}
			curl_setopt_array( $handle, $options );
			curl_multi_add_handle( $multi, $handle );
			$handles[ $index ] = $handle;
		}

		$running = null;
		do {
			$multi_status = curl_multi_exec( $multi, $running );
			if ( CURLM_CALL_MULTI_PERFORM === $multi_status ) {
				continue;
			}
			if ( $running > 0 && -1 === curl_multi_select( $multi, 1.0 ) ) {
				usleep( 10000 );
			}
		} while ( $running > 0 );

		$responses = array();
		foreach ( $handles as $index => $handle ) {
			$body = curl_multi_getcontent( $handle );
			$responses[ $index ] = array(
				'status' => (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ),
				'body' => (string) $body,
				'url' => (string) curl_getinfo( $handle, CURLINFO_EFFECTIVE_URL ),
			);
			curl_multi_remove_handle( $multi, $handle );
			curl_close( $handle );
		}
		curl_multi_close( $multi );
		ksort( $responses );
		return $responses;
	}

	/** Build a local-only URL from a relative admin path and validated port. */
	private function local_url( string $value, int $port ): string {
		if ( '' === $value ) {
			$value = '/';
		}
		if ( 0 === strpos( $value, 'http://' ) || 0 === strpos( $value, 'https://' ) ) {
			$url = $value;
		} else {
			$url = 'http://127.0.0.1:' . $port . ( 0 === strpos( $value, '/' ) ? $value : '/' . $value );
		}
		$parts = parse_url( $url );
		$this->assertIsArray( $parts );
		$this->assertSame( 'http', strtolower( $parts['scheme'] ?? '' ) );
		$this->assertSame( '127.0.0.1', strtolower( $parts['host'] ?? '' ) );
		$this->assertSame( $port, (int) ( $parts['port'] ?? 80 ) );
		return $url;
	}

	/**
	 * Return all native and supplemental rows owned by one order.
	 *
	 * @return array<string,array<int,array<string,string>>>
	 */
	private function database_snapshot( int $order_id ): array {
		global $wpdb;
		$order_table = $wpdb->prefix . 'usces_order';
		$cart_table  = $wpdb->prefix . 'usces_ordercart';
		$meta_table  = $wpdb->prefix . 'usces_order_meta';
		$cart_meta_table = $wpdb->prefix . 'usces_ordercart_meta';
		$order = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$order_table} WHERE ID = %d", $order_id ), ARRAY_A );
		$this->assertCount( 1, $order, 'The dedicated HTTP fixture order must exist before the HTTP test.' );
		$cart = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$cart_table} WHERE order_id = %d ORDER BY cart_id", $order_id ), ARRAY_A );
		$meta = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$meta_table} WHERE order_id = %d ORDER BY ometa_id", $order_id ), ARRAY_A );
		$cart_ids = array_map( 'intval', array_column( $cart, 'cart_id' ) );
		$cart_meta = array();
		if ( $cart_ids ) {
			$ids = implode( ',', $cart_ids );
			$cart_meta = $wpdb->get_results( "SELECT * FROM {$cart_meta_table} WHERE cart_id IN ({$ids}) ORDER BY cartmeta_id", ARRAY_A );
		}
		return array( 'order' => $order, 'cart' => $cart, 'meta' => $meta, 'cart_meta' => $cart_meta );
	}

	/** Restore the exact native rows captured by setUp. */
	private function restore_database_snapshot( array $snapshot ): void {
		global $wpdb;
		$order_id = isset( $snapshot['order'][0]['ID'] ) ? (int) $snapshot['order'][0]['ID'] : 0;
		$this->assertGreaterThan( 0, $order_id, 'The database snapshot must contain the dedicated order ID.' );
		$cart_ids = array_map( 'intval', array_column( $snapshot['cart'], 'cart_id' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}usces_order_meta WHERE order_id = %d", $order_id ) );
		if ( $cart_ids ) {
			$ids = implode( ',', $cart_ids );
			$wpdb->query( "DELETE FROM {$wpdb->prefix}usces_ordercart_meta WHERE cart_id IN ({$ids})" );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}usces_ordercart WHERE order_id = %d", $order_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}usces_order WHERE ID = %d", $order_id ) );
		foreach ( $snapshot['order'] as $row ) {
			$this->assertNotFalse( $wpdb->insert( $wpdb->prefix . 'usces_order', $row ) );
		}
		foreach ( $snapshot['cart'] as $row ) {
			$this->assertNotFalse( $wpdb->insert( $wpdb->prefix . 'usces_ordercart', $row ) );
		}
		foreach ( $snapshot['cart_meta'] as $row ) {
			$this->assertNotFalse( $wpdb->insert( $wpdb->prefix . 'usces_ordercart_meta', $row ) );
		}
		foreach ( $snapshot['meta'] as $row ) {
			$this->assertNotFalse( $wpdb->insert( $wpdb->prefix . 'usces_order_meta', $row ) );
		}
		if ( function_exists( 'wp_cache_flush_runtime' ) ) {
			wp_cache_flush_runtime();
		} else {
			wp_cache_flush();
		}
	}

	/** Temporarily enable Welcart's reduced-rate order metadata path. */
	private function enable_reduced_tax_metadata( int $order_id ): void {
		global $wpdb, $usces;
		$record = wtd_order_record( $order_id );
		$condition = maybe_unserialize( $record['order']['order_condition'] );
		$this->assertIsArray( $condition );
		$condition['applicable_taxrate'] = 'reduced';
		$snapshot = json_decode( $record['meta']['wtd_discount_snapshot'], true );
		$this->assertIsArray( $snapshot );
		$snapshot['condition']['applicable_taxrate'] = 'reduced';
		$snapshot['original']['condition']['applicable_taxrate'] = 'reduced';
		$order_table = $wpdb->prefix . 'usces_order';
		$meta_table  = $wpdb->prefix . 'usces_order_meta';
		$updated = $wpdb->update( $order_table, array( 'order_condition' => maybe_serialize( $condition ) ), array( 'ID' => $order_id ), array( '%s' ), array( '%d' ) );
		$this->assertNotFalse( $updated );
		$json = wp_json_encode( $snapshot );
		$this->assertIsString( $json );
		$updated = $wpdb->update( $meta_table, array( 'meta_value' => $json ), array( 'order_id' => $order_id, 'meta_key' => 'wtd_discount_snapshot' ), array( '%s' ), array( '%d', '%s' ) );
		$this->assertNotFalse( $updated );
		$this->assertNotFalse( $usces->set_order_meta_value( 'tax_standard', '0', $order_id ) );
		$this->assertNotFalse( $usces->set_order_meta_value( 'tax_reduced', '0', $order_id ) );
	}

	/** Save a response body as a local diagnostic artifact. */
	private function write_evidence( string $name, string $body ): void {
		$prefix = getenv( 'WTD_TEST_SESSION_PREFIX' );
		$prefix = is_string( $prefix ) && preg_match( '/^[A-Za-z0-9_-]+$/', $prefix ) ? $prefix : 'order-failure-http';
		$directory = dirname( __DIR__ ) . '/temp/' . $prefix . '_failures';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Local integration evidence only.
		if ( ! is_dir( $directory ) && ! mkdir( $directory, 0775, true ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local integration evidence only.
		file_put_contents( $directory . '/' . sanitize_title( $name ) . '.html', $body );
	}
}
