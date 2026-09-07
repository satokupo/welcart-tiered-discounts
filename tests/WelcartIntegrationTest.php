<?php

use PHPUnit\Framework\TestCase;

/** @group integration */
final class WelcartIntegrationTest extends TestCase
{
    public function test_native_cart_recalculates_registered_prices_and_tax_without_accumulating(): void
    {
        global $usces;
        require_once __DIR__ . '/fixtures/setup.php';
        $data = wtd_test_setup();
        update_option('welcart_tiered_discounts', ['schema_version'=>1, 'target'=>['mode'=>'all'], 'tiers'=>[['threshold'=>10000,'type'=>'fixed','value'=>500,'enabled'=>true]]]);
        unset($GLOBALS['wtd_locked_settings']);
        $_POST = ['inCart'=>[$data['products']['six']=>['wtd-six'=>'1']], 'quant'=>[$data['products']['six']=>['wtd-six'=>'1']]];
        $_SERVER['HTTP_REFERER'] = home_url('/');
        $usces->cart->crear_cart();
        $usces->cart->inCart();
        $_POST = ['inCart'=>[$data['products']['reduced']=>['wtd-reduced'=>'1']], 'quant'=>[$data['products']['reduced']=>['wtd-reduced'=>'1']]];
        $usces->cart->inCart();
        $_SESSION['usces_entry'] = ['customer'=>['country'=>'JP','pref'=>'東京都'], 'delivery'=>['country'=>'JP','pref'=>'東京都','delivery_flag'=>0], 'order'=>['payment_name'=>'WTD Test Bank Transfer','delivery_method'=>0,'usedpoint'=>0]];
        $usces->options['applicable_taxrate'] = 'reduced';
        $usces->options['tax_mode'] = 'exclude';
        for ($pass = 0; $pass < 2; $pass++) {
            $usces->set_cart_fees([], $usces->cart->get_entry());
            $order = $usces->cart->get_entry()['order'];
            $this->assertEquals(10000, $order['total_items_price']);
            $this->assertEquals(-500, $order['discount']);
            $this->assertEquals(874, $order['tax']);
            $this->assertEquals(10374, $order['total_full_price']);
            $tax = Welcart_Tax::get_instance();
            $this->assertEquals(-300, $tax->discount_standard);
            $this->assertEquals(-200, $tax->discount_reduced);
        }
        $registered = wtd_registered_lines([['post_id'=>$data['products']['standard'], 'sku'=>'wtd-standard','price'=>7000,'quantity'=>1]]);
        $this->assertSame('10000', wtd_normalize_decimal($registered[0]['price']));
        $this->assertSame('10000', wtd_subtotal($registered));
    }

    public function test_order_tax_delegates_to_native_apis_and_restores_shop_context(): void
    {
        global $usces;
        $before = $usces->options;
        $session = $_SESSION;
        $singleton = get_object_vars(Welcart_Tax::get_instance());
        $calls = [];
        $observe = static function ($tax) use (&$calls) { $calls[] = current_filter(); return $tax; };
        add_filter('usces_filter_getTax', $observe);
        add_filter('usces_filter_internal_tax', $observe);
        $condition = ['tax_mode'=>'exclude','tax_target'=>'products','tax_rate'=>10,'tax_method'=>'cutting','tax_display'=>'activate','applicable_taxrate'=>'standard','point_coverage'=>1];
        try {
            $this->assertEquals(950, wtd_native_tax(9500, 10, $condition));
            $condition['tax_mode'] = 'include';
            $this->assertEquals(863, wtd_native_tax(9500, 10, $condition));
            $condition['applicable_taxrate'] = 'reduced';
            $this->assertEquals(281, wtd_native_tax(3800, 8, $condition));
            $this->assertSame(['usces_filter_getTax','usces_filter_internal_tax','usces_filter_getTax'], $calls);
        } finally {
            remove_filter('usces_filter_getTax', $observe);
            remove_filter('usces_filter_internal_tax', $observe);
        }
        $this->assertSame($before, $usces->options);
        $this->assertSame($session, $_SESSION);
        $this->assertSame($singleton, get_object_vars(Welcart_Tax::get_instance()));
        $fail = static function () { throw new RuntimeException('native-tax-failure'); };
        add_filter('usces_filter_getTax', $fail);
        try {
            wtd_native_tax(3800, 8, $condition);
            $this->fail('Native failures must propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('native-tax-failure', $error->getMessage());
        } finally {
            remove_filter('usces_filter_getTax', $fail);
        }
        $this->assertSame($before, $usces->options);
        $this->assertSame($session, $_SESSION);
        $this->assertSame($singleton, get_object_vars(Welcart_Tax::get_instance()));
    }

    public function test_discount_is_registered_on_the_native_welcart_path(): void
    {
        $this->assertTrue(function_exists('wtd_discount_filter'), 'Plugin discount callback must be loaded by WordPress.');
        $this->assertNotFalse(has_filter('usces_order_discount', 'wtd_discount_filter'));
    }

    public function test_purchase_without_the_displayed_confirmation_is_rejected(): void
    {
        unset($_SESSION['wtd_confirmation'], $_POST['wtd_confirmation']);
        $this->assertTrue(function_exists('wtd_purchase_check'), 'The confirmation gate must be loaded.');
        $this->assertFalse(wtd_purchase_check(false));
        $this->assertFalse(wtd_purchase_check(true));
    }

    public function test_failed_save_rolls_back_the_same_wordpress_connection_and_session(): void
    {
        global $wpdb;
        $this->assertTrue(function_exists('wtd_begin_order_write'), 'Atomic save boundary must be loaded.');
        update_option('wtd_test_atomic', 'before', false);
        $_SESSION['wtd_test_atomic'] = 'before';
        wtd_begin_order_write('probe');
        update_option('wtd_test_atomic', 'during', false);
        $_SESSION['wtd_test_atomic'] = 'during';
        wtd_rollback_order_write();
        $this->assertSame('before', get_option('wtd_test_atomic'));
        $this->assertSame('before', $_SESSION['wtd_test_atomic']);
        $this->assertEmpty($GLOBALS['wtd_transaction'] ?? null);
    }

    public function test_a_native_write_failure_is_not_hidden_by_a_later_successful_read(): void
    {
        global $wpdb;
        update_option('wtd_test_atomic', 'before', false);
        wtd_begin_order_write('probe');
        $old = $wpdb->suppress_errors();
        $wpdb->query("UPDATE {$wpdb->prefix}wtd_nonexistent SET value = 1");
        $wpdb->get_var('SELECT 1');
        $wpdb->suppress_errors($old);
        try {
            wtd_commit_order_write();
            $this->fail('A later successful query must not turn a failed save into success.');
        } catch (RuntimeException $error) {
            $this->assertSame('order_save_failed', $error->getMessage());
        } finally {
            wtd_rollback_order_write();
        }
        $this->assertSame('before', get_option('wtd_test_atomic'));
    }

    public function test_missing_native_tax_metadata_cannot_be_committed(): void
    {
        $this->assertTrue(function_exists('wtd_verify_order_tax'));
        $this->expectException(RuntimeException::class);
        wtd_verify_order_tax(['meta'=>['subtotal_standard'=>'6000','discount_standard'=>null]], ['subtotal_standard'=>6000,'discount_standard'=>-300]);
    }

    public static function taxCases(): array
    {
        return [
            'exclusive standard' => ['exclude', false, 10000, 0, 0, 1, 'products', '950.00', '0.00', '10450.00'],
            'inclusive standard' => ['include', false, 10000, 0, 0, 1, 'products', '0.00', '863.00', '9500.00'],
            'exclusive mixed' => ['exclude', true, 6000, 4000, 0, 1, 'products', '874.00', '0.00', '10374.00'],
            'inclusive mixed' => ['include', true, 6000, 4000, 0, 1, 'products', '0.00', '799.00', '9500.00'],
            'inclusive points' => ['include', false, 10000, 0, 1000, 1, 'products', '0.00', '863.00', '8500.00'],
            'exclusive points' => ['exclude', false, 10000, 0, 1000, 1, 'products', '950.00', '0.00', '9450.00'],
            'inclusive points before tax' => ['include', false, 10000, 0, 1000, 0, 'all', '0.00', '772.00', '8500.00'],
            'exclusive points before tax' => ['exclude', false, 10000, 0, 1000, 0, 'all', '850.00', '0.00', '9350.00'],
        ];
    }

    /** @dataProvider taxCases */
    public function test_preview_amounts_use_the_saved_tax_condition($mode, $mixed, $standard, $reduced, $points, $coverage, $target, $tax, $internal, $total): void
    {
        $lines = [['price'=>(string)$standard, 'quantity'=>'1', 'taxrate'=>'standard']];
        if ($reduced) $lines[] = ['price'=>(string)$reduced, 'quantity'=>'1', 'taxrate'=>'reduced'];
        $settings = ['schema_version'=>1, 'target'=>['mode'=>'all'], 'tiers'=>[['threshold'=>10000,'type'=>'fixed','value'=>500,'enabled'=>true]]];
        $condition = ['tax_mode'=>$mode, 'tax_target'=>$target, 'tax_rate'=>10, 'tax_rate_reduced'=>8, 'tax_method'=>'cutting', 'applicable_taxrate'=>$mixed ? 'reduced' : 'standard', 'point_coverage'=>$coverage];
        $result = wtd_order_amounts($lines, $settings, $condition, ['shipping_charge'=>'0','cod_fee'=>'0','usedpoint'=>$points]);
        $this->assertSame('500', $result['discount']);
        $this->assertSame($tax, $result['tax']);
        $this->assertSame($internal, $result['internal_tax']);
        $this->assertSame($total, $result['total']);
        $this->assertSame($internal, wtd_money_string(wtd_money_cents($result['internal_tax_parts']['standard']) + wtd_money_cents($result['internal_tax_parts']['reduced'])));
    }

    public static function taxEdgeCases(): array
    {
        $standard_settings = ['schema_version'=>1, 'target'=>['mode'=>'all'], 'tiers'=>[['threshold'=>10000,'type'=>'fixed','value'=>500,'enabled'=>true]]];
        $rounding_settings = ['schema_version'=>1, 'target'=>['mode'=>'all'], 'tiers'=>[['threshold'=>10000,'type'=>'fixed','value'=>499,'enabled'=>true]]];
        $standard_condition = ['tax_mode'=>'exclude','tax_target'=>'products','tax_rate'=>10,'tax_rate_reduced'=>8,'tax_display'=>'activate','applicable_taxrate'=>'standard','point_coverage'=>1];
        $standard_fees = ['shipping_charge'=>'0','cod_fee'=>'0','usedpoint'=>0];

        return [
            'cutting keeps registered subtotal and discount' => [
                'lines' => [['price'=>'10504','quantity'=>'1','taxrate'=>'standard']],
                'settings' => $rounding_settings,
                'condition' => array_replace($standard_condition, ['tax_method'=>'cutting']),
                'fees' => $standard_fees,
                'expected' => ['subtotal'=>'10504','discount'=>'499','tax'=>'1000.00','internal_tax'=>'0.00','total'=>'11005.00'],
            ],
            'bring keeps registered subtotal and discount' => [
                'lines' => [['price'=>'10504','quantity'=>'1','taxrate'=>'standard']],
                'settings' => $rounding_settings,
                'condition' => array_replace($standard_condition, ['tax_method'=>'bring']),
                'fees' => $standard_fees,
                'expected' => ['subtotal'=>'10504','discount'=>'499','tax'=>'1001.00','internal_tax'=>'0.00','total'=>'11006.00'],
            ],
            'rounding keeps registered subtotal and discount' => [
                'lines' => [['price'=>'10504','quantity'=>'1','taxrate'=>'standard']],
                'settings' => $rounding_settings,
                'condition' => array_replace($standard_condition, ['tax_method'=>'rounding']),
                'fees' => $standard_fees,
                'expected' => ['subtotal'=>'10504','discount'=>'499','tax'=>'1001.00','internal_tax'=>'0.00','total'=>'11006.00'],
            ],
            'tax display off produces no tax' => [
                'lines' => [['price'=>'10000','quantity'=>'1','taxrate'=>'standard']],
                'settings' => $standard_settings,
                'condition' => array_replace($standard_condition, ['tax_method'=>'cutting','tax_display'=>'deactivate']),
                'fees' => $standard_fees,
                'expected' => ['subtotal'=>'10000','discount'=>'500','tax'=>'0.00','internal_tax'=>'0.00','total'=>'9500.00'],
            ],
            'zero tax rate produces no tax' => [
                'lines' => [['price'=>'10000','quantity'=>'1','taxrate'=>'standard']],
                'settings' => $standard_settings,
                'condition' => array_replace($standard_condition, ['tax_method'=>'cutting','tax_rate'=>0]),
                'fees' => $standard_fees,
                'expected' => ['subtotal'=>'10000','discount'=>'500','tax'=>'0.00','internal_tax'=>'0.00','total'=>'9500.00'],
            ],
            'all target includes shipping and fee in tax base' => [
                'lines' => [['price'=>'10000','quantity'=>'1','taxrate'=>'standard']],
                'settings' => $standard_settings,
                'condition' => array_replace($standard_condition, ['tax_method'=>'cutting','tax_target'=>'all']),
                'fees' => ['shipping_charge'=>'100','cod_fee'=>'50','usedpoint'=>0],
                'expected' => ['subtotal'=>'10000','discount'=>'500','tax'=>'965.00','internal_tax'=>'0.00','total'=>'10615.00'],
            ],
            'reduced-only lines allocate discount and tax to reduced rate' => [
                'lines' => [['price'=>'10000','quantity'=>'1','taxrate'=>'reduced']],
                'settings' => $standard_settings,
                'condition' => array_replace($standard_condition, ['tax_method'=>'cutting','applicable_taxrate'=>'reduced']),
                'fees' => $standard_fees,
                'expected' => ['subtotal'=>'10000','discount'=>'500','tax'=>'760.00','internal_tax'=>'0.00','total'=>'10260.00'],
            ],
            'empty lines keep subtotal discount and tax at zero' => [
                'lines' => [],
                'settings' => $standard_settings,
                'condition' => array_replace($standard_condition, ['tax_method'=>'cutting']),
                'fees' => $standard_fees,
                'expected' => ['subtotal'=>'0','discount'=>'0','tax'=>'0.00','internal_tax'=>'0.00','total'=>'0.00'],
            ],
        ];
    }

    /** @dataProvider taxEdgeCases */
    public function test_order_amounts_cover_saved_tax_edges(array $lines, array $settings, array $condition, array $fees, array $expected): void
    {
        $result = wtd_order_amounts($lines, $settings, $condition, $fees);
        $this->assertSame($expected['subtotal'], $result['subtotal']);
        $this->assertSame($expected['discount'], $result['discount']);
        $this->assertSame($expected['tax'], $result['tax']);
        $this->assertSame($expected['internal_tax'], $result['internal_tax']);
        $this->assertSame($expected['total'], $result['total']);
        $this->assertSame($expected['internal_tax'], wtd_money_string(wtd_money_cents($result['internal_tax_parts']['standard']) + wtd_money_cents($result['internal_tax_parts']['reduced'])));
    }

    public function test_order_time_tax_condition_wins_over_current_shop_options(): void
    {
        global $usces;
        $before = $usces->options;
        $current = array_replace($before, ['tax_mode'=>'include','tax_target'=>'all','tax_rate'=>8,'tax_method'=>'bring','tax_display'=>'deactivate','applicable_taxrate'=>'standard']);
        $usces->options = $current;
        $settings = ['schema_version'=>1, 'target'=>['mode'=>'all'], 'tiers'=>[['threshold'=>10000,'type'=>'fixed','value'=>500,'enabled'=>true]]];
        $condition = ['tax_mode'=>'exclude','tax_target'=>'products','tax_rate'=>10,'tax_rate_reduced'=>8,'tax_method'=>'cutting','tax_display'=>'activate','applicable_taxrate'=>'standard','point_coverage'=>1];
        try {
            $result = wtd_order_amounts([['price'=>'10000','quantity'=>'1','taxrate'=>'standard']], $settings, $condition, ['shipping_charge'=>'0','cod_fee'=>'0','usedpoint'=>0]);
            $this->assertSame('10000', $result['subtotal']);
            $this->assertSame('500', $result['discount']);
            $this->assertSame('950.00', $result['tax']);
            $this->assertSame('10450.00', $result['total']);
            $this->assertSame($current, $usces->options);
        } finally {
            $usces->options = $before;
        }
    }
}
