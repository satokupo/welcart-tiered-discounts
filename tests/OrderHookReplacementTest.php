<?php

use PHPUnit\Framework\TestCase;

/** @group integration */
final class OrderHookReplacementTest extends TestCase
{
    /** Native JSON termination is contained here for observation, never in product code. */
    private function nativeJson(callable $calculate): array
    {
        $stop = new RuntimeException('expected-native-json');
        $handler = static function () use ($stop) { return static function () use ($stop) { throw $stop; }; };
        $ajax = static function () { return true; };
        $level = ob_get_level();
        add_filter('wp_die_ajax_handler', $handler);
        add_filter('wp_doing_ajax', $ajax);
        ob_start();
        try {
            try {
                $calculate();
                $this->fail('Native calculation must finish through wp_send_json.');
            } catch (RuntimeException $error) {
                if ($error !== $stop) throw $error;
            }
            $result = json_decode(ob_get_contents(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('ok', $result['status']);
            return $result;
        } finally {
            while (ob_get_level() > $level) ob_end_clean();
            remove_filter('wp_die_ajax_handler', $handler);
            remove_filter('wp_doing_ajax', $ajax);
        }
    }

    public function test_native_whole_order_paths_need_more_than_the_discount_filter(): void
    {
        global $usces, $wpdb;
        require_once __DIR__ . '/fixtures/setup.php';
        $fixture = wtd_test_setup();
        $order = (int)get_option('wtd_test_saved_order_id');
        $this->assertGreaterThan(0, $order, 'Run the native order seed first.');
        $options = $usces->options;
        $session = $_SESSION;
        $post = $_POST;
        $tax = Welcart_Tax::get_instance();
        $taxState = get_object_vars($tax);
        $discount = static function () { return -500; };
        $writes = [];
        $watch = static function ($sql) use (&$writes) {
            if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER)\b/i', $sql)) $writes[] = $sql;
            return $sql;
        };
        $wpdb->query('START TRANSACTION');
        try {
            $ids = [];
            foreach (['six'=>'wtd-six','reduced'=>'wtd-reduced'] as $key=>$sku) {
                $before = array_column(usces_get_ordercartdata($order), 'cart_id');
                $_POST = ['order_id'=>$order,'post_id'=>$fixture['products'][$key],'sku'=>$sku,'itemOption'=>[]];
                $this->assertTrue((bool)usces_add_ordercartdata());
                $new = array_values(array_diff(array_column(usces_get_ordercartdata($order), 'cart_id'), $before));
                $this->assertCount(1, $new);
                $ids[] = $new[0];
            }
            $usces->options['tax_mode'] = 'exclude';
            $usces->options['applicable_taxrate'] = 'reduced';
            $before = wtd_order_record($order);
            add_filter('usces_filter_order_discount_recalculation', $discount);
            add_filter('query', $watch, PHP_INT_MAX);
            $standard = $this->nativeJson(static function () use ($fixture) {
                usces_order_recalculation(0, 0, [$fixture['products']['standard']], [10000], [1], [], 0, 0, 0, 0, '');
            });
            $this->assertEquals(950, str_replace(',', '', $standard['tax']));
            $this->assertEquals(10450, str_replace(',', '', $standard['total_full_price']));
            $products = [$fixture['products']['six'], $fixture['products']['reduced']];
            $missingSplit = $this->nativeJson(static function () use ($products, $ids) {
                usces_order_recalculation_reduced(0, 0, $products, [6000,4000], [1,1], $ids, 0, 0, 0, 0, 0, '');
            });
            $this->assertEquals(-500, $missingSplit['discount']);
            $this->assertEquals(920, $missingSplit['tax']);
            $this->assertEquals(10420, $missingSplit['total_full_price']);
            $split = $this->nativeJson(static function () use ($products, $ids) {
                usces_order_recalculation_reduced(0, 0, $products, [6000,4000], [1,1], $ids, 0, 0, 0, -300, -200, '');
            });
            $this->assertEquals(874, $split['tax']);
            $this->assertEquals(10374, $split['total_full_price']);
            $this->assertEquals(570, $split['tax_standard']);
            $this->assertEquals(304, $split['tax_reduced']);
            $this->assertArrayNotHasKey('usedpoint', $split);
            $warnings = [];
            set_error_handler(static function ($severity, $message) use (&$warnings) { $warnings[] = $message; return true; });
            try {
                $draft = $this->nativeJson(static function () use ($products, $ids) {
                    usces_order_recalculation_reduced(0, 0, $products, [6000,4000], [1,1], [$ids[0],-1], 0, 0, 0, -300, -200, '');
                });
            } finally {
                restore_error_handler();
            }
            $nullOffset = PHP_VERSION_ID < 80000 ? 'Trying to access array offset on value of type null' : 'Trying to access array offset on null';
            $this->assertContains($nullOffset, $warnings, 'The native saved-cart lookup cannot resolve a draft row.');
            $this->assertEquals(0, $draft['subtotal_reduced']);
            $this->assertNotEquals(874, $draft['tax']);
            $this->assertSame($before, wtd_order_record($order));
            $this->assertSame([], $writes, 'Native comparison with unchanged tax rate must not write.');
        } finally {
            remove_filter('query', $watch, PHP_INT_MAX);
            remove_filter('usces_filter_order_discount_recalculation', $discount);
            $wpdb->query('ROLLBACK');
            $usces->options = $options;
            $_SESSION = $session;
            $_POST = $post;
            foreach ($taxState as $key=>$value) $tax->$key = $value;
        }
    }
}
