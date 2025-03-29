<?php
namespace fostercommerce\bestsellers\utilities;

use Craft;
use craft\base\Utility;

class BackfillUtility extends Utility
{
	public static function displayName(): string
	{
		return 'Backfill Orders';
	}

	public static function id(): string
	{
		return 'backfill-orders';
	}

	public static function contentHtml(): string
	{
		return Craft::$app->view->renderTemplate('best-sellers/_utilities/backfill',[
			'title' => 'Backfill Orders',
		]);
	}
}
