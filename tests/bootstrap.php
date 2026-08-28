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
