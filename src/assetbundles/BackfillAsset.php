<?php

namespace fostercommerce\bestsellers\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class BackfillAsset extends AssetBundle
{
	public function init(): void
	{
		$this->sourcePath = __DIR__ . '/dist';

		$this->depends = [
			CpAsset::class,
		];

		$this->css = [
			'css/backfill.css',
		];

		parent::init();
	}
}
