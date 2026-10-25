=== Stock & Price Sync for WooCommerce ===
Tags: woocommerce, stock, inventory, prices, sync
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WooCommerce stock and prices by SKU from a supplier feed or ERP export (CSV or JSON). Preview changes, schedule runs, keep a log.

== Description ==

Stock & Price Sync downloads a CSV or JSON feed from a URL, matches every row to a simple product or variation by SKU, and updates stock quantities, regular prices and sale prices that differ. Products that are not in the feed are never touched.

**Features**

* CSV (comma, semicolon, tab or pipe delimiter) or JSON feeds, with an optional auth header such as `X-Api-Key` or `Authorization`.
* Field mapping for SKU, stock, regular price and sale price, with dot notation for nested JSON keys (`price.regular`).
* Matches simple products and variations by SKU.
* Strict number validation: negative stock, placeholders like `N/A`, zero prices and numbers written with the wrong decimal separator are reported, never imported.
* Only changed fields are saved.
* Preview: see every planned change (SKU, product, field, current, new) before anything is saved, then apply exactly that plan. Products that changed after the preview are skipped and reported.
* Log of the last 20 runs with counts (updated, unchanged, not found, invalid, errors) and per-row details.
* Schedule: every 15 minutes, hourly, twice daily or daily (WP-Cron), plus a "Sync now" button.
* Dry-run mode.
* WP-CLI: `wp wsps sync [--dry-run]`.
* Atomic lock against overlapping runs, batch processing, HPOS compatible, translation-ready.
* Feeds are fetched with `wp_safe_remote_get()`; the saved API key is removed when the feed host changes.

**Rules for values**

* An empty stock or regular price leaves the field alone.
* An empty sale price (or JSON `null`) removes the sale price. Leave the sale price unmapped if you manage sales in WooCommerce.
* Stock must be a whole number from 0 upward. Setting stock enables stock tracking for the product.
* A sale price equal to the regular price means "no sale". A sale price higher than the regular price makes the row invalid.
* A price of 0 makes the row invalid (feeds often use 0 for "price on request"). Allow it with the `wsps_allow_zero_prices` filter.

== Installation ==

1. Upload the plugin zip under Plugins > Add New > Upload Plugin, or copy the `stock-price-sync-for-woocommerce` folder to `wp-content/plugins/`.
2. Activate it. WooCommerce must be active.
3. Go to WooCommerce > Stock Sync, enter the feed URL and the field mapping, and save.
4. Open "Preview & sync" and click "Preview changes" to check the result before applying it.

== Frequently Asked Questions ==

= Which products are updated? =

Simple products and variations whose SKU appears in the feed. A variable parent product has no stock or price of its own to set here; use the SKUs of its variations.

= What happens to products that are not in the feed? =

Nothing. The plugin never sets stock to zero for missing products.

= My feed is on an internal server. Why is it refused? =

Feeds are downloaded with `wp_safe_remote_get()`, which refuses loopback and private network addresses. Allow your host with the `http_request_host_is_external` filter, e.g. in a small must-use plugin.

= Can it run without the admin screen? =

Yes. Choose a schedule, or run `wp wsps sync` from a system cron job.

= Where is the auth header value stored? =

In the plugin's settings option in the database, like other WordPress API credentials. It is never printed back into the settings page.

= What is removed on uninstall? =

The settings, the scheduled event, preview transients and the log table.

== Screenshots ==

1. Settings: feed source, field mapping and schedule.
2. Preview of the planned changes and of the rows that will be skipped.
3. Log of recent runs with per-row details.
4. WooCommerce products after a sync.

== Changelog ==

= 1.0.0 =
* First release.
