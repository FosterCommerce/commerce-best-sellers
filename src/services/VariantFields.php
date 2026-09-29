<?php

namespace fostercommerce\bestsellers\services;

use craft\base\FieldInterface;
use craft\commerce\models\ProductType;
use craft\commerce\Plugin as Commerce;
use craft\fieldlayoutelements\CustomField;
use craft\fields\Money;
use fostercommerce\bestsellers\Plugin;
use yii\base\Component;

/**
 * Resolves the variant field instances chosen per product type in the plugin settings.
 */
class VariantFields extends Component
{
	public const UNIT_COST_FIELD_TYPES = [
		Money::class,
	];

	public function hasUnitCostField(): bool
	{
		return $this->getUnitCostProductTypes() !== [];
	}

	/**
	 * @return list<ProductType>
	 */
	public function getUnitCostProductTypes(): array
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		return array_values(array_filter(
			$commerce->getProductTypes()->getAllProductTypes(),
			fn (ProductType $productType): bool => $this->getUnitCostField($productType) instanceof FieldInterface,
		));
	}

	public function getUnitCostField(ProductType $productType): ?FieldInterface
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$instanceUid = $plugin->getSettings()->unitCostFields[$productType->uid] ?? null;
		if ($instanceUid === null) {
			return null;
		}

		$layoutElement = $productType->getVariantFieldLayout()->getElementByUid($instanceUid);
		if (! $layoutElement instanceof CustomField) {
			return null;
		}

		// The field's type can change after the setting is saved
		return ReportFields::isAnyType($layoutElement->getField(), self::UNIT_COST_FIELD_TYPES) ? $layoutElement->getField() : null;
	}

	/**
	 * Get the filter field instances chosen for a product type, by layout element UID.
	 *
	 * @return array<string, FieldInterface>
	 */
	public function getFilterFields(ProductType $productType): array
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		return $plugin->reportFields->getLayoutFields(
			$productType->getVariantFieldLayout(),
			$plugin->getSettings()->filterFields[$productType->uid] ?? [],
		);
	}
}
