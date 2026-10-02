# Templating

Reading sales data from Twig and PHP, for best-seller listings and buy-again pages.

The units and item sales methods read the plugin's sales data and cover only the orders the plugin has recorded. On a fresh install, run the backfill first. See [data and backfill](../user-guide/data-and-backfill.md). The previous-purchase methods query Commerce directly and need no backfill.

## Dates

Every method that takes dates accepts a `YYYY-MM-DD` string or any string PHP's `strtotime` accepts, such as `'30 days ago'`. Dates are read in the site's timezone:

- A `YYYY-MM-DD` start date begins at midnight, and a `YYYY-MM-DD` end date includes that whole day. The control panel reports read dates the same way.
- Other strings are exact moments. `'30 days ago'` starts at the current time of day, 30 days back.

Pass `null` as the start date for all time. The end date is optional, and a start date alone runs until now. From PHP, `bestSellers()` also takes a `DateTime`.

## Best-seller listings

Call `bestSellers(from, to)` on a product or variant query to attach sales figures to each element it returns:

```twig
{% set bestSellers = craft.products()
    .bestSellers('30 days ago')
    .orderBy('totalQtySold DESC')
    .limit(10)
    .all() %}

{% for product in bestSellers|filter(product => product.totalQtySold) %}
    {{ product.title }}: {{ product.totalQtySold }} sold,
    {{ product.totalItemSalesNet|commerceCurrency }}
{% endfor %}
```

| Property | Value |
| --- | --- |
| `totalQtySold` | Units sold in the date range. |
| `totalItemSalesNet` | Item sales net of Discount adjustments. |
| `totalRevenue` | Item sales before those discounts. Deprecated since 1.3.0. |

`bestSellers()` does not sort, so add your own `orderBy`. Elements with no sales in the range stay in the results, with all three properties at zero. The `filter` in the example drops them.

`craft.variants()` takes the same call. From PHP, use `Product::find()` or `Variant::find()`. See Craft's [element queries](https://craftcms.com/docs/5.x/development/element-queries.html).

## Totals for one product or variant

`craft.bestsellers` returns a single total:

```twig
{{ craft.bestsellers.variantTotalSales(variant.id, '30 days ago') }}
{{ craft.bestsellers.productTotalItemSalesNet(product.id)|commerceCurrency }}
```

| Method | Returns |
| --- | --- |
| `productTotalSales(id, from, to)` | `int`. Units sold. |
| `variantTotalSales(id, from, to)` | `int`. Units sold. |
| `productTotalItemSalesNet(id, from, to)` | `float`. Item sales net of Discount adjustments. |
| `variantTotalItemSalesNet(id, from, to)` | `float`. Same, per variant. |
| `productTotalRevenue(id, from, to)` | `float`. Gross, discounts not subtracted. Deprecated since 1.3.0. |
| `variantTotalRevenue(id, from, to)` | `float`. Same, per variant. Deprecated since 1.3.0. |

From PHP, create a `fostercommerce\bestsellers\variables\BestSellersVariable` and call the same methods.

The `ItemSalesNet` methods use the formula of the Item Sales (Net) column on the [Products](../user-guide/products.md) report: quantity times sale price, minus the Discount adjustments on those lines, with no tax or shipping. On a store that uses coupons, they return less than the deprecated `Revenue` methods.

**The formula matches the Products report; the totals do not.** These methods filter recorded sales by date alone. The Products report also drops orders with a full balance owed (authorized but never captured, fully refunded, or failed payment) and applies the order status filter. A fully refunded order counts in Twig and not in the control panel, so front-end totals can be higher than the control panel's.

## Buy-again pages

Both previous-purchase methods need a logged-in user, so wrap them in `{% if currentUser %}`:

```twig
{% if currentUser %}
    {% set previousOrder = craft.bestsellers.previousPurchaseByUser(variant.id, currentUser) %}
    {% if previousOrder %}
        You bought this on {{ previousOrder.dateOrdered|date('M j, Y') }}
    {% endif %}
{% endif %}
```

| Method | Returns |
| --- | --- |
| `previousPurchaseByUser(purchasableId, user)` | The user's most recent completed `Order` containing it, or `null`. |
| `previouslyPurchasedProducts(user)` | A `VariantQuery` of every variant the user has bought, most recent first, or `null`. |

`previouslyPurchasedProducts()` returns a query rather than results, and takes `.limit()` and `{% paginate %}`.

## Bundles

Where the webdna Commerce Bundles plugin is installed, a bundle sale is recorded against its child variants. These methods do not return sales for a bundle's own ID. Its children return their units and their share of the revenue. See [products](../user-guide/products.md#bundles).
