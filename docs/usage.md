# Developer Documentation

## Twig Variables

Best Sellers registers a `craft.bestsellers` variable with the following methods. All date parameters accept `YYYY-MM-DD` strings or human-readable dates like `"2 weeks ago"`.

### Product Sales

```twig
{# Total units sold (all time) #}
{{ craft.bestsellers.productTotalSales(product.id) }}

{# Total units sold (last 2 weeks) #}
{{ craft.bestsellers.productTotalSales(product.id, '2 weeks ago') }}

{# Total units sold (specific date range) #}
{{ craft.bestsellers.productTotalSales(product.id, '2024-01-01', '2024-03-01') }}

{# Total revenue (all time) #}
{{ craft.bestsellers.productTotalRevenue(product.id) }}

{# Total revenue (last 30 days) #}
{{ craft.bestsellers.productTotalRevenue(product.id, '30 days ago') }}
```

### Variant Sales

```twig
{# Total units sold (all time) #}
{{ craft.bestsellers.variantTotalSales(variant.id) }}

{# Total units sold (specific date range) #}
{{ craft.bestsellers.variantTotalSales(variant.id, '2024-01-01', '2024-03-01') }}

{# Total revenue (all time) #}
{{ craft.bestsellers.variantTotalRevenue(variant.id) }}

{# Total revenue (last 90 days) #}
{{ craft.bestsellers.variantTotalRevenue(variant.id, '90 days ago') }}
```

### Previous Purchases

```twig
{# Check if the current user has previously purchased a specific product/variant #}
{% set previousOrder = craft.bestsellers.previousPurchaseByUser(variant.id, currentUser) %}
{% if previousOrder %}
    You purchased this on {{ previousOrder.dateOrdered|date('M j, Y') }}
{% endif %}
```

#### Buy Again Page

`previouslyPurchasedProducts()` returns a variant query ordered by most recent purchase, making it easy to build a "Buy Again" page with pagination:

```twig
{% if currentUser %}
    {% set previousPurchases = craft.bestsellers.previouslyPurchasedProducts(currentUser) %}

    {% if previousPurchases %}
        {% paginate previousPurchases.limit(12) as pageInfo, variants %}

        {% if variants|length %}
            <div class="product-grid">
                {% for variant in variants %}
                    {% include '_includes/product-card' with {
                        title: variant.product.title,
                        sku: variant.sku,
                        purchaseableId: variant.id,
                        url: variant.product.url,
                        image: variant.product.featuredImage[0] ?? null,
                        price: variant.getPrice(),
                        salePrice: variant.getSalePrice(),
                        addToCart: true,
                    } %}
                {% endfor %}
            </div>

            {% include '_includes/pagination' %}
        {% endif %}
    {% endif %}
{% endif %}
```

## PHP

```php
use fostercommerce\bestsellers\variables\BestSellersVariable;

$bestSellers = new BestSellersVariable();

// Product sales
$productSales = $bestSellers->productTotalSales($product->id);
$productSales = $bestSellers->productTotalSales($product->id, '2 months ago');
$productRevenue = $bestSellers->productTotalRevenue($product->id);

// Variant sales
$variantSales = $bestSellers->variantTotalSales($variant->id);
$variantSales = $bestSellers->variantTotalSales($variant->id, '2024-01-01', '2024-03-01');
$variantRevenue = $bestSellers->variantTotalRevenue($variant->id);

// Previous purchases
$previousOrder = $bestSellers->previousPurchaseByUser($purchasableId, $user);
$purchasedVariants = $bestSellers->previouslyPurchasedProducts($user);
```

## Element Query Behavior

Best Sellers adds a `bestSellers()` behavior to `ProductQuery` and `VariantQuery`. This lets you sort and filter products by sales data using Craft's standard element query API.

### Twig

```twig
{# Top selling products (all time) #}
{% set bestSellers = craft.commerce.products
    .bestSellers('all')
    .limit(10)
    .all() %}

{# Top selling products in a date range #}
{% set bestSellers = craft.commerce.products
    .bestSellers('30 days ago')
    .limit(10)
    .all() %}

{# Top selling variants #}
{% set bestVariants = craft.commerce.variants
    .bestSellers('2024-01-01', '2024-12-31')
    .limit(10)
    .all() %}

{# Access sales data on the element #}
{% for product in bestSellers %}
    {{ product.title }}: {{ product.totalQtySold }} sold, {{ product.totalRevenue|currency }}
{% endfor %}
```

### PHP

```php
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;

// Top products by total quantity sold
$products = Product::find()
    ->bestSellers('30 days ago')
    ->limit(10)
    ->all();

// Top variants in a specific date range
$variants = Variant::find()
    ->bestSellers('2024-01-01', '2024-06-30')
    ->limit(20)
    ->all();

foreach ($products as $product) {
    echo $product->title . ': ' . $product->totalQtySold . ' units';
}
```

## Console Commands

### Backfill Orders

Process existing completed orders that were placed before the plugin was installed:

```bash
./craft best-sellers/backfill
```

To limit to a specific date range:

```bash
./craft best-sellers/backfill --start-date=2025-01-01 --end-date=2025-12-31
```

Orders are queued in batches of 25. Progress is visible in the Craft queue.

To clear existing variant sales and reprocess from scratch:

```bash
./craft best-sellers/backfill/refresh-orders
./craft best-sellers/backfill/refresh-orders --start-date=2025-01-01 --end-date=2025-12-31
```

### Rebuild Daily Stats

Rebuild the pre-aggregated daily stats table from order data:

```bash
./craft best-sellers/backfill/daily-stats
```

This is useful after running the backfill, or if stats get out of sync. The command automatically detects the date range from your completed orders.

To rebuild stats for a single date (e.g. if one day looks off):

```bash
./craft best-sellers/backfill/daily-stats --date=2026-03-15
```

To clear and rebuild the entire daily stats table:

```bash
./craft best-sellers/backfill/refresh-daily-stats
./craft best-sellers/backfill/refresh-daily-stats --date=2026-03-15
```

### Clearing Data

Clear individual tables without reprocessing:

```bash
./craft best-sellers/backfill/clear-orders
./craft best-sellers/backfill/clear-daily-stats
./craft best-sellers/backfill/clear-logs
```

All commands are also available from the **Utilities > Best Sellers** page in the control panel, where you can optionally specify a date range for the backfill.

## Events

Best Sellers provides three events for extending the reporting UI.

### RegisterReportPagesEvent

Add custom pages to the report navigation.

```php
use fostercommerce\bestsellers\events\RegisterReportPagesEvent;

Event::on(
    Plugin::class,
    'registerReportPages',
    function (RegisterReportPagesEvent $event) {
        $event->pages[] = [
            'label' => 'My Custom Report',
            'url' => 'best-sellers/my-report',
        ];
    }
);
```

### RegisterKpiCardsEvent

Add custom KPI cards to any report page.

```php
use fostercommerce\bestsellers\events\RegisterKpiCardsEvent;

Event::on(
    Plugin::class,
    'registerKpiCards',
    function (RegisterKpiCardsEvent $event) {
        if ($event->page === 'overview') {
            $event->cards[] = [
                'label' => 'Custom Metric',
                'value' => 42,
                'change' => 12.5,
            ];
        }
    }
);
```

### ModifyReportDataEvent

Modify report data before it renders.

```php
use fostercommerce\bestsellers\events\ModifyReportDataEvent;

Event::on(
    Plugin::class,
    'modifyReportData',
    function (ModifyReportDataEvent $event) {
        // $event->page - which report page
        // $event->data - the data array (modify in place)
    }
);
```
