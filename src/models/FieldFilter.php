<?php

namespace fostercommerce\bestsellers\models;

use craft\base\FieldInterface;
use craft\base\Model;

class FieldFilter extends Model
{
	public int $productTypeId = 0;

	public FieldInterface $field;

	/**
	 * @var list<string>
	 */
	public array $values = [];
}
