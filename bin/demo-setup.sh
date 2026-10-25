#!/usr/bin/env bash
# Starts the local demo: WordPress + WooCommerce + this plugin + demo products.
# Development helper only; not part of the plugin package.
set -euo pipefail

cd "$(dirname "$0")/.."

PORT="${WSPS_PORT:-8088}"
compose() { docker compose -p wsps-demo "$@"; }
wp() { compose run --rm -T cli wp "$@"; }

compose up -d db wordpress feeds

echo "Waiting for WordPress files..."
for _ in $(seq 1 60); do
	if compose exec -T wordpress test -f /var/www/html/wp-config.php 2>/dev/null; then
		break
	fi
	sleep 2
done

if ! wp core is-installed 2>/dev/null; then
	wp core install \
		--url="http://localhost:${PORT}" \
		--title="Pet Supplies Demo Shop" \
		--admin_user=admin \
		--admin_password=admin \
		--admin_email=admin@example.com \
		--skip-email
fi

wp plugin install woocommerce --activate
wp plugin activate stock-price-sync-for-woocommerce
wp rewrite structure '/%postname%/'
wp eval-file wp-content/plugins/stock-price-sync-for-woocommerce/bin/demo-seed.php

echo
echo "Ready: http://localhost:${PORT}/wp-admin  (admin / admin)"
echo "Feed for the settings screen: http://feeds.supplier.test/v1/supplier-feed.csv"
echo "  header X-Api-Key: demo-key-2026, delimiter ';', decimal comma, columns sku / qty / price / sale_price"
