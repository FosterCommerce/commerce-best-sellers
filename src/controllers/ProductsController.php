<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\db\Query;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\Plugin;
use fostercommerce\bestsellers\records\VariantSale;
use yii\web\Response;

class ProductsController extends BaseReportController
{
	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveDateRange();

		$productsOrVariants = $request->getQueryParam('productsOrVariants', 'products');
		$sortBy = $request->getQueryParam('sortBy', 'revenue');
		$productType = $request->getQueryParam('productType', 'all');

		$productStats = Plugin::getInstance()->productStats;

		if ($productsOrVariants === 'variants') {
			$items = $productStats->getTopVariants($dateRange['fromDT'], $dateRange['toDT'], $sortBy, 100, $productType);
		} else {
			$items = $productStats->getTopProducts($dateRange['fromDT'], $dateRange['toDT'], $sortBy, 100, $productType);
		}

		// Batch-load product elements for CP/front-end URLs
		$productIds = array_unique(array_column($items, 'productId'));
		$productElements = [];
		if (! empty($productIds)) {
			$products = Product::find()
				->id($productIds)
				->status(null)
				->all();
			foreach ($products as $product) {
				$productElements[$product->id] = $product;
			}
		}

		// Summary stats
		$totalUnitsSold = array_sum(array_column($items, 'unitsSold'));
		$totalRevenue = array_sum(array_map('floatval', array_column($items, 'revenue')));
		$uniqueProductCount = count($items);

		return $this->renderTemplate('best-sellers/_products', [
			'title' => 'Products',
			'selectedSubnavItem' => 'products',
			'from' => $dateRange['from'],
			'to' => $dateRange['to'],
			'preset' => $dateRange['preset'],
			'productsOrVariants' => $productsOrVariants,
			'sortBy' => $sortBy,
			'productType' => $productType,
			'items' => $items,
			'productElements' => $productElements,
			'totalUnitsSold' => $totalUnitsSold,
			'totalRevenue' => $totalRevenue,
			'uniqueProductCount' => $uniqueProductCount,
		]);
	}

	/**
	 * Show orders containing a specific product or variant.
	 */
	public function actionOrders(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveDateRange();

		$productId = (int) $request->getQueryParam('productId', 0);
		$variantId = (int) $request->getQueryParam('variantId', 0);

		if (! $productId && ! $variantId) {
			throw new \yii\web\BadRequestHttpException('productId or variantId is required.');
		}

		// Get order IDs from variant_sales
		$query = (new Query())
			->select(['vs.[[orderId]]', 'vs.[[qty]]', 'vs.[[lineItemTotal]]', 'vs.[[variantSku]]'])
			->from(['vs' => VariantSale::tableName()])
			->where(['>=', 'vs.[[dateOrdered]]', $dateRange['fromDT']])
			->andWhere(['<=', 'vs.[[dateOrdered]]', $dateRange['toDT']]);

		if ($variantId) {
			$query->andWhere(['vs.[[variantId]]' => $variantId]);
		} else {
			$query->andWhere(['vs.[[productId]]' => $productId]);
		}

		$salesRows = $query->orderBy(['vs.[[dateOrdered]]' => SORT_DESC])->all();

		$orderIds = array_unique(array_column($salesRows, 'orderId'));

		// Build a lookup of line item info per order
		$lineItemsByOrder = [];
		foreach ($salesRows as $row) {
			$lineItemsByOrder[$row['orderId']][] = $row;
		}

		// Load order elements
		$orders = [];
		if (! empty($orderIds)) {
			$orderElements = Order::find()
				->id($orderIds)
				->isCompleted(true)
				->orderBy(['dateOrdered' => SORT_DESC])
				->all();

			foreach ($orderElements as $order) {
				$lineInfo = $lineItemsByOrder[$order->id] ?? [];
				$totalQty = array_sum(array_column($lineInfo, 'qty'));
				$totalRevenue = array_sum(array_column($lineInfo, 'lineItemTotal'));

				$orders[] = [
					'order' => $order,
					'qty' => $totalQty,
					'revenue' => $totalRevenue,
				];
			}
		}

		// Get product/variant title for the heading
		$titleRow = (new Query())
			->select(['vs.[[productTitle]]', 'vs.[[variantTitle]]'])
			->from(['vs' => VariantSale::tableName()])
			->where($variantId ? ['vs.[[variantId]]' => $variantId] : ['vs.[[productId]]' => $productId])
			->limit(1)
			->one();

		$itemTitle = $titleRow
			? ($variantId ? ($titleRow['productTitle'] . ': ' . $titleRow['variantTitle']) : $titleRow['productTitle'])
			: 'Unknown';

		return $this->renderTemplate('best-sellers/_product-orders', [
			'title' => $itemTitle,
			'selectedSubnavItem' => 'products',
			'from' => $dateRange['from'],
			'to' => $dateRange['to'],
			'preset' => $dateRange['preset'],
			'orders' => $orders,
			'itemTitle' => $itemTitle,
			'productId' => $productId,
			'variantId' => $variantId,
		]);
	}
}
