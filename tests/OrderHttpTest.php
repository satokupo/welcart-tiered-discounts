<?php

use PHPUnit\Framework\TestCase;

/** @group integration */
final class OrderHttpTest extends TestCase
{
    public function test_legacy_and_corrupt_orders_and_cross_order_nonce_keep_their_boundaries(): void
    {
        global $wpdb, $usces;
        require_once __DIR__ . '/fixtures/HttpClient.php';
        $source = wtd_order_record((int)get_option('wtd_test_saved_order_id'));
        $http = new WtdTestHttp();
        $http->login(getenv('WTD_TEST_ADMIN_USER'), getenv('WTD_TEST_ADMIN_PASSWORD'));
        $list = $http->request('/wp-admin/admin.php?page=usces_orderlist');
        preg_match('/order_action=edit[^"\s]*?wc_nonce=([a-z0-9]+)/', $list['body'], $nonce);
        $ids = [];
        foreach ([false, true] as $corrupt) {
            $order = $source['order'];
            unset($order['ID']);
            $order['order_email'] = 'wtd-boundary@example.test';
            $wpdb->insert($wpdb->prefix . 'usces_order', $order);
            $id = (int)$wpdb->insert_id;
            $ids[] = $id;
            foreach ($source['cart'] as $old) {
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}usces_ordercart WHERE cart_id=%d", $old['cart_id']), ARRAY_A);
                unset($row['cart_id']);
                $row['order_id'] = $id;
                $wpdb->insert($wpdb->prefix . 'usces_ordercart', $row);
            }
            if ($corrupt) $usces->set_order_meta_value('wtd_discount_snapshot', '{broken', $id);
            $before = wtd_order_record($id);
            $page = $http->request('/wp-admin/admin.php?page=usces_orderlist&order_action=edit&order_id='.$id.'&wc_nonce='.$nonce[1]);
            $this->assertSame(200, $page['status']);
            $fields = WtdTestHttp::formFields($page['body'], 'order_editpost_form');
            if (!$corrupt) {
                $this->assertStringContainsString('注文時の割引一覧がないため', $page['body']);
                $this->assertArrayNotHasKey('wtd_preview', $fields);
                $this->assertSame($before, wtd_order_record($id));
            } else {
                $fields['update_order_edit'] = 'update';
                $saved = $http->request('/wp-admin/admin.php?page=usces_orderlist&order_action=editpost&order_id='.$id, $fields);
                $this->assertSame(409, $saved['status']);
                $this->assertSame($before, wtd_order_record($id));
            }
        }
        [$authenticated, $source_id, $fields, $before_source] = $this->editForm();
        $other_before = wtd_order_record($ids[1]);
        $changed_id = $authenticated->request('/wp-admin/admin-ajax.php', array_merge($fields, ['action'=>'wtd_preview_order', 'order_id'=>$ids[1]]));
        $this->assertSame(403, $changed_id['status']);
        $this->assertSame($before_source, wtd_order_record($source_id));
        $this->assertSame($other_before, wtd_order_record($ids[1]));
        update_option('wtd_test_boundary_order_ids', $ids, false);
    }

    private function editForm(): array
    {
        require_once __DIR__ . '/fixtures/HttpClient.php';
        $id = (int)get_option('wtd_test_saved_order_id');
        $http = new WtdTestHttp();
        $http->login(getenv('WTD_TEST_ADMIN_USER'), getenv('WTD_TEST_ADMIN_PASSWORD'));
        $list = $http->request('/wp-admin/admin.php?page=usces_orderlist');
        preg_match('/order_action=edit[^"\s]*?wc_nonce=([a-z0-9]+)/', $list['body'], $nonce);
        $page = $http->request('/wp-admin/admin.php?page=usces_orderlist&order_action=edit&order_id=' . $id . '&wc_nonce=' . $nonce[1]);
        $this->assertSame(200, $page['status']);
        $fields = WtdTestHttp::formFields($page['body'], 'order_editpost_form');
        $this->assertArrayHasKey('wtd_nonce', $fields);
        return [$http, $id, $fields, wtd_order_record($id)];
    }

    public function test_cancellation_with_changed_money_preserves_native_status_and_history(): void
    {
        global $wpdb;
        [$http, $id, $fields, $before] = $this->editForm();
        $quantity = (float)$before['cart'][0]['quantity'] === 3.0 ? '1' : '3';
        $fields['quant[' . $before['cart'][0]['cart_id'] . ']'] = $quantity;
        $preview = $http->request('/wp-admin/admin-ajax.php', array_merge($fields,['action'=>'wtd_preview_order','order_id'=>$id]));
        $this->assertSame(200, $preview['status'], $preview['body']);
        $fields['wtd_preview'] = json_decode($preview['body'], true)['data']['token'];
        $fields['offer[taio]'] = 'cancel';
        $fields['update_order_edit'] = 'update';
        try {
            $saved = $http->request('/wp-admin/admin.php?page=usces_orderlist&order_action=editpost&order_id=' . $id, $fields);
            $this->assertSame(200, $saved['status']);
            $after = wtd_order_record($id);
            $this->assertStringContainsString('cancel', $after['order']['order_status']);
            $this->assertSame($quantity, wtd_normalize_decimal($after['cart'][0]['quantity']));
            $this->assertSame($quantity === '1' ? '-500.00' : '-2000.00', $after['order']['order_discount']);
            $this->assertCount(count(json_decode($before['meta']['wtd_discount_history'] ?: '[]', true)) + 1, json_decode($after['meta']['wtd_discount_history'], true));
        } finally {
            $wpdb->update($wpdb->prefix . 'usces_order', ['order_status'=>$before['order']['order_status']], ['ID'=>$id]);
        }
    }

    public function test_staged_addition_and_removal_are_read_only_until_confirmed_save(): void
    {
        [$http, $id, $fields, $before] = $this->editForm();
        $staged = $http->request('/wp-admin/admin-ajax.php', ['action'=>'order_item2cart_ajax','order_id'=>$id,'post_id'=>$before['cart'][0]['post_id'],'sku'=>$before['cart'][0]['sku_code'],'wc_nonce'=>$fields['wc_nonce'],'wtd_form'=>http_build_query($fields)]);
        $this->assertSame(200, $staged['status'], $staged['body']);
        $this->assertSame($before, wtd_order_record($id));
        $rows = WtdTestHttp::formFields('<form id="rows"><table><tbody>' . $staged['body'] . '</tbody></table></form>', 'rows');
        $this->assertArrayHasKey('wtd_new', $rows);
        $fields = array_merge($fields, $rows);
        $ajax = $http->request('/wp-admin/admin-ajax.php', array_merge($fields, ['action'=>'wtd_preview_order','order_id'=>$id]));
        $this->assertSame(200, $ajax['status'], $ajax['body']);
        $preview = json_decode($ajax['body'], true)['data'];
        $fields['wtd_preview'] = $preview['token'];
        $fields['update_order_edit'] = 'update';
        $saved = $http->request('/wp-admin/admin.php?page=usces_orderlist&order_action=editpost&order_id=' . $id, $fields);
        $this->assertSame(200, $saved['status']);
        $added = wtd_order_record($id);
        $this->assertCount(count($before['cart']) + 1, $added['cart']);
        $this->assertSame($before['meta']['wtd_discount_snapshot'], $added['meta']['wtd_discount_snapshot']);
        $new_id = array_values(array_diff(array_column($added['cart'], 'cart_id'), array_column($before['cart'], 'cart_id')))[0];
        [$http, $id, $fields, $before_remove] = $this->editForm();
        foreach (['skuPrice','quant','postId'] as $key) unset($fields[$key . '[' . $new_id . ']']);
        $fields['wtd_removed'] = json_encode([(int)$new_id]);
        $previewed = $http->request('/wp-admin/admin-ajax.php', array_merge($fields,['action'=>'wtd_preview_order','order_id'=>$id]));
        $this->assertSame(200, $previewed['status'], $previewed['body']);
        $this->assertSame($before_remove, wtd_order_record($id));
        $fields['wtd_preview'] = json_decode($previewed['body'], true)['data']['token'];
        $fields['update_order_edit'] = 'update';
        $saved = $http->request('/wp-admin/admin.php?page=usces_orderlist&order_action=editpost&order_id=' . $id, $fields);
        $this->assertSame(200, $saved['status']);
        $after = wtd_order_record($id);
        $this->assertSame($before['cart'], $after['cart']);
        $this->assertCount(count(json_decode($before['meta']['wtd_discount_history'] ?: '[]', true)) + 2, json_decode($after['meta']['wtd_discount_history'], true));
    }

    public function test_changed_quantity_cannot_save_without_a_matching_displayed_preview(): void
    {
        global $wpdb;
        require_once __DIR__ . '/fixtures/HttpClient.php';
        $id = (int)get_option('wtd_test_saved_order_id');
        $this->assertGreaterThan(0, $id, 'Run NativeOrderStorageTest to create a dummy order first.');
        $before = wtd_order_record($id);
        $http = new WtdTestHttp();
        $http->login(getenv('WTD_TEST_ADMIN_USER'), getenv('WTD_TEST_ADMIN_PASSWORD'));
        $list = $http->request('/wp-admin/admin.php?page=usces_orderlist');
        preg_match('/order_action=edit[^"\s]*?wc_nonce=([a-z0-9]+)/', $list['body'], $nonce);
        $this->assertNotEmpty($nonce[1] ?? null, 'The native order-list edit link must provide its nonce.');
        $page = $http->request('/wp-admin/admin.php?page=usces_orderlist&order_action=edit&order_id=' . $id . '&wc_nonce=' . $nonce[1]);
        $this->assertSame(200, $page['status']);
        $fields = WtdTestHttp::formFields($page['body'], 'order_editpost_form');
        $this->assertArrayHasKey('wc_nonce', $fields);
        $fields['quant[' . $before['cart'][0]['cart_id'] . ']'] = '1';
        $fields['wtd_preview'] = '';
        $fields['update_order_edit'] = 'update';
        try {
            $response = $http->request('/wp-admin/admin.php?page=usces_orderlist&order_action=editpost&order_id=' . $id, $fields);
            $this->assertSame(409, $response['status']);
            $after = wtd_order_record($id);
            $this->assertSame($before, $after);
        } finally {
            // Keep this dedicated dummy order usable after the initial RED run.
            $wpdb->update($wpdb->prefix . 'usces_order', $before['order'], ['ID'=>$id]);
            foreach ($before['cart'] as $line) {
                $wpdb->update($wpdb->prefix . 'usces_ordercart', ['price'=>$line['price'],'quantity'=>$line['quantity']], ['cart_id'=>$line['cart_id']]);
            }
        }
    }

    public function test_admin_preview_rejects_a_missing_order_nonce(): void
    {
        require_once __DIR__ . '/fixtures/HttpClient.php';
        $http = new WtdTestHttp();
        $login = $http->login(getenv('WTD_TEST_ADMIN_USER'), getenv('WTD_TEST_ADMIN_PASSWORD'));
        $this->assertSame(200, $login['status']);
        $response = $http->request('/wp-admin/admin-ajax.php', ['action'=>'wtd_preview_order','order_id'=>1]);
        $this->assertSame(403, $response['status']);
    }

    public function test_native_product_addition_cannot_write_before_discount_preview(): void
    {
        require_once __DIR__ . '/fixtures/HttpClient.php';
        $id = (int)get_option('wtd_test_saved_order_id');
        $before = wtd_order_record($id);
        $http = new WtdTestHttp();
        $http->login(getenv('WTD_TEST_ADMIN_USER'), getenv('WTD_TEST_ADMIN_PASSWORD'));
        $list = $http->request('/wp-admin/admin.php?page=usces_orderlist');
        preg_match('/order_action=edit[^"\s]*?wc_nonce=([a-z0-9]+)/', $list['body'], $nonce);
        try {
            $response = $http->request('/wp-admin/admin-ajax.php', ['action'=>'order_item2cart_ajax','order_id'=>$id,'post_id'=>$before['cart'][0]['post_id'],'sku'=>$before['cart'][0]['sku_code'],'wc_nonce'=>$nonce[1]]);
            $this->assertSame(409, $response['status']);
            $this->assertSame($before, wtd_order_record($id));
        } finally {
            foreach (wtd_order_record($id)['cart'] as $line) {
                if (!in_array($line['cart_id'], array_column($before['cart'], 'cart_id'), true)) usces_delete_ordercartdata($line['cart_id']);
            }
        }
    }

    public function test_confirmed_server_quote_overwrites_client_money_and_appends_history(): void
    {
        global $wpdb;
        require_once __DIR__ . '/fixtures/HttpClient.php';
        $id = (int)get_option('wtd_test_saved_order_id');
        $before = wtd_order_record($id);
        $http = new WtdTestHttp();
        $http->login(getenv('WTD_TEST_ADMIN_USER'), getenv('WTD_TEST_ADMIN_PASSWORD'));
        $list = $http->request('/wp-admin/admin.php?page=usces_orderlist');
        preg_match('/order_action=edit[^"\s]*?wc_nonce=([a-z0-9]+)/', $list['body'], $nonce);
        $page = $http->request('/wp-admin/admin.php?page=usces_orderlist&order_action=edit&order_id=' . $id . '&wc_nonce=' . $nonce[1]);
        $fields = WtdTestHttp::formFields($page['body'], 'order_editpost_form');
        $quantity = (float)$before['cart'][0]['quantity'] === 3.0 ? '1' : '3';
        $fields['quant[' . $before['cart'][0]['cart_id'] . ']'] = $quantity;
        $ajax = $http->request('/wp-admin/admin-ajax.php', array_merge($fields, ['action'=>'wtd_preview_order','order_id'=>$id]));
        $this->assertSame(200, $ajax['status'], $ajax['body']);
        $preview = json_decode($ajax['body'], true)['data'];
        $this->assertSame($quantity === '1' ? '500' : '2000', $preview['quote']['discount']);
        $fields['wtd_preview'] = $preview['token'];
        $fields['offer[discount]'] = '-123456';
        $fields['offer[tax]'] = '123456';
        $fields['update_order_edit'] = 'update';
        try {
            $saved = $http->request('/wp-admin/admin.php?page=usces_orderlist&order_action=editpost&order_id=' . $id, $fields);
            $this->assertSame(200, $saved['status']);
            $after = wtd_order_record($id);
            $this->assertSame($quantity === '1' ? '-500.00' : '-2000.00', $after['order']['order_discount']);
            $this->assertSame('0.00', $after['order']['order_tax']);
            $this->assertSame($before['meta']['wtd_discount_snapshot'], $after['meta']['wtd_discount_snapshot']);
            $history = json_decode($after['meta']['wtd_discount_history'], true);
            $this->assertCount(count(json_decode($before['meta']['wtd_discount_history'] ?: '[]', true)) + 1, $history);
        } finally {
            if (!isset($history)) {
                $wpdb->update($wpdb->prefix . 'usces_order', $before['order'], ['ID'=>$id]);
                foreach ($before['cart'] as $line) $wpdb->update($wpdb->prefix . 'usces_ordercart', ['price'=>$line['price'],'quantity'=>$line['quantity']], ['cart_id'=>$line['cart_id']]);
            }
        }
    }
}
