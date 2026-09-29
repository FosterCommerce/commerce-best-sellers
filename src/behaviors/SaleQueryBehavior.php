<?php

namespace fostercommerce\bestsellers\behaviors;

use craft\base\Element;
use craft\elements\db\ElementQuery;
use DateTime;
use fostercommerce\bestsellers\helpers\Query as QueryHelper;
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

		if ($from !== null) {
			$this->bestSellersFrom = QueryHelper::toDateBound($from, false);
		}

		if ($to !== null) {
			$this->bestSellersTo = QueryHelper::toDateBound($to, true);
		}

		/** @var ElementQuery<TKey, TElement> $variantQuery */
		$variantQuery = $this->owner;
		return $variantQuery;
	}
}
