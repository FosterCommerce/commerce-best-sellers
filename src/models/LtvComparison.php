<?php

namespace fostercommerce\bestsellers\models;

use craft\base\Model;
use JsonSerializable;

class LtvComparison extends Model implements JsonSerializable
{
	/**
	 * @var LtvSegment|null Credentialed customer segment
	 */
	public ?LtvSegment $credentialed = null;

	/**
	 * @var LtvSegment|null Guest customer segment
	 */
	public ?LtvSegment $guest = null;

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array
	{
		return [
			'credentialed' => $this->credentialed,
			'guest' => $this->guest,
		];
	}
}
