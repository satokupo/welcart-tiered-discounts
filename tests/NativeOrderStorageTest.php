<?php

use PHPUnit\Framework\TestCase;

/** @group integration */
final class NativeOrderStorageTest extends TestCase
{
    private function prepareOrder(): array
    {
        global $usces;
        require_once __DIR__ . '/fixtures/setup.php';
        $fixture = wtd_test_setup();
        update_option('welcart_tiered_discounts', ['schema_version'=>1,'target'=>['mode'=>'all'],'tiers'=>[['threshold'=>10000,'type'=>'fixed','value'=>500,'enabled'=>true],['threshold'=>30000,'type'=>'fixed','value'=>2000,'enabled'=>true]]]);
        unset($GLOBALS['wtd_locked_settings']);
        $usces->cart->crear_cart();
        $_POST = ['inCart'=>[$fixture['products']['standard']=>['wtd-standard'=>1]],'quant'=>[$fixture['products']['standard']=>['wtd-standard'=>3]]];
        $_SERVER['HTTP_REFERER'] = home_url('/');
        $usces->cart->inCart();
        $customer = array_fill_keys(['mailaddress1','mailaddress2','name1','name2','name3','name4','zipcode','pref','address1','address2','address3','tel','fax','country'], '');
        $customer = array_replace($customer, ['mailaddress1'=>'wtd-buyer@example.test','mailaddress2'=>'wtd-buyer@example.test','name1'=>'Test','name2'=>'Buyer','country'=>'JP','pref'=>'東京都']);
        $_SESSION['usces_member'] = ['ID'=>0];
        $_SESSION['usces_entry'] = ['customer'=>$customer, 'delivery'=>array_merge($customer,['delivery_flag'=>0]),'condition'=>$usces->get_condition(),'order'=>['payment_name'=>'WTD Test Bank Transfer','delivery_method'=>0,'usedpoint'=>0,'note'=>'','delivery_date'=>'','delivery_time'=>'']];
        $usces->set_cart_fees(['ID'=>0], $usces->cart->get_entry());
        wtd_confirmation_form('');
        $_POST = ['wtd_confirmation'=>$_SESSION['wtd_confirmation']['token']];
        $this->assertTrue(wtd_purchase_check(true));
        return $fixture;
    }

    public function test_native_order_callbacks_save_the_original_tier_list_and_all_money(): void
    {
        $this->prepareOrder();
        do_action('usces_pre_reg_orderdata');
        $id = usces_reg_orderdata();
        do_action('usces_post_reg_orderdata', $id, []);
        $record = wtd_order_record($id);
        $this->assertSame('30000.00', $record['order']['order_item_total_price']);
        $this->assertSame('-2000.00', $record['order']['order_discount']);
        $snapshot = wtd_read_snapshot($record['meta']['wtd_discount_snapshot']);
        $this->assertCount(2, $snapshot['settings']['tiers']);
        $this->assertSame('28000.00', $snapshot['original']['amounts']['total_full_price']);
        $this->assertEmpty($GLOBALS['wtd_transaction'] ?? null);
        update_option('wtd_test_saved_order_id', $id, false);
    }

    public function test_create_quantity_three_order_for_browser_acceptance(): void
    {
        $this->prepareOrder();
        do_action('usces_pre_reg_orderdata');
        $id = usces_reg_orderdata();
        do_action('usces_post_reg_orderdata', $id, []);
        $record = wtd_order_record($id);
        $this->assertSame('-2000.00', $record['order']['order_discount']);
        $this->assertSame('3', wtd_normalize_decimal($record['cart'][0]['quantity']));
        update_option('wtd_test_ui_order_id', $id, false);
    }

    public function test_snapshot_write_failure_rolls_back_native_rows_and_stock(): void
    {
        global $wpdb, $usces;
        $fixture = $this->prepareOrder();
        $member = wtd_test_ensure_member('wtd-rollback@example.test', 'wtd-local-rollback-123!', 10000);
        $_SESSION['usces_member'] = ['ID'=>$member['ID'], 'point'=>10000];
        $_SESSION['usces_entry']['order']['usedpoint'] = 1000;
        $usces->set_cart_fees($usces->get_member(), $usces->cart->get_entry());
        wtd_confirmation_form('');
        $_POST = ['wtd_confirmation'=>$_SESSION['wtd_confirmation']['token']];
        $this->assertTrue(wtd_purchase_check(true));
        $before = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}usces_order");
        $stock = $usces->get_skus($fixture['products']['standard'], 'code', false)['wtd-standard']['stocknum'];
        $fail = static function ($sql) {
            return stripos($sql, 'INSERT INTO') !== false && strpos($sql, 'wtd_discount_snapshot') !== false ? 'INSERT INTO wtd_nonexistent (value) VALUES (1)' : $sql;
        };
        $die = static function () { return static function () { throw new RuntimeException('expected-save-stop'); }; };
        add_filter('query', $fail, 1000);
        add_filter('wp_die_handler', $die);
        $old = $wpdb->suppress_errors();
        try {
            do_action('usces_pre_reg_orderdata');
            $id = usces_reg_orderdata();
            do_action('usces_post_reg_orderdata', $id, []);
            $this->fail('A failed snapshot must not commit an order.');
        } catch (RuntimeException $error) {
            $this->assertSame('expected-save-stop', $error->getMessage());
        } finally {
            remove_filter('query', $fail, 1000);
            remove_filter('wp_die_handler', $die);
            $wpdb->suppress_errors($old);
            wtd_rollback_order_write();
        }
        $this->assertSame($before, (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}usces_order"));
        $this->assertEquals($stock, $usces->get_skus($fixture['products']['standard'], 'code', false)['wtd-standard']['stocknum']);
        $this->assertSame(10000, (int)$wpdb->get_var($wpdb->prepare('SELECT mem_point FROM ' . usces_get_tablename('usces_member') . ' WHERE ID = %d', $member['ID'])));
        $this->assertSame(10000, (int)$_SESSION['usces_member']['point']);
    }

    public static function savedTaxCases(): array
    {
        return [
            'standard include'=>['include',false,'0.00','9500.00',null,null],
            'standard exclude'=>['exclude',false,'950.00','10450.00',null,null],
            'mixed include'=>['include',true,'0.00','9500.00','0','0'],
            'mixed exclude'=>['exclude',true,'874.00','10374.00','570','304'],
        ];
    }

    /** @dataProvider savedTaxCases */
    public function test_native_saved_tax_matrix($mode, $mixed, $tax, $total, $tax_s, $tax_r): void
    {
        global $usces;
        $fixture = $this->prepareOrder();
        $entry = $usces->cart->get_entry();
        $usces->cart->crear_cart();
        $products = $mixed ? ['six'=>'wtd-six','reduced'=>'wtd-reduced'] : ['standard'=>'wtd-standard'];
        foreach ($products as $key=>$sku) {
            $_POST = ['inCart'=>[$fixture['products'][$key]=>[$sku=>1]], 'quant'=>[$fixture['products'][$key]=>[$sku=>1]]];
            $usces->cart->inCart();
        }
        $usces->options['tax_mode'] = $mode;
        $usces->options['applicable_taxrate'] = $mixed ? 'reduced' : 'standard';
        $_SESSION['usces_entry'] = $entry;
        $_SESSION['usces_entry']['condition'] = $usces->get_condition();
        $usces->set_cart_fees(['ID'=>0], $usces->cart->get_entry());
        wtd_confirmation_form('');
        $_POST = ['wtd_confirmation'=>$_SESSION['wtd_confirmation']['token']];
        $this->assertTrue(wtd_purchase_check(true));
        do_action('usces_pre_reg_orderdata');
        $id = usces_reg_orderdata();
        do_action('usces_post_reg_orderdata', $id, []);
        $record = wtd_order_record($id);
        $this->assertSame('-500.00', $record['order']['order_discount']);
        $this->assertSame($tax, $record['order']['order_tax']);
        $snapshot = wtd_read_snapshot($record['meta']['wtd_discount_snapshot']);
        $this->assertSame($total, $snapshot['original']['amounts']['total_full_price']);
        $quote = wtd_order_quote($record, wtd_record_input($record));
        $this->assertSame($total, $quote['total']);
        if ($mixed) {
            foreach (['subtotal_standard'=>'6000','subtotal_reduced'=>'4000','discount_standard'=>'-300','discount_reduced'=>'-200','tax_standard'=>$tax_s,'tax_reduced'=>$tax_r] as $key=>$expected) {
                $this->assertSame($expected, wtd_normalize_decimal(ltrim($record['meta'][$key], '-')) === '0' ? '0' : (string)$record['meta'][$key]);
            }
        }
    }
}
