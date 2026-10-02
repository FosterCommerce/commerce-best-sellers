<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\Request;
use craft\web\User;
use craft\web\View;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
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
	 * @throws BadRequestHttpException
	 * @throws NotFoundHttpException
	 */
	public function actionRestore(): Response
	{
		/** @var Request $request */
		$request = Craft::$app->getRequest();
		$number = $request->getQueryParam('number');

		if (! is_string($number) || $number === '') {
			throw new BadRequestHttpException(Craft::t('best-sellers', 'cart.error.numberRequired'));
		}

		/** @var User $user */
		$user = Craft::$app->getUser();

		// Log out and reload this link, since a post to users/logout can't redirect to it
		if ($request->getIsPost() && $request->getBodyParam('logOut')) {
			$user->logout(false);

			return $this->redirect($request->getAbsoluteUrl());
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

		// Restore on the order's site, since the cart cookie belongs to that site's store and domain.
		// Redirect once, since an order site that doesn't resolve from its own URL would loop.
		if ($order->orderSiteId !== null && $order->orderSiteId !== Craft::$app->getSites()->getCurrentSite()->id && ! $request->getQueryParam('siteRedirected')) {
			return $this->redirect(UrlHelper::siteUrl(Craft::$app->getConfig()->getGeneral()->actionTrigger . '/best-sellers/cart/restore', [
				'number' => $number,
				'siteRedirected' => 1,
			], siteId: $order->orderSiteId));
		}

		$currentUser = $user->getIdentity();
		$currentUserId = $currentUser?->id;
		$cartCustomerId = $order->customerId;
		$cartCustomer = $order->getCustomer();
		$isCredentialed = $cartCustomer && $cartCustomer->getIsCredentialed();

		// Block another customer's cart, and require login for an account holder's cart
		$blocked = ($currentUser && $cartCustomerId && $cartCustomerId !== $currentUserId) || (! $currentUser && $isCredentialed);

		if ($blocked) {
			// Return the owner to this link after login
			if (! $currentUser) {
				$user->setReturnUrl($request->getAbsoluteUrl());
			}

			// Omit the login URL when front-end login is disabled, so the page hides its login button
			$loginPath = Craft::$app->getConfig()->getGeneral()->getLoginPath();
			$loginUrl = is_string($loginPath) ? UrlHelper::url($loginPath) : null;

			$message = $currentUser
				? Craft::t('best-sellers', 'cart.error.belongsToOther')
				: Craft::t('best-sellers', 'cart.error.loginRequired');

			// Offer a logout when logged in, since the login page skips its form for a logged-in user
			$html = Craft::$app->getView()->renderTemplate('best-sellers/_cart/login-required', [
				'loginUrl' => $loginUrl,
				'logOutFirst' => $currentUser !== null,
				'message' => $message,
			], View::TEMPLATE_MODE_CP);

			return $this->asRaw($html);
		}

		$cartsService = $commerce->getCarts();
		$cartsService->forgetCart();

		$cartsService->setSessionCartNumber($number);

		Craft::$app->getSession()->setNotice(Craft::t('best-sellers', 'cart.restored'));

		$loadCartRedirectUrl = $commerce->getSettings()->loadCartRedirectUrl ?? '';
		$cartUrl = UrlHelper::siteUrl($loadCartRedirectUrl, siteId: $order->orderSiteId);

		return $this->redirect($cartUrl);
	}
}
