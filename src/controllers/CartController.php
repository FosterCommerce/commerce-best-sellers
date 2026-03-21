<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\Request;
use craft\web\View;
use yii\web\HttpException;
use yii\web\Response;

/**
 * Front-end controller for restoring abandoned carts.
 *
 * Generates a shareable URL that restores a cart to the visitor's session
 * and redirects to the configured cart page.
 */
class CartController extends Controller
{
	protected array|int|bool $allowAnonymous = true;

	/**
	 * Restore an abandoned cart by its order number.
	 *
	 * Usage: /actions/best-sellers/cart/restore?number={orderNumber}
	 *
	 * @throws HttpException
	 */
	public function actionRestore(): Response
	{
		/** @var Request $request */
		$request = Craft::$app->getRequest();
		/** @var string $number */
		$number = $request->getQueryParam('number', '');

		if ($number === '') {
			throw new HttpException(400, Craft::t('best-sellers', 'Cart number is required.'));
		}

		$commerce = Commerce::getInstance();
		if (! $commerce) {
			throw new HttpException(500, Craft::t('best-sellers', 'Commerce plugin is not available.'));
		}

		$order = $commerce->getOrders()->getOrderByNumber($number);

		if (! $order) {
			throw new HttpException(404, Craft::t('best-sellers', 'Cart not found.'));
		}

		if ($order->isCompleted) {
			throw new HttpException(400, Craft::t('best-sellers', 'This order has already been completed.'));
		}

		// Check if cart belongs to a credentialed user
		$currentUser = Craft::$app->getUser()->getIdentity();
		$currentUserId = $currentUser?->id;
		$cartCustomer = $order->getCustomer();

		if ($cartCustomer && $cartCustomer->getIsCredentialed() && (! $currentUserId || $order->customerId !== $currentUserId)) {
			/** @var string $loginPath */
			$loginPath = Craft::$app->getConfig()->getGeneral()->getLoginPath();
			$loginUrl = UrlHelper::url($loginPath, [
				'return' => $request->getAbsoluteUrl(),
			]);

			// Render login-required page
			$view = Craft::$app->getView();
			$oldMode = $view->getTemplateMode();
			$view->setTemplateMode(View::TEMPLATE_MODE_CP);
			$html = $view->renderTemplate('best-sellers/_cart/login-required', [
				'loginUrl' => $loginUrl,
				'message' => Craft::t('best-sellers', 'This cart belongs to a user account. Please log in to continue.'),
			]);
			$view->setTemplateMode($oldMode);

			return $this->asRaw($html);
		}

		// Restore the cart
		$cartsService = $commerce->getCarts();
		$cartsService->forgetCart();

		$orderNumber = $order->number;
		if ($orderNumber !== null) {
			$cartsService->setSessionCartNumber($orderNumber);
		}

		Craft::$app->getSession()->setNotice(Craft::t('best-sellers', 'Your cart has been restored.'));

		// Redirect to Commerce's configured load cart redirect URL, or site root
		$cartUrl = $commerce->getSettings()->loadCartRedirectUrl ?? '/';

		return $this->redirect($cartUrl);
	}
}
