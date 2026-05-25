<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\Request;
use craft\web\View;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

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
	 * @throws BadRequestHttpException
	 * @throws NotFoundHttpException
	 * @throws ServerErrorHttpException
	 */
	public function actionRestore(): Response
	{
		/** @var Request $request */
		$request = Craft::$app->getRequest();
		/** @var string $number */
		$number = $request->getQueryParam('number', '');

		if ($number === '') {
			throw new BadRequestHttpException(Craft::t('best-sellers', 'cart.error.numberRequired'));
		}

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		$order = $commerce->getOrders()->getOrderByNumber($number);

		if (! $order) {
			throw new NotFoundHttpException(Craft::t('best-sellers', 'cart.error.notFound'));
		}

		if ($order->isCompleted) {
			throw new BadRequestHttpException(Craft::t('best-sellers', 'cart.error.completed'));
		}

		$currentUser = Craft::$app->getUser()->getIdentity();
		$currentUserId = $currentUser?->id;
		$cartCustomerId = $order->customerId;
		$cartCustomer = $order->getCustomer();
		$isCredentialed = $cartCustomer && $cartCustomer->getIsCredentialed();

		$blocked = false;
		if ($currentUser && $cartCustomerId && $cartCustomerId !== $currentUserId) {
			// Logged in user trying to restore someone else's cart
			$blocked = true;
		} elseif (! $currentUser && $isCredentialed) {
			// Logged out user trying to restore a credentialed user's cart
			$blocked = true;
		}

		if ($blocked) {
			/** @var string $loginPath */
			$loginPath = Craft::$app->getConfig()->getGeneral()->getLoginPath();
			$loginUrl = UrlHelper::url($loginPath, [
				'return' => $request->getAbsoluteUrl(),
			]);

			$message = $currentUser
				? Craft::t('best-sellers', 'cart.error.belongsToOther')
				: Craft::t('best-sellers', 'cart.error.loginRequired');

			// Render login-required page
			$view = Craft::$app->getView();
			$oldMode = $view->getTemplateMode();
			$view->setTemplateMode(View::TEMPLATE_MODE_CP);
			$html = $view->renderTemplate('best-sellers/_cart/login-required', [
				'loginUrl' => $loginUrl,
				'message' => $message,
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

		Craft::$app->getSession()->setNotice(Craft::t('best-sellers', 'cart.restored'));

		// Redirect to Commerce's configured load cart redirect URL
		$loadCartRedirectUrl = $commerce->getSettings()->loadCartRedirectUrl ?? '';
		$cartUrl = UrlHelper::siteUrl($loadCartRedirectUrl, siteId: $order->orderSiteId);

		return $this->redirect($cartUrl);
	}
}
