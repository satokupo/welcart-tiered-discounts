<?php

use PHPUnit\Framework\TestCase;

/** @group integration */
final class OrderPreviewTest extends TestCase
{
    public function test_native_recalculation_scripts_only_bridge_snapshot_orders(): void
    {
        global $usces;
        $id = (int) get_option('wtd_test_saved_order_id');
        $this->assertGreaterThan(0, $id, 'Run the native order seed first.');
        foreach (['order_edit_form_recalculation', 'order_edit_form_recalculation_reduced'] as $hook) {
            $this->assertNotFalse(has_filter($hook, 'wtd_order_recalculation_script'));
            $this->assertSame('native-script', apply_filters($hook, 'native-script', []));
            $this->assertSame('native-script', apply_filters($hook, 'native-script', ['ID'=>0]));
            $script = apply_filters($hook, 'native-script', ['ID'=>$id]);
            $this->assertStringContainsString('wtdOrders.preview()', $script);
            $this->assertStringNotContainsString('native-script', $script);
        }
    }

    public function test_quantity_change_uses_order_time_tiers_and_rejects_foreign_cart_ids(): void
    {
        $this->assertTrue(function_exists('wtd_order_quote'));
        $snapshot = ['schema_version'=>1,'settings'=>['schema_version'=>1,'target'=>['mode'=>'all'],'tiers'=>[
            ['threshold'=>10000,'value'=>500,'type'=>'fixed','enabled'=>true],
            ['threshold'=>30000,'value'=>2000,'type'=>'fixed','enabled'=>true]
        ]],'condition'=>['tax_mode'=>'include','tax_target'=>'products','tax_rate'=>10,'tax_rate_reduced'=>8,'tax_method'=>'cutting','point_coverage'=>1,'applicable_taxrate'=>'standard'], 'original'=>['amounts'=>array_fill_keys(['total_items_price','discount','shipping_charge','cod_fee','tax','usedpoint','getpoint','total_full_price'], '0')], 'original_cart'=>[]];
        $record = ['order'=>['ID'=>10,'mem_id'=>0], 'cart'=>[['cart_id'=>1,'post_id'=>6,'sku_code'=>'wtd-standard','price'=>'10000','quantity'=>'3']], 'cart_meta'=>[1=>['taxrate'=>[],'option'=>[]]],'meta'=>['wtd_discount_snapshot'=>wp_json_encode($snapshot)]];
        $input = ['skuPrice'=>[1=>'10000'],'quant'=>[1=>'3'],'postId'=>[1=>'6'],'offer'=>['shipping_charge'=>'0','cod_fee'=>'0','usedpoint'=>'0']];
        $first = wtd_order_quote($record, $input);
        $this->assertSame('2000', $first['discount']);
        $input['quant'][1] = '1';
        $second = wtd_order_quote($record, $input);
        $this->assertSame('500', $second['discount']);
        $this->assertSame('863.00', $second['internal_tax']);
        $this->assertSame('9500.00', $second['total']);
        $input['skuPrice'][12345] = '1';
        $this->expectException(InvalidArgumentException::class);
        wtd_order_quote($record, $input);
    }

    public function test_preview_token_requires_the_exact_current_inputs_and_database_version(): void
    {
        $this->assertTrue(function_exists('wtd_preview_token'));
        $quote = ['lines'=>[['cart_id'=>1,'price'=>'10000','quantity'=>'3']], 'discount'=>'2000'];
        $token = wtd_preview_token(10, 'database-version-a', $quote);
        $this->assertSame($token, wtd_preview_token(10, 'database-version-a', $quote));
        $quote['lines'][0]['quantity'] = '1';
        $quote['discount'] = '500';
        $this->assertNotSame($token, wtd_preview_token(10, 'database-version-a', $quote));
        $this->assertNotSame($token, wtd_preview_token(10, 'database-version-b', $quote));
        $this->assertNotSame($token, wtd_preview_token(11, 'database-version-a', $quote));
    }

    public function test_removing_every_row_clears_earned_points(): void
    {
        $snapshot = ['schema_version'=>1,'settings'=>['schema_version'=>1,'target'=>['mode'=>'all'],'tiers'=>[]], 'condition'=>['tax_mode'=>'include','tax_target'=>'products','tax_rate'=>10,'tax_method'=>'cutting','point_coverage'=>1], 'original'=>['amounts'=>array_fill_keys(['total_items_price','discount','shipping_charge','cod_fee','tax','usedpoint','getpoint','total_full_price'], '0')], 'original_cart'=>[]];
        $record = ['order'=>['ID'=>10,'mem_id'=>1,'order_getpoint'=>42], 'cart'=>[['cart_id'=>1,'post_id'=>6,'sku_code'=>'wtd-standard','price'=>'10000','quantity'=>'1']], 'cart_meta'=>[1=>['taxrate'=>[],'option'=>[]]], 'meta'=>['wtd_discount_snapshot'=>wp_json_encode($snapshot)]];
        $quote = wtd_order_quote($record, ['wtd_removed'=>'[1]', 'offer'=>['shipping_charge'=>'0','cod_fee'=>'0','usedpoint'=>'0']]);
        $this->assertSame('0.00', $quote['total']);
        $this->assertSame(0, $quote['getpoint']);
    }

    public function test_corrupt_order_time_settings_are_rejected_instead_of_using_current_shop_tiers(): void
    {
        $this->assertTrue(function_exists('wtd_read_snapshot'));
        $this->expectException(InvalidArgumentException::class);
        wtd_read_snapshot('{"schema_version":1,"settings":{"tiers":[]}}');
    }

    public function test_a_missing_original_amount_cannot_be_treated_as_a_valid_snapshot(): void
    {
        $record = wtd_order_record((int)get_option('wtd_test_saved_order_id'));
        $snapshot = json_decode($record['meta']['wtd_discount_snapshot'], true);
        unset($snapshot['original']['amounts']['discount']);
        $this->expectException(InvalidArgumentException::class);
        wtd_read_snapshot(wp_json_encode($snapshot));
    }
}
