<?php

namespace fostercommerce\bestsellers\services;

use Craft;
use craft\base\ElementInterface;
use craft\base\FieldInterface;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\db\ElementQueryInterface;
use craft\elements\ElementCollection;
use craft\fieldlayoutelements\CustomField;
use craft\fields\BaseOptionsField;
use craft\fields\BaseRelationField;
use craft\fields\Checkboxes;
use craft\fields\data\MultiOptionsFieldData;
use craft\fields\data\OptionData;
use craft\fields\data\SingleOptionFieldData;
use craft\fields\Dropdown;
use craft\fields\Lightswitch;
use craft\fields\MultiSelect;
use craft\fields\RadioButtons;
use craft\models\FieldLayout;
use fostercommerce\bestsellers\Plugin;
use yii\base\Component;

/**
 * Report fields service.
 */
class ReportFields extends Component
{
	public const FIELD_TYPES = [
		BaseRelationField::class,
		Dropdown::class,
		RadioButtons::class,
		Checkboxes::class,
		MultiSelect::class,
		Lightswitch::class,
	];

	/**
	 * @param list<class-string> $fieldTypes
	 */
	public static function isAnyType(FieldInterface $field, array $fieldTypes = self::FIELD_TYPES): bool
	{
		foreach ($fieldTypes as $fieldType) {
			if ($field instanceof $fieldType) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get a layout's custom field instances of the given types, as label by layout element UID.
	 *
	 * @param list<class-string> $fieldTypes
	 * @return array<string, string>
	 */
	public function getInstanceOptions(FieldLayout $layout, array $fieldTypes = self::FIELD_TYPES): array
	{
		$options = [];
		foreach ($layout->getCustomFieldElements() as $customField) {
			$field = $customField->getField();
			if (self::isAnyType($field, $fieldTypes)) {
				$options[(string) $customField->uid] = sprintf('%s (%s)', Craft::t('site', (string) $field->name), $field->handle);
			}
		}

		return $options;
	}

	/**
	 * Get the chosen field instances that still exist in the layout with a supported type, by layout element UID.
	 *
	 * @param list<string> $instanceUids
	 * @return array<string, FieldInterface>
	 */
	public function getLayoutFields(FieldLayout $layout, array $instanceUids): array
	{
		$fields = [];
		foreach ($instanceUids as $instanceUid) {
			$layoutElement = $layout->getElementByUid($instanceUid);
			// The field's type can change after the setting is saved
			if ($layoutElement instanceof CustomField && self::isAnyType($layoutElement->getField())) {
				$fields[$instanceUid] = $layoutElement->getField();
			}
		}

		return $fields;
	}

	public function getOrderLayout(): FieldLayout
	{
		/** @var FieldLayout $layout */
		$layout = Craft::$app->getFields()->getLayoutByType(Order::class);

		return $layout;
	}

	/**
	 * Get the order field instances chosen for the Orders report, by layout element UID.
	 *
	 * @return array<string, FieldInterface>
	 */
	public function getOrderFields(): array
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();

		return $this->getLayoutFields($this->getOrderLayout(), $plugin->getSettings()->orderFields);
	}

	/**
	 * Get the field's options, as label by value. Relation fields list the elements the source elements relate to.
	 *
	 * @return array<int|string, string>
	 */
	public function getOptions(FieldInterface $field, ElementQueryInterface $sourceQuery): array
	{
		if ($field instanceof Lightswitch) {
			return $this->getLightswitchOptions($field);
		}

		if ($field instanceof BaseOptionsField) {
			$options = [];
			foreach ($field->options as $option) {
				// Skip optgroup headings, which have no value
				if (isset($option['value']) && $option['value'] !== '') {
					$options[(string) $option['value']] = Craft::t('site', (string) $option['label']);
				}
			}

			return $options;
		}

		if ($field instanceof BaseRelationField) {
			return $this->getRelationOptions($field, $sourceQuery);
		}

		return [];
	}

	/**
	 * Build the element query param that matches any of the selected values, or null when the selection matches every element.
	 *
	 * @param list<string> $values
	 */
	public function getElementQueryParam(FieldInterface $field, array $values): mixed
	{
		if (! $field instanceof Lightswitch) {
			return $values;
		}

		return count(array_unique($values)) > 1 ? null : $values[0] === '1';
	}

	/**
	 * Get an element's value for the field as display text, joining several values with commas.
	 */
	public function getDisplayValue(ElementInterface $element, FieldInterface $field): string
	{
		$value = $element->getFieldValue((string) $field->handle);

		if ($field instanceof Lightswitch) {
			return $this->getLightswitchOptions($field)[$value ? '1' : '0'];
		}

		if ($value instanceof SingleOptionFieldData) {
			return $value->value === null ? '' : Craft::t('site', (string) $value->label);
		}

		if ($value instanceof MultiOptionsFieldData) {
			$labels = [];
			/** @var OptionData $option */
			foreach ($value as $option) {
				$labels[] = Craft::t('site', (string) $option->label);
			}

			return implode(', ', $labels);
		}

		if ($value instanceof ElementQueryInterface || $value instanceof ElementCollection) {
			/** @var list<ElementInterface> $relatedElements */
			$relatedElements = $value->all();
			$labels = [];
			foreach ($relatedElements as $relatedElement) {
				$labels[] = $relatedElement->getUiLabel();
			}

			return implode(', ', $labels);
		}

		return '';
	}

	/**
	 * @return array{1: string, 0: string}
	 */
	private function getLightswitchOptions(Lightswitch $field): array
	{
		return [
			'1' => ($field->onLabel ?? '') !== '' ? Craft::t('site', (string) $field->onLabel) : Craft::t('app', 'Enabled'),
			'0' => ($field->offLabel ?? '') !== '' ? Craft::t('site', (string) $field->offLabel) : Craft::t('app', 'Disabled'),
		];
	}

	/**
	 * @return array<int, string>
	 */
	private function getRelationOptions(BaseRelationField $field, ElementQueryInterface $sourceQuery): array
	{
		/** @var list<int|string> $targetIds */
		$targetIds = (new Query())
			->select('[[relations.targetId]]')
			->distinct()
			->from([
				'relations' => CraftTable::RELATIONS,
			])
			->where([
				'[[relations.fieldId]]' => $field->id,
			])
			->andWhere([
				'in',
				'[[relations.sourceId]]',
				(clone $sourceQuery)->select(['elements.id']),
			])
			->column();

		if ($targetIds === []) {
			return [];
		}

		/** @var list<ElementInterface> $targets */
		$targets = $field::elementType()::find()
			->id($targetIds)
			->site('*')
			->unique()
			->preferSites([Craft::$app->getSites()->getCurrentSite()->id])
			->status(null)
			->all();

		$options = [];
		foreach ($targets as $target) {
			$options[(int) $target->id] = $target->getUiLabel();
		}

		asort($options);

		return $options;
	}
}
