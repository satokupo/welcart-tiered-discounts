<?php

use PHPUnit\Framework\TestCase;

/** @group integration */
final class OrderNativeEdgeTest extends TestCase
{
    public function test_empty_order_does_not_borrow_the_administrators_shopping_cart(): void
    {
        global $usces;
        $post = $_POST;
        $session = $_SESSION;
        try {
            $row = wtd_order_record((int)get_option('wtd_test_saved_order_id'))['cart'][0];
            $usces->cart->crear_cart();
            $_POST = ['inCart'=>[$row['post_id']=>[$row['sku_code']=>1]], 'quant'=>[$row['post_id']=>[$row['sku_code']=>1]]];
            $_SERVER['HTTP_REFERER'] = home_url('/');
            $usces->cart->inCart();
            $this->assertGreaterThan(0, $usces->get_total_price());
            wtd_begin_order_write('edit');
            wtd_apply_order_rows(0, ['cart'=>[]], ['removed'=>[], 'lines'=>[]]);
            $this->assertSame(0.0, (float)$usces->get_total_price([]));
        } finally {
            wtd_rollback_order_write();
            $_POST = $post;
            $_SESSION = $session;
        }
    }

    public function test_added_checkbox_options_survive_the_native_update(): void
    {
        global $wpdb;
        require_once __DIR__ . '/fixtures/setup.php';
        $post = $_POST;
        wtd_begin_order_write('edit');
        try {
            $product = wtd_test_ensure_product(['item_code'=>'WTD-EDGE-OPTIONS','sku_code'=>'edge','title'=>'WTD Test Options','list_price'=>100,'price'=>100,'taxrate'=>'standard']);
            wel_add_opt_data($product, ['name'=>'Gift','means'=>4,'essential'=>0,'value'=>"Yes\nNo",'sort'=>0]);
            $order = wtd_order_record((int)get_option('wtd_test_saved_order_id'))['order'];
            unset($order['ID']);
            $wpdb->insert($wpdb->prefix . 'usces_order', $order);
            $id = $wpdb->insert_id;
            $_POST = [];
            $quote = wtd_apply_order_rows($id, ['cart'=>[]], ['removed'=>[], 'lines'=>[['cart_id'=>-1,'post_id'=>$product,'sku_code'=>'edge','price'=>'100.00','quantity'=>'1','new_options'=>['Gift'=>['Yes']]]]]);
            $cart_id = $quote['lines'][0]['cart_id'];
            $_POST['skuPrice'][$cart_id] = '100';
            $_POST['quant'][$cart_id] = '1';
            usces_update_ordercartdata($id);
            $meta = usces_get_ordercart_meta('option', $cart_id, 'Gift');
            $this->assertSame(['Yes'], maybe_unserialize($meta[0]['meta_value']));
        } finally {
            wtd_rollback_order_write();
            $_POST = $post;
        }
    }

    public function test_decimal_prices_and_quantities_survive_native_columns_or_are_rejected(): void
    {
        global $wpdb;
        $before = wtd_order_record((int)get_option('wtd_test_saved_order_id'));
        $post = $_POST;
        wtd_begin_order_write('edit');
        try {
            $order = $before['order'];
            unset($order['ID']);
            $wpdb->insert($wpdb->prefix . 'usces_order', $order);
            $id = $wpdb->insert_id;
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}usces_ordercart WHERE cart_id = %d", $before['cart'][0]['cart_id']), ARRAY_A);
            unset($row['cart_id']);
            $row['order_id'] = $id;
            $wpdb->insert($wpdb->prefix . 'usces_ordercart', $row);
            $cart_id = $wpdb->insert_id;
            foreach ([['12.50','2'],['10000.00','0.5'],['99999999.99','1']] as [$price,$quantity]) {
                $_POST = ['skuPrice'=>[$cart_id=>$price], 'quant'=>[$cart_id=>$quantity]];
                usces_update_ordercartdata($id);
                $saved = usces_get_ordercartdata($id)[0];
                $this->assertSame($price, $saved['price']);
                $this->assertSame($quantity, wtd_normalize_decimal($saved['quantity']));
            }
            $input = wtd_record_input($before);
            $input['skuPrice'][$before['cart'][0]['cart_id']] = '100000000';
            try {
                wtd_order_quote($before, $input);
                $this->fail('Column overflow must be rejected before a save.');
            } catch (InvalidArgumentException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
            $input = wtd_record_input($before);
            $input['quant'][$before['cart'][0]['cart_id']] = '1.23456789';
            $this->expectException(InvalidArgumentException::class);
            wtd_order_quote($before, $input);
        } finally {
            wtd_rollback_order_write();
            $_POST = $post;
        }
    }
}
