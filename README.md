![Screenshot](resources/images/header.png)

# Best Sellers for Craft Commerce

Sales analytics and reporting built for Craft Commerce. Track revenue, orders, products, customers, discounts, and carts, all from your control panel.

Best Sellers gives you a clear picture of what's selling, who's buying, and how your store is performing over any date range. No external services, no data leaving your site.

## Requirements

This plugin requires Craft CMS 5.0.0 or later, Craft Commerce 5.0.0 or later, and PHP 8.2 or later.

## Installation

To install the plugin, follow these instructions.

1. Open your terminal and go to your Craft project:

        cd /path/to/project

2. Then tell Composer to load the plugin:

        composer require fostercommerce/commerce-best-sellers

3. In the Control Panel, go to Settings > Plugins and click the "Install" button for Best Sellers.

After installing, backfill your existing order data:

```bash
./craft best-sellers/backfill
./craft best-sellers/backfill/daily-stats
```

This processes your completed orders and builds the aggregated stats. New orders are tracked automatically going forward.

You can also run the backfill from the control panel under **Utilities > Best Sellers**.

## Features

### Dashboard

The dashboard provides a full overview of store performance, organized into focused sections with narrative summaries that explain what the numbers mean.

**Overview**
- Narrative summary comparing revenue, orders, and AOV against the previous period, same period last year, and trailing 12-month average
- KPI cards for Revenue, Orders, AOV, and Repeat Rate with sparkline trends and period-over-period change
- Revenue/Orders/AOV toggle chart with previous period comparison overlay

**Discounts & Order Composition**
- KPI cards for Total Discounts, Items Sold, and Avg Items/Order
- Discounted vs. Full-Price Orders breakdown with order count, revenue, and AOV comparison
- Most Used Discounts ranked table with order counts linking to filtered orders view
- Items Per Order histogram with clickable bars linking to filtered orders
- Shipping Methods ranked table with order counts linking to filtered orders

**Customers & Retention**
- KPI cards for Customers, New Customers, and Avg Customer LTV
- Top Customers by Revenue ranked table
- Credentialed vs Guest comparison (customer count, LTV, avg orders, total revenue)
- New vs Returning Customers trend chart

**Product Performance**
- KPI cards for Unique Products Sold and Product Revenue
- Best Sellers top 10 ranked table with links to product edit pages

**Carts**
- Cart abandonment rate, abandoned value, and age breakdown (4-24h, 1-7d, 7+d)
- Highest-Value Abandoned Carts table with customer email, cart value, and age
- "View" links to Commerce order editor and "Share" links that copy a cart restore URL
- Anonymous cart toggle for filtering
- Cart restore action that restores the cart to the visitor's session and redirects to Commerce's configured cart URL

**Global Controls**
- Date range picker with presets: Today, This Week, This Month, This Year, Past 7/30/90 Days, Past Year, All Time, or custom dates
- Order Status filter that persists across all report pages via session
- Past date ranges exclude today (complete days only, matching Google Analytics behavior)
- Partial period comparisons are prorated for fair comparison (e.g., "This Year" compares Jan 1 through today against the same window last year)

### Orders

Browse and search every completed order with filtering and CSV export.

- Filters: Order Status, Payment Status, Shipping Method, Discount (discounted/full-price and specific discount names by ID)
- Items Per Order bucket filter (from dashboard chart clicks)
- Sortable columns: order number, date, status, merchandise total, tax, discount, shipping, total paid, items sold, payment status
- Page totals for all currency columns
- Cross-page filter links from dashboard widgets show a filter banner with "Clear filter" option
- CSV export with all applied filters

### Products

See which products (or variants) drive the most revenue, with breakdowns by product type.

- Toggle between products and variants
- Filter by product type
- Search by title, SKU, or product type
- Click through to see every order containing a specific product
- Sortable by units sold, order count, revenue, or average price
- CSV export

### Customers

Understand who your customers are, how much they spend, and how often they come back.

- Filter by customer type: credentialed or guest
- Search by email
- Sortable by email, status, order count, total spent, AOV, or last purchase date
- Links to customer profiles in the control panel
- CSV export

### Operations

Store configuration reference and all-time operational data. Lives outside the date range scope.

- Commerce Settings quick links (General Settings, Store Settings)
- Email Notifications: which emails fire on each order status transition, with enabled/disabled indicators and recipient types
- All Configured Emails table with name, subject, recipient, template path, and enabled status
- Coupon Usage (All Time) ranked table with usage counts and total discount per code

### Cart Restore

Built-in cart restoration for abandoned cart recovery. No third-party dependencies.

- Shareable URL
- Restores the cart to the visitor's session and redirects to Commerce's `loadCartRedirectUrl`
- Credentialed customer carts require the account owner to be logged in
- Logged-in users cannot claim another user's cart
- Logged-out users can restore inactive/guest carts
- Styled login-required page when authentication is needed
- Cart purge expiry info shown in the dashboard

## Templating

Best Sellers provides Twig variables for displaying sales data on your front end. Show bestseller badges, "X sold" counts, or sort products by popularity.

For full Twig and PHP usage examples, see the [Developer Documentation](docs/usage.md).

## Querying by Sales Data

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

## Console Commands

| Command | Description |
|---------|-------------|
| `./craft best-sellers/backfill` | Queue existing completed orders for processing (batches of 25) |
| `./craft best-sellers/backfill/daily-stats` | Rebuild the daily stats table from order data |

Both commands are also available from the **Utilities > Best Sellers** page in the control panel.

## Roadmap

- Goal settings (set revenue/order targets and track progress)
- Inventory reports (stock levels, low stock alerts, sell-through rates)
- Saved reports (save filter configurations for quick access)
- Extensible reports (API for plugins/modules to add custom report sections, KPI cards, and widgets)
- Subscription analytics (recurring revenue, churn, MRR tracking for Commerce subscriptions)
- Third-party purchasable support (Digital Products, Donations, and other plugin purchasable types)

## Credits

Brought to you by [Foster Commerce](https://fostercommerce.com)
