<?php

namespace fostercommerce\bestsellers\behaviors;

use craft\base\Element;
use craft\elements\db\ElementQuery;
use DateTime;
use yii\base\Behavior;

/**
 * @template TKey of array-key
 * @template TElement of Element
 * @extends Behavior<\craft\base\Component>
 */
class SaleQueryBehavior extends Behavior
{
	public ?DateTime $bestSellersFrom = null;

	public ?DateTime $bestSellersTo = null;

	private bool $includeBestSellersData = false;

	public function getIncludeBestSellersData(): bool
	{
		return $this->includeBestSellersData;
	}

	/**
	 * @return ElementQuery<TKey, TElement>
	 */
	public function bestSellers(null|string|DateTime $from, null|string|DateTime $to = null): mixed
	{
		$this->includeBestSellersData = true;

		if (is_string($from)) {
			$this->bestSellersFrom = new DateTime($from);
		}

		if (is_string($to)) {
			$this->bestSellersTo = new DateTime($to);
		}

		/** @var ElementQuery<TKey, TElement> $variantQuery */
		$variantQuery = $this->owner;
		return $variantQuery;
	}
}
