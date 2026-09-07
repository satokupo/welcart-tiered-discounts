<?php
/**
 * PHPUnit bootstrap for the development toolchain.
 *
 * @package Welcart_Tiered_Discounts
 */

declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (is_readable($autoload)) {
	require_once $autoload;
}

if ('1' === getenv('WTD_TEST_MODE')) {
    $_SERVER['HTTP_HOST'] = '127.0.0.1:' . (getenv('WTD_TEST_PORT') ?: '8080');
    $_SERVER['SERVER_NAME'] = '127.0.0.1';
    $_SERVER['SERVER_PORT'] = getenv('WTD_TEST_PORT') ?: '8080';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $_SERVER['HTTP_REFERER'] = 'http://' . $_SERVER['HTTP_HOST'] . '/';
    define('WP_USE_THEMES', false);
    define('DISABLE_WP_CRON', true);
    require '/var/www/html/wp-load.php';
    // A native wp_die exits with zero; make an unexpected stop fail PHPUnit.
    add_filter('wp_die_handler', static function () {
        return static function ($message) { throw new RuntimeException(wp_strip_all_tags(is_wp_error($message) ? $message->get_error_message() : (string)$message)); };
    });
} else {
    if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');
    // Integration loads these through WordPress at a different mount path.
    require_once dirname(__DIR__) . '/plugin/includes/discount.php';
    require_once dirname(__DIR__) . '/plugin/includes/settings.php';
}
