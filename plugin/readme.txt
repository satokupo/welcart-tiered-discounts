=== Welcart Tiered Discounts ===
Contributors: satokupo
Tags: welcart, discount, ecommerce
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tiered fixed or percentage discounts for Welcart, with order-time settings and confirmed administrator edits.

== Description ==

Selects the highest eligible enabled threshold using registered SKU prices and quantities across all products.
Supports fixed and percentage discounts, checkout reconfirmation, immutable order-time settings, and an edit history.
Does not modify WordPress, Welcart, or theme files. Requires Welcart 2.12.1.

== Installation ==

1. Activate Welcart.
2. Place this folder at wp-content/plugins/welcart-tiered-discounts and activate the plugin.
3. Open Settings > Step Discounts (ステップ割引) and save thresholds, types, and discount values.
4. Verify the cart, confirmation page, and saved order amounts.

== Frequently Asked Questions ==

= Do current settings change past orders? =
No. Administrator edits calculate from the complete order-time tier list and must be previewed before saving.

= Can I combine other discount plugins? =
Compatibility with other discounts is not guaranteed. Welcart's standard points flow is covered by integration tests.

== Changelog ==

= 1.0.0 =
* Tiered discounts, order-time settings, and confirmed order edits.
