# Best Sellers



## Requirements

This plugin requires Craft CMS 5.0.0 or later, and PHP 8.2 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “Best Sellers”. Then press “Install”.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require fostercommerce/commerce-best-sellers

# tell Craft to install the plugin
./craft plugin/install best-sellers
```


## Usage

#### Twig
```
# product's total sales (all time)
{{ craft.bestsellers.productTotalSales(product.id) }}

# product's total sales (last 2 weeks)
{{ craft.bestsellers.productTotalSales(product.id, '2 weeks ago') }}

# variant's total sales (all time)
{{ craft.bestsellers.variantTotalSales(product.defaultVariant.id) }}

# variant's total sales (specific date range)
{{ craft.bestsellers.variantTotalSales(product.defaultVariant.id, '2023-01-01', '2023-03-01') }}

```

#### PHP
```php
use fostercommerce\bestsellers\variables\BestSellersVariable;
$bestSellers = new BestSellersVariable();
// product's total sales (all time)
$productSales = $bestSellers->productTotalSales($product->id);
// product's total sales (last 2 months)
$productSales = $bestSellers->productTotalSales($product->id, '2 months ago');
// variant's total sales (all time)
$variantSales = $bestSellers->variantTotalSales($product->defaultVariant->id);
// variant's total sales (specific date range)
$variantSales = $bestSellers->variantTotalSales($product->defaultVariant->id, '2023-01-01', '2023-03-01');
```

#### Console Command to process existing orders
```
php craft best-sellers/backfill
```
