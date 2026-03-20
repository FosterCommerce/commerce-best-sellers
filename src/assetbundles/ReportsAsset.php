<?php

namespace fostercommerce\bestsellers\assetbundles;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

class ReportsAsset extends AssetBundle
{
	public function init(): void
	{
		$this->sourcePath = __DIR__ . '/dist';

		$this->depends = [
			CpAsset::class,
		];

		$this->css = [
			'css/reports.css',
		];

		$this->js = [
			'js/chart.umd.min.js',
			'js/chartjs-adapter-date-fns.bundle.min.js',
			'js/reports.js',
		];

		parent::init();
	}
}
