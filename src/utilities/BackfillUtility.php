<?php

namespace fostercommerce\bestsellers\utilities;

use Craft;
use craft\base\Utility;
use craft\commerce\models\ProductType;
use craft\helpers\Html;
use fostercommerce\bestsellers\assetbundles\BackfillAsset;
use fostercommerce\bestsellers\Plugin;

class BackfillUtility extends Utility
{
	public static function displayName(): string
	{
		return Craft::t('best-sellers', 'nav.bestSellers');
	}

	public static function id(): string
	{
		return 'best-sellers';
	}

	public static function icon(): ?string
	{
		return dirname(__DIR__) . '/icon-mask.svg';
	}

	public static function requiresPermission(): ?string
	{
		return Plugin::PERMISSION_BACKFILL;
	}

	public static function contentHtml(): string
	{
		Craft::$app->view->registerAssetBundle(BackfillAsset::class);

		return Craft::$app->view->renderTemplate('best-sellers/_utilities/backfill', [
			'showUnitCostFill' => Plugin::getInstance()->variantFields->hasUnitCostField(),
			'unitCostProductTypeOptions' => array_map(static fn (ProductType $productType): array => [
				'label' => Html::encode((string) $productType->name),
				'value' => $productType->id,
			], Plugin::getInstance()->variantFields->getUnitCostProductTypes()),
		]);
	}
}
