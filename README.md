![Screenshot](resources/images/header.png)

# Best Sellers for Craft Commerce

Sales analytics and reporting built for Craft Commerce. Track revenue, orders, products, customers, discounts, and carts, all from your control panel.

Best Sellers gives you a clear picture of what's selling, who's buying, and how your store is performing over any date range.

## Requirements

Best Sellers requires Craft CMS 5.0.0, Craft Commerce 5.0.0 and PHP 8.2.

## Setup

### Installation

To install the plugin, search for “Best Sellers” in the Craft Plugin Store follow these instructions.

Or install via your terminal.

1. Open your terminal and go to your Craft project:

        cd /path/to/project

2. Then tell Composer to require the plugin, and Craft to install it:

        composer require fostercommerce/commerce-best-sellers && php craft install/plugin bestsellers

### Prep data

After installing, backfill your existing order data into Best Sellers database tables. These will create queue jobs. Run `best-sellers/backfill` first.

```bash
./craft best-sellers/backfill
./craft best-sellers/backfill/daily-stats
```

This processes your completed orders and builds the aggregated stats. New orders are tracked automatically going forward.

You can also run the backfill from the control panel under **Utilities > Best Sellers**.

## Features

### Dashboard

The dashboard provides a full overview of store performance for any date range, organized into focused sections with narrative summaries that explain what the numbers mean.

**Overview**
- Revenue, Orders, AOV, and Repeat Rate KPI cards
- Revenue/Orders/AOV toggle chart with previous period comparison
- Narrative summary comparing against previous period, same period last year, and trailing 12-month average

**Discounts & Order Composition**
- Total Discounts, Items Sold, and Avg Items/Order KPI cards
- Discounted vs. Full-Price Orders breakdown (order count, revenue, AOV)
- Most Used Discounts ranked table with order counts linking to filtered orders
- Items Per Order histogram with clickable bars linking to filtered orders
- Shipping Methods ranked table with order counts linking to filtered orders

**Customers & Retention**
- Customers, New Customers, and Avg Customer LTV KPI cards
- Top Customers by Revenue ranked table
- Credentialed vs Guest comparison (customer count, LTV, avg orders, total revenue)
- New vs Returning Customers trend chart

**Product Performance**
- Unique Products Sold and Product Revenue KPI cards
- Best Sellers top 10 ranked table with links to product edit pages

**Carts**
- Cart abandonment rate, abandoned value, and age breakdown (4-24h, 1-7d, 7+d)
- Highest-Value Abandoned Carts table with customer email, value, age, and cart restore links
- Anonymous cart toggle for filtering

**Global Controls**
- Date range picker with presets (Today, This Week, This Month, This Year, Past 7/30/90 Days, Past Year, All Time, custom)
- Order Status filter that persists across all report pages via session
- Past date ranges exclude today (complete days only)
- Partial period comparisons are prorated for fair comparison

### Orders

Browse and search every completed order with filtering.

- Filters: Order Status, Payment Status, Shipping Method, Discount (discounted/full-price and specific discounts by ID), Items Per Order bucket
- Sortable columns: order number, date, status, merchandise total, tax, discount, shipping, total paid, items sold, payment status
- Page totals for all currency columns
- Dashboard widgets link to pre-filtered views
- CSV export with all applied filters

### Products

See which products or variants are generating the most revenue, with breakdowns by product type.

- Toggle between viewing products or variants
- Filter by product type
- Search by title, SKU, or product type
- Drill down to every order containing a specific product
- Sortable by units sold, order count, revenue, or average price
- CSV export with all applied filters

### Customers

Understand who your customers are, how much they spend, and how often they come back.

- Filter by customer type (credentialed or guest)
- Search by email
- Sortable by email, status, order count, total spent, AOV, or last purchase date
- Links to customer profiles in the control panel
- CSV export with all applied filters

### Operations

Operational overview to help you understand store configuration, email notifications, and coupon usage across all time.

- Commerce Settings quick links (General Settings, Store Settings)
- Order Status Emails table showing which emails fire on each status transition, with enabled/disabled indicators and recipient types
- All Configured Emails table with name, subject, recipient, template path, and enabled status
- Coupon Usage (All Time) ranked table with usage counts and total discount per code

### Cart Restore

Built-in cart restoration for abandoned cart recovery.

- Shareable restore URL
- Restores the cart to the visitor's session and redirects to Commerce's `loadCartRedirectUrl`
- Credentialed customer carts require the account owner to be logged in
- Logged-in users cannot claim another user's cart
- Logged-out users can restore inactive/guest carts
- Styled login-required page when authentication is needed
- Cart purge expiry info shown in the dashboard based on Commerce's `purgeInactiveCartsDuration`

## Templating

Best Sellers provides Twig variables for displaying sales data on your front end.

### Units Sold & Revenue

```twig
{# Units sold for a variant #}
{{ craft.bestsellers.variantTotalSales(variant.id) }}
{{ craft.bestsellers.variantTotalSales(variant.id, '30 days ago') }}
{{ craft.bestsellers.variantTotalSales(variant.id, '2024-01-01', '2024-12-31') }}

{# Revenue for a variant #}
{{ craft.bestsellers.variantTotalRevenue(variant.id)|commerceCurrency }}

{# Units sold for a product (all variants combined) #}
{{ craft.bestsellers.productTotalSales(product.id) }}

{# Revenue for a product #}
{{ craft.bestsellers.productTotalRevenue(product.id)|commerceCurrency }}
```

### Previous Purchases

```twig
{# Check if the current user previously purchased a specific product #}
{% set previousOrder = craft.bestsellers.previousPurchaseByUser(variant.id, currentUser) %}
{% if previousOrder %}
    You purchased this on {{ previousOrder.dateOrdered|date('M j, Y') }}
    <a href="{{ previousOrder.cpEditUrl }}">Order #{{ previousOrder.reference }}</a>
{% endif %}

{# Get all variants previously purchased by a user #}
{% set purchasedVariants = craft.bestsellers.previouslyPurchasedProducts(currentUser) %}
{% if purchasedVariants %}
    <h3>Your Previously Purchased Products</h3>
    {% for variant in purchasedVariants.all() %}
        {{ variant.title }}
    {% endfor %}
{% endif %}
```

### Best Sellers Queries

Best Sellers extends Craft's element queries so you can fetch products or variants sorted by sales:

```twig
{# Top 10 best-selling products in the last 30 days #}
{% set bestSellers = craft.commerce.products
    .bestSellers('30 days ago')
    .limit(10)
    .all() %}
```

```php
use craft\commerce\elements\Product;

$bestSellers = Product::find()
    ->bestSellers('2024-01-01', '2024-12-31')
    ->limit(10)
    ->all();

foreach ($bestSellers as $product) {
    echo $product->title . ': ' . $product->totalQtySold;
}
```

For additional examples, see the [Developer Documentation](docs/usage.md).

## Console Commands

| Command | Description |
|---------|-------------|
| `./craft best-sellers/backfill` | Queue existing completed orders for processing (batches of 25) |
| `./craft best-sellers/backfill --start-date=2025-01-01 --end-date=2025-12-31` | Backfill orders within a specific date range |
| `./craft best-sellers/backfill --fresh` | Clear all recorded data and reprocess from scratch |
| `./craft best-sellers/backfill/daily-stats` | Rebuild the daily stats table from order data |
| `./craft best-sellers/backfill/daily-stats --date=2026-03-15` | Rebuild daily stats for a single date |
| `./craft best-sellers/backfill/daily-stats --fresh` | Truncate the daily stats table and rebuild |

Both commands are also available from the **Utilities > Best Sellers** page in the control panel.

## Roadmap

- Goal settings (revenue/order targets with progress tracking)
- Inventory reports (stock levels, low stock alerts, sell-through rates)
- Saved reports (save filter configurations for quick access)
- Extensible reports (API for plugins/modules to add custom report sections, KPI cards, and widgets)
- Subscription analytics (recurring revenue, churn, MRR for Commerce subscriptions)
- Third-party purchasable support (Digital Products, Donations, and other plugin purchasable types)

## Credits

Brought to you by [Foster Commerce](https://fostercommerce.com)
