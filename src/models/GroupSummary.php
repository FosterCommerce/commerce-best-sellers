<?php

namespace fostercommerce\bestsellers\models;

use craft\base\Model;

class GroupSummary extends Model
{
	/**
	 * @var list<string> Individual summary sentences (may contain HTML markup)
	 */
	public array $sentences = [];

	/**
	 * @var list<string> Contextual warning strings
	 */
	public array $warnings = [];
}
