<?php

namespace fostercommerce\bestsellers\services;

use Craft;
use craft\base\FieldInterface;
use craft\commerce\models\ProductType;
use craft\commerce\Plugin as Commerce;
use craft\fieldlayoutelements\CustomField;
use craft\fields\BaseRelationField;
use craft\fields\Checkboxes;
use craft\fields\Dropdown;
use craft\fields\Lightswitch;
use craft\fields\Money;
use craft\fields\MultiSelect;
use craft\fields\RadioButtons;
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

	/**
	 * Field types the Products report can filter by.
	 */
	public const FILTER_FIELD_TYPES = [
		BaseRelationField::class,
		Dropdown::class,
		RadioButtons::class,
		Checkboxes::class,
		MultiSelect::class,
		Lightswitch::class,
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
		return $this->isAnyType($layoutElement->getField(), self::UNIT_COST_FIELD_TYPES) ? $layoutElement->getField() : null;
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
		$layout = $productType->getVariantFieldLayout();
		$filterFields = [];
		foreach ($plugin->getSettings()->filterFields[$productType->uid] ?? [] as $instanceUid) {
			$layoutElement = $layout->getElementByUid($instanceUid);
			// The field's type can change after the setting is saved
			if ($layoutElement instanceof CustomField && $this->isAnyType($layoutElement->getField(), self::FILTER_FIELD_TYPES)) {
				$filterFields[$instanceUid] = $layoutElement->getField();
			}
		}

		return $filterFields;
	}

	/**
	 * Get a product type's variant field instances of the given types, as label by layout element UID.
	 *
	 * @param list<class-string> $fieldTypes
	 * @return array<string, string>
	 */
	public function getInstanceOptions(ProductType $productType, array $fieldTypes): array
	{
		$options = [];
		foreach ($productType->getVariantFieldLayout()->getCustomFieldElements() as $customField) {
			$field = $customField->getField();
			if ($this->isAnyType($field, $fieldTypes)) {
				$options[(string) $customField->uid] = sprintf('%s (%s)', Craft::t('site', (string) $field->name), $field->handle);
			}
		}

		return $options;
	}

	/**
	 * @param list<class-string> $fieldTypes
	 */
	private function isAnyType(FieldInterface $field, array $fieldTypes): bool
	{
		foreach ($fieldTypes as $fieldType) {
			if ($field instanceof $fieldType) {
				return true;
			}
		}

		return false;
	}
}
