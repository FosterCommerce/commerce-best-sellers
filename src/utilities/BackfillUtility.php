<?php

namespace fostercommerce\bestsellers\utilities;

use Craft;
use craft\base\Utility;
use fostercommerce\bestsellers\Plugin;

class BackfillUtility extends Utility
{
	public static function displayName(): string
	{
		return 'Best Sellers';
	}

	public static function id(): string
	{
		return 'best-sellers';
	}

	public static function icon(): ?string
	{
		return dirname(__DIR__) . '/icon-mask.svg';
	}

	public static function requiresPermission(): ?string
	{
		return Plugin::PERMISSION_BACKFILL;
	}

	public static function contentHtml(): string
	{
		return Craft::$app->view->renderTemplate('best-sellers/_utilities/backfill');
	}
}
