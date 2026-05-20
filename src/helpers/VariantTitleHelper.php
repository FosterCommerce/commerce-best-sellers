<?php

namespace fostercommerce\bestsellers\helpers;

abstract class VariantTitleHelper
{
	/**
	 * Build a display title for a variant. Single-variant products (Commerce
	 * auto-creates one variant with the same title as its product) would
	 * otherwise read "Foo: Foo"; collapse that to just "Foo".
	 */
	public static function buildDisplayTitle(string $productTitle, ?string $variantTitle): string
	{
		if ($variantTitle === null || $variantTitle === '' || $variantTitle === $productTitle) {
			return $productTitle;
		}

		return $productTitle . ': ' . $variantTitle;
	}
}
