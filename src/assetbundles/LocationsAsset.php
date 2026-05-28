<?php

namespace fostercommerce\bestsellers\assetbundles;

use craft\web\AssetBundle;

class LocationsAsset extends AssetBundle
{
	public function init(): void
	{
		$this->sourcePath = __DIR__ . '/dist';

		$this->depends = [
			ReportsAsset::class,
		];

		$this->js = [
			'js/topojson-client.umd.min.js',
			'js/d3.min.js',
			'js/locations.js',
		];

		parent::init();
	}
}
