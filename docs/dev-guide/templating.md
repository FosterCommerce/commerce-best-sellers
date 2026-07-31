# Templating

Reading sales data from Twig and PHP. Audience: developers building front-end templates on a store running Best Sellers.

The units and item sales methods read the plugin's own tables, so they only cover orders it has recorded. On a fresh install, run the backfill first. See [data and backfill](../user-guide/data-and-backfill.md). The previous-purchase methods query Commerce directly and need no backfill.

## craft.bestsellers

Date arguments take a `YYYY-MM-DD` string or anything `strtotime` understands, such as `"2 weeks ago"`. Both are optional: neither means all time, a start alone means from then until now.

| Method | Returns |
| --- | --- |
| `productTotalSales(id, from, to)` | `int`. Units sold. |
| `variantTotalSales(id, from, to)` | `int`. Units sold. |
| `productTotalItemSalesNet(id, from, to)` | `float`. Item sales net of coupon and manual discounts. |
| `variantTotalItemSalesNet(id, from, to)` | `float`. Same, per variant. |
| `productTotalRevenue(id, from, to)` | `float`. Gross, discounts not subtracted. Deprecated since 1.6.0. |
| `variantTotalRevenue(id, from, to)` | `float`. Same, per variant. Deprecated since 1.6.0. |
| `previousPurchaseByUser(purchasableId, user)` | The user's most recent completed `Order` containing it, or `null`. |
| `previouslyPurchasedProducts(user)` | A `VariantQuery` of everything they have bought, most recent first, or `null`. |

```twig
{{ craft.bestsellers.variantTotalSales(variant.id, '30 days ago') }}
{{ craft.bestsellers.productTotalItemSalesNet(product.id)|commerceCurrency }}

{% set previousOrder = craft.bestsellers.previousPurchaseByUser(variant.id, currentUser) %}
{% if previousOrder %}
    You bought this on {{ previousOrder.dateOrdered|date('M j, Y') }}
{% endif %}
```

The `ItemSalesNet` methods use the same formula as the Item Sales (Net) column on the control panel's [Products](../user-guide/products.md) report: quantity times sale price, minus the coupon and manual discounts on those lines, with no tax or shipping. Switching off the deprecated `Revenue` methods will change the number on any store that uses coupons.

**The formula matches; the totals will not.** Every method here filters recorded sales by date alone. The Products report additionally drops orders with a full balance owed (authorized but never captured, fully refunded, or failed payment) and applies the order status filter. A fully refunded order counts in Twig and not in the control panel. Expect the front end to read higher.

`previouslyPurchasedProducts()` returns a query rather than results, so it takes `.limit()` and `{% paginate %}` for a "buy again" page.

## Element queries

`bestSellers(from, to)` on `ProductQuery` and `VariantQuery` joins each element to its recorded sales and attaches three properties to the elements returned:

| Property | Value |
| --- | --- |
| `totalQtySold` | Units sold in the window. |
| `totalItemSalesNet` | Item sales net of line-level discounts. |
| `totalRevenue` | Item sales before those discounts. Deprecated since 1.6.0. |

Dates take the same strings as above, and `null` for an open end.

**It attaches the data; it does not sort.** Add your own `orderBy`:

```twig
{% set bestSellers = craft.commerce.products
    .bestSellers('30 days ago')
    .orderBy('totalQtySold DESC')
    .limit(10)
    .all() %}

{% for product in bestSellers if product.totalQtySold %}
    {{ product.title }}: {{ product.totalQtySold }} sold,
    {{ product.totalItemSalesNet|commerceCurrency }}
{% endfor %}
```

The join keeps elements with no sales in the window, and their three properties come back `null` rather than zero, which is what the `if` above filters on.

The same call works on `Product::find()` and `Variant::find()` from PHP, as does `new BestSellersVariable()` for the methods in the table above.

## Bundles

Where the webdna Commerce Bundles plugin is installed, a bundle sale is recorded against its constituent variants. A bundle's own ID returns no sales from any of these methods; its children return the units and their allocated share of the revenue. See [products](../user-guide/products.md#bundles).
